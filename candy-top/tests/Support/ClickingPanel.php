<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Support;

use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Top\Overlay\Overlay;
use SugarCraft\Top\Panel\ClickCapture;
use SugarCraft\Top\Panel\Panel;
use SugarCraft\Top\Panel\PanelContext;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\Panel\PanelResult;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Region;

/**
 * Test double for the App click map ({@see ClickCapture}): claims clicks
 * whose 0-based cell falls in `$button` (box-local), records every Msg it
 * receives, and — when `$opens` is set — answers a key named `$opensOn`
 * with a {@see PanelResult::$overlay} request.
 */
final class ClickingPanel implements Panel, ClickCapture
{
    /**
     * @param list<Msg> $seen
     */
    public function __construct(
        private readonly string $box,
        private readonly ?Rect $button = null,
        public readonly array $seen = [],
        private readonly ?Overlay $opens = null,
        private readonly string $opensOn = '',
    ) {
    }

    public function box(): string
    {
        return $this->box;
    }

    public function collect(PanelContext $context): ?\Closure
    {
        return null;
    }

    public function modal(PanelContext $context): bool
    {
        return false;
    }

    public function capturesKey(KeyMsg $key, PanelContext $context): bool
    {
        return false;
    }

    public function capturesClick(MouseMsg $msg, PanelContext $context): bool
    {
        $box = $context->box;

        return $this->button !== null && $box !== null
            && $this->button->contains($msg->x - 1 - $box->x, $msg->y - 1 - $box->y);
    }

    public function update(Msg $msg, PanelContext $context): PanelResult
    {
        $next = new self($this->box, $this->button, [...$this->seen, $msg], $this->opens, $this->opensOn);
        $open = $msg instanceof KeyMsg && $this->opens !== null && $msg->string() === $this->opensOn ? $this->opens : null;

        return new PanelResult($next, null, [], $open);
    }

    public function paint(Region $region, PanelFrame $frame): void
    {
    }
}
