<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Template;
use App\Services\Export\DocumentExportService;
use App\Services\Export\TemplateDocumentExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function __construct(
        private readonly DocumentExportService $exportService,
        private readonly TemplateDocumentExportService $templateExportService,
    ) {
        //
    }

    /**
     * Export a message as a Word document. Drafts produced from an uploaded
     * .docx template are exported by filling that template's placeholders with
     * the intake values collected during drafting, so the original logo and
     * formatting are preserved.
     */
    public function word(Request $request, Message $message): StreamedResponse
    {
        abort_unless($message->conversation->user_id === $request->user()->id, 403);

        $title = $this->deriveTitle($message);
        $template = $this->resolvedTemplate($request, $message);

        if ($template?->isDocxFileTemplate()) {
            $tempFile = $this->templateExportService->fillForMessage($message, $template);
            $filename = $this->sanitizeFilename($title).'.docx';

            return $this->streamFile($tempFile, $filename, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        }

        $tempFile = $this->exportService->toWord($message->content, $title);
        $filename = $this->sanitizeFilename($title).'.docx';

        return $this->streamFile($tempFile, $filename, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }

    /**
     * Export a message as a PDF. Drafts produced from an uploaded .docx
     * template are exported from the filled template file (server-side PDF
     * rendering, keeping the template's logo), so the exported file matches
     * the user's company letterhead.
     */
    public function pdf(Request $request, Message $message): StreamedResponse
    {
        abort_unless($message->conversation->user_id === $request->user()->id, 403);

        $title = $this->deriveTitle($message);
        $template = $this->resolvedTemplate($request, $message);

        if ($template?->isDocxFileTemplate()) {
            $filledPath = $this->templateExportService->fillForMessage($message, $template);
            $tempFile = $this->templateExportService->toPdf($filledPath);
            $filename = $this->sanitizeFilename($title).'.pdf';

            return $this->streamFile($tempFile, $filename, 'application/pdf');
        }

        $tempFile = $this->exportService->toPdf($message->content, $title);
        $filename = $this->sanitizeFilename($title).'.pdf';

        return $this->streamFile($tempFile, $filename, 'application/pdf');
    }

    /**
     * The template a drafted message was produced from, when the draft carries
     * one and the requesting user may still access it.
     */
    protected function resolvedTemplate(Request $request, Message $message): ?Template
    {
        $templateId = $message->metadata['template_id'] ?? null;

        if (! is_string($templateId) || $templateId === '') {
            return null;
        }

        return Template::query()
            ->visibleTo($request->user())
            ->whereKey($templateId)
            ->first();
    }

    protected function deriveTitle(Message $message): string
    {
        return $message->conversation->title ?? 'Saligan AI Response';
    }

    protected function sanitizeFilename(string $name): string
    {
        $name = preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $name);
        $name = preg_replace('/\s+/', '_', trim($name));

        return Str::limit($name, 60) ?: 'response';
    }

    protected function streamFile(string $tempFile, string $filename, string $contentType): StreamedResponse
    {
        return response()->streamDownload(function () use ($tempFile): void {
            readfile($tempFile);
            @unlink($tempFile);
        }, $filename, [
            'Content-Type' => $contentType,
        ]);
    }
}
