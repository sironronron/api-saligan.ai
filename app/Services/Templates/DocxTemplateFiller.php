<?php

namespace App\Services\Templates;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * Fills placeholders in a .docx template by editing the original file's
 * document.xml in place, so fonts, logos, layout, embedded images, and every
 * other part of the original file are completely untouched. Text is matched
 * inside <w:t> nodes only, merging adjacent runs that share identical
 * formatting so placeholders Word split across multiple runs (autocorrect
 * artifacts) are still found.
 */
class DocxTemplateFiller
{
    public const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /**
     * Fill the given template file with the provided values, returning the
     * path to a copy of the original file with its document.xml edited in
     * place. The caller owns the returned temp file and must unlink it.
     *
     * @param  array<string, string>  $values  Literal bracketed token => value.
     */
    public function fill(string $storagePath, array $values): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'saligan_fill_');
        $copyPath = $tmp.'.docx';

        if (! copy($storagePath, $copyPath)) {
            @unlink($tmp);
            throw new RuntimeException('Could not read the template file.');
        }

        try {
            $zip = new ZipArchive;

            if ($zip->open($copyPath) !== true) {
                throw new RuntimeException('Could not open the template archive.');
            }

            $documentXml = $zip->getFromName('word/document.xml');

            if ($documentXml === false) {
                $zip->close();
                throw new RuntimeException('The template has no document body.');
            }

            $zip->addFromString('word/document.xml', $this->fillXml($documentXml, $values));
            $zip->close();
        } catch (\Throwable $e) {
            @unlink($tmp);
            @unlink($copyPath);
            throw $e;
        }

        @unlink($tmp);

        return $copyPath;
    }

    /**
     * Apply the token replacements to a document.xml string.
     *
     * @param  array<string, string>  $values
     */
    public function fillXml(string $xml, array $values): string
    {
        $dom = $this->load($xml);
        $xpath = $this->xpath($dom);

        foreach ($xpath->query('//w:p') as $paragraph) {
            $this->fillParagraph($paragraph, $values);
        }

        return (string) $dom->saveXML();
    }

    /**
     * The text of the document after merging identically-formatted runs, for
     * placeholder matchability analysis. Each element is the merged text of
     * one mergeable group, so a placeholder spanning a formatting boundary is
     * never reported as present.
     *
     * @return array<int, string>
     */
    public function groupTexts(string $xml): array
    {
        $dom = $this->load($xml);
        $xpath = $this->xpath($dom);

        $groups = [];

        foreach ($xpath->query('//w:p') as $paragraph) {
            foreach ($this->runGroups($paragraph) as $group) {
                if ($group['textOnly']) {
                    $groups[] = $group['text'];
                }
            }
        }

        return $groups;
    }

    /**
     * Replace tokens inside a single paragraph's runs.
     *
     * @param  array<string, string>  $values
     */
    protected function fillParagraph(DOMElement $paragraph, array $values): void
    {
        foreach ($this->runGroups($paragraph) as $group) {
            if (! $group['textOnly']) {
                continue;
            }

            $replaced = $this->replaceTokens($group['text'], $values);

            if ($replaced === $group['text']) {
                continue;
            }

            $this->emitMergedRun($paragraph, $group['runs'], $group['rpr'], $replaced);
        }
    }

    /**
     * Group a paragraph's direct-child runs for merging: consecutive runs with
     * identical formatting (identical <w:rPr>) and only text content are
     * grouped together; anything else (non-text runs, hyperlinks, bookmarks,
     * paragraphs properties) is a boundary. <w:proofErr> markers Word inserts
     * between runs are ignored so spell-check artifacts don't block merging.
     *
     * @return array<int, array{textOnly: bool, rprKey: string, rpr: ?DOMElement, text: string, runs: array<int, DOMElement>}>
     */
    protected function runGroups(DOMElement $paragraph): array
    {
        $groups = [];
        $currentIndex = null;

        foreach ($paragraph->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                $currentIndex = null;

                continue;
            }

            if ($child->localName === 'proofErr') {
                continue;
            }

            if ($child->localName !== 'r') {
                $currentIndex = null;

                continue;
            }

            [$textOnly, $rpr, $text] = $this->classifyRun($child);

            if (! $textOnly) {
                $currentIndex = null;
                $groups[] = ['textOnly' => false, 'rprKey' => '', 'rpr' => null, 'text' => '', 'runs' => [$child]];

                continue;
            }

            $rprKey = $this->nodeKey($rpr);

            if ($currentIndex !== null && $groups[$currentIndex]['rprKey'] === $rprKey) {
                $groups[$currentIndex]['text'] .= $text;
                $groups[$currentIndex]['runs'][] = $child;

                continue;
            }

            $groups[] = ['textOnly' => true, 'rprKey' => $rprKey, 'rpr' => $rpr, 'text' => $text, 'runs' => [$child]];
            $currentIndex = count($groups) - 1;
        }

        return $groups;
    }

    /**
     * Classify a run into its formatting, text, and whether it contains only
     * text (so it is safe to merge with its neighbors).
     *
     * @return array{0: bool, 1: ?DOMElement, 2: string}
     */
    protected function classifyRun(DOMElement $run): array
    {
        $rpr = null;
        $text = '';
        $textOnly = true;

        foreach ($run->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            if ($child->localName === 'rPr') {
                $rpr = $child;

                continue;
            }

            if ($child->localName === 't') {
                $text .= $child->textContent;

                continue;
            }

            $textOnly = false;
        }

        return [$textOnly, $rpr, $text];
    }

    /**
     * Replace the buffered runs with a single run carrying the merged text,
     * preserving the shared run formatting.
     *
     * @param  array<int, DOMElement>  $runs
     */
    protected function emitMergedRun(DOMElement $paragraph, array $runs, ?DOMElement $rpr, string $text): void
    {
        $document = $paragraph->ownerDocument;
        $first = $runs[0];

        $run = $document->createElementNS(self::NS_W, 'w:r');

        if ($rpr !== null) {
            $run->appendChild($document->importNode($rpr, true));
        }

        $textNode = $document->createElementNS(self::NS_W, 'w:t');
        $textNode->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        $textNode->appendChild($document->createTextNode($text));
        $run->appendChild($textNode);

        $paragraph->insertBefore($run, $first);

        foreach ($runs as $run) {
            $paragraph->removeChild($run);
        }
    }

    /**
     * @param  array<string, string>  $values
     */
    protected function replaceTokens(string $text, array $values): string
    {
        if ($values === []) {
            return $text;
        }

        $tokens = array_keys($values);

        usort($tokens, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($tokens as $token) {
            $replacement = $values[$token] ?? null;

            if ($replacement === null) {
                continue;
            }

            $text = str_replace($token, (string) $replacement, $text);
        }

        return $text;
    }

    /**
     * A stable key for comparing two runs' formatting.
     */
    protected function nodeKey(?DOMElement $node): string
    {
        if ($node === null) {
            return '';
        }

        return (string) $node->ownerDocument->saveXML($node);
    }

    protected function load(string $xml): DOMDocument
    {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = false;

        if (! $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            throw new RuntimeException('Could not parse the template document.');
        }

        return $dom;
    }

    protected function xpath(DOMDocument $dom): DOMXPath
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::NS_W);

        return $xpath;
    }
}
