<?php

declare(strict_types=1);

namespace SugarCraft\Top\Config;

use SugarCraft\Top\Lang;

/**
 * The parsed `presets` option: built-in preset 0 followed by up to 9 custom
 * layouts.
 *
 * Mirrors aristocratos/btop Config::presetsValid + Config::preset_list and
 * the `p`/`P` cycle in btop_input.cpp. Format: presets separated by
 * whitespace, boxes by `,`, each box a `name:P:G` triple — P is `0|1`
 * (alternate position), G a graph symbol or `default`.
 */
final class Presets
{
    /** btop's always-present preset 0: every box, default placement and symbols. */
    public const BUILTIN = 'cpu:0:default,mem:0:default,net:0:default,proc:0:default';

    /** btop caps custom presets at 9 (digits 1-9 after the built-in 0). */
    public const MAX_PRESETS = 9;

    /** btop caps a preset at 4 boxes — one per screen region. */
    public const MAX_BOXES = 4;

    /** @param list<Preset> $presets */
    private function __construct(
        private readonly array $presets,
    ) {
    }

    /** Only the built-in preset 0 — what an empty `presets=""` yields. */
    public static function new(): self
    {
        return self::parse('');
    }

    /**
     * Parse a `presets` value, prepending preset 0.
     *
     * Splitting drops empty fields exactly like btop's `ssplit`, so runs of
     * spaces or a trailing comma are harmless while `cpu::default` (an empty
     * P collapses away) is malformed.
     *
     * @throws InvalidOptionValue With btop's presetsValid wording.
     */
    public static function parse(string $value): self
    {
        $presets = [self::parseOne(self::BUILTIN)];

        foreach (self::split($value, ' ') as $i => $raw) {
            if ($i + 1 > self::MAX_PRESETS) {
                throw self::fail('config.preset.too_many');
            }
            $presets[] = self::parseOne($raw);
        }

        return new self($presets);
    }

    /** @return list<Preset> All presets, index 0 being the built-in one. */
    public function all(): array
    {
        return $this->presets;
    }

    /** Number of presets including the built-in one (always >= 1). */
    public function count(): int
    {
        return \count($this->presets);
    }

    /**
     * Preset at $index.
     *
     * @throws \OutOfRangeException When no such preset exists.
     */
    public function at(int $index): Preset
    {
        return $this->presets[$index]
            ?? throw new \OutOfRangeException("No preset at index {$index}");
    }

    /**
     * Index the `p` (forward) / `P` (backward) key moves to, or null when the
     * keypress changes nothing.
     *
     * Mirrors btop_input.cpp's preset cycle including `disable_presets`:
     * "All" swallows the key, "Default" skips preset 0 (and does nothing when
     * it is the only one), "Custom" pins preset 0.
     */
    public function cycle(?int $current, bool $forward, string $disablePresets): ?int
    {
        $count = $this->count();
        if ($disablePresets === 'All' || ($disablePresets === 'Default' && $count <= 1)) {
            return null;
        }
        $first = $disablePresets === 'Default' ? 1 : 0;

        if ($disablePresets === 'Custom') {
            $next = 0;
        } elseif ($current !== null) {
            if ($forward) {
                $next = $current + 1 >= $count ? $first : $current + 1;
            } else {
                $next = $current - 1 < $first ? $count - 1 : $current - 1;
            }
        } else {
            $next = $forward ? $first : $count - 1;
        }

        return $next === $current ? null : $next;
    }

    /** The custom presets back in btop's whitespace-joined spelling (preset 0 excluded). */
    public function toString(): string
    {
        return implode(' ', array_map(
            static fn (Preset $p): string => $p->toString(),
            \array_slice($this->presets, 1),
        ));
    }

    private static function parseOne(string $raw): Preset
    {
        $boxes = [];
        foreach (self::split($raw, ',') as $i => $box) {
            if ($i + 1 > self::MAX_BOXES) {
                throw self::fail('config.preset.too_many_boxes');
            }
            $vals = self::split($box, ':');
            if (\count($vals) !== 3) {
                throw self::fail('config.preset.malformed');
            }
            [$name, $position, $symbol] = $vals;
            if (!\in_array($name, Schema::BOXES, true)) {
                throw self::fail('config.preset.invalid_box');
            }
            if ($position !== '0' && $position !== '1') {
                throw self::fail('config.preset.invalid_position');
            }
            if (!\in_array($symbol, Schema::GRAPH_SYMBOLS_DEF, true)) {
                throw self::fail('config.preset.invalid_graph');
            }
            $boxes[] = new PresetBox($name, $position === '1', $symbol);
        }

        return new Preset($boxes);
    }

    /**
     * btop ssplit(): split on one delimiter and drop empty fields.
     *
     * @return list<string>
     */
    private static function split(string $value, string $delimiter): array
    {
        return array_values(array_filter(
            explode($delimiter, $value),
            static fn (string $part): bool => $part !== '',
        ));
    }

    private static function fail(string $key): InvalidOptionValue
    {
        return new InvalidOptionValue(Lang::t($key), 'presets');
    }
}
