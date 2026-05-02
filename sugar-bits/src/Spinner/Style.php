<?php

declare(strict_types=1);

namespace CandyCore\Bits\Spinner;

/**
 * A spinner style: an ordered list of frames and a per-frame interval.
 *
 * Built-in factories cover the standard bubbles styles (line, dot,
 * minidot, meter, points, pulse, globe).
 */
final class Style
{
    /** @param list<string> $frames */
    public function __construct(
        public readonly array $frames,
        public readonly float $fps = 10.0,
    ) {
        if ($frames === []) {
            throw new \InvalidArgumentException('spinner style needs at least one frame');
        }
        if ($fps <= 0.0) {
            throw new \InvalidArgumentException('spinner fps must be > 0');
        }
    }

    public static function line(): self    { return new self(['|', '/', '-', '\\'],  10.0); }
    public static function dot(): self     { return new self(['⠋','⠙','⠹','⠸','⠼','⠴','⠦','⠧','⠇','⠏'], 10.0); }
    public static function miniDot(): self { return new self(['⠁','⠂','⠄','⡀','⢀','⠠','⠐','⠈'], 12.0); }
    public static function points(): self  { return new self(['∙∙∙','●∙∙','∙●∙','∙∙●'], 7.0); }
    public static function pulse(): self   { return new self(['█','▓','▒','░'], 8.0); }
    public static function globe(): self   { return new self(['🌍','🌎','🌏'], 4.0); }
    public static function meter(): self   { return new self(['▱▱▱','▰▱▱','▰▰▱','▰▰▰','▰▰▱','▰▱▱'], 7.0); }

    public function interval(): float
    {
        return 1.0 / $this->fps;
    }
}
