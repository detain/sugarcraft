<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Top\Collect\Memory;
use SugarCraft\Top\Collect\MemorySnapshot;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Panel\Gfx\History;
use SugarCraft\Top\Panel\Gfx\NamedSources;
use SugarCraft\Top\Panel\Mem\Disks;
use SugarCraft\Top\Panel\Mem\DisksSection;
use SugarCraft\Top\Panel\Mem\MemView;
use SugarCraft\Top\Source\CollectorSource;
use SugarCraft\Top\Source\Fake\FakeMemory;
use SugarCraft\Top\Source\Samples;
use SugarCraft\Top\Source\Source;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Region;

/**
 * btop's mem box, memory half: Total, the used/available/cached/free
 * classes and the swap section as meters or graphs (mem_graphs), the
 * btop #1739 zswap row, the btop #1747 single focused graph
 * (mem_selected), and the P-D seam for the disks half
 * ({@see DisksSection}).
 *
 * Keys (btop_input.cpp:572-591, the mem-box input block): `d` flips
 * show_disks, `i` flips io_mode — both as synchronous {@see PanelResult::$set}
 * writes — and a left click on the `disks` / `io` border buttons does the
 * same (btop's `Input::mouse_mappings["d"]` / `["i"]`).
 *
 * Mirrors aristocratos/btop Mem::draw (src/btop_draw.cpp:1237-1389).
 */
final class MemPanel implements Panel
{
    /** Every series the view can graph (btop mem.percent keys + #1739). */
    public const SERIES = ['used', 'available', 'cached', 'free', 'swap_used', 'swap_free', 'swap_used_disk', 'zswap'];

    private const DEFAULT_HISTORY = 512;

    private function __construct(
        private readonly NamedSources $sources,
        private readonly ?DisksSection $disks,
        private readonly History $history,
        private readonly ?MemorySnapshot $snapshot,
    ) {
    }

    public static function new(Source $memory): self
    {
        return new self(NamedSources::of(['mem' => $memory]), null, History::new(), null);
    }

    /** The roster entry {@see Panels::standard()} uses, with the P-D disks section installed. */
    public static function standard(Config $config, bool $fake = false): self
    {
        return self::new($fake ? FakeMemory::new() : CollectorSource::of(Memory::new(null, $config->bool('zfs_arc_cached'))))
            ->withDisks(Disks::standard($config, $fake));
    }

    /** Install (or remove) the P-D disks section. */
    public function withDisks(?DisksSection $disks): self
    {
        return new self($this->sources, $disks, $this->history, $this->snapshot);
    }

    public function box(): string
    {
        return 'mem';
    }

    public function collect(PanelContext $context): ?\Closure
    {
        $sampling = $this->sources->with('disks', $this->disks?->source($context));

        return static function () use ($sampling): Msg {
            [$snapshot, $next] = $sampling->sample();

            return new SampledMsg('mem', $snapshot, $next);
        };
    }

    public function modal(PanelContext $context): bool
    {
        return false;
    }

    /** btop's mem-box keys: `d` (show_disks) and `i` (io_mode). */
    public function capturesKey(KeyMsg $key, PanelContext $context): bool
    {
        return self::option($key) !== null;
    }

    public function update(Msg $msg, PanelContext $context): PanelResult
    {
        if ($msg instanceof SampledMsg && $msg->box === 'mem' && $msg->snapshot instanceof Samples) {
            return new PanelResult($this->withSamples($msg->snapshot, $msg->next, $context));
        }
        if ($msg instanceof KeyMsg && ($option = self::option($msg)) !== null) {
            return $this->flip($option, $context);
        }
        if ($msg instanceof MouseMsg && ($option = self::button($msg, $context)) !== null) {
            return $this->flip($option, $context);
        }

        return new PanelResult($this);
    }

    public function paint(Region $region, PanelFrame $frame): void
    {
        MemView::paint($region, $frame, $this);
    }

    public function history(): History
    {
        return $this->history;
    }

    /** The last measured snapshot (btop #1008: a failed read keeps it); null before one. */
    public function snapshot(): ?MemorySnapshot
    {
        return $this->snapshot;
    }

    public function disks(): ?DisksSection
    {
        return $this->disks;
    }

    /**
     * The local cells of the border buttons this panel answers clicks on,
     * btop's mouse_mappings: `d` -> the `disks` label, `i` -> `io` (only
     * while show_disks). Exposed for tests.
     *
     * @return array<string, Rect> option => local rect on row 0
     */
    public static function buttons(PanelContext $context): array
    {
        $box = $context->box;
        $layout = $context->layout;
        if ($box === null || $layout === null) {
            return [];
        }
        $disks = $layout->showDisks && $layout->memDivider !== null;
        $out = ['show_disks' => Rect::new($disks ? $layout->memDivider - $box->x + 3 : $box->width - 8, 0, 5, 1)];
        if ($disks) {
            $out['io_mode'] = Rect::new($box->width - 5, 0, 2, 1);
        }

        return $out;
    }

    private function withSamples(Samples $samples, Source $next, PanelContext $context): self
    {
        // btop trims mem.percent to `width * 2` with Mem::width — the mem box.
        $cap = 2 * max(1, $context->box?->width ?? $context->layout?->width ?? intdiv(self::DEFAULT_HISTORY, 2));
        $nextSet = $next instanceof NamedSources ? $next : NamedSources::of([]);
        $sources = $this->sources->merge($nextSet->only(['mem']));
        $history = $this->history;
        $snapshot = $this->snapshot;
        $mem = $samples->get('mem');
        if ($mem instanceof MemorySnapshot) {
            $measured = $mem->measured();
            foreach (self::SERIES as $key) {
                $value = !$measured ? -1.0 : match ($key) {
                    'swap_used', 'swap_free', 'swap_used_disk', 'zswap' => $mem->swapPercent($key),
                    default => $mem->percent($key),
                };
                $history = $history->push($key, $value, $cap);
            }
            if ($measured) {
                $snapshot = $mem;
            }
        }
        $disks = $this->disks;
        $disksSnap = $samples->get('disks');
        $disksNext = $nextSet->get('disks');
        if ($disks !== null && $disksSnap !== null && $disksNext !== null) {
            $disks = $disks->withSample($disksSnap, $disksNext, $context, $snapshot);
        }

        return new self($sources, $disks, $history, $snapshot);
    }

    private function flip(string $option, PanelContext $context): PanelResult
    {
        $value = !$context->config->bool($option);
        $cmd = null;
        if ($option === 'show_disks') {
            // btop runs the mem collector (no_update = false) so the disks
            // half has data at once; sample under the flipped config.
            $cmd = $this->collect(new PanelContext($context->config->with($option, $value), $context->layout, $context->box));
        }

        return new PanelResult($this, $cmd, [$option => $value]);
    }

    private static function option(KeyMsg $key): ?string
    {
        if ($key->type !== KeyType::Char || $key->ctrl || $key->alt) {
            return null;
        }

        return match ($key->rune) {
            'd' => 'show_disks',
            'i' => 'io_mode',
            default => null,
        };
    }

    private static function button(MouseMsg $msg, PanelContext $context): ?string
    {
        if ($msg->action !== MouseAction::Press || $msg->button !== MouseButton::Left
            || $msg->shift || $msg->alt || $msg->ctrl || $context->box === null) {
            return null;
        }
        $x = $msg->x - 1 - $context->box->x;
        $y = $msg->y - 1 - $context->box->y;
        foreach (self::buttons($context) as $option => $rect) {
            if ($rect->contains($x, $y)) {
                return $option;
            }
        }

        return null;
    }
}
