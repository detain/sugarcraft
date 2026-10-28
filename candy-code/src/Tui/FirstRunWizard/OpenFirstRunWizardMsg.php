<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\FirstRunWizard;

use SugarCraft\Code\App\Msg;

/**
 * "Open the first-run wizard" — the message a driver sends the shell when the
 * launch decided the operator needs a provider (or the operator asks for the
 * setup flow by name). Mirrors {@see \SugarCraft\Code\Tui\MediaForm\OpenMediaFormMsg}:
 * the command decides, the shell owns the slot. There is no payload to seed —
 * the wizard opens exactly the same way every time, and the current provider
 * is deliberately absent: {@see FirstRunWizard::shouldOffer()} already
 * refused launches that have one.
 *
 * Emitted by the wiring step (C-TUI-5.e); this step ships the door only.
 */
final readonly class OpenFirstRunWizardMsg implements Msg
{
}
