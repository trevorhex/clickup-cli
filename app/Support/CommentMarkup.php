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
 * items, **bold**, `code`, [text](url) links and @mentions. Anything else is
 * plain text.
 */
class CommentMarkup
{
    /** "@heather" or "@heather.w", but not the "@" in an email address. */
    private const MENTION = '(?<![\w.@])@\w(?:[\w.-]*\w)?';

    /**
     * @param  array<string, array{id: int, username: string}>  $people  lowercase handle (no "@") => user, from mentions()
     * @return list<array>
     */
    public static function toBlocks(string $markdown, array $people = []): array
    {
        $blocks = [];

        foreach (self::lines($markdown) as $line) {
            [$format, $content] = self::lineFormat($line);

            array_push($blocks, ...self::inline($content, $people));
            $blocks[] = ['text' => "\n", 'attributes' => $format];
        }

        return $blocks;
    }

    /**
     * The @handles in a comment, lowercase and without the "@". Ones inside `code`,
     * **bold** or links are left as text, so they aren't returned.
     *
     * @return list<string>
     */
    public static function mentions(string $markdown): array
    {
        $handles = [];

        foreach (self::lines($markdown) as $line) {
            foreach (self::split(self::lineFormat($line)[1]) as $part) {
                if (preg_match('/^'.self::MENTION.'$/u', $part)) {
                    $handles[] = mb_strtolower(substr($part, 1));
                }
            }
        }

        return array_values(array_unique($handles));
    }

    /** @return list<string> */
    private static function lines(string $markdown): array
    {
        return explode("\n", str_replace("\r\n", "\n", trim($markdown)));
    }

    /** @return list<string> */
    private static function split(string $text): array
    {
        return preg_split('/(\*\*.+?\*\*|`[^`]+`|\[[^\]]+\]\([^)\s]+\)|'.self::MENTION.')/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
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

    /** @return list<array> */
    private static function inline(string $text, array $people): array
    {
        return array_map(fn (string $part) => match (true) {
            ($person = self::mentioned($part, $people)) !== null => [
                'type' => 'tag',
                'user' => ['id' => $person['id']],
                'text' => '@'.$person['username'],
            ],
            (bool) preg_match('/^\*\*(.+)\*\*$/', $part, $m) => ['text' => $m[1], 'attributes' => ['bold' => true]],
            (bool) preg_match('/^`(.+)`$/', $part, $m) => ['text' => $m[1], 'attributes' => ['code' => true]],
            (bool) preg_match('/^\[(.+)\]\((.+)\)$/', $part, $m) => ['text' => $m[1], 'attributes' => ['link' => $m[2]]],
            default => ['text' => $part, 'attributes' => []],
        }, self::split($text));
    }

    /** The person a "@handle" part refers to, if it's one we resolved. */
    private static function mentioned(string $part, array $people): ?array
    {
        return preg_match('/^'.self::MENTION.'$/u', $part) ? ($people[mb_strtolower(substr($part, 1))] ?? null) : null;
    }
}
