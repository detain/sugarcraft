<?php

declare(strict_types=1);

/**
 * SugarColorprofile — terminal color profile detection and color degradation.
 *
 * Port of charmbracelet/colorprofile providing:
 * - Detection of terminal color capability via environment + TTY inspection
 * - Color conversion (TrueColor → ANSI256 → ANSI16 → ASCII)
 * - ProfileWriter for automatic ANSI degradation on write
 * - NO_COLOR / FORCE_COLOR / COLORTERM standard env var support
 *
 * Chosen divergences, dated (fleet ladder-preamble discipline): 2026-11-13
 * (CP-05 Shape B) — the Ascii rung is COLORLESS: env suppressions
 * (NO_COLOR / CLICOLOR=0 / TERM=dumb) answer it and it drops color codes
 * while keeping decoration, matching upstream's drop-down-the-color-ladder
 * and no-color.org's "color attributes only" reading; NoTTY stays reserved
 * for hardware absence (the stream/TTY gate). Fgbg alone keeps the
 * black/white conversion. Chafa's INDEXED_240/INDEXED_16_8/FGBG arms are a
 * request-side vocabulary upstream does not carry.
 *
 * @see https://github.com/charmbracelet/colorprofile
 */
namespace SugarCraft\ColorProfile;

/**
 * Detect and query the terminal color profile.
 *
 * Mirrors the top-level functions in charmbracelet/colorprofile.
 */
final class Palette
{
    /**
     * Parameter lists longer than this are passed through untouched; no real
     * SGR sequence approaches it, and explode()ing a megabyte-scale one is a
     * memory amplification on an attacker-influenced render path.
     */
    private const MAX_SGR_PARAMS_BYTES = 4096;

    private ColorProfile $profile;

    /**
     * Detect the terminal color profile from the environment.
     *
     * The full 13-step ladder (CLICOLOR_FORCE, FORCE_COLOR, NO_COLOR,
     * CLICOLOR, TERM=dumb, COLORTERM, TERM_PROGRAM, WT_SESSION,
     * GOOGLE_CLOUD_SHELL, TMUX/STY, TERM depth rungs) runs through the shared
     * {@see DetectionChain} — Probe, TerminalProbe and Palette all take the
     * same env answer from that one ladder at the env rung (those surfaces'
     * additive terminfo/TERM_PROGRAM phases may raise the final answer above
     * it; Palette's answer is the rung itself). The only step living here is
     * the TTY gate below it.
     *
     * @param resource|null $stream  Stream whose TTY-ness gates color (null =
     *                               env-only, no TTY check — mirroring upstream
     *                               colorprofile.Detect which checks isatty on
     *                               the *passed* writer, never on a default)
     * @param array<string,string|null> $env  Hermetic override map; a NON-EMPTY
     *                               map REPLACES the ambient environment
     *                               entirely ({@see Env::resolve()}). [] = ambient.
     */
    public function __construct(
        $stream = null,
        array $env = [],
    ) {
        // Parse, don't validate late (-057/DX-16): the stream's SHAPE is a
        // construction-time fact. A wrong value today surfaces as a TypeError
        // deep inside stream_isatty()/detect(), far from the call that
        // passed it — fail at the boundary instead.
        if ($stream !== null && !is_resource($stream)) {
            throw new \InvalidArgumentException(Lang::t('stream.invalid_resource', ['label' => 'Palette']));
        }

        $this->profile = self::detectProfile($stream, $env);
    }

    /**
     * Get the detected profile.
     */
    public function profile(): ColorProfile
    {
        return $this->profile;
    }

    /**
     * Override the detected profile (e.g. manually downgrade for testing).
     */
    public function withProfile(ColorProfile $profile): self
    {
        $clone = clone $this;
        $clone->profile = $profile;
        return $clone;
    }

    /**
     * Shortcut: detect and return the profile enum.
     *
     * @param resource|null $stream
     * @param array<string,string|null> $env
     */
    public static function detect($stream = null, array $env = []): ColorProfile
    {
        return (new self($stream, $env))->profile();
    }

    /**
     * Convert a color to the detected (or manually set) profile.
     */
    public function convert(Color $color): Color
    {
        return $color->convert($this->profile);
    }

    /**
     * Static shortcut for one-off color conversion.
     */
    public static function toProfile(Color $color, ColorProfile $profile): Color
    {
        return $color->convert($profile);
    }

    /**
     * Convert any TrueColor/ANSI256/ANSI sequence in a string to
     * match the current profile. At the colorless Ascii rung every COLOR
     * segment drops while decoration and all other sequences pass (CP-05);
     * NoTTY strips everything.
     *
     * @param string $ansi  A string potentially containing SGR/CSI/OSC sequences
     * @return string       The string with color codes degraded/stripped
     */
    public function degrade(string $ansi): string
    {
        if ($this->profile === ColorProfile::NoTTY) {
            return $this->stripAnsi($ansi);
        }

        if ($this->profile === ColorProfile::TrueColor) {
            return $ansi; // No conversion needed
        }

        return $this->rewriteAnsi($ansi, $this->profile);
    }

    /**
     * Strip all ANSI escape sequences from a string.
     * Used when NoTTY is active.
     */
    public static function stripAnsi(string $s): string
    {
        // CSI sequences per ECMA-48: parameter bytes 0x30-0x3F (digits, `:`,
        // `<`, `=`, `>`, `?` — the private-mode markers ride this class),
        // optional intermediate bytes 0x20-0x2F (`$`, `!`, space …), final
        // byte 0x40-0x7E. The old shape `[0-9;:<>=?]*[A-Za-z]` could not
        // match DECSTR `ESC [ ! p`, DECRQM `ESC [ 2 $ p`, ICL `ESC [ 5 @`
        // or `ESC [ 1 _ b` — those leaked as literal text under NoTTY.
        // OSC sequences: \x1b]...(\x07|\x1b\\)
        // DCS sequences: \x1bP...(\x07|\x1b\\)
        // SS3 sequences: \x1bO followed by its final byte (F1-F4, Up/Down …).
        // A bare \x1bO at end of string still falls through to the Fe arm so
        // the truncated pair is consumed too.
        // APC/PM/SOS strings: \x1b[_^X]...(\x07|\x1b\\)
        // Fe escapes: the C0 escape-code pairs — DECSC `ESC 7`, DECRC `ESC 8`,
        // RIS `ESC c`, NEL `ESC E`, keypad `ESC >` / `ESC =`, LS2 `ESC n`,
        // plus the historic cursor/mode letters below.
        // Charset selectors: \x1b(B, \x1b(U, etc.
        return \preg_replace(
            '/(?:\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)|'
            . '\x1b\[[0-?]*[ -\/]*[@-~]|'
            . '\x1b[PX^_][^\x07\x1b]*(?:\x07|\x1b\\\\)|'
            . '\x1bO[@-~]|'
            . '\x1b[OopeHMJKhCBDsunE78>=c]|'
            . '\x1b[()*+][ -~])/',
            '',
            $s,
        ) ?? $s;
    }

    /**
     * Comment on the detected profile in a human-readable way.
     */
    public function comment(): string
    {
        return match ($this->profile) {
            ColorProfile::TrueColor => 'fancy',
            ColorProfile::Ansi256   => '1990s fancy',
            ColorProfile::Ansi240   => '1990s fancy, theme-safe',
            ColorProfile::Ansi      => 'normcore',
            ColorProfile::Ansi8     => 'CGA basic',
            ColorProfile::Fgbg      => 'paper & ink',
            ColorProfile::Ascii     => 'ancient',
            ColorProfile::NoTTY     => 'naughty!',
        };
    }

    /**
     * Full descriptive sentence about the terminal's color capabilities.
     */
    public function describe(): string
    {
        return "Your terminal supports {$this->profile->label()} ({$this->profile->description()}).";
    }

    // -------------------------------------------------------------------------
    // Private detection logic
    // -------------------------------------------------------------------------

    private static function detectProfile($stream, array $env): ColorProfile
    {
        // The complete 13-step environment ladder — CLICOLOR_FORCE,
        // FORCE_COLOR, NO_COLOR, CLICOLOR=0, TERM=dumb, COLORTERM,
        // TERM_PROGRAM=iTerm.app, WT_SESSION, GOOGLE_CLOUD_SHELL, TMUX/STY
        // and the TERM depth rungs — lives in DetectionChain so Probe,
        // TerminalProbe and Palette never disagree at the env rung of the same
        // environment; those surfaces' additive terminfo/TERM_PROGRAM phases
        // may still raise the final answer above the rung, and this class
        // alone applies the TTY gate below it. (Previously FORCE_COLOR and the iTerm upgrade were
        // duplicated here on a per-key OVERLAY while the chain beneath used
        // a whole-map REPLACEMENT: an ambient CLICOLOR_FORCE leaked into a
        // "hermetic" detect() call while an ambient NO_COLOR did not.)
        $chain = DetectionChain::detect($env);

        // Since CP-05 (Shape B) the chain's answer IS the profile — the
        // suppressions land on the colorless Ascii rung, and only a reserved
        // NONE (hardware absence, never derived from the env ladder) maps to
        // NoTTY's full strip through toColorProfile(). The old early return
        // here collapsed every !allowsColor answer onto NoTTY, which used to
        // be exactly NO_COLOR's rung and stripped decoration the standards
        // body and this lib's own docblocks promise to keep.
        $profile = $chain->toColorProfile();

        // TTY detection — Palette-specific (Probe and TerminalProbe don't
        // check TTY). Only applies when the caller hands us a stream: with
        // $stream === null detection is env-only. The iTerm upgrade is now
        // part of the ladder, so a piped stream demotes iTerm.app to NoTTY
        // exactly like every other rung — the old code returned TrueColor for
        // iTerm BEFORE this gate, printing 24-bit escape vomit into pipes.
        if ($stream !== null && \function_exists('stream_isatty')) {
            if (!@\stream_isatty($stream)) {
                return ColorProfile::NoTTY;
            }
        }

        return $profile;
    }

    /**
     * Rewrite ANSI SGR sequences in a string to match $targetProfile.
     *
     * Walks every CSI …m sequence, not just ones that happen to START with a
     * color introducer: attribute-prefixed forms (`\e[1;38;2;…m`), combined
     * forms (`\e[38;5;n;4m`) and colon sub-parameter forms (`\e[38:2::r:g:b`,
     * ECMA-48) all get their color segments rewritten while every other
     * parameter — 1/2/3/4/5/7/8/9/21/53, the resets 39/49/59, styled-underline
     * `4:n` — passes through untouched, and an underline color (SGR 58) is
     * re-emitted per-profile rather than dropped or left at a depth the
     * terminal cannot address.
     */
    private function rewriteAnsi(string $s, ColorProfile $targetProfile): string
    {
        return \preg_replace_callback(
            '/\x1b\[([0-9;:]*)m/',
            fn (array $m): string => $this->rewriteSgr($m[1], $targetProfile),
            $s,
        ) ?? $s;
    }

    /**
     * Rewrite one SGR parameter list (the text between `CSI` and `m`).
     */
    private function rewriteSgr(string $params, ColorProfile $target): string
    {
        // The colorless rung never reaches the degrade walk — every color
        // segment drops rather than re-emits, which is its own shape (CP-05).
        if ($target === ColorProfile::Ascii) {
            return $this->stripColorSgr($params);
        }

        // Fast path: no extended-color introducer → byte-identical passthrough.
        // (Leading zeros are valid ECMA-48 padding: `038` introduces color 38.)
        // Absurdly long parameter lists also pass through untouched — real SGR
        // lists fit in a few hundred bytes, and walking a megabyte-scale one
        // would multiply memory for no benefit on hostile input.
        if (
            $params === ''
            || \strlen($params) > self::MAX_SGR_PARAMS_BYTES
            || \preg_match('/(?:^|;)0*(?:38|48|58)(?:$|[;:])/', $params) !== 1
        ) {
            return "\x1b[{$params}m";
        }

        $groups = \explode(';', $params);
        $out = [];
        $count = \count($groups);

        for ($i = 0; $i < $count; $i++) {
            $subs = \explode(':', $groups[$i]);
            $head = $subs[0];
            if ($head === '' || !\ctype_digit($head)) {
                $out[] = $groups[$i];
                continue;
            }
            $intro = (int) $head;
            if ($intro !== 38 && $intro !== 48 && $intro !== 58) {
                $out[] = $groups[$i];
                continue;
            }

            $consumed = 0;
            // Colon form: the whole spec lives inside this one group
            // (`38:5:196`, `38:2::r:g:b`). Otherwise it continues across the
            // following semicolon-separated groups.
            $spec = \count($subs) > 1
                ? $this->parseColonColor(\array_slice($subs, 1))
                : $this->parseSemicolonColor($groups, $i, $consumed);
            if ($spec === null) {
                // Not a color spec we understand (e.g. `38;3` CMY) — pass through.
                $out[] = $groups[$i];
                continue;
            }
            [$kind, $values] = $spec;
            $out[] = $this->emitColor($intro, $kind, $values, $target);
            $i += $consumed;
        }

        return "\x1b[" . \implode(';', $out) . 'm';
    }

    /**
     * The colorless rung (CP-05, Shape B): drop every COLOR-carrying segment
     * from one SGR parameter list and keep everything else. Color codes are
     * the 30-37/40-47 basics, the 90-97/100-107 brights, and the extended
     * 38/48/58 specs (including underline color); decoration (bold, dim,
     * italic, underline, inverse, blink …), the return-to-default resets
     * 39/49/59 (they un-color — they paint nothing), and every unrecognized
     * parameter pass through byte-identical. A parameter list reduced to
     * nothing drops the WHOLE sequence: a bare `\e[m` is an SGR-0 reset and
     * would erase decoration the caller asked to keep. Spec consumption
     * reuses the degrade parser, so semicolon and colon forms drop exactly
     * the same bytes the degrade walk would have rewritten.
     */
    private function stripColorSgr(string $params): string
    {
        if ($params === '') {
            // `\e[m` uncolored is the full attribute reset — not a color code.
            return "\x1b[m";
        }

        // Same hostile-input ceiling as the degrade walk: a megabyte-scale
        // parameter list passes through untouched rather than being walked.
        if (\strlen($params) > self::MAX_SGR_PARAMS_BYTES) {
            return "\x1b[{$params}m";
        }

        $groups  = \explode(';', $params);
        $out     = [];
        $count   = \count($groups);

        for ($i = 0; $i < $count; $i++) {
            $subs = \explode(':', $groups[$i]);
            $head = $subs[0];
            if ($head === '' || !\ctype_digit($head)) {
                $out[] = $groups[$i];
                continue;
            }

            $value = self::sgrInt($head);

            if ($value === 38 || $value === 48 || $value === 58) {
                $consumed = 0;
                $spec     = \count($subs) > 1
                    ? $this->parseColonColor(\array_slice($subs, 1))
                    : $this->parseSemicolonColor($groups, $i, $consumed);
                if ($spec === null) {
                    // Not a color spec we understand (e.g. `38;3` CMY) — the
                    // degrade walk passes it through, so the colorless rung
                    // keeps the very same bytes.
                    $out[] = $groups[$i];
                    continue;
                }
                $i += $consumed;
                continue; // a real spec was parsed — the whole thing drops
            }

            if (
                ($value >= 30 && $value <= 37)
                || ($value >= 40 && $value <= 47)
                || ($value >= 90 && $value <= 97)
                || ($value >= 100 && $value <= 107)
            ) {
                continue;
            }

            $out[] = $groups[$i];
        }

        if ($out === []) {
            return '';
        }

        return "\x1b[" . \implode(';', $out) . 'm';
    }

    /**
     * Parse `…;5;n` / `…;2;r;g;b` (and missing-argument defaults) following a
     * lone 38/48/58 group. ECMA-48 defaults absent color ids/components to 0.
     *
     * @param list<string> $groups
     * @param-out int      $consumed following groups taken by the spec
     *
     * @return array{0:int,1:list<int>} [colourId (5|2), components]
     */
    private function parseSemicolonColor(array $groups, int $i, int &$consumed): ?array
    {
        $consumed = 0;
        if (!isset($groups[$i + 1])) {
            // Trailing `38` with no spec: xterm defaults the color to slot 0.
            return [5, [0]];
        }
        $subs = \explode(':', $groups[$i + 1]);
        $type = (int) $subs[0];

        if ($type === 5) {
            if (isset($subs[1]) && $subs[1] !== '') {
                $consumed = 1;
                return [5, [self::sgrInt($subs[1])]];
            }
            $consumed = 2;
            return [5, [$this->firstSub($groups[$i + 2] ?? null) ?? 0]];
        }

        if ($type === 2) {
            // `38;2::r:g:b` / `38;2:tol;r;g;b` / `38;2;r;g;b`
            $tail = \array_values(\array_filter(
                \array_slice($subs, 1),
                static fn (string $v): bool => $v !== '',
            ));
            if (\count($tail) >= 3) {
                // Colon sub-parameters may carry a color-space id / tolerance
                // before the components (`38;2:1:tol;r:g:b`-style continuations);
                // the components are always the LAST three values. Known edge:
                // for a hybrid form that packs ≥4 sub-parameters into the
                // semicolon-headed type group (`38;2:cs:tol:r:g:b;extra`) the
                // leading fields are consumed here while later SEMICOLON groups
                // pass through as plain attributes — xterm would keep pulling
                // components from them. No real emitter produces the hybrid;
                // fully-colon and fully-semicolon forms both parse exactly.
                $consumed = 1;
                $rgb = \array_slice($tail, -3);
                return [2, \array_map(self::sgrInt(...), $rgb)];
            }
            $consumed = 4;
            return [2, [
                $this->firstSub($groups[$i + 2] ?? null) ?? 0,
                $this->firstSub($groups[$i + 3] ?? null) ?? 0,
                $this->firstSub($groups[$i + 4] ?? null) ?? 0,
            ]];
        }

        return null;
    }

    /**
     * Parse the sub-parameter list of a colon-form spec (`5:196`, `2:r:g:b`,
     * `2:tol:r:g:b` — color-space/tolerance fields precede the trailing three
     * components; an empty tolerance sub-parameter is skipped). Missing
     * components default to 0, matching the semicolon path and xterm.
     *
     * @param list<string> $subs
     *
     * @return array{0:int,1:list<int>}|null
     */
    private function parseColonColor(array $subs): ?array
    {
        $type = (int) ($subs[0] ?? '');
        if ($type === 5) {
            return [5, [self::sgrInt((string) ($subs[1] ?? '0'))]];
        }
        if ($type === 2) {
            $tail = \array_values(\array_filter(
                \array_slice($subs, 1),
                static fn (string $v): bool => $v !== '',
            ));
            $rgb = \array_slice($tail, -3);
            $rgb = \array_pad($rgb, 3, '0');
            return [2, \array_map(self::sgrInt(...), $rgb)];
        }
        return null;
    }

    /**
     * First sub-parameter of a group as int, or null for a missing/non-numeric group.
     */
    private function firstSub(?string $group): ?int
    {
        if ($group === null) {
            return null;
        }
        if ($group === '') {
            return 0;
        }
        $sub = \explode(':', $group)[0];
        return \ctype_digit($sub) ? self::sgrInt($sub) : null;
    }

    /**
     * Cast an SGR numeric literal, capping absurd magnitudes at 999 instead of
     * letting a >19-digit run overflow the int cast through INF (where a
     * "huge red" would silently come out as 0/black). Color components clamp
     * to 255 downstream, so 999 saturates identically to PHP_INT_MAX.
     */
    private static function sgrInt(string $digits): int
    {
        $trimmed = \ltrim($digits, '0');
        if ($trimmed === '') {
            return 0;
        }
        return \strlen($trimmed) > 3 ? 999 : (int) $trimmed;
    }

    /**
     * Render the parsed color as replacement SGR parameters for $target.
     *
     * At ANSI256 an in-range slot is already exactly addressable, so it is
     * passed through as the slot the theme picked — decoding to RGB and
     * re-quantising could knock a themeable basic slot (0-15) onto the fixed
     * cube and strip the user's palette. At ANSI240 (chafa's
     * INDEXED_240) that mercy does not extend to slots 0-15: those vary with
     * the user's theme and the 240-color promise excludes them, so a
     * `38;5;k` with k < 16 is decoded and re-quantised onto the fixed range
     * instead of passed through. 38/48 at 16-color depth collapse to
     * the 4-bit SGR; 58 keeps its introducer (underlines need a color id a
     * 4-bit code cannot carry) pinned to the nearest of the 16 palette slots.
     * The colorless Ascii rung never reaches this method — rewriteSgr routes
     * it to stripColorSgr, which DROPS color segments instead of repainting
     * them (CP-05 Shape B; Ascii ≠ Fgbg finally true).
     *
     * @param list<int> $values
     */
    private function emitColor(int $intro, int $kind, array $values, ColorProfile $target): string
    {
        $slot = $kind === 5 ? $values[0] : null;
        // Wire slots arrive hostile (e.g. a stream saying 38;5;300) and the
        // decoder is fail-loud since CP-16 — degrade never crashes, so the
        // quantise-to-the-ramp-end leg lives right here, once per emit.
        $wireSlot = $slot === null ? 0 : min(255, max(0, $slot));

        if ($target === ColorProfile::Ansi256) {
            if ($slot !== null && $slot >= 0 && $slot <= 255) {
                return "{$intro};5;{$slot}";
            }
            $color = $kind === 2
                ? new Color($values[0], $values[1], $values[2])
                : Color::fromAnsi256Index($wireSlot);
            return "{$intro};5;{$color->toAnsi256Index()}";
        }

        if ($target === ColorProfile::Ansi240) {
            if ($slot !== null && $slot >= 16 && $slot <= 255) {
                return "{$intro};5;{$slot}";
            }
            // Below 16 there is no honest passthrough: decode the themeable
            // slot to its reference RGB and re-quantise — toAnsi256Index()
            // lands exclusively on the fixed 16-255 range.
            $color = $kind === 2
                ? new Color($values[0], $values[1], $values[2])
                : Color::fromAnsi256Index($wireSlot);
            return "{$intro};5;{$color->toAnsi256Index()}";
        }

        // ANSI / ANSI 8 / FGBG (58 keeps an indexed introducer). Ascii was
        // this fall-through's fourth customer until CP-05 gave it a strip,
        // not a repaint — see stripColorSgr.
        $color = $kind === 2
            ? new Color($values[0], $values[1], $values[2])
            : Color::fromAnsi256Index($wireSlot);
        $idx = $color->convert($target)->toAnsi16Index();

        if ($intro === 58) {
            return "58;5;{$idx}";
        }
        return (string) Color::ansi16Sgr($idx, $intro === 48);
    }
}
