<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Hooks\BuiltIn\ConfirmRemoveHook;
use SugarCraft\Code\Hooks\BuiltIn\ProtectFilesHook;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\HookInterface;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Hooks\ScriptHook;
use SugarCraft\Code\Tools\BuiltIn\Bash;
use SugarCraft\Code\Tools\BuiltIn\Edit;

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

    /**
     * FU6, the producer side of the operator claim: a `ScriptHook` created the way
     * a config entry is created — NO `event` key, so {@see ScriptHook::fromConfig()}
     * defaults it to `PreToolUse` — that prints to stdout and exits 0. Its bytes must
     * come back as the verdict's `additionalContext`, which is exactly what both
     * gates now carry to their result slots (RuntimeTest / ChatTest prove the
     * consumer half). Before the collection fix the note died here at the registry
     * boundary; the gate consumers had nothing to consume.
     */
    public function testADefaultEventScriptHookNoteSurvivesThePreChain(): void
    {
        $script = sys_get_temp_dir() . '/crush-fu6-e2e-' . uniqid('', true) . '.sh';
        file_put_contents($script, "#!/bin/sh\nprintf 'e2e_collected_note'\n");
        chmod($script, 0o755);

        try {
            $hook = ScriptHook::fromConfig(['command' => $script, 'name' => 'fu6-e2e']);
            $this->assertSame(HookEvent::PreToolUse, $hook->event());

            $registry = new HookRegistry();
            $registry->register($hook);
            $manager = new HookManager($registry);

            $result = $manager->preToolUse(new HookContext(
                sessionId: 'test-session-e2e',
                toolName: (new Bash())->name(),
                toolArgs: ['command' => 'ls -la'],
                toolInput: json_encode(['command' => 'ls -la']),
                toolOutput: '',
                model: 'test-model',
                provider: 'test-provider',
                projectRoot: '/tmp/test-project',
            ));

            $this->assertTrue($result->isAllowed());
            $this->assertSame('e2e_collected_note', $result->additionalContext);
        } finally {
            @unlink($script);
        }
    }

    /**
     * The deny polarity of the same chain, pinned AT THE REGISTRY SEAM: a DENY is
     * returned VERBATIM — its own `additionalContext` field still set — but every
     * note collected before it is dropped on the floor ({@see HookRegistry::scan()}
     * hands back an empty collection tuple for any non-permitting verdict). That is
     * precisely why the two gate consumers must pin their deny arms to an empty
     * note slot: the field surviving here proves the drop is the CONSUMER's job,
     * which RuntimeTest and ChatTest each pin on their own live path.
     */
    public function testADenyingChainDropsNotesCollectedBeforeTheVerdict(): void
    {
        $registry = new HookRegistry();
        $registry->register(new class implements HookInterface {
            public function name(): string
            {
                return 'fu6-e2e-noter';
            }

            public function event(): HookEvent
            {
                return HookEvent::PreToolUse;
            }

            public function matcher(): string
            {
                return '.*';
            }

            public function execute(HookContext $context): HookResult
            {
                return HookResult::allow('', 'note_before_deny');
            }
        });
        $registry->register(new class implements HookInterface {
            public function name(): string
            {
                return 'fu6-e2e-denier';
            }

            public function event(): HookEvent
            {
                return HookEvent::PreToolUse;
            }

            public function matcher(): string
            {
                return '.*';
            }

            public function execute(HookContext $context): HookResult
            {
                return HookResult::deny('no', 'deny_own_note');
            }
        });

        $result = (new HookManager($registry))->preToolUse(new HookContext(
            sessionId: 'test-session-e2e',
            toolName: (new Bash())->name(),
            toolArgs: ['command' => 'ls -la'],
            toolInput: json_encode(['command' => 'ls -la']),
            toolOutput: '',
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: '/tmp/test-project',
        ));

        $this->assertTrue($result->isDenied());
        $this->assertSame('deny_own_note', $result->additionalContext, 'a DENY verdict is returned verbatim — the field belongs to the verdict');
        $this->assertSame(0, substr_count($result->additionalContext, 'note_before_deny'), 'the chain collection before a DENY is dropped at the registry boundary');
    }
}
