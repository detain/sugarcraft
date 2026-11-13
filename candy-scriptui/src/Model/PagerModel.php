<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Model;

use SugarCraft\Forms\Viewport\Viewport;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\ScriptUi\Lang;
use SugarCraft\ScriptUi\Runtime\Emphasis;
use SugarCraft\ScriptUi\Runtime\Geometry;

/**
 * Read-only pager used by {@see \SugarCraft\ScriptUi\Command\PagerCommand}.
 * Wraps a {@see Viewport}; Esc / `q` / Ctrl-C exit. Standard navigation
 * keys (↑/↓/jk, PgUp/PgDn/space/b/f, Home/g/End/G, Ctrl-U/D) come from
 * Viewport's own update().
 */
final class PagerModel implements Model
{
    /**
     * Rows the view() footer (hints.pager + hints.pager.more) occupies.
     * Every height derivation that is expressed in terminal rows must
     * subtract the same number, or the frame grows past the screen
     * (scriptui-20: bottom-bleed in alt-screen / stray hint lines into
     * scrollback inline).
     */
    private const HINT_ROWS = 2;

    public static function fromContent(
        string $content,
        int $width = 0,
        int $height = 0,
        bool $showLineNumbers = false,
        string $match = '',
        int $frameRows = 0,
    ): self {
        // A 0 dimension means "use the terminal": resolve it from Geometry
        // now (the initial WindowSizeMsg then keeps it current on resize);
        // fall back to the historical 80x20 when nothing is knowable.
        $rows   = Geometry::rowsOrNull();
        $width  = $width  > 0 ? $width  : (Geometry::columnsOrNull() ?? 80);
        // Auto height reserves the hint footer rows; the historical
        // 20-row fallback (no knowable size) keeps pinned output stable.
        $height = $height > 0 ? $height : ($rows !== null ? max(1, $rows - self::HINT_ROWS - $frameRows) : 20);
        // Multi-line untrusted paint: control/escape bytes out, CR mapped to
        // LF so overwrite tricks cannot reach the viewport (cluster 12).
        $content = \SugarCraft\Core\Util\Sanitize::untrustedForDisplay($content);
        // scriptui-78: an empty input used to paint a 0-line viewport — the
        // last "nothing happened" surface in the tool. Say so, in the same
        // nothing-to-do family as the filter/table refusals.
        if ($content === '') {
            $content = Lang::t('runtime.empty_input_pager');
        }
        // scriptui-72 (finding): --match used to run AFTER numberLines,
        // so a needle like "1" (or "│") reverse-videoed the gutter digits
        // and separator too — painting match styling onto chrome the user
        // never typed. Highlight the BODY first, then prefix the gutter:
        // program-composed digits can never be styled as matches.
        if ($match !== '') {
            $content = self::highlightMatches($content, $match);
        }
        if ($showLineNumbers) {
            $content = self::numberLines($content);
        }
        $vp = Viewport::new($width, max(1, $height))->setContent($content);
        return new self($vp, false, terminalRows: $rows ?? 0, frameRows: $frameRows);
    }

    /**
     * Prefix each line with a 1-based line number, right-aligned to the
     * widest number's width. Matches gum's `--show-line-numbers`.
     */
    private static function numberLines(string $content): string
    {
        $lines = $content === '' ? [''] : explode("\n", $content);
        $width = strlen((string) count($lines));
        $out = [];
        foreach ($lines as $i => $line) {
            $n = (string) ($i + 1);
            $out[] = str_pad($n, $width, ' ', STR_PAD_LEFT) . ' │ ' . $line;
        }
        return implode("\n", $out);
    }

    /**
     * Wrap every case-insensitive occurrence of `$needle` in reverse-
     * video so users can spot it as the viewport scrolls. Mirrors
     * gum's `--match` flag (substring-only — no regex).
     */
    private static function highlightMatches(string $content, string $needle): string
    {
        if ($needle === '') {
            return $content;
        }
        $sgr = Emphasis::sgr(Ansi::REVERSE);
        $rst = Emphasis::reset();
        // Case-insensitive substring replace using preg_replace; quote
        // the needle so regex metachars aren't treated as syntax.
        $pattern = '/' . preg_quote($needle, '/') . '/i';
        return (string) preg_replace($pattern, $sgr . '$0' . $rst, $content);
    }

    private function __construct(
        public readonly Viewport $viewport,
        public readonly bool $exited,
        public readonly bool $interrupted = false,
        public readonly int $terminalRows = 0,
        public readonly int $frameRows = 0,
    ) {}

    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * @return array{0:Model, 1:?\Closure}
     */
    public function update(Msg $msg): array
    {
        if ($this->exited) {
            return [$this, null];
        }
        if ($msg instanceof KeyMsg) {
            if ($msg->type === KeyType::Escape
                || ($msg->ctrl && $msg->rune === 'c')
                || ($msg->type === KeyType::Char && $msg->rune === 'q' && !$msg->ctrl)) {
                // 'q'/Esc are a normal finish (exit 0); Ctrl+C is a
                // cancellation and the command must exit 130, not pretend
                // the page was read to the end.
                return [new self($this->viewport, true, $msg->ctrl && $msg->rune === 'c', $this->terminalRows, $this->frameRows), Cmd::quit()];
            }
        }
        if ($msg instanceof WindowSizeMsg) {
            // Re-fit the viewport to the live terminal size; content that
            // was pre-wrapped by --soft-wrap at launch keeps its shape.
            // scriptui-20: the reservation from fromContent() must survive
            // the re-fit — the viewport takes rows minus the footer rows,
            // never the full terminal height, so the composed frame stays
            // within the screen.
            $width  = max(1, $msg->cols);
            $rows   = max(1, $msg->rows);
            $height = max(1, $rows - self::HINT_ROWS - $this->frameRows);
            if ($width !== $this->viewport->width || $height !== $this->viewport->height || $rows !== $this->terminalRows) {
                return [new self($this->viewport->withSize($width, $height), $this->exited, $this->interrupted, $rows, $this->frameRows), null];
            }
            return [$this, null];
        }
        [$next, $cmd] = $this->viewport->update($msg);
        return [new self($next, false), $cmd];
    }

    public function view(): string
    {
        $body = $this->viewport->view();
        if (!$this->exited && ($this->terminalRows === 0 || $this->terminalRows >= self::HINT_ROWS + 1)) {
            // scriptui-57 (146): the footer is the only in-frame
            // discoverability channel, and one line could not carry the
            // bound key set — the roster now spans two lines, and
            // HINT_ROWS makes every height derivation subtract both.
            // On terminals too short to hold footer + one content row the
            // footer yields (the scriptui-20 never-exceed contract wins
            // over discoverability at 2 rows).
            $body .= "\n" . Lang::t('hints.pager') . "\n" . Lang::t('hints.pager.more');
        }
        return $body;
    }
    public function isExited(): bool { return $this->exited; }
    public function isInterrupted(): bool { return $this->interrupted; }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
