<?php

declare(strict_types=1);

namespace CandyCore\Glow;

use CandyCore\Bits\Viewport\Viewport;
use CandyCore\Core\Cmd;
use CandyCore\Core\KeyType;
use CandyCore\Core\Model;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\KeyMsg;

/**
 * Pager Model used by {@see RenderCommand} when `-p` / `--pager` is set.
 * Wraps a {@see Viewport} containing the already-rendered (styled) text.
 * Standard reader keys are forwarded to the Viewport; `q` / `Esc` /
 * `Ctrl+C` exit the loop.
 */
final class GlowModel implements Model
{
    public static function fromContent(string $content, int $width = 80, int $height = 24): self
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

    public function view(): string
    {
        return $this->viewport->view();
    }

    public function isExited(): bool { return $this->exited; }
}
