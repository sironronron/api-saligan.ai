<?php

namespace App\Services\Documents;

class DocumentChunker
{
    /**
     * Split extracted text into overlapping, paragraph-aware chunks.
     *
     * @return array<int, string>
     */
    public function chunk(string $text, int $size = 500, int $overlap = 50): array
    {
        $text = $this->normalize($text);

        if ($text === '') {
            return [];
        }

        if (count($this->tokenize($text)) <= $size) {
            return [$text];
        }

        $paragraphs = preg_split('/\n\s*\n/', $text) ?: [];

        $chunks = [];
        $buffer = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            if (count($this->tokenize($paragraph)) > $size) {
                if ($buffer !== '') {
                    $chunks[] = $buffer;
                    $buffer = '';
                }

                foreach ($this->splitParagraph($paragraph, $size, $overlap) as $segment) {
                    $chunks[] = $segment;
                }

                continue;
            }

            if ($buffer !== '' && count($this->tokenize($buffer)) + count($this->tokenize($paragraph)) > $size) {
                $chunks[] = $buffer;
                $buffer = $this->tail($buffer, $overlap);
            }

            $buffer = trim($buffer === '' ? $paragraph : $buffer."\n\n".$paragraph);
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return $chunks;
    }

    /**
     * Split a single paragraph that exceeds the size limit into overlapping
     * word windows.
     *
     * @return array<int, string>
     */
    protected function splitParagraph(string $paragraph, int $size, int $overlap): array
    {
        $tokens = $this->tokenize($paragraph);

        $step = max(1, $size - $overlap);

        $segments = [];

        for ($offset = 0; $offset < count($tokens); $offset += $step) {
            $segments[] = implode(' ', array_slice($tokens, $offset, $size));
        }

        return $segments;
    }

    /**
     * Normalize whitespace and control characters.
     */
    protected function normalize(string $text): string
    {
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;

        return preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
    }

    /**
     * Split text into whitespace-delimited tokens.
     *
     * @return array<int, string>
     */
    protected function tokenize(string $text): array
    {
        return preg_split('/\s+/', trim($text)) ?: [];
    }

    /**
     * Keep the last $count tokens of a string.
     */
    protected function tail(string $text, int $count): string
    {
        $tokens = $this->tokenize($text);

        if (count($tokens) <= $count) {
            return $text;
        }

        return implode(' ', array_slice($tokens, -$count));
    }
}
