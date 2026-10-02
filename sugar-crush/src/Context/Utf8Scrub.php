<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context;

/**
 * The single authority that turns bytes read off disk into valid UTF-8 before
 * they can reach the system prompt.
 *
 * WHY IT EXISTS (audit 15d-08). Both production providers hand the request to
 * Guzzle as `'json' => $params`, and `GuzzleHttp\Utils::jsonEncode()` THROWS
 * on a malformed UTF-8 string. So ONE Latin-1 byte in a `CLAUDE.md`, an
 * `@import`, a forced `instructions:` match, a rules file, a SKILL.md or a
 * memory note did not mangle that document — it failed EVERY request of the
 * session, because the system prompt is rebuilt into every request. MEASURED
 * before this class: a `CLAUDE.md` holding `Caf\xe9` gave a 5,465-byte prompt
 * with `mb_check_encoding()` false and `json_encode error: Malformed UTF-8
 * characters` on the encode. {@see EnvironmentBlock} had already met the same
 * failure for its git output and repaired it for its own block only; this is
 * that repair lifted out so every loader reaches the same one.
 *
 * WHY AT LOAD TIME AND NOT AT THE ASSEMBLY FOLD. A scrub of the finished prompt
 * would fix the encode but could only say "somewhere in this prompt": the
 * loader is the last place that still knows WHICH FILE the bytes came from, so
 * it is where {@see notice()} can name it. Loading clean also fixes the
 * consumers that never see the assembled prompt — a YAML frontmatter parse
 * refuses invalid UTF-8 outright, so before this a single bad byte in a memory
 * note's body silently dropped the whole note, and one in a skill's
 * description dropped the whole skill.
 *
 * WHY `?` AND NOT U+FFFD — the argument {@see EnvironmentBlock}'s
 * `UTF8_SUBSTITUTE` docblock makes, restated because this class now owns it:
 * mbstring emits ONE substitute per invalid SEQUENCE and every invalid
 * sequence is at least one byte, so a one-byte substitute can never make the
 * output longer than the input. Every downstream cap in the prompt path is
 * counted in bytes, and a repair that could grow a capped section past its cap
 * would defeat the bound applied before it. It also makes the count exact:
 * 0x3F is never a continuation byte, so a `?` already in the text is never
 * swallowed into an invalid sequence, and the number of substitutions is
 * exactly the increase in `?` count.
 */
final class Utf8Scrub
{
    /** `?` — what each invalid UTF-8 sequence becomes; see the class docblock. */
    public const SUBSTITUTE = 0x3F;

    private function __construct()
    {
    }

    /**
     * $text as valid UTF-8, plus how many invalid sequences were replaced.
     *
     * Valid input is returned untouched (the same string, zero), which is what
     * keeps every clean repository's prompt byte-identical to before.
     *
     * @return array{0: string, 1: int}
     */
    public static function scrub(string $text): array
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return [$text, 0];
        }

        // Global mbstring state, so it is restored even if the convert throws.
        $previous = mb_substitute_character();
        mb_substitute_character(self::SUBSTITUTE);

        try {
            $scrubbed = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        } finally {
            mb_substitute_character($previous);
        }

        return [$scrubbed, substr_count($scrubbed, '?') - substr_count($text, '?')];
    }

    /** $text as valid UTF-8, for a field too small to carry a notice of its own. */
    public static function clean(string $text): string
    {
        return self::scrub($text)[0];
    }

    /**
     * The line announcing a repair, or the empty string when there was none.
     *
     * Announced because silent repair is the same defect as silent truncation
     * one field over: the model would read `Caf?` as a word that really is
     * spelled that way. Same shape as {@see EnvironmentBlock}'s `[encoding: …]`
     * note, so the model meets one convention wherever the repair happened.
     * $source is scrubbed too — it is usually a path, and a path is bytes.
     */
    public static function notice(int $replaced, string $source): string
    {
        if ($replaced <= 0) {
            return '';
        }

        return "\n[encoding: {$replaced} byte sequence(s) of " . self::clean($source)
            . ' were not valid UTF-8 and were replaced with "?".]';
    }

    /** {@see scrub()} then {@see notice()}: the repaired text with its note appended. */
    public static function announced(string $text, string $source): string
    {
        [$scrubbed, $replaced] = self::scrub($text);

        return $scrubbed . self::notice($replaced, $source);
    }
}
