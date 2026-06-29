<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\BuiltIn\ConfirmRemoveHook;
use SugarCraft\Crush\Hooks\BuiltIn\ProtectFilesHook;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Tools\BuiltIn\Bash;
use SugarCraft\Crush\Tools\BuiltIn\Edit;

/**
 * @see HookManager
 */
final class HookGateE2ETest extends TestCase
{
    public function testConfirmRemoveHookDeniesBashRmRf(): void
    {
        $manager = new HookManager(new HookRegistry());
        $manager->registerBuiltIns();

        $context = new HookContext(
            sessionId: 'test-session-e2e',
            toolName: (new Bash())->name(),
            toolArgs: ['command' => 'rm -rf /'],
            toolInput: json_encode(['command' => 'rm -rf /']),
            toolOutput: '',
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: '/tmp/test-project',
        );

        $result = $manager->preToolUse($context);

        $this->assertTrue($result->isDenied());
        $this->assertStringContainsString('recursive', $result->message);
    }

    public function testProtectFilesHookDeniesEditEnv(): void
    {
        $manager = new HookManager(new HookRegistry());
        $manager->registerBuiltIns();

        $context = new HookContext(
            sessionId: 'test-session-e2e',
            toolName: (new Edit())->name(),
            toolArgs: ['file_path' => '.env', 'old_string' => 'foo', 'new_string' => 'bar'],
            toolInput: json_encode(['file_path' => '.env']),
            toolOutput: '',
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: '/tmp/test-project',
        );

        $result = $manager->preToolUse($context);

        $this->assertTrue($result->isDenied());
        $this->assertStringContainsString('.env', $result->message);
    }

    public function testProtectFilesHookAllowsEditSrcFile(): void
    {
        $manager = new HookManager(new HookRegistry());
        $manager->registerBuiltIns();

        $context = new HookContext(
            sessionId: 'test-session-e2e',
            toolName: (new Edit())->name(),
            toolArgs: ['file_path' => 'src/Foo.php', 'old_string' => 'foo', 'new_string' => 'bar'],
            toolInput: json_encode(['file_path' => 'src/Foo.php']),
            toolOutput: '',
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: '/tmp/test-project',
        );

        $result = $manager->preToolUse($context);

        $this->assertTrue($result->isAllowed());
    }

    public function testConfirmRemoveHookAllowsBenignBash(): void
    {
        $manager = new HookManager(new HookRegistry());
        $manager->registerBuiltIns();

        $context = new HookContext(
            sessionId: 'test-session-e2e',
            toolName: (new Bash())->name(),
            toolArgs: ['command' => 'ls -la'],
            toolInput: json_encode(['command' => 'ls -la']),
            toolOutput: '',
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: '/tmp/test-project',
        );

        $result = $manager->preToolUse($context);

        $this->assertTrue($result->isAllowed());
    }
}
