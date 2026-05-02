<?php

declare(strict_types=1);

namespace CandyCore\Shell\Model;

use CandyCore\Bits\Viewport\Viewport;
use CandyCore\Core\Cmd;
use CandyCore\Core\KeyType;
use CandyCore\Core\Model;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\KeyMsg;

/**
 * Read-only pager used by {@see \CandyCore\Shell\Command\PagerCommand}.
 * Wraps a {@see Viewport}; Esc / `q` / Ctrl-C exit. Standard navigation
 * keys (↑/↓/jk, PgUp/PgDn/space/b/f, Home/g/End/G, Ctrl-U/D) come from
 * Viewport's own update().
 */
final class PagerModel implements Model
{
    public static function fromContent(string $content, int $width = 80, int $height = 20): self
    {
        $vp = Viewport::new($width, max(1, $height))->setContent($content);
        return new self($vp, false);
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
