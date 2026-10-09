<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\MediaForm;

use SugarCraft\Crush\App\Msg;
use SugarCraft\Crush\Media\MediaRequest;

/**
 * "Open the media generation form" — the message a driver sends the shell
 * when the operator asks to generate without typing flags. Mirrors
 * {@see \SugarCraft\Crush\Tui\Settings\OpenSettingsMsg}: the command decides,
 * the shell owns the slot, and an optional draft seeds the form (W4's PNG
 * import will fill it; W3.1's bare `/generate` sends it empty).
 */
final readonly class OpenMediaFormMsg implements Msg
{
    public function __construct(public ?MediaRequest $request = null)
    {
    }
}
