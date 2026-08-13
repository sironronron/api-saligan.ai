<?php

namespace App\Enums;

use App\Models\Conversation;
use App\Models\Document;

enum LabelKind: string
{
    case DocumentCategory = 'document_category';
    case ThreadTag = 'thread_tag';

    /**
     * The human-readable label for the kind.
     */
    public function label(): string
    {
        return match ($this) {
            self::DocumentCategory => 'Document Category',
            self::ThreadTag => 'Thread Tag',
        };
    }

    /**
     * The model a label of this kind may be attached to. Guards against a
     * thread tag being filed on a document, or the reverse.
     *
     * @return class-string
     */
    public function labelableType(): string
    {
        return match ($this) {
            self::DocumentCategory => Document::class,
            self::ThreadTag => Conversation::class,
        };
    }

    /**
     * The most labels of this kind a single record may carry. Categories are
     * a filing decision and stay few; without a ceiling the filters stop
     * discriminating and every document matches every query.
     */
    public function maxPerRecord(): int
    {
        return match ($this) {
            self::DocumentCategory => 5,
            self::ThreadTag => 10,
        };
    }
}
