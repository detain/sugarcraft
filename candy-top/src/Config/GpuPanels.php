<?php

declare(strict_types=1);

namespace SugarCraft\Top\Config;

use SugarCraft\Top\Lang;

/**
 * btop PR #1730's GPU box slots: `gpuN` box names for any GPU index N, at
 * most {@see MAX} shown at once, each shown box sitting in a stable panel
 * SLOT (0-5, toggled by the number keys 5, 6, 7, 8, 9, 0) whose target GPU
 * can be switched independently (the title's `← gpuN →` zones).
 *
 * The slot of each shown gpu box is runtime state — btop's
 * `Config::current_gpu_panel_slots`, never written to the file — and
 * lives in the non-persisted `gpu_panel_slots` option as space-separated
 * slot numbers, one per gpu box in shown_boxes order. Any change of
 * shown_boxes that does not set the slots too (options menu, presets,
 * a reload) resets them, as btop's `set_boxes` clears them
 * ({@see Config::with()}); {@see slots()} then reads the default
 * 0..n-1. Gpu boxes are kept ordered by slot inside shown_boxes
 * (btop normalize_gpu_panel_order), so the box titles stay stable when a
 * middle slot is closed.
 *
 * Mirrors aristocratos/btop PR #1730 Config::gpu_box_index,
 * gpu_panel_slot_from_key, toggle_gpu_box, switch_gpu_box,
 * normalize_gpu_panel_order.
 */
final class GpuPanels
{
    /** btop Config::max_gpu_panels. */
    public const MAX = 6;

    /** btop gpu_panel_keys: slot n is toggled by this digit. */
    public const KEYS = ['5', '6', '7', '8', '9', '0'];

    /** The runtime option holding the slot of each shown gpu box. */
    public const SLOTS_KEY = 'gpu_panel_slots';

    private function __construct()
    {
    }

    /**
     * The GPU index a `gpuN` box names, null for anything else — btop
     * gpu_box_index: "gpu" plus digits only ("gpu", "gpu-1", "gpu1x" are
     * not GPU boxes). More than 9 digits is out of range (btop's stoul
     * would throw on anything past its own limit).
     */
    public static function index(string $box): ?int
    {
        if (preg_match('/^gpu(\d{1,9})$/D', $box, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    /** btop gpu_box_name. */
    public static function name(int $gpu): string
    {
        return 'gpu' . $gpu;
    }

    /** The panel slot a number key toggles, null for any other key. */
    public static function slotFromKey(string $key): ?int
    {
        $slot = array_search($key, self::KEYS, true);

        return $slot === false ? null : $slot;
    }

    /** The digit that toggles `$slot` (btop gpu_panel_key) — 0 for slot 5. */
    public static function key(int $slot): int
    {
        return (int) (self::KEYS[$slot] ?? '0');
    }

    /** A box name shown_boxes accepts: cpu, mem, net, proc or any gpuN (count unchecked). */
    public static function valid(string $box): bool
    {
        return \in_array($box, Schema::BOXES, true) || self::index($box) !== null;
    }

    /**
     * GPU indexes of the gpu boxes in `$boxes`, in order.
     *
     * @param list<string> $boxes
     * @return list<int>
     */
    public static function targets(array $boxes): array
    {
        $out = [];
        foreach ($boxes as $box) {
            $i = self::index($box);
            if ($i !== null) {
                $out[] = $i;
            }
        }

        return $out;
    }

    /**
     * The slot of each shown gpu box, in shown order — the stored
     * `gpu_panel_slots` when it still describes the boxes (one distinct
     * slot below {@see MAX} per gpu box), else btop's default 0..n-1.
     *
     * @return list<int>
     */
    public static function slots(Config $config): array
    {
        $n = \count(self::targets($config->shownBoxes()));
        $stored = self::parseSlots($config->string(self::SLOTS_KEY));
        if ($stored !== null && \count($stored) === $n && \count(array_unique($stored)) === $n) {
            return $stored;
        }

        return $n === 0 ? [] : range(0, $n - 1);
    }

    /**
     * btop toggle_gpu_box: close the box in `$slot`, or open one there
     * targeting the first GPU from index `$slot` on (wrapping) that no
     * other box shows. Null when nothing changes: the slot is free but
     * there is no GPU `$slot` (btop's `slot >= Gpu::count` guard), six
     * boxes are already shown, every GPU already has a box, or closing
     * would leave no box at all (shown_boxes must name one). The terminal
     * fit is the caller's check.
     */
    public static function toggle(Config $config, int $slot, int $gpuCount): ?Config
    {
        if ($slot < 0 || $slot >= self::MAX) {
            return null;
        }
        $boxes = $config->shownBoxes();
        $slots = self::slots($config);
        $at = array_search($slot, $slots, true);
        if ($at !== false) {
            $seen = 0;
            foreach ($boxes as $pos => $box) {
                if (self::index($box) !== null && $seen++ === $at) {
                    array_splice($boxes, $pos, 1);
                    array_splice($slots, $at, 1);

                    return $boxes === [] ? null : self::withBoxes($config, $boxes, $slots);
                }
            }

            return null;
        }
        if ($slot >= $gpuCount || \count($slots) >= self::MAX) {
            return null;
        }
        $occupied = array_flip(self::targets($boxes));
        for ($step = 0; $step < $gpuCount; $step++) {
            $gpu = ($slot + $step) % $gpuCount;
            if (!isset($occupied[$gpu])) {
                $boxes[] = self::name($gpu);
                $slots[] = $slot;

                return self::withBoxes($config, $boxes, $slots);
            }
        }

        return null;
    }

    /**
     * btop switch_gpu_box: retarget the `$panel`-th shown gpu box to the
     * next (`$direction` 1) or previous (-1) GPU no other box shows,
     * wrapping. Null when there is one GPU or less, no such box, or every
     * other GPU is already shown.
     */
    public static function switchTarget(Config $config, int $panel, int $direction, int $gpuCount): ?Config
    {
        if ($gpuCount <= 1) {
            return null;
        }
        $boxes = $config->shownBoxes();
        $positions = [];
        foreach ($boxes as $pos => $box) {
            if (self::index($box) !== null) {
                $positions[] = $pos;
            }
        }
        if ($panel < 0 || $panel >= \count($positions)) {
            return null;
        }
        $pos = $positions[$panel];
        $current = (int) self::index($boxes[$pos]);
        $original = $current;
        $occupied = [];
        foreach ($positions as $p) {
            if ($p !== $pos) {
                $occupied[(int) self::index($boxes[$p])] = true;
            }
        }
        for ($step = 0; $step < $gpuCount; $step++) {
            $current = (($current + $direction) % $gpuCount + $gpuCount) % $gpuCount;
            if ($current !== $original && !isset($occupied[$current])) {
                $boxes[$pos] = self::name($current);

                return self::withBoxes($config, $boxes, self::slots($config));
            }
        }

        return null;
    }

    /**
     * btop normalize_gpu_panel_order + Config::set: the gpu boxes sorted
     * by slot inside the positions gpu boxes occupy, then shown_boxes and
     * the slots written together (shown_boxes first — writing it alone
     * resets the slots).
     *
     * @param list<string> $boxes
     * @param list<int>    $slots one per gpu box in `$boxes`
     *
     * @throws InvalidOptionValue when the boxes are not a valid shown_boxes value
     */
    public static function withBoxes(Config $config, array $boxes, array $slots): Config
    {
        $boxes = array_values($boxes);
        $positions = [];
        $gpus = [];
        foreach ($boxes as $pos => $box) {
            if (self::index($box) !== null) {
                $positions[] = $pos;
                $gpus[] = $box;
            }
        }
        $slots = array_values($slots);
        if (\count($slots) !== \count($gpus) || \count(array_unique($slots)) !== \count($slots)) {
            $slots = $gpus === [] ? [] : range(0, \count($gpus) - 1);
        }
        $order = array_keys($slots);
        usort($order, static fn (int $a, int $b): int => $slots[$a] <=> $slots[$b]);
        foreach ($positions as $k => $pos) {
            $boxes[$pos] = $gpus[$order[$k]];
        }
        $sorted = array_map(static fn (int $k): int => $slots[$k], $order);

        return $config
            ->with('shown_boxes', implode(' ', $boxes))
            ->with(self::SLOTS_KEY, implode(' ', $sorted));
    }

    /**
     * The `gpu_panel_slots` law: space-separated slot numbers 0-5, each
     * at most once.
     *
     * @throws InvalidOptionValue
     */
    public static function validateSlots(string $value): void
    {
        $slots = self::parseSlots($value);
        if ($slots === null || \count(array_unique($slots)) !== \count($slots)) {
            throw new InvalidOptionValue(Lang::t('config.warn.invalid_gpu_slots'), self::SLOTS_KEY);
        }
    }

    /** @return list<int>|null null when a token is not a slot number */
    private static function parseSlots(string $value): ?array
    {
        $out = [];
        foreach (preg_split('/\s+/', trim($value), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (preg_match('/^[0-5]$/D', $token) !== 1) {
                return null;
            }
            $out[] = (int) $token;
        }

        return $out;
    }
}
