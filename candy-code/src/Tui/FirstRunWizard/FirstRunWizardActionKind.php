<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\FirstRunWizard;

/**
 * What the operator decided in the first-run wizard — the wizard itself is
 * I/O-free by construction (same law as the
 * {@see \SugarCraft\Code\Tui\MediaForm\MediaForm} and
 * {@see \SugarCraft\Code\Tui\DirectoryPicker\DirectoryPicker} widgets it
 * mirrors): the model returns an action object, and the host executes the
 * consequence.
 */
enum FirstRunWizardActionKind: string
{
    /** `Enter` on a non-empty key at step 2: the host persists provider +
        key through {@see \SugarCraft\Code\Config\ProviderKeyStore::store()}. */
    case Finish = 'finish';

    /** Close without storing. A null payload means a plain skip; a provider
        means the operator chose the `candy-code login <provider>` deferral,
        and the host echoes that command as the next step. */
    case Skip = 'skip';

    /** `Esc`: throw the wizard away. Nothing decided, nothing stored. */
    case Cancel = 'cancel';
}
