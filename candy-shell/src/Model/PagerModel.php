<?php

declare(strict_types=1);

namespace CandyCore\Shell\Model;

use CandyCore\Bits\Viewport\Viewport;
use CandyCore\Core\Cmd;
use CandyCore\Core\KeyType;
use CandyCore\Core\Model;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Core\Util\Ansi;

/**
 * Read-only pager used by {@see \CandyCore\Shell\Command\PagerCommand}.
 * Wraps a {@see Viewport}; Esc / `q` / Ctrl-C exit. Standard navigation
 * keys (↑/↓/jk, PgUp/PgDn/space/b/f, Home/g/End/G, Ctrl-U/D) come from
 * Viewport's own update().
 */
final class PagerModel implements Model
{
    public static function fromContent(
        string $content,
        int $width = 80,
        int $height = 20,
        bool $showLineNumbers = false,
        string $match = '',
    ): self {
        if ($showLineNumbers) {
            $content = self::numberLines($content);
        }
        if ($match !== '') {
            $content = self::highlightMatches($content, $match);
        }
        $vp = Viewport::new($width, max(1, $height))->setContent($content);
        return new self($vp, false);
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
        $sgr = "\x1b[7m";
        $rst = "\x1b[0m";
        // Case-insensitive substring replace using preg_replace; quote
        // the needle so regex metachars aren't treated as syntax.
        $pattern = '/' . preg_quote($needle, '/') . '/i';
        return (string) preg_replace($pattern, $sgr . '$0' . $rst, $content);
    }

    private function __construct(
        public readonly Viewport $viewport,
        public readonly bool $exited,
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
                return [new self($this->viewport, true), Cmd::quit()];
            }
        }
        [$next, $cmd] = $this->viewport->update($msg);
        return [new self($next, false), $cmd];
    }

    public function view(): string  { return $this->viewport->view(); }
    public function isExited(): bool { return $this->exited; }
}
