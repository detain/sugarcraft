<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Spinner;

use SugarCraft\Forms\Lang;

/**
 * A spinner style: an ordered list of frames and a per-frame interval.
 *
 * Built-in factories cover the standard bubbles styles (line, dot,
 * minidot, meter, points, pulse, globe).
 *
 * Mirrors charmbracelet/bubbles spinner.Spinner.
 */
final class Style
{
    /** @param list<string> $frames */
    public function __construct(
        public readonly array $frames,
        public readonly float $fps = 10.0,
    ) {
        if ($frames === []) {
            throw new \InvalidArgumentException(Lang::t('spinner.empty_frames'));
        }
        if ($fps <= 0.0) {
            throw new \InvalidArgumentException(Lang::t('spinner.fps_positive'));
        }
    }

    public static function line(): self    { return new self(['|', '/', '-', '\\'],  10.0); }
    public static function dot(): self     { return new self(['⠋','⠙','⠹','⠸','⠼','⠴','⠦','⠧','⠇','⠏'], 10.0); }
    public static function miniDot(): self { return new self(['⠁','⠂','⠄','⡀','⢀','⠠','⠐','⠈'], 12.0); }
    public static function points(): self  { return new self(['∙∙∙','●∙∙','∙●∙','∙∙●'], 7.0); }
    public static function pulse(): self   { return new self(['█','▓','▒','░'], 8.0); }
    public static function globe(): self   { return new self(['🌍','🌎','🌏'], 4.0); }
    public static function meter(): self   { return new self(['▱▱▱','▰▱▱','▰▰▱','▰▰▰','▰▰▱','▰▱▱'], 7.0); }

    /** Bouncing dot: ⢄ ⢂ ⢁ ⡁ ⡈ ⡐ ⡠ — used by Bubbles' `Jump`. */
    public static function jump(): self
    {
        return new self(['⢄','⢂','⢁','⡁','⡈','⡐','⡠'], 7.0);
    }

    /** Moon-phase rotation: 🌑 🌒 🌓 🌔 🌕 🌖 🌗 🌘 — Bubbles' `Moon`. */
    public static function moon(): self
    {
        return new self(['🌑','🌒','🌓','🌔','🌕','🌖','🌗','🌘'], 8.0);
    }

    /** Walking monkey: 🙈 🙉 🙊 — Bubbles' `Monkey`. */
    public static function monkey(): self
    {
        return new self(['🙈','🙉','🙊'], 3.0);
    }

    /** Sliding hamburger bars: ☱ ☲ ☴ ☲ — Bubbles' `Hamburger`. */
    public static function hamburger(): self
    {
        return new self(['☱','☲','☴','☲'], 3.0);
    }

    /** Animated dots: ` `, `.`, `..`, `...` — Bubbles' `Ellipsis`. */
    public static function ellipsis(): self
    {
        return new self(['','.','..','...'], 3.0);
    }

    /**
     * Returns a list of all available spinner style names.
     *
     * @return list<string>
     */
    public static function catalog(): array
    {
        return ['line', 'dot', 'miniDot', 'points', 'pulse', 'globe', 'meter', 'jump', 'moon', 'monkey', 'hamburger', 'ellipsis'];
    }

    public function interval(): float
    {
        return 1.0 / $this->fps;
    }
}
