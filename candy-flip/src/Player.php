<?php

declare(strict_types=1);

namespace SugarCraft\Flip;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;

/**
 * GIF player as a SugarCraft Model. Loads every frame up-front (the
 * decoder caps at 256), then advances one frame per `TickMsg` —
 * scheduled via `Cmd::tick($interval, …)` so we don't need a render
 * loop in the bin/.
 *
 * Keys: space — pause/resume.  ←/→ — manual step.  q/esc — quit.
 */
final class Player implements Model
{
    use Mutable;

    /**
     * @param list<Frame> $frames
     */
    public function __construct(
        public readonly array $frames,
        public readonly int $index = 0,
        public readonly bool $paused = false,
        public readonly float $interval = 0.1,
        public readonly string $preset = Renderer::PRESET_SOLID,
        public readonly ?Renderer $renderer = null,
    ) {}

    public function init(): ?\Closure
    {
        if ($this->frames === [] || $this->paused) {
            return null;
        }
        return Cmd::tick($this->frames[$this->index]->delay / 100.0, static fn(): Msg => new TickMsg());
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg) {
            if ($msg->type === KeyType::Escape
                || ($msg->type === KeyType::Char && $msg->rune === 'q')
                || ($msg->ctrl && $msg->rune === 'c')) {
                return [$this, Cmd::quit()];
            }
            if ($msg->type === KeyType::Space) {
                $next = $this->withPaused(!$this->paused);
                return [$next, $next->paused ? null : $next->scheduleTick()];
            }
            if ($msg->type === KeyType::Right) {
                return [$this->withIndex($this->index + 1), null];
            }
            if ($msg->type === KeyType::Left) {
                return [$this->withIndex($this->index - 1), null];
            }
            if ($msg->type === KeyType::Char && $msg->rune === 'd') {
                return [$this->withPreset(
                    $this->preset === Renderer::PRESET_SOLID
                        ? Renderer::PRESET_DENSITY
                        : Renderer::PRESET_SOLID,
                ), null];
            }
        }
        if ($msg instanceof TickMsg && !$this->paused && $this->frames !== []) {
            $next = $this->withIndex($this->index + 1);
            return [$next, $next->scheduleTick()];
        }
        if ($msg instanceof WindowSizeMsg) {
            // Re-clamp renderer to new window size, reserving one row for the status line.
            $renderer = Renderer::withConstraints($msg->rows - 1, $msg->cols);
            return [$this->mutate(['renderer' => $renderer]), null];
        }
        return [$this, null];
    }

    public function view(): string
    {
        if ($this->frames === []) {
            return "(no frames)\n";
        }
        $frame = $this->frames[$this->index];
        $renderer = $this->renderer ?? Renderer::new();
        $pic   = $renderer->renderFrame($frame, $this->preset);
        $total = count($this->frames);
        $status = sprintf(
            "frame %d/%d  ·  %s  ·  %s   space pause   ←/→ step   d preset   q quit",
            $this->index + 1, $total, $this->preset,
            $this->paused ? 'paused' : 'playing',
        );
        return $pic . "\n" . $status . "\n";
    }

    private function withIndex(int $index): self
    {
        $n = count($this->frames);
        $i = $n === 0 ? 0 : (($index % $n) + $n) % $n;
        return $this->mutate(['index' => $i]);
    }

    private function withPreset(string $preset): self
    {
        return $this->mutate(['preset' => $preset]);
    }

    private function withPaused(bool $paused): self
    {
        return $this->mutate(['paused' => $paused]);
    }

    private function scheduleTick(): \Closure
    {
        return Cmd::tick($this->frames[$this->index]->delay / 100.0, static fn(): Msg => new TickMsg());
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
