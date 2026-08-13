<?php

namespace App\Support;

use App\Models\Document;
use App\Models\Message;

final class MessageAttachments
{
    /**
     * Resolve the documents the user attached to a message.
     *
     * The ids are recorded on the message's metadata when it is sent, so the
     * files stay shown with the message that carried them even after the
     * conversation is reloaded. A document deleted since simply drops out.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function for(Message $message): array
    {
        $ids = $message->metadata['attachment_ids'] ?? [];

        if (! is_array($ids) || $ids === []) {
            return [];
        }

        $documents = Document::query()
            ->whereIn('id', $ids)
            ->get(['id', 'title', 'original_filename', 'mime_type', 'status'])
            ->keyBy('id');

        $attachments = [];

        // Ordered by the ids on the message, not by the query, so the files
        // appear in the order the user attached them.
        foreach ($ids as $id) {
            $document = $documents->get($id);

            if ($document === null) {
                continue;
            }

            $attachments[] = [
                'id' => $document->id,
                'title' => $document->title,
                'original_filename' => $document->original_filename,
                'mime_type' => $document->mime_type,
                'status' => $document->status->value,
            ];
        }

        return $attachments;
    }
}
