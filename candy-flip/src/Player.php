<?php

declare(strict_types=1);

namespace CandyCore\Flip;

use CandyCore\Core\Cmd;
use CandyCore\Core\KeyType;
use CandyCore\Core\Model;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\KeyMsg;

/**
 * GIF player as a CandyCore Model. Loads every frame up-front (the
 * decoder caps at 256), then advances one frame per `TickMsg` —
 * scheduled via `Cmd::tick($interval, …)` so we don't need a render
 * loop in the bin/.
 *
 * Keys: space — pause/resume.  ←/→ — manual step.  q/esc — quit.
 */
final class Player implements Model
{
    /**
     * @param list<Frame> $frames
     */
    public function __construct(
        public readonly array $frames,
        public readonly int $index = 0,
        public readonly bool $paused = false,
        public readonly float $interval = 0.1,
        public readonly string $preset = Renderer::PRESET_SOLID,
    ) {}

    public function init(): ?\Closure
    {
        if ($this->frames === [] || $this->paused) {
            return null;
        }
        return Cmd::tick($this->interval, static fn(): Msg => new TickMsg());
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
                return [$this->step(+1), null];
            }
            if ($msg->type === KeyType::Left) {
                return [$this->step(-1), null];
            }
            if ($msg->type === KeyType::Char && $msg->rune === 'd') {
                $next = new Player(
                    $this->frames, $this->index, $this->paused, $this->interval,
                    $this->preset === Renderer::PRESET_SOLID
                        ? Renderer::PRESET_DENSITY
                        : Renderer::PRESET_SOLID,
                );
                return [$next, null];
            }
        }
        if ($msg instanceof TickMsg && !$this->paused && $this->frames !== []) {
            $next = $this->step(+1);
            return [$next, $next->scheduleTick()];
        }
        return [$this, null];
    }

    public function view(): string
    {
        if ($this->frames === []) {
            return "(no frames)\n";
        }
        $frame = $this->frames[$this->index];
        $pic   = Renderer::render($frame, $this->preset);
        $total = count($this->frames);
        $status = sprintf(
            "frame %d/%d  ·  %s  ·  %s   space pause   ←/→ step   d preset   q quit",
            $this->index + 1, $total, $this->preset,
            $this->paused ? 'paused' : 'playing',
        );
        return $pic . "\n" . $status . "\n";
    }

    private function step(int $direction): self
    {
        $n = count($this->frames);
        $i = $n === 0 ? 0 : (($this->index + $direction) % $n + $n) % $n;
        return new Player($this->frames, $i, $this->paused, $this->interval, $this->preset);
    }

    private function withPaused(bool $paused): self
    {
        return new Player($this->frames, $this->index, $paused, $this->interval, $this->preset);
    }

    private function scheduleTick(): \Closure
    {
        return Cmd::tick($this->interval, static fn(): Msg => new TickMsg());
    }
}
