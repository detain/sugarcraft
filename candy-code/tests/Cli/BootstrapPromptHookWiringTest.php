<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Hooks\HookConfig;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Hooks\PromptHook;
use SugarCraft\Code\Message;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-EXT-6.d: a `type: prompt` hook is only as good as the backend it is asked
 * on, and the manager cannot conjure one — `Bootstrap::hooks()` installs the
 * title backend BEFORE the files load. These are the three legs of that seam:
 * the launch really feeds the backend into the hooks it loads, a launch with
 * no provider still loads the hook and answers through its own loud door
 * rather than crashing, and the install-before-the-load ORDER is the law, not
 * a nicety (a setter that ran after the load would build a chain of
 * backend-less hooks out of an installable launch).
 */
final class BootstrapPromptHookWiringTest extends TestCase
{
    use HomeSandboxTrait;

    /** Every input this family reads; cleared in setUp, restored in tearDown. */
    private const MANAGED_ENV = [
        'CANDY_CODE_PROVIDER',
        'CANDY_CODE_MODEL',
        'CANDY_CODE_TITLE_MODEL',
        'CANDY_CODE_SUMMARY_MODEL',
        'CANDY_CODE_BACKEND_CMD',
        'CANDY_CODE_BACKEND_STREAM',
        'ANTHROPIC_API_KEY',
    ];

    private string $wiringHome = '';

    /** @var array<string, string|false> */
    private array $envSnapshot = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Snapshot FIRST, then clear, then RE-ARM: the lane-cf contract — the
        // trait captures HOME on its first call, and HOME is in its key set.
        $dir = sys_get_temp_dir() . '/c6d-wire-home-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $this->wiringHome = $this->useHomeSandbox($dir);

        foreach (self::MANAGED_ENV as $key) {
            $this->envSnapshot[$key] = getenv($key);
            putenv($key);
        }
        $this->useHomeSandbox($this->wiringHome);

        mkdir($this->wiringHome . '/.candy-code', 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (self::MANAGED_ENV as $key) {
            $value = $this->envSnapshot[$key] ?? false;
            if ($value === false || $value === '') {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }
        $this->restoreHomeSandbox();
        $this->removeTree($this->wiringHome);

        parent::tearDown();
    }

    public function testALaunchFeedsTheTitleBackendIntoThePromptHookItLoads(): void
    {
        // The anthropic tier with a dummy key constructs offline (the
        // TitleBackendAuxiliaryModelTest idiom); no titleModel is set, so the
        // hook lands on the cheap auxiliary default.
        putenv('CANDY_CODE_PROVIDER=anthropic');
        putenv('ANTHROPIC_API_KEY=sk-test');
        $this->writePromptHookFile();

        $chain = Bootstrap::hooks();
        $hook = $chain->hook('PreToolUse', 'guard-ask');

        $this->assertInstanceOf(PromptHook::class, $hook);
        $this->assertSame('Is this destructive? $ARGUMENTS', $hook->prompt);
        $this->assertInstanceOf(
            EngineBackend::class,
            $hook->backend,
            'the launch built no title backend into the prompt hook — deleting the withPromptBackend install from Bootstrap::hooks() lands here',
        );
    }

    public function testAProviderlessLaunchStillLoadsThePromptHookAndItAnswersLoudly(): void
    {
        // No provider anywhere: titleBackend() is null, the hook still
        // registers, and executing it DENIES natively through its own door
        // instead of ever calling out (the silence-is-not-approval law).
        $this->writePromptHookFile();

        $chain = Bootstrap::hooks();
        $hook = $chain->hook('PreToolUse', 'guard-ask');

        $this->assertInstanceOf(PromptHook::class, $hook);
        $this->assertNull($hook->backend, 'no provider configured means no title backend, not a crash at load');

        $result = $hook->execute(new \SugarCraft\Code\Hooks\HookContext(
            sessionId: 'wiring-session',
            toolName: 'Bash',
            toolArgs: ['command' => 'ls'],
            toolInput: (string) json_encode(['command' => 'ls']),
            toolOutput: '',
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: $this->wiringHome,
        ));

        $this->assertTrue($result->isDenied());
        $this->assertStringContainsString('did not answer', $result->message);
        $this->assertStringContainsString('no title backend', $result->message);
    }

    public function testTheSetterRunsBeforeTheLoadAndNeverOnAStaleClone(): void
    {
        $row = HookConfig::parse(
            "hooks:\n  PreToolUse:\n    - name: first\n      type: prompt\n      prompt: why\n"
            . "    - name: late\n      type: prompt\n      prompt: why\n",
            'wiring-test.yaml',
        );

        // Installed AFTER the first load: the hook already in the chain keeps
        // no backend (the door answers loudly) while the install-before-load
        // law is what test 1 pins for real launches.
        $late = new HookManager(new HookRegistry());
        $late->loadEntries([$row[0]], 'wiring-test.yaml');
        $this->assertNull($late->hook('PreToolUse', 'first')->backend);

        $early = new HookManager(new HookRegistry());
        $backend = new class implements Backend {
            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant((string) json_encode(['ok' => true]));
            }

            public function completeAsync(
                array $history,
                ?callable $onToken = null,
                ?CancellationToken $cancellation = null,
                ?callable $onEvent = null,
            ): PromiseInterface {
                return \React\Promise\resolve($this->complete($history));
            }
        };

        $this->assertSame(
            $early,
            $early->withPromptBackend($backend),
            'the setter is the mutating chain-owner it names itself — a returned clone would orphan every registration made on the original',
        );
        $early->loadEntries([$row[1]], 'wiring-test.yaml');
        $this->assertSame($backend, $early->hook('PreToolUse', 'late')->backend);
    }

    private function writePromptHookFile(): void
    {
        file_put_contents(
            $this->wiringHome . '/.candy-code/hooks.yaml',
            "hooks:\n  PreToolUse:\n    - name: guard-ask\n      type: prompt\n      matcher: Bash\n"
            . "      prompt: Is this destructive? \$ARGUMENTS\n",
        );
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
