<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\ProcRow;

use SugarCraft\Core\Util\Color;
use SugarCraft\Dash\Plot\DistanceFade;
use SugarCraft\Dash\Plot\Gradient101;

/**
 * The theme slots a process row reads: three flat colors for text and
 * underlay, two highlight pairs (selected / followed), and the three
 * 101-entry ramps of the distance fade.
 *
 * {@see new()} derives `proc` and `proc_color` the way btop's theme loader
 * injects them; {@see withRamps()} accepts ramps a theme store already
 * expanded (e.g. a GradientStore built from a .theme file).
 *
 * Mirrors aristocratos/btop Theme::Default_theme (main_fg, inactive_fg,
 * selected_*, followed_*, process_*) and the proc / proc_color injection
 * in Theme::generateGradients (src/btop_theme.cpp).
 */
final class ProcRowPalette
{
    /**
     * @param list<Color> $proc
     * @param list<Color> $procColor
     * @param list<Color> $process
     */
    private function __construct(
        public readonly Color $mainFg,
        public readonly Color $inactiveFg,
        public readonly Color $selectedBg,
        public readonly Color $selectedFg,
        public readonly Color $followedBg,
        public readonly Color $followedFg,
        public readonly array $proc,
        public readonly array $procColor,
        public readonly array $process,
    ) {
    }

    /**
     * @param list<Color> $processStops process_start[, process_mid], process_end — low → high
     * @throws \InvalidArgumentException on fewer than 2 process stops
     */
    public static function new(
        Color $mainFg,
        Color $inactiveFg,
        array $processStops,
        Color $selectedBg,
        Color $selectedFg,
        ?Color $followedBg = null,
        ?Color $followedFg = null,
    ): self {
        $process = Gradient101::expand($processStops);
        return new self(
            $mainFg,
            $inactiveFg,
            $selectedBg,
            $selectedFg,
            $followedBg ?? $selectedBg,
            $followedFg ?? $selectedFg,
            DistanceFade::ramp($mainFg, $inactiveFg),
            DistanceFade::ramp($inactiveFg, $processStops[array_key_first($processStops)]),
            $process,
        );
    }

    /** btop's built-in Default theme. */
    public static function btop(): self
    {
        return self::new(
            Color::hex('#cccccc'),
            Color::hex('#404040'),
            [Color::hex('#80d0a3'), Color::hex('#dcd179'), Color::hex('#d45454')],
            Color::hex('#6a2f2f'),
            Color::hex('#eeeeee'),
            Color::hex('#4040b5'),
            Color::hex('#eeeeee'),
        );
    }

    /**
     * Replace the three ramps with pre-expanded ones.
     *
     * @param list<Color> $proc      main_fg → inactive_fg
     * @param list<Color> $procColor inactive_fg → process_start
     * @param list<Color> $process   the process gradient
     * @throws \InvalidArgumentException on a ramp that is not 101 Colors
     */
    public function withRamps(array $proc, array $procColor, array $process): self
    {
        foreach (['proc' => $proc, 'procColor' => $procColor, 'process' => $process] as $name => $ramp) {
            if (count($ramp) !== 101 || !array_is_list($ramp)) {
                throw new \InvalidArgumentException(sprintf('ProcRowPalette %s ramp must be a list of 101 Colors', $name));
            }
            foreach ($ramp as $entry) {
                if (!$entry instanceof Color) {
                    throw new \InvalidArgumentException(sprintf('ProcRowPalette %s ramp must be a list of 101 Colors', $name));
                }
            }
        }
        return $this->mutate(['proc' => $proc, 'procColor' => $procColor, 'process' => $process]);
    }

    // No withMainFg()/withInactiveFg(): both seed derived ramps, so
    // changing either alone would leave proc/proc_color stale — rebuild
    // through new() instead.

    public function withSelected(Color $bg, Color $fg): self
    {
        return $this->mutate(['selectedBg' => $bg, 'selectedFg' => $fg]);
    }

    public function withFollowed(Color $bg, Color $fg): self
    {
        return $this->mutate(['followedBg' => $bg, 'followedFg' => $fg]);
    }

    /**
     * Readonly promoted state can't be written on a clone, so rebuild
     * through the constructor with the overrides merged in.
     *
     * @param array<string, mixed> $props
     */
    private function mutate(array $props): self
    {
        $p = $props + [
            'mainFg' => $this->mainFg,
            'inactiveFg' => $this->inactiveFg,
            'selectedBg' => $this->selectedBg,
            'selectedFg' => $this->selectedFg,
            'followedBg' => $this->followedBg,
            'followedFg' => $this->followedFg,
            'proc' => $this->proc,
            'procColor' => $this->procColor,
            'process' => $this->process,
        ];
        return new self(
            $p['mainFg'],
            $p['inactiveFg'],
            $p['selectedBg'],
            $p['selectedFg'],
            $p['followedBg'],
            $p['followedFg'],
            $p['proc'],
            $p['procColor'],
            $p['process'],
        );
    }
}
