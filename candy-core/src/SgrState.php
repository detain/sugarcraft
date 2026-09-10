<?php

declare(strict_types=1);

namespace SugarCraft\Core;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Token;

/**
 * Running SGR ("Select Graphic Rendition") state — bold / italic /
 * underline / etc. attribute flags plus the current foreground and
 * background colour. Used by the cursed cell-diff {@see Renderer} to
 * resume a partial-line repaint with the correct styling.
 *
 * Mirrors the subset of SGR codes commonly emitted by SugarCraft /
 * Sprinkles. CSI parameters outside this surface (38;5;n / 38;2;r;g;b
 * for fg, 48 variants for bg, etc.) are captured as literal SGR
 * substrings so they round-trip cleanly.
 *
 * E50 adds OBSERVED state for the two styles the row-wise prefix could
 * previously lose: SGR 58 (underline colour) and OSC 8 hyperlinks. Read
 * it via {@see underlineColor()}, {@see linkUri()} and
 * {@see linkId()}; feed a full token stream through {@see apply()}.
 * Re-emission through {@see toPrefix()} is deliberately NOT extended in
 * this round — sugar-crush's Renderer::balanceSgr() still owns the
 * row-close contract, and silently duplicating it here would change
 * bytes under every existing snapshot. The general fix (balanceSgr
 * delegating to this class) is the filed follow-up seam.
 *
 * Construct via {@see initial()} (no styling) and update via
 * {@see apply(Token)}; emit the equivalent prefix via
 * {@see toPrefix()}.
 */
final class SgrState
{
    private bool $bold = false;
    private bool $italic = false;
    private bool $underline = false;
    private bool $strike = false;
    private bool $faint = false;
    private bool $blink = false;
    private bool $reverse = false;
    private bool $conceal = false;
    /** Raw `\x1b[...m` sequence for the current foreground (or '' for default). */
    private string $fg = '';
    /** Raw `\x1b[...m` sequence for the current background. */
    private string $bg = '';
    /** Raw `\x1b[58;…m` sequence for the underline colour (SGR 58), '' = default. */
    private string $underlineColor = '';
    /** OSC 8 hyperlink target currently open ('' = none / closed). */
    private string $linkUri = '';
    /** OSC 8 hyperlink `id=` param of the open link ('' when unnamed). */
    private string $linkId = '';

    public static function initial(): self
    {
        return new self();
    }

    /**
     * Apply a {@see Token::CSI} `m` token (an SGR sequence) to this
     * state. No-op for any other token type — callers feed the whole
     * token stream and we silently ignore non-SGR.
     */
    public function applyCsi(Token $t): void
    {
        if ($t->type !== Token::CSI || $t->final !== 'm') {
            return;
        }
        $params = $t->params === '' ? [0] : array_map('intval', explode(';', $t->params));
        $count = count($params);
        for ($i = 0; $i < $count; $i++) {
            $p = $params[$i];
            switch ($p) {
                case 0:
                    $this->bold = false; $this->italic = false; $this->underline = false;
                    $this->strike = false; $this->faint = false; $this->blink = false;
                    $this->reverse = false; $this->conceal = false;
                    $this->fg = ''; $this->bg = '';
                    // E50: a reset also ends the underline colour AND an
                    // open OSC 8 link — `Ansi::reset()` closing the row is
                    // exactly where the old class leaked the hyperlink.
                    $this->underlineColor = '';
                    $this->linkUri = '';
                    $this->linkId = '';
                    break;
                case 1:  $this->bold      = true;  break;
                case 2:  $this->faint     = true;  break;
                case 3:  $this->italic    = true;  break;
                case 4:  $this->underline = true;  break;
                case 5:  $this->blink     = true;  break;
                case 7:  $this->reverse   = true;  break;
                case 8:  $this->conceal   = true;  break;
                case 9:  $this->strike    = true;  break;
                case 22: $this->bold = false; $this->faint = false; break;
                case 23: $this->italic    = false; break;
                case 24: $this->underline = false; break;
                case 25: $this->blink     = false; break;
                case 27: $this->reverse   = false; break;
                case 28: $this->conceal   = false; break;
                case 29: $this->strike    = false; break;
                case 39: $this->fg = ''; break;
                case 49: $this->bg = ''; break;
                case 59: $this->underlineColor = ''; break;
                default:
                    if (($p >= 30 && $p <= 37) || ($p >= 90 && $p <= 97)) {
                        $this->fg = "\x1b[{$p}m";
                        break;
                    }
                    if (($p >= 40 && $p <= 47) || ($p >= 100 && $p <= 107)) {
                        $this->bg = "\x1b[{$p}m";
                        break;
                    }
                    if ($p === 38 && isset($params[$i + 1])) {
                        $mode = $params[$i + 1];
                        if ($mode === 5 && isset($params[$i + 2])) {
                            $this->fg = "\x1b[38;5;{$params[$i + 2]}m";
                            $i += 2;
                            break;
                        }
                        if ($mode === 2 && isset($params[$i + 2], $params[$i + 3], $params[$i + 4])) {
                            $this->fg = "\x1b[38;2;{$params[$i + 2]};{$params[$i + 3]};{$params[$i + 4]}m";
                            $i += 4;
                            break;
                        }
                    }
                    if ($p === 48 && isset($params[$i + 1])) {
                        $mode = $params[$i + 1];
                        if ($mode === 5 && isset($params[$i + 2])) {
                            $this->bg = "\x1b[48;5;{$params[$i + 2]}m";
                            $i += 2;
                            break;
                        }
                        if ($mode === 2 && isset($params[$i + 2], $params[$i + 3], $params[$i + 4])) {
                            $this->bg = "\x1b[48;2;{$params[$i + 2]};{$params[$i + 3]};{$params[$i + 4]}m";
                            $i += 4;
                            break;
                        }
                    }
                    if ($p === 58 && isset($params[$i + 1])) {
                        $mode = $params[$i + 1];
                        if ($mode === 5 && isset($params[$i + 2])) {
                            $this->underlineColor = "\x1b[58;5;{$params[$i + 2]}m";
                            $i += 2;
                            break;
                        }
                        if ($mode === 2 && isset($params[$i + 2], $params[$i + 3], $params[$i + 4])) {
                            $this->underlineColor = "\x1b[58;2;{$params[$i + 2]};{$params[$i + 3]};{$params[$i + 4]}m";
                            $i += 4;
                            break;
                        }
                    }
            }
        }
    }

    /**
     * Feed one token of any kind; routing is on token KIND, never on text.
     * CSI `m` → SGR update, OSC → hyperlink update, everything else is a
     * no-op, so callers may stream the whole parse output through.
     */
    public function apply(Token $t): void
    {
        if ($t->type === Token::OSC) {
            $this->applyOsc($t);
            return;
        }
        $this->applyCsi($t);
    }

    /**
     * Track an OSC 8 hyperlink token. The body is `8;params;URI`
     * (an empty URI closes the link — `OSC 8 ; ; ST`); any other OSC
     * number is observed and ignored. The tracked state does NOT yet
     * flow into {@see toPrefix()} — see the class docblock for the
     * balanceSgr-delegation seam.
     */
    public function applyOsc(Token $t): void
    {
        if ($t->type !== Token::OSC) {
            return;
        }
        $parts = explode(';', $t->data, 3);
        if (($parts[0] ?? '') !== '8') {
            return;
        }
        if (!isset($parts[2])) {
            // `8;URI` — params field omitted entirely.
            $this->linkUri = $parts[1] ?? '';
            $this->linkId  = '';
            return;
        }
        $this->linkUri = $parts[2];
        // Params is a colon-separated `key=value` list; only `id` matters.
        $this->linkId = preg_match('/(?:^|:)id=([^:]*)/', $parts[1], $m) ? $m[1] : '';
    }

    /** Raw `\x1b[58;…m` sequence for the underline colour ('' = default). */
    public function underlineColor(): string
    {
        return $this->underlineColor;
    }

    /** OSC 8 target of the currently open hyperlink ('' when none). */
    public function linkUri(): string
    {
        return $this->linkUri;
    }

    /** `id=` param of the currently open OSC 8 hyperlink ('' when unnamed). */
    public function linkId(): string
    {
        return $this->linkId;
    }

    /** True while an OSC 8 hyperlink is open — a row boundary must close it. */
    public function hasOpenLink(): bool
    {
        return $this->linkUri !== '';
    }

    /**
     * Emit the SGR sequence that re-establishes this state from a
     * blank slate. Always starts with `CSI 0m` so a partial repaint
     * never inherits stale attributes from whatever was last on screen.
     * Returns `''` when the state is already the default (nothing to
     * emit).
     *
     * Deliberately unchanged by E50: underline colour and links are
     * tracked but not re-emitted here, so every existing byte snapshot
     * holds until balanceSgr() delegates (the filed seam).
     */
    public function toPrefix(): string
    {
        if (!$this->bold && !$this->italic && !$this->underline && !$this->strike
            && !$this->faint && !$this->blink && !$this->reverse && !$this->conceal
            && $this->fg === '' && $this->bg === '') {
            return '';
        }
        $codes = [0];
        if ($this->bold) {
            $codes[] = 1;
        }
        if ($this->faint) {
            $codes[] = 2;
        }
        if ($this->italic) {
            $codes[] = 3;
        }
        if ($this->underline) {
            $codes[] = 4;
        }
        if ($this->blink) {
            $codes[] = 5;
        }
        if ($this->reverse) {
            $codes[] = 7;
        }
        if ($this->conceal) {
            $codes[] = 8;
        }
        if ($this->strike) {
            $codes[] = 9;
        }
        $out = "\x1b[" . implode(';', $codes) . 'm';
        if ($this->fg !== '') {
            $out .= $this->fg;
        }
        if ($this->bg !== '') {
            $out .= $this->bg;
        }
        return $out;
    }

    public function isDefault(): bool
    {
        return $this->toPrefix() === '';
    }
}
