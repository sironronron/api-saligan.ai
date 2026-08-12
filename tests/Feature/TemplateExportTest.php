<?php

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Template;
use App\Models\User;
use App\Services\Export\TemplateDocumentExportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use ZipArchive;

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->create();
    $this->pro = Plan::factory()->pro()->create();
    Subscription::factory()->for($this->user)->create(['plan_id' => $this->pro->id]);
});

/**
 * Build a .docx template whose header carries a logo image and whose body
 * carries the given placeholder text, so the export path exercises both the
 * header stamping and the in-place fill.
 */
function docxWithHeaderAndBody(string $bodyText): string
{
    $logo = tempnam(sys_get_temp_dir(), 'logo_').'.png';
    $im = imagecreatetruecolor(80, 20);
    $color = imagecolorallocate($im, 30, 100, 200);
    imagefill($im, 0, 0, $color);
    imagestring($im, 5, 4, 2, 'LOGO', 0xFFFFFF);
    imagepng($im, $logo);
    imagedestroy($im);

    $phpWord = new PhpWord;
    $section = $phpWord->addSection();
    $header = $section->addHeader();
    $headerRun = $header->addTextRun();
    $headerRun->addImage($logo, ['width' => 80, 'height' => 20]);
    $headerRun->addText(' ACME LAW OFFICES');
    $section->addText($bodyText);

    $path = tempnam(sys_get_temp_dir(), 'tpl_').'.docx';
    IOFactory::createWriter($phpWord, 'Word2007')->save($path);

    @unlink($logo);

    return $path;
}

/**
 * Upload a .docx template with the given placeholder body and return the model.
 */
function createDocxTemplate(string $bodyText): Template
{
    $path = docxWithHeaderAndBody($bodyText);

    $response = test()->signInAs(test()->user)->postJson('/api/templates', [
        'name' => 'Acme Company Letter',
        'category' => 'custom',
        'template_file' => new UploadedFile(
            $path,
            'acme.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            null,
            true,
        ),
    ])->assertCreated();

    @unlink($path);

    return Template::findOrFail($response->json('data.id'));
}

function exportMessage(Message $message, string $type): TestResponse
{
    return test()->signInAs(test()->user)->post("/api/messages/{$message->id}/export/{$type}");
}

it('exports a Word file by filling the original docx template', function () {
    $template = createDocxTemplate('Dear [Client Name], please settle the [Amount Due].');
    $conversation = Conversation::factory()->for($this->user)->create(['title' => 'Demand letter']);

    $intake = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::User,
        'content' => "[Intake Form Submission]\n[Client Name]: Juan Dela Cruz\n[Amount Due]: PHP 50,000.00",
        'created_at' => now()->subSeconds(2),
    ]);

    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Assistant,
        'content' => "[[DOCUMENT_START]]\nDear Juan Dela Cruz,\n[[DOCUMENT_END]]",
        'metadata' => ['template_id' => $template->id],
        'created_at' => now()->subSecond(),
    ]);

    $response = exportMessage($message, 'word')->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('openxmlformats');

    $filled = tempnam(sys_get_temp_dir(), 'filled_').'.docx';
    file_put_contents($filled, $response->streamedContent());

    $zip = new ZipArchive;
    $zip->open($filled);
    $documentXml = $zip->getFromName('word/document.xml');
    $headerXml = $zip->getFromName('word/header1.xml');
    $zip->close();

    @unlink($filled);

    expect($documentXml)->toContain('Juan Dela Cruz')
        ->and($documentXml)->not->toContain('[Client Name]')
        ->and($documentXml)->toContain('PHP 50,000.00')
        ->and($headerXml)->toContain('imagedata')
        ->and($headerXml)->toContain('ACME LAW OFFICES');
});

it('exports a PDF rendered from the filled template with the header logo', function () {
    $template = createDocxTemplate('Dear [Client Name], please settle the [Amount Due].');
    $conversation = Conversation::factory()->for($this->user)->create(['title' => 'Demand letter']);

    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::User,
        'content' => "[Intake Form Submission]\n[Client Name]: Juan Dela Cruz\n[Amount Due]: PHP 50,000.00",
        'created_at' => now()->subSeconds(2),
    ]);

    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Assistant,
        'content' => "[[DOCUMENT_START]]\nDear Juan Dela Cruz,\n[[DOCUMENT_END]]",
        'metadata' => ['template_id' => $template->id],
        'created_at' => now()->subSecond(),
    ]);

    $response = exportMessage($message, 'pdf')->assertOk();

    $pdf = (string) $response->streamedContent();

    expect($response->headers->get('Content-Type'))->toContain('application/pdf')
        ->and(substr($pdf, 0, 5))->toBe('%PDF-');

    // The letterhead logo stamped from the template header appears in the PDF.
    preg_match_all('/\/Subtype\s*\/Image/', $pdf, $matches);
    expect($matches[0])->toHaveCount(1);
});

it('falls back to the markdown export when no template is associated', function () {
    $conversation = Conversation::factory()->for($this->user)->create(['title' => 'Plain draft']);

    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Assistant,
        'content' => "[[DOCUMENT_START]]\nDEMAND LETTER\n[[DOCUMENT_END]]",
        'metadata' => [],
    ]);

    exportMessage($message, 'pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('fills the template with the values the model supplied through fill_template_fields', function () {
    // Verbatim mode tells the model to answer with the tool call and no prose,
    // so the drafted text carries none of the facts. Before the fill values
    // were persisted, every source the export consulted came up empty and the
    // "generated" file was a byte-for-byte copy of the original template.
    $path = docxWithHeaderAndBody('Dear [Client Name], please settle the [Amount Due] by [Due Date].');

    Storage::put('templates/verbatim.docx', file_get_contents($path));
    @unlink($path);

    $template = Template::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Acme Company Letter',
        'original_path' => 'templates/verbatim.docx',
        'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'placeholder_fields' => ['[Client Name]', '[Amount Due]', '[Due Date]'],
    ]);

    expect($template->isVerbatimTemplate())->toBeTrue();

    $conversation = Conversation::factory()->for($this->user)->create();

    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Assistant,
        'content' => 'I filled in your template with the details for this matter.',
        'metadata' => [
            'template_id' => $template->id,
            'template_fields' => [
                '[Client Name]' => 'Roberto Villanueva',
                '[Amount Due]' => 'Three Million Pesos (PHP 3,000,000.00)',
                '[Due Date]' => 'October 26, 2026',
            ],
        ],
    ]);

    $filled = app(TemplateDocumentExportService::class)
        ->fillForMessage($message, $template);

    $zip = new ZipArchive;
    $zip->open($filled);
    $documentXml = $zip->getFromName('word/document.xml');
    $headerXml = $zip->getFromName('word/header1.xml');
    $zip->close();

    @unlink($filled);

    expect($documentXml)
        ->toContain('Roberto Villanueva')
        ->toContain('Three Million Pesos (PHP 3,000,000.00)')
        ->toContain('October 26, 2026')
        ->not->toContain('[Client Name]')
        ->not->toContain('[Amount Due]')
        ->not->toContain('[Due Date]');

    // The letterhead the user uploaded survives the fill untouched.
    expect($headerXml)->toContain('ACME LAW OFFICES');
});
