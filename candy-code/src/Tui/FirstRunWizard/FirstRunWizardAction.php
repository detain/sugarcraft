<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\FirstRunWizard;

/**
 * The action object the host consumes: kind plus, for the kinds that decide
 * values, the provider the operator stood on and (for
 * {@see FirstRunWizardActionKind::Finish}) the masked-typed key itself.
 *
 * The key rides here and nowhere else: the wizard never touches a store, and
 * the host hands the pair to {@see \SugarCraft\Code\Config\ProviderKeyStore}
 * when — and only when — it decides to persist.
 */
final class FirstRunWizardAction
{
    private function __construct(
        public readonly FirstRunWizardActionKind $kind,
        public readonly ?string $provider,
        public readonly ?string $key,
    ) {
    }

    public static function finish(string $provider, string $key): self
    {
        return new self(FirstRunWizardActionKind::Finish, $provider, $key);
    }

    /** Plain skip carries no provider; the login deferral carries one. */
    public static function skip(?string $provider = null): self
    {
        return new self(FirstRunWizardActionKind::Skip, $provider, null);
    }

    public static function cancel(): self
    {
        return new self(FirstRunWizardActionKind::Cancel, null, null);
    }
}
