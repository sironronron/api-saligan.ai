<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Services\Export\DocumentExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function __construct(
        private readonly DocumentExportService $exportService,
    ) {
        //
    }

    /**
     * Export a message as a Word document.
     */
    public function word(Request $request, Message $message): StreamedResponse
    {
        abort_unless($message->conversation->user_id === $request->user()->id, 403);

        $title = $this->deriveTitle($message);
        $tempFile = $this->exportService->toWord($message->content, $title);
        $filename = $this->sanitizeFilename($title).'.docx';

        return response()->streamDownload(function () use ($tempFile) {
            readfile($tempFile);
            @unlink($tempFile);
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }

    /**
     * Export a message as a PDF.
     */
    public function pdf(Request $request, Message $message): StreamedResponse
    {
        abort_unless($message->conversation->user_id === $request->user()->id, 403);

        $title = $this->deriveTitle($message);
        $tempFile = $this->exportService->toPdf($message->content, $title);
        $filename = $this->sanitizeFilename($title).'.pdf';

        return response()->streamDownload(function () use ($tempFile) {
            readfile($tempFile);
            @unlink($tempFile);
        }, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
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
}
