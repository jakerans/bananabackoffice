<?php

namespace App\Support\Agreements;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * The agreement's wording as plain text (docs/slice-33-agreement-plan.md,
 * step 33a). One line is one thing:
 *
 * - "12. SECTION TITLE": a numbered section heading, its title in capitals;
 * - "- text": an item of a list;
 * - "3) text": an item of a numbered list;
 * - anything else: a paragraph.
 *
 * Blank lines are ignored. It is stored and shown as text, never as web code,
 * so nothing typed or imported can run in a browser.
 */
class AgreementText
{
    /**
     * Read the wording into what comes before the first section, and the sections.
     *
     * @return array{intro: list<array{type: string, text?: string, ordered?: bool, items?: list<string>}>, sections: list<array{number: int, title: string, blocks: list<array{type: string, text?: string, ordered?: bool, items?: list<string>}>}>}
     */
    public static function parse(string $body): array
    {
        $intro = [];
        $sections = [];
        $blocks = &$intro;

        foreach (preg_split('/\R/u', $body) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^(\d{1,3})\.\s+(\S.*)$/u', $line, $heading) === 1 && mb_strtoupper($heading[2]) === $heading[2]) {
                unset($blocks);
                $sections[] = ['number' => (int) $heading[1], 'title' => $heading[2], 'blocks' => []];
                $blocks = &$sections[array_key_last($sections)]['blocks'];

                continue;
            }

            if (preg_match('/^(?:[-•]|(\d{1,3})\))\s+(\S.*)$/u', $line, $item) === 1) {
                $ordered = $item[1] !== '';
                $last = array_key_last($blocks);

                if ($last !== null && $blocks[$last]['type'] === 'list' && ($blocks[$last]['ordered'] ?? null) === $ordered) {
                    $blocks[$last]['items'][] = $item[2];
                } else {
                    $blocks[] = ['type' => 'list', 'ordered' => $ordered, 'items' => [$item[2]]];
                }

                continue;
            }

            $blocks[] = ['type' => 'paragraph', 'text' => $line];
        }

        return ['intro' => $intro, 'sections' => $sections];
    }

    /**
     * Put line endings and spacing in order, so two copies of the same wording compare equal.
     */
    public static function normalize(string $body): string
    {
        $lines = array_map(fn (string $line): string => preg_replace('/[ \t]+/u', ' ', trim($line)) ?? trim($line), preg_split('/\R/u', $body) ?: []);

        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)) ?? '');
    }

    /**
     * Turn PaintScout's terms, a page of paragraphs and lists, into this plain text.
     *
     * The first paragraph is the title when it is written in capitals and is not
     * a section heading. Emphasis is dropped; the words are kept.
     *
     * @return array{title: string|null, body: string}
     */
    public static function fromHtml(string $html): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><body>'.$html.'</body>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $lines = [];
        $body = $document->getElementsByTagName('body')->item(0);

        foreach ($body->childNodes ?? [] as $node) {
            if (! $node instanceof DOMElement) {
                $text = self::text($node);

                if ($text !== '') {
                    $lines[] = $text;
                }

                continue;
            }

            $tag = strtolower($node->tagName);

            if ($tag === 'ul' || $tag === 'ol') {
                $number = 0;

                foreach ($node->childNodes as $item) {
                    if ($item instanceof DOMElement && strtolower($item->tagName) === 'li' && ($text = self::text($item)) !== '') {
                        $lines[] = ($tag === 'ol' ? (++$number).')' : '-').' '.$text;
                    }
                }

                continue;
            }

            $text = self::text($node);

            if ($text !== '') {
                $lines[] = $text;
            }
        }

        $title = null;
        $first = $lines[0] ?? null;

        if ($first !== null && mb_strtoupper($first) === $first && preg_match('/^\d{1,3}\.\s/u', $first) !== 1) {
            $title = $first;
            array_shift($lines);
        }

        return ['title' => $title, 'body' => implode("\n", $lines)];
    }

    /**
     * A node's words on one line.
     */
    private static function text(DOMNode $node): string
    {
        return trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');
    }
}
