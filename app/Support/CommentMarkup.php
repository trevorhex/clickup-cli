<?php

namespace App\Support;

/**
 * Turns a small Markdown subset into ClickUp's rich comment blocks.
 *
 * ClickUp stores comments as Quill-style deltas: inline text runs carry
 * bold/code/link attributes, and the "\n" that ends each line carries the
 * line's format (header level, bullet or numbered list). Plain `comment_text`
 * shows Markdown literally, so comments are sent as blocks instead.
 *
 * Supported: "# " to "### " headings, "- " / "* " bullets, "1. " numbered
 * items, **bold**, `code` and [text](url) links. Anything else is plain text.
 */
class CommentMarkup
{
    /** @return list<array{text: string, attributes: array}> */
    public static function toBlocks(string $markdown): array
    {
        $blocks = [];
        $lines = explode("\n", str_replace("\r\n", "\n", trim($markdown)));

        foreach ($lines as $line) {
            [$format, $content] = self::lineFormat($line);

            array_push($blocks, ...self::inline($content));
            $blocks[] = ['text' => "\n", 'attributes' => $format];
        }

        return $blocks;
    }

    /** @return array{0: array, 1: string} the "\n" attributes and the line without its marker */
    private static function lineFormat(string $line): array
    {
        return match (true) {
            (bool) preg_match('/^(#{1,3})\s+(.*)$/', $line, $m) => [['header' => strlen($m[1])], $m[2]],
            (bool) preg_match('/^[-*]\s+(.*)$/', $line, $m) => [['list' => ['list' => 'bullet']], $m[1]],
            (bool) preg_match('/^\d+[.)]\s+(.*)$/', $line, $m) => [['list' => ['list' => 'ordered']], $m[1]],
            default => [[], $line],
        };
    }

    /** @return list<array{text: string, attributes: array}> */
    private static function inline(string $text): array
    {
        $parts = preg_split('/(\*\*.+?\*\*|`[^`]+`|\[[^\]]+\]\([^)\s]+\))/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        return array_map(fn (string $part) => match (true) {
            (bool) preg_match('/^\*\*(.+)\*\*$/', $part, $m) => ['text' => $m[1], 'attributes' => ['bold' => true]],
            (bool) preg_match('/^`(.+)`$/', $part, $m) => ['text' => $m[1], 'attributes' => ['code' => true]],
            (bool) preg_match('/^\[(.+)\]\((.+)\)$/', $part, $m) => ['text' => $m[1], 'attributes' => ['link' => $m[2]]],
            default => ['text' => $part, 'attributes' => []],
        }, $parts);
    }
}
