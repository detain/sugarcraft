<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks\BuiltIn;

use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookResult;

final readonly class AuditHook implements HookInterface
{
    private string $logFile;

    public function __construct(?string $logFile = null)
    {
        $this->logFile = $logFile ?? sys_get_temp_dir() . '/sugar-crush-audit.log';
    }

    public function name(): string
    {
        return 'audit';
    }

    public function event(): HookEvent
    {
        return HookEvent::PostToolUse;
    }

    public function matcher(): string
    {
        return '.*';
    }

    public function execute(HookContext $context): HookResult
    {
        $entry = sprintf(
            "[%s] %s %s %s => %s\n",
            date('Y-m-d H:i:s'),
            $context->sessionId,
            $context->toolName,
            $context->toolInput,
            substr($context->toolOutput, 0, 200)
        );

        file_put_contents($this->logFile, $entry, FILE_APPEND | LOCK_EX);

        return HookResult::allow();
    }
}
