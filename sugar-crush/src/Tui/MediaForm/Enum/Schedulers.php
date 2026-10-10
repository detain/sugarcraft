<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\MediaForm\Enum;

/**
 * The stock-A1111 sigma-schedule roster (crush-media W3.4, plan §2.1
 * "Schedule type" row and §2.9 verbatim, in dropdown order). Case VALUES
 * are the displayed labels and the wire `scheduler` value — the enum-mode
 * Select resolves through `EnumClass::from(selectedTitle)`.
 *
 * Pairing law (§2.9): "Automatic" defers to each sampler's own default
 * schedule; the legacy `"<sampler> <scheduler>"` combo labels are split on
 * collect by the form, the `fix_p_invalid_sampler_and_scheduler` analog.
 *
 * Runtime truth beats this list when a server answers `GET /schedulers`
 * ({@see \SugarCraft\Crush\Tui\MediaForm\ChoiceFeed}); the enum is the
 * offline default set and the fail-open floor.
 */
enum Schedulers: string
{
    case AUTOMATIC = 'Automatic';
    case UNIFORM = 'Uniform';
    case KARRAS = 'Karras';
    case EXPONENTIAL = 'Exponential';
    case POLYEXPONENTIAL = 'Polyexponential';
    case SGM_UNIFORM = 'SGM Uniform';
    case KL_OPTIMAL = 'KL Optimal';
    case ALIGN_YOUR_STEPS = 'Align Your Steps';
    case SIMPLE = 'Simple';
    case NORMAL = 'Normal';
    case DDIM = 'DDIM';
    case BETA = 'Beta';

    /** @return list<string> the labels in §2.1 dropdown order */
    public static function names(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** §2.1: the dropdown lands on Automatic. */
    public static function defaultName(): string
    {
        return self::AUTOMATIC->value;
    }

    public static function knows(string $label): bool
    {
        return self::tryFrom($label) !== null;
    }
}
