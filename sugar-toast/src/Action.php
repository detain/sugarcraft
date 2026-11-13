<?php

declare(strict_types=1);

namespace SugarCraft\Toast;

/**
 * A clickable action button for an alert.
 *
 * Encapsulates a label and a closure callback that is invoked when the
 * action is triggered.
 *
 * Lifetime: the callback rides its Alert into the dismiss/overflow history,
 * so a captured scope stays alive until the history limit evicts the entry.
 * Actions (and therefore Alerts and Toasts carrying them) are NOT
 * serializable and are fork-hostile in the sense that a child process
 * inherits a detached copy of the scope — host persistence layers must
 * store labels, not Action objects.
 *
 * The label is normalised at construction — the single ingress point the
 * whole label channel flows through. Inline CSI-SGR styling survives (a
 * red "Delete" is a legitimate design ask, and the message channel already
 * honours it); every other control construct is stripped, so a label sourced
 * from hostile text can never emit raw TAB/LF bytes, OSC framing, or
 * non-styling escapes onto the wire. sugar-mouse
 * zone sentinels U+E000/U+E001 are ordinary printable PUA glyphs here and
 * pass through by the same documented law as the message channel
 * (README "Security & Trust Boundary").
 */
final class Action
{
    /** User-visible button label, normalised at construction. @var non-empty-string */
    public readonly string $label;

    /**
     * @param string $label  User-visible button label; must retain at least
     *                       one printable character after control/escape
     *                       normalisation (psalm's non-empty-string made
     *                       literal)
     * @param \Closure(): void $callback  Invoked when action is triggered
     */
    public function __construct(string $label, public readonly \Closure $callback)
    {
        $this->label = self::normaliseLabel($label);
    }

    /**
     * Factory for fluent construction — the project's `::new()` convention
     * (no `::make()`/`::create()`/`::default()`).
     */
    public static function new(string $label, \Closure $callback): self
    {
        return new self($label, $callback);
    }

    /**
     * Parse the label down to printable text + CSI-SGR styling (Law 2: data
     * is trusted after this boundary, so no render path re-validates it).
     *
     * Kept: CSI SGR sequences — parameter bytes `[0-9;]` with optional
     * intermediates and final `m`, rewritten in canonical form. Dropped: OSC
     * strings (BEL- or ST-terminated), DCS/APC/PM/SOS strings, non-SGR CSI
     * sequences (DEC-private `?…m` included), any remaining bare ESC byte,
     * all C0 controls (TAB and DEL included), and C1 controls. The PUA zone
     * sentinels U+E000/U+E001 are outside every drop set and survive
     * untouched, exactly as documented.
     *
     * Ordered-arm law regression note: the kept arm once admitted `?` as a
     * parameter byte, so `ESC[?1049m`-shaped DEC-private m-finals were
     * re-emitted canonically — a project-tier label could then flip the
     * host's alt-screen or bracketed-paste mode, making the sentence above
     * ("non-SGR CSI can never be mistaken for a kept sequence") false. The
     * `?` lives only in the drop-arm class now; do not "tidy" the two
     * parameter classes into one shared pattern, the asymmetry IS the law.
     *
     * @throws \InvalidArgumentException when nothing printable remains
     */
    private static function normaliseLabel(string $label): string
    {
        // One ordered pass. Alternatives compete left-to-right, so SGR is
        // kept before generic CSI dies, and a non-SGR escape can never be
        // mistaken for a kept sequence by a later stage — an earlier staged
        // version of this function ate the ESC bytes of the sequences it
        // promised to keep.
        $out = \preg_replace_callback(
            '/\x1b\[(?<sgr>[0-9;]*)(?<si>[ -\/]*)m'             // kept: CSI SGR (canonical rewrite); no '?' — DEC-private m-finals belong to the drop arm
            . '|\x1b\][\s\S]*?(?:\x07|\x1b\\\\)'                // OSC terminated (BEL or ST): framing+payload die, following text lives
            . '|\x1b\][\s\S]*'                                  // unterminated OSC: fail-closed swallow
            . '|\x1b[\x50\x58\x5e\x5f][\s\S]*?(?:\x07|\x1b\\\\)' // DCS/APC/PM/SOS terminated
            . '|\x1b[\x50\x58\x5e\x5f][\s\S]*'                  // …and fail-closed swallow
            . '|\x1b\[[0-9;?]*[ -\/]*[@-~]'                     // non-SGR CSI (incl. DEC-private ?…m): dropped
            . '|\x1b'                                           // bare ESC: dropped
            . '|[\x{00}-\x{1F}\x{7F}-\x{9F}]/u',                // C0 (TAB/LF/CR all), DEL, C1
            static fn (array $m): string => isset($m['sgr'])
                ? "\x1b[{$m['sgr']}{$m['si']}m"
                : '',
            $label
        );
        if ($out === null) {
            // Invalid UTF-8 (the /u compile guard): byte-wise floor of the
            // /u branch above — C0 and DEL drop, but raw C1 bytes fall
            // outside [\x00-\x1F\x7F] and survive this fallback (they
            // cannot be told apart without decoding). Encoded C1 that does
            // ride valid UTF-8 is dropped by the /u branch and zero-scored
            // by the grid width law either way; the cell grid's own stance
            // is reproducing malformed bytes, never executing them.
            $out = \preg_replace_callback(
                '/\x1b\[(?<sgr>[0-9;]*)(?<si>[ -\/]*)m|\x1b\][\s\S]*?(?:\x07|\x1b\\\\)|\x1b\][\s\S]*|\x1b[\x50\x58\x5e\x5f][\s\S]*?(?:\x07|\x1b\\\\)|\x1b[\x50\x58\x5e\x5f][\s\S]*|\x1b\[[0-9;?]*[ -\/]*[@-~]|\x1b|[\x00-\x1F\x7F]/',
                static fn (array $m): string => isset($m['sgr'])
                    ? "\x1b[{$m['sgr']}{$m['si']}m"
                    : '',
                $label
            ) ?? '';
        }

        // A label left with only SGR framing and/or spaces has no visible
        // button at all — refuse rather than render an empty bracket.
        $visible = \preg_replace('/\x1b\[[0-9;?]*[ -\/]*m/', '', $out) ?? $out;
        if (\trim($visible) === '') {
            throw new \InvalidArgumentException(
                'Action label must contain at least one printable character '
                . 'after control/escape normalisation'
            );
        }

        return $out;
    }
}
