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
 * Style. CSI parameters outside this surface (38;5;n / 38;2;r;g;b
 * for fg, 48 variants for bg, etc.) are captured as literal SGR
 * substrings so they round-trip cleanly.
 *
 * Both SGR dialects are observed: the semicolon-flattened form
 * (`38;2;r;g;b`, what this library emits) and the ITU T.416 colon
 * sub-parameter form (`38:2:r:g:b`, what real terminals and tools
 * emit). Groups split on `;`, sub-parameters on `:`; a colon group
 * carries its color inside the group, so it never reads across into
 * the NEXT group's parameters. Decorative sub-parameters on codes
 * this state does not model (underline styles `4:0`–`4:3`, intensity
 * `1:0`/`1:2`) are recognised-but-unmodelled: the base code's effect
 * applies and the sub is dropped, exactly what the pre-colon parser
 * did when intval() truncated `4:0` to `4` — the wire contract for
 * every existing tape and golden is therefore byte-frozen; only
 * malformed-adjacent colon input changes reading (from "swallow the
 * next group" to "parse the color").
 *
 * The class OBSERVES the two styles a row-wise prefix could lose:
 * SGR 58 (underline colour) and OSC 8 hyperlinks. Read
 * it via {@see underlineColor()}, {@see linkUri()} and
 * {@see linkId()}; feed a full token stream through {@see apply()}.
 *
 * The ROW-BOUNDARY contract lives here: {@see rowOpen()} and
 * {@see rowClose()} are the balance bytes candy-code's
 * Renderer::balanceSgr() used to compute with a private URI tracker, so
 * this class is the single home of
 * the contract. {@see toPrefix()} itself stays byte-frozen — the extra
 * bytes ride only on the row-boundary methods, so every existing
 * prefix snapshot holds.
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
     * Apply a {@see \SugarCraft\Core\Util\Token::CSI} `m` token (an SGR sequence) to this
     * state. No-op for any other token type — callers feed the whole
     * token stream and we silently ignore non-SGR.
     */
    public function applyCsi(Token $t): void
    {
        if ($t->type !== Token::CSI || $t->final !== 'm') {
            return;
        }
        // Split groups on ';' and sub-parameters on ':' (ITU T.416
        // colon dialect, see class docblock). Semicolon-only input
        // produces exactly the old $params list plus empty sub-arms.
        $groups = $t->params === '' ? ['0'] : explode(';', $t->params);
        $params = [];
        $subs = [];
        foreach ($groups as $group) {
            $pieces = explode(':', $group);
            $params[] = (int) $pieces[0];
            $subs[] = array_map('intval', array_slice($pieces, 1));
        }
        $count = count($params);
        for ($i = 0; $i < $count; $i++) {
            $p = $params[$i];
            switch ($p) {
                case 0:
                    $this->bold = false; $this->italic = false; $this->underline = false;
                    $this->strike = false; $this->faint = false; $this->blink = false;
                    $this->reverse = false; $this->conceal = false;
                    $this->fg = ''; $this->bg = '';
                    // A reset also ends the underline colour AND an
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
                    if ($p === 38 || $p === 48 || $p === 58) {
                        $ext = self::extendedColor($p, $subs[$i] ?? [], $params, $i);
                        if ($ext === null) {
                            // Incomplete in either dialect: the code is
                            // skipped and the walk continues — the exact
                            // pre-fix shape (no consumption, no state change).
                            break;
                        }
                        [$advance, $sequence] = $ext;
                        match ($p) {
                            38 => $this->fg = $sequence,
                            48 => $this->bg = $sequence,
                            default => $this->underlineColor = $sequence,
                        };
                        $i += $advance;
                        break;
                    }
            }
        }
    }

    /**
     * Parse an extended-colour parameter (38 fg / 48 bg / 58 underline)
     * in either dialect, returning [parameters-consumed-after-the-code,
     * normalised-semi-colon-sequence] or null when the parameter is
     * incomplete — the skip-the-code behaviour the pre-fix parser had.
     *
     * Colon form: the whole colour rides inside its own group, sub-params
     * `[mode, values…]`; `38:2::r:g:b` carries a colour-space ID slot
     * between mode and the RGB triple (five subs), which is dropped —
     * this state round-trips the raw sequence, it does not colour-manage.
     * Semicolon form (the shipped dialect): the group has no subs and the
     * values continue in the flat list after the code.
     *
     * @param list<int> $subs    sub-parameters of this code's group
     * @param list<int> $params  the flat code list (semicolon dialect reads past $index)
     * @return array{0:int,1:string}|null
     */
    private static function extendedColor(int $code, array $subs, array $params, int $index): ?array
    {
        if ($subs !== []) {
            $mode = $subs[0];
            if ($mode === 5 && isset($subs[1])) {
                return [0, "\x1b[{$code};5;{$subs[1]}m"];
            }
            if ($mode === 2) {
                $offset = count($subs) === 5 ? 2 : 1;
                if (isset($subs[$offset + 2])) {
                    return [0, "\x1b[{$code};2;{$subs[$offset]};{$subs[$offset + 1]};{$subs[$offset + 2]}m"];
                }
            }
            return null;
        }
        $mode = $params[$index + 1] ?? null;
        if ($mode === 5) {
            if (!isset($params[$index + 2])) {
                return null;
            }
            return [2, "\x1b[{$code};5;{$params[$index + 2]}m"];
        }
        if ($mode === 2) {
            if (!isset($params[$index + 4])) {
                return null;
            }
            return [4, "\x1b[{$code};2;{$params[$index + 2]};{$params[$index + 3]};{$params[$index + 4]}m"];
        }
        return null;
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
     * number is observed and ignored. The tracked state flows into the
     * row-boundary re-open/close of {@see rowOpen()} / {@see rowClose()},
     * never into {@see toPrefix()} itself.
     *
     * Delta vs the `Renderer::balanceSgr()` tracker this class replaced, on the
     * MALFORMED two-field body `8;URI` (the spec's params field omitted):
     * the old tracker split the body and read index 2 with `?? ''`, so a
     * two-field OSC landed in the empty-URI branch and was never tracked —
     * no close was emitted at a row boundary and a terminal that opened the
     * link let it leak across rows. This class takes the lone field as the
     * URI (lenient parse, Fail-Fast would only shed information) and
     * balances it like any other link. Fine because the delta is observable
     * only on input that is invalid per the OSC 8 grammar; no tape, golden,
     * or snapshot byte feeds one, and under either terminal reading of
     * `8;URI` (open with URI, or close) emitting a balanced close/re-open is
     * the safer boundary behaviour than leaking.
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
     * Deliberately unchanged as the observed state and the row-boundary
     * contract were added: underline colour and
     * links are tracked but not re-emitted here — the row-boundary
     * bytes ride on {@see rowOpen()} / {@see rowClose()}, so every
     * existing prefix snapshot holds.
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

    /**
     * True when the state emits nothing: exactly the conjunction
     * {@see toPrefix()} guards its early return, evaluated per-field.
     * The previous implementation rebuilt the whole prefix string just
     * to strlen it — rowClose() calls this once per row, so the
     * allocation sat on the frame hot path for a boolean answer.
     * Underline colour and open links are deliberately absent, matching
     * the byte contract (they ride {@see rowOpen()}/{@see rowClose()}).
     */
    public function isDefault(): bool
    {
        return !$this->bold && !$this->italic && !$this->underline && !$this->strike
            && !$this->faint && !$this->blink && !$this->reverse && !$this->conceal
            && $this->fg === '' && $this->bg === '';
    }

    /**
     * Bytes that must PRECEDE a row that a diff may repaint alone, so the
     * row re-establishes the styling carried in from earlier rows: the SGR
     * prefix plus an OSC 8 re-open while a hyperlink is still open.
     *
     * This is the contract candy-code's Renderer::balanceSgr()
     * used to compute with a private URI tracker; the row-boundary half
     * of the balance now lives here, next to the state it emits.
     *
     * The re-open deliberately omits the `id=` param even when
     * {@see linkId()} tracked one: the byte contract of the delegating
     * call site (and every golden pinned under it) predates id tracking,
     * and an id would change those bytes. The URI itself round-trips.
     */
    public function rowOpen(): string
    {
        return $this->toPrefix() . ($this->hasOpenLink() ? self::osc8($this->linkUri) : '');
    }

    /**
     * Bytes that must FOLLOW a row so it leaves nothing bleeding: an SGR
     * reset unless the state is already default, plus an OSC 8 close
     * while a hyperlink is open (CSI 0 m does not end a link on a real
     * terminal — only the empty-URI OSC does).
     *
     * A row carrying a reset ENDS the observed link here, matching the
     * reset-ends-link accessor semantics pinned by sugar-core's own suite; candy-code
     * measures its goldens to hold byte-identical under the delegation
     * (no pinned row opens a link, resets mid-row, and ends inside the
     * label).
     */
    public function rowClose(): string
    {
        return ($this->isDefault() ? '' : Ansi::reset())
            . ($this->hasOpenLink() ? self::osc8('') : '');
    }

    /**
     * An OSC 8 hyperlink open (with $uri) or close (with ''), ST-terminated.
     *
     * ST (`ESC \`) rather than BEL: both terminate an OSC and sugar-core's
     * {@see \SugarCraft\Core\Util\Parser} accepts either, but BEL inside a frame is a byte a
     * terminal may also ring.
     */
    public static function osc8(string $uri): string
    {
        return "\x1b]8;;" . $uri . "\x1b\\";
    }
}
