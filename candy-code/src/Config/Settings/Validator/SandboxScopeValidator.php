<?php

declare(strict_types=1);

namespace SugarCraft\Code\Config\Settings\Validator;

use SugarCraft\Code\Config\Settings\SettingValidator;
use SugarCraft\Code\Tools\Sandbox\SandboxScope;

/**
 * The `sandboxScope` vocabulary: every entry must name a known scope.
 *
 * This is the audit half of the strictest-read rule, NOT its enforcement
 * point: {@see SandboxScope::requests()} reads an unknown entry as EVERY
 * scope, so a typo jails more than intended rather than less, and refusing
 * the write would only remove the notice. What the validator does is name
 * the surprise — a `settings keys` audit row, a refused write dialog line —
 * so `["hoks"]` cannot spend a whole session silently confining every
 * runner behind the operator's back. The shape itself (list of non-empty
 * strings) is already held by the type-implied
 * {@see GlobListValidator::new()}; this adds the vocabulary leg.
 */
final class SandboxScopeValidator implements SettingValidator
{
    private function __construct()
    {
    }

    public static function new(): self
    {
        return new self();
    }

    public function validate(mixed $value, array $all = []): ?string
    {
        if ($value === null || !\is_array($value)) {
            // Unset and unusable shapes are the shape validator's ground;
            // there is no vocabulary to check against them here.
            return null;
        }

        foreach ($value as $entry) {
            if (!\is_string($entry)) {
                continue;
            }

            $name = strtolower(trim($entry));
            if ($name !== '' && !\in_array($name, SandboxScope::SCOPES, true)) {
                return sprintf(
                    "'%s' is not a sandbox scope (%s) — an unknown entry jails every scope",
                    $entry,
                    implode(', ', SandboxScope::SCOPES),
                );
            }
        }

        return null;
    }
}
