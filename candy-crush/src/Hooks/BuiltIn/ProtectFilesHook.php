<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks\BuiltIn;

use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookResult;

final readonly class ProtectFilesHook implements HookInterface
{
    private const PROTECTED_PATTERNS = [
        '/\.env$/',
        '/composer\.json$/',
        '/composer\.lock$/',
        '/\.git\/config$/',
        '/config\/.*\.php$/',
    ];

    public function name(): string
    {
        return 'protect-files';
    }

    public function event(): HookEvent
    {
        return HookEvent::PreToolUse;
    }

    public function matcher(): string
    {
        return '^(bash|Edit)$';
    }

    public function execute(HookContext $context): HookResult
    {
        $input = $context->toolInput;

        foreach (self::PROTECTED_PATTERNS as $pattern) {
            if (preg_match($pattern, $input)) {
                return HookResult::deny(
                    "This hook prevents modification of files matching: $pattern"
                );
            }
        }

        return HookResult::allow();
    }
}
