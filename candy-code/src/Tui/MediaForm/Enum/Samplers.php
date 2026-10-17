<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\MediaForm\Enum;

/**
 * The stock-A1111 sampler roster (crush-media W3.4, plan §2.9 verbatim:
 * k-diffusion 1–15, timesteps/CompVis 16–19, LCM 20, in upstream file
 * order). Case VALUES are the displayed dropdown labels because
 * `Select::withEnum()` resolves the selection through
 * `EnumClass::from(selectedTitle)` — the label IS the wire `sampler_name`.
 *
 * Runtime truth beats this list when a server is reachable: the form's
 * choice feed (§4.4 `GET /samplers`) replaces these options wholesale at
 * open ({@see \SugarCraft\Code\Tui\MediaForm\ChoiceFeed}); the enum stays
 * as the offline default set and the fail-open floor.
 */
enum Samplers: string
{
    case DPMPP_2M = 'DPM++ 2M';
    case DPMPP_SDE = 'DPM++ SDE';
    case DPMPP_2M_SDE = 'DPM++ 2M SDE';
    case DPMPP_2M_SDE_HEUN = 'DPM++ 2M SDE Heun';
    case DPMPP_2S_A = 'DPM++ 2S a';
    case DPMPP_3M_SDE = 'DPM++ 3M SDE';
    case EULER_A = 'Euler a';
    case EULER = 'Euler';
    case LMS = 'LMS';
    case HEUN = 'Heun';
    case DPM2 = 'DPM2';
    case DPM2_A = 'DPM2 a';
    case DPM_FAST = 'DPM fast';
    case DPM_ADAPTIVE = 'DPM adaptive';
    case RESTART = 'Restart';
    case DDIM = 'DDIM';
    case DDIM_CFGPP = 'DDIM CFG++';
    case PLMS = 'PLMS';
    case UNIPC = 'UniPC';
    case LCM = 'LCM';

    /** @return list<string> the labels in §2.9 display order */
    public static function names(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** §2.1: the dropdown lands on plain Euler. */
    public static function defaultName(): string
    {
        return self::EULER->value;
    }

    public static function knows(string $label): bool
    {
        return self::tryFrom($label) !== null;
    }
}
