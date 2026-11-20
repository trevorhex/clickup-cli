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
     * @param  array<string, array{id: int, username: string}>  $people  lowercase name as typed after "@" => user, from resolving mentions()
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
     * Each @mention in a comment, as the names it could stand for, longest first and
     * lowercase: "@Cameron Smith thanks" gives ["cameron smith thanks", "cameron smith",
     * "cameron"], so a full name can be matched. Mentions inside `code`, **bold** or
     * links are left as text, so they aren't returned.
     *
     * @return list<list<string>>
     */
    public static function mentions(string $markdown): array
    {
        $mentions = [];

        foreach (self::lines($markdown) as $line) {
            $parts = self::split(self::lineFormat($line)[1]);

            foreach ($parts as $i => $part) {
                if (! preg_match('/^'.self::MENTION.'$/u', $part)) {
                    continue;
                }

                $names = [mb_strtolower(substr($part, 1))];
                preg_match_all("/\\G\\s+([\\p{L}\\p{M}'’-]+)/u", $parts[$i + 1] ?? '', $words);
                foreach (array_slice($words[1], 0, 2) as $word) {
                    $names[] = end($names).' '.mb_strtolower($word);
                }

                $mentions[] = array_reverse($names);
            }
        }

        return array_values(array_unique($mentions, SORT_REGULAR));
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
        $parts = self::split($text);
        $blocks = [];

        for ($i = 0; $i < count($parts); $i++) {
            $part = $parts[$i];

            $hit = preg_match('/^'.self::MENTION.'$/u', $part) ? self::mentioned($part, $parts[$i + 1] ?? '', $people) : null;

            if ($hit) {
                [$person, $length] = $hit;
                $blocks[] = ['type' => 'tag', 'user' => ['id' => $person['id']], 'text' => '@'.$person['username']];

                // The rest of a typed full name ("@Cameron| Smith") is part of the tag.
                if (isset($parts[$i + 1]) && ($parts[$i + 1] = substr($parts[$i + 1], $length)) === '') {
                    $i++;
                }

                continue;
            }

            $blocks[] = match (true) {
                (bool) preg_match('/^\*\*(.+)\*\*$/', $part, $m) => ['text' => $m[1], 'attributes' => ['bold' => true]],
                (bool) preg_match('/^`(.+)`$/', $part, $m) => ['text' => $m[1], 'attributes' => ['code' => true]],
                (bool) preg_match('/^\[(.+)\]\((.+)\)$/', $part, $m) => ['text' => $m[1], 'attributes' => ['link' => $m[2]]],
                default => ['text' => $part, 'attributes' => []],
            };
        }

        return $blocks;
    }

    /**
     * Who a "@handle" refers to, preferring the longest resolved name that the
     * following text continues ("@cameron" + " smith…" → "cameron smith").
     *
     * @return array{0: array, 1: int}|null the person, and how many bytes of $next the name used
     */
    private static function mentioned(string $part, string $next, array $people): ?array
    {
        $handle = mb_strtolower(substr($part, 1));
        $best = null;

        foreach ($people as $name => $person) {
            $name = (string) $name;
            if ($name !== $handle && ! str_starts_with($name, "{$handle} ")) {
                continue;
            }

            $rest = substr($name, strlen($handle));
            $pattern = '/^'.implode('\s+', array_map(fn ($w) => preg_quote($w, '/'), explode(' ', $rest))).'(?![\p{L}\p{M}\w])/iu';

            if ($rest === '' || preg_match($pattern, $next, $m)) {
                $length = $rest === '' ? 0 : strlen($m[0]);
                if ($best === null || $length > $best[1]) {
                    $best = [$person, $length];
                }
            }
        }

        return $best;
    }
}
