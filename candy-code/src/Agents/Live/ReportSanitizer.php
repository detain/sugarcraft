<?php

declare(strict_types=1);

namespace SugarCraft\Code\Agents\Live;

/**
 * Neutralizes prompt-injection-by-imitation in sub-agent report text.
 *
 * A delegated run's transcript is re-ingested after it is written: the live
 * Agent View projects it into rows ({@see AgentTranscriptTail::messages()}),
 * and a finished run is saved whole as the session a human or the parent
 * model opens. {@see AgentTranscriptTail::clean()} already strips what a
 * TERMINAL would interpret — escapes, C0/C1 controls, DEL, the Private-Use
 * markup arena (the same vocabulary as sugar-core's
 * {@see \SugarCraft\Core\Util\Sanitize::untrustedForMarkedFrames()} family).
 * What survives that sweep is the other dangerous class: perfectly printable
 * text that a MODEL can mistake for the harness's own turn envelope — a line
 * starting {@code <system>}, a {@code [SYSTEM]} header, a ChatML-style
 * {@code <|im_start|>} token, a fake {@code [tool_result]} block opener,
 * an {@code assistant:} label. Those are not control bytes; no escape strip
 * can see them. This class is the strip that can: it rewrites the delimited
 * marker SHAPES into an inert visible form so no content is dropped and a
 * reader can still see what the run tried to say.
 *
 * The transform vocabulary (deterministic, idempotent, never content-losing):
 *
 *   - Pipe-delimited special tokens ({@code <|name|>}) are SWALLOWED wherever
 *     they occur, replaced by {@see MARKER}. These have no honest use in
 *     report prose — they are reserved vocabularies of ChatML/Llama-style
 *     templates, and one forged mid-sentence still opens a pseudo-turn.
 *   - Angle tags ({@code <name>}, {@code </name>}, even unterminated) AT A
 *     LINE HEAD get their ASCII {@code <} (and a directly-closing {@code >})
 *     replaced by the fullwidth forms U+FF1C/U+FF1E: readably identical to a
 *     human, invisible to any parser keyed on ASCII tag introducers, and
 *     reversible by reading (NFKC folds the glyph back).
 *   - Bracket headers ({@code [SYSTEM]}) at a line head, when ALL-CAPS or a
 *     known role word, get their ASCII square brackets folded to
 *     U+FF3B/U+FF3D the same way. Lowercase non-role brackets ([docs], [fix])
 *     stay — markdown labels are too common a false positive to mangle.
 *   - Role-label lines ({@code system:}, {@code assistant(name):}) at a head
 *     have the label span (word, optional parenthetical, colon) folded to
 *     fullwidth; the colon is part of what makes the line parse as a header,
 *     so it folds with the word.
 *
 * A head is the start of a logical line after ANY amount of whitespace,
 * CR (which returns the physical cursor to column 0 — a repaint cannot hide
 * a header from this scan), the invisible bidi/zero-width format characters
 * (the two tiers of {@see \SugarCraft\Core\Util\Sanitize::markInvisibleFormatting()}
 * — kept, but looked through, so a ZWSP cannot smuggle a header past the
 * anchor), and any number of {@see MARKER}s a previous pass left behind (so a
 * forged pipe token cannot push a real marker out of the anchored position).
 *
 * What is deliberately NOT stripped, and why:
 *   - Mid-line angle/bracket tags: {@code "the parser accepts <system> as
 *     data"} is prose about a marker, not a turn envelope — the imitation
 *     needs a line-initial position to read as one (pipe tokens excepted, as
 *     they smuggle no position requirement).
 *   - The invisible characters themselves — the tail keeps paste fidelity;
 *     they are traversed by the anchor rather than deleted.
 *   - Free-form claims ("ignore previous instructions") — no delimiter makes
 *     them machine-relevant; a harness keys on structure, and text this
 *     honest is better shown to the reader than silently edited.
 *   - Escapes, C0/C1, PUA — owned upstream by {@code clean()}; this class
 *     runs AFTER that sweep and assumes it already happened (it re-scrubs
 *     invalid UTF-8 defensively so a standalone caller cannot hand it a
 *     pattern-poisoned subject).
 *
 * Idempotence: every replacement emits characters that are NOT themselves
 * ASCII introducers, so a second pass over the output finds no new head. The
 * one growth case is a pipe token at the very head of an already-swallowed
 * line (the MARKER contains none, so it re-emits verbatim) — repeated passes
 * reach a fixed point, which the test suite pins.
 *
 * Length: output is at most three bytes per rewritten ASCII byte (fullwidth
 * spellings) plus MARKER widenings; the tail's line and page budgets bound the
 * input ({@see SubAgentTranscriptLog::MAX_LINE_BYTES}), and this class adds a
 * constant factor, not a new unbounded surface.
 */
final class ReportSanitizer
{
    /**
     * The display form a swallowed special token takes — shaped like the
     * harness's own bracket-note idiom ({@code <U+XXXX>}). Its inner colon
     * keeps it out of {@see BRACKET_HEAD} and its leading {@code [} keeps it
     * out of {@see ROLE_HEAD}, so it is stable under its own rules; the head
     * anchor looks through it so a marker cannot shield a following header.
     */
    public const MARKER = '[harness: neutralized]';

    /**
     * Role vocabulary — words whose tag/label form reads as a turn envelope
     * to a chat-template parser. Case-insensitive by construction (the
     * patterns apply the {@code i} flag only where a roster word is matched).
     */
    private const ROLE_WORDS = 'system|assistant|user|human|ai|bot|tool|tools|tool_result|tools_result|function|functions|instruction|instructions|prompt|developer|admin|root|harness';

    /** The format characters a line head may hide behind (both 15b-28 tiers). */
    private const INVISIBLE_CLASS = '\x{061C}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{2066}-\x{2069}\x{FEFF}';

    /**
     * Whitespace, CR (column 0), the invisible format tiers, and MARKERs —
     * everything a line head may hide behind. Built from {@see MARKER} so the
     * traversal can never drift from the marker it traverses.
     */
    private static function leadIn(): string
    {
        static $pattern = null;
        $pattern ??= '/^(?:[\s' . self::INVISIBLE_CLASS . ']|\Q' . self::MARKER . '\E)*/u';

        return $pattern;
    }

    /**
     * ChatML/Llama-style special tokens, matched byte-wise (no {@code /u}):
     * the same fail-closed law as {@see \SugarCraft\Core\Util\Sanitize::untrusted()}
     * — a malformed sequence anywhere must not be able to turn the sweep off,
     * and these literal ASCII bytes cannot occur inside another character.
     */
    private const PIPE_TOKEN = '/<\|[A-Za-z0-9_+\-]{0,64}\|>/';

    /** Angle tag (open, close, or unterminated) anchored at the head. */
    private const ANGLE_HEAD = '/^<(\/?[A-Za-z][A-Za-z0-9_+\-]{0,63}>?)/u';

    /**
     * Bracket header anchored at the head: an all-caps shouty form (the
     * imitation style) or a roster word in any case ([SYSTEM], [System],
     * [Tool_Result], [tool_result]) — deliberately NOT any lowercase word,
     * which is markdown-label territory.
     */
    private const BRACKET_HEAD = '/^\[(?:(?i:' . self::ROLE_WORDS . ')|[A-Z][A-Z0-9_+\-]{1,31})\]/u';

    /** Role-label line header: roster word, optional (name), colon. */
    private const ROLE_HEAD = '/^(?:' . self::ROLE_WORDS . ')(?:\([A-Za-z0-9_+\-]{1,64}\))?[ \t]*:(?![A-Za-z0-9])/iu';

    /**
     * $report text with every imitation-control HEAD neutralized in place.
     * Pure and deterministic; never drops content.
     */
    public static function sanitize(string $text): string
    {
        if ($text === '') {
            return '';
        }

        // The /u patterns below FAIL on malformed UTF-8 — scrub first so they
        // cannot no-op over hostile bytes (the tail's clean() scrubs before
        // calling; a standalone caller gets the same guarantee here).
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_scrub($text, 'UTF-8');
        }

        // Split with the separators captured so every \r\n, \r and \n rides
        // back out byte-identical — CR ends a logical line for anchoring
        // purposes (column 0) without being rewritten into one.
        $parts = preg_split('/(\r\n|\r|\n)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return self::bluntEscape($text);
        }

        $out = '';
        for ($i = 0, $n = \count($parts); $i < $n; $i += 2) {
            $out .= self::defangHead(self::swallowPipeTokens($parts[$i]));
            if (isset($parts[$i + 1])) {
                $out .= $parts[$i + 1];
            }
        }

        return $out;
    }

    /**
     * Every special token in one line-chunk becomes {@see MARKER}. The
     * byte-level pattern has no failure mode on well-formed input; the
     * fallback over-escapes rather than leaking (fail-closed).
     */
    private static function swallowPipeTokens(string $chunk): string
    {
        return preg_replace_callback(
            self::PIPE_TOKEN,
            static fn (array $m): string => self::MARKER,
            $chunk,
        ) ?? self::bluntEscape($chunk);
    }

    /**
     * The one imitation header at this chunk's head (angle, bracket, or role
     * label — in that order, since {@code <|} shapes are already swallowed)
     * folded into its inert visible spelling. One head per line: whatever
     * follows the rewritten header is mid-line text, inert by the contract in
     * the class docblock.
     */
    private static function defangHead(string $chunk): string
    {
        if ($chunk === '') {
            return $chunk;
        }

        if (preg_match(self::leadIn(), $chunk, $lead) !== 1) {
            return self::bluntEscape($chunk);
        }
        $start = \strlen($lead[0]);
        if ($start >= \strlen($chunk)) {
            return $chunk;
        }
        $head = substr($chunk, $start);
        $prefix = substr($chunk, 0, $start);

        if (preg_match(self::ANGLE_HEAD, $head, $tag) === 1) {
            return $prefix . str_replace(['<', '>'], ['＜', '＞'], $tag[0]) . substr($head, \strlen($tag[0]));
        }

        if (preg_match(self::BRACKET_HEAD, $head, $tag) === 1) {
            return $prefix . str_replace(['[', ']'], ['［', '］'], $tag[0]) . substr($head, \strlen($tag[0]));
        }

        if (preg_match(self::ROLE_HEAD, $head, $tag) === 1) {
            return $prefix . self::fullwidth($tag[0]) . substr($head, \strlen($tag[0]));
        }

        return $chunk;
    }

    /**
     * ASCII printable (!-~) → fullwidth (U+FF01-U+FF5E): same glyphs a reader
     * sees, no ASCII token a parser can match, NFKC-reversible.
     */
    private static function fullwidth(string $ascii): string
    {
        return preg_replace_callback(
            '/[!-~]/',
            static fn (array $m): string => mb_chr(\ord($m[0]) + 0xFEE0, 'UTF-8'),
            $ascii,
        ) ?? self::bluntEscape($ascii);
    }

    /**
     * Never-reached-in-practice last resort (a PCRE internal error): fold
     * every introducer byte in the chunk to its fullwidth spelling. Wrong for
     * prose, right for safety — this whole class over-neutralizes when it
     * cannot tell.
     */
    private static function bluntEscape(string $chunk): string
    {
        return strtr($chunk, ['<' => '＜', '[' => '［', '|' => '｜']);
    }
}
