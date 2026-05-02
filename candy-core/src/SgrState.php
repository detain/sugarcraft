<?php

declare(strict_types=1);

namespace CandyCore\Core;

use CandyCore\Core\Util\Ansi;
use CandyCore\Core\Util\Token;

/**
 * Running SGR ("Select Graphic Rendition") state — bold / italic /
 * underline / etc. attribute flags plus the current foreground and
 * background colour. Used by the cursed cell-diff {@see Renderer} to
 * resume a partial-line repaint with the correct styling.
 *
 * Mirrors the subset of SGR codes commonly emitted by CandyCore /
 * Sprinkles. CSI parameters outside this surface (38;5;n / 38;2;r;g;b
 * for fg, 48 variants for bg, 58 for underline colour, etc.) are
 * captured as literal SGR substrings so they round-trip cleanly.
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
            }
        }
    }

    /**
     * Emit the SGR sequence that re-establishes this state from a
     * blank slate. Always starts with `CSI 0m` so a partial repaint
     * never inherits stale attributes from whatever was last on screen.
     * Returns `''` when the state is already the default (nothing to
     * emit).
     */
    public function toPrefix(): string
    {
        if (!$this->bold && !$this->italic && !$this->underline && !$this->strike
            && !$this->faint && !$this->blink && !$this->reverse && !$this->conceal
            && $this->fg === '' && $this->bg === '') {
            return '';
        }
        $codes = [0];
        if ($this->bold)      $codes[] = 1;
        if ($this->faint)     $codes[] = 2;
        if ($this->italic)    $codes[] = 3;
        if ($this->underline) $codes[] = 4;
        if ($this->blink)     $codes[] = 5;
        if ($this->reverse)   $codes[] = 7;
        if ($this->conceal)   $codes[] = 8;
        if ($this->strike)    $codes[] = 9;
        $out = "\x1b[" . implode(';', $codes) . 'm';
        if ($this->fg !== '') $out .= $this->fg;
        if ($this->bg !== '') $out .= $this->bg;
        return $out;
    }

    public function isDefault(): bool
    {
        return $this->toPrefix() === '';
    }
}
