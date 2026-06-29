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
        '/(^|[\s\/])\.env(\s|$)/',
        '/composer\.json\b/',
        '/composer\.lock\b/',
        '/\.git\/config\b/',
        '/(^|\/)config\/[^\s]*\.php\b/',
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
        return '^(Bash|Edit|Write|Read)$';
    }

    public function execute(HookContext $context): HookResult
    {
        $toolName = ucfirst(strtolower($context->toolName));
        $input = match ($toolName) {
            'Bash' => $context->toolArgs['command'] ?? '',
            'Edit', 'Write', 'Read' => $context->toolArgs['file_path'] ?? '',
            default => $context->toolInput,
        };

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
