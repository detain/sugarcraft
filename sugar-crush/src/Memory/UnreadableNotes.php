<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Memory;

/**
 * The user-facing wording for memory notes a {@see MemoryStore} skipped as
 * unreadable — the launch notice and the `/memory` section — in one place, so
 * the two cannot describe the same files differently.
 *
 * WHY THIS EXISTS. Since 76f252708 (audit 15d-04) a malformed note is skipped
 * instead of failing every turn, and {@see MemoryStore::skipped()} records why.
 * The only reader was {@see \SugarCraft\Crush\Context\MemoryBlock}, which tells
 * the MODEL — inside the prompt — that some project notes could not be read.
 * The person who wrote the note, and the only one who can fix it, was told
 * nothing: a note silently stopped working. This class is what reaches them,
 * outside the prompt (audit 15d-02 follow-up).
 *
 * A SugarCraft diagnostic, not a port — charmbracelet/crush has no memory
 * store to mirror.
 */
final class UnreadableNotes
{
    /**
     * The aggregate launch row: `%d` the count, `%s` the noun plural, `%s`
     * was/were, `%s` the per-scope breakdown, `%s` the pronoun.
     *
     * ONE ROW, whatever the count, for the reason the skill-skip row is one: a
     * launch transcript is a shared, capped channel, and a store full of
     * broken notes must not flood it. The detail lives behind `/memory list`.
     */
    public const NOTICE_FORMAT =
        '%d memory note%s could not be read and %s skipped (%s); %s not in the prompt — `/memory list <scope>` names each file and why';

    /** Heads the `/memory` section listing the unreadable files. */
    public const SECTION_HEADER = '**Unreadable notes (skipped — not listed and not in the prompt):**';

    /**
     * Per-file ceiling on the parser's reason in the `/memory` section, in
     * characters. A YAML error can quote a whole line of the note back; one
     * row per file has to stay one row.
     */
    public const REASON_MAX_CHARS = 200;

    private function __construct()
    {
    }

    /**
     * The one-line launch notice for $unreadable, or '' when there is nothing
     * to say.
     *
     * @param array<string, string> $unreadable path => reason, as
     *        {@see MemoryStore::unreadable()} returns it
     */
    public static function notice(array $unreadable): string
    {
        $count = \count($unreadable);
        if ($count === 0) {
            return '';
        }

        $byScope = [];
        foreach (array_keys($unreadable) as $path) {
            $scope = self::scopeOf((string) $path);
            $byScope[$scope] = ($byScope[$scope] ?? 0) + 1;
        }
        ksort($byScope, \SORT_STRING);

        $breakdown = implode(', ', array_map(
            static fn(string $scope, int $n): string => "{$scope}: {$n}",
            array_keys($byScope),
            $byScope,
        ));

        return sprintf(
            self::NOTICE_FORMAT,
            $count,
            $count === 1 ? '' : 's',
            $count === 1 ? 'was' : 'were',
            $breakdown,
            $count === 1 ? 'it is' : 'they are',
        );
    }

    /**
     * The `/memory` answer's section naming every unreadable file and why, or
     * an empty list when there is none — so a caller appends nothing at all.
     *
     * @param array<string, string> $unreadable path => reason
     * @return list<string>
     */
    public static function rows(array $unreadable): array
    {
        if ($unreadable === []) {
            return [];
        }

        ksort($unreadable, \SORT_STRING);
        $rows = [self::SECTION_HEADER];

        foreach ($unreadable as $path => $reason) {
            $rows[] = '- `' . self::oneLine((string) $path) . '` — ' . self::clip(self::oneLine($reason));
        }

        return $rows;
    }

    /**
     * The scope a note file belongs to: the store keeps each scope in its own
     * sub-directory, `<store>/<scope>/<id>.md`.
     */
    private static function scopeOf(string $path): string
    {
        return basename(\dirname($path));
    }

    /**
     * A path or parser message is arbitrary bytes (a YAML error quotes the
     * note): control bytes go, because a row that can start a new line can
     * forge the next one, and invalid UTF-8 is scrubbed, because the transcript
     * is JSON-encoded into the session store and one bad byte fails the encode.
     */
    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', mb_scrub($text, 'UTF-8')));
    }

    private static function clip(string $text): string
    {
        if (mb_strlen($text, 'UTF-8') <= self::REASON_MAX_CHARS) {
            return $text;
        }

        return mb_substr($text, 0, self::REASON_MAX_CHARS - 1, 'UTF-8') . '…';
    }
}
