<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Context\RulesState;
use SugarCraft\Crush\Context\SessionPromptMemo;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tests\Prompt\PromptFixture;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * {@see SessionPromptMemo} (step 1.A-1): the cache itself, and the freshness
 * policy it gives {@see Runtime}'s PerSession layers — read once per
 * session, frozen until forgotten, rebuilt when a keyed input changes.
 */
final class SessionPromptMemoTest extends TestCase
{
    use HomeSandboxTrait;

    /** @var list<PromptFixture> */
    private array $fixtures = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $fixture) {
            $fixture->destroy();
        }
        $this->restoreHomeSandbox();
    }

    public function testRememberBuildsOncePerSessionAndSlot(): void
    {
        $memo = SessionPromptMemo::new();
        $builds = 0;
        $build = static function () use (&$builds): string {
            return 'v' . ++$builds;
        };

        $this->assertSame('v1', $memo->remember('s1', 'slot', $build));
        $this->assertSame('v1', $memo->remember('s1', 'slot', $build));
        $this->assertSame('v2', $memo->remember('s1', 'other', $build), 'a different slot builds');
        $this->assertSame('v3', $memo->remember('s2', 'slot', $build), 'a different session builds');
        $this->assertSame('v4', $memo->remember(null, 'slot', $build));
        $this->assertSame('v4', $memo->remember(null, 'slot', $build), 'a null session id is a session of its own');
        $this->assertTrue($memo->has('s1', 'slot'));
        $this->assertFalse($memo->has('s3', 'slot'));
    }

    public function testForgetIsTheRefreshPoint(): void
    {
        $memo = SessionPromptMemo::new();
        $n = 0;
        $build = static function () use (&$n): int {
            return ++$n;
        };

        $memo->remember('s1', 'a', $build);
        $memo->remember('s2', 'a', $build);
        $memo->forget('s1');

        $this->assertFalse($memo->has('s1', 'a'));
        $this->assertTrue($memo->has('s2', 'a'), 'forgetting one session leaves the others');
        $this->assertSame(3, $memo->remember('s1', 'a', $build));

        $memo->forgetAll();
        $this->assertSame([], $memo->sessions());
    }

    public function testSessionsAreEvictedLeastRecentlyUsedFirst(): void
    {
        $memo = SessionPromptMemo::new();
        for ($i = 0; $i < SessionPromptMemo::MAX_SESSIONS; $i++) {
            $memo->remember("s{$i}", 'a', static fn (): int => 1);
        }
        $memo->remember('s0', 'a', static fn (): int => 1); // touch: s0 is now the most recent
        $memo->remember('new', 'a', static fn (): int => 1);

        $this->assertCount(SessionPromptMemo::MAX_SESSIONS, $memo->sessions());
        $this->assertFalse($memo->has('s1', 'a'), 's1 was the least recently used');
        $this->assertTrue($memo->has('s0', 'a'));
        $this->assertSame('new', $memo->sessions()[SessionPromptMemo::MAX_SESSIONS - 1]);
    }

    public function testSlotsPerSessionAreBounded(): void
    {
        $memo = SessionPromptMemo::new();
        for ($i = 0; $i <= SessionPromptMemo::MAX_SLOTS_PER_SESSION; $i++) {
            $memo->remember('s', "slot{$i}", static fn (): int => 1);
        }

        $this->assertFalse($memo->has('s', 'slot0'), 'the oldest slot went first');
        $this->assertTrue($memo->has('s', 'slot' . SessionPromptMemo::MAX_SLOTS_PER_SESSION));
    }

    /**
     * THE POLICY, driven through the real assembler: two Runtimes — two turns
     * — sharing one memo assemble a byte-identical system prompt even though
     * CLAUDE.md and the repo map's source tree moved between them; a Runtime
     * without the memo (the pre-1.A-1 per-turn behaviour) sees every change.
     */
    public function testTwoTurnsSharingAMemoKeepEveryPerSessionLayerFrozen(): void
    {
        $fixture = $this->fixture();
        $fixture->write('CLAUDE.md', 'ORIGINAL INSTRUCTION');
        $fixture->write('composer.json', '{"autoload":{"psr-4":{"Fx\\\\":"src/"}}}');
        $fixture->write('src/A.php', '<?php');
        $app = $fixture->app()->withSessionId('session-1');
        $memo = SessionPromptMemo::new();

        // No injected EnvironmentBlock: an injected one is its owner's
        // session snapshot and bypasses the memo by design, so the turns
        // here capture through the memo like EngineBackend's Runtimes do.
        $first = $fixture->systemPrompt($app, $this->bareRuntime($app)->withSessionPromptMemo($memo));
        $this->assertStringContainsString('ORIGINAL INSTRUCTION', $first);
        $this->assertStringContainsString('(1 files)', $first, 'the repo map counted the one source file');

        $fixture->write('CLAUDE.md', 'EDITED INSTRUCTION');
        $fixture->write('src/B.php', '<?php');

        // A new turn: fresh Runtime, same memo.
        $second = $fixture->systemPrompt($app, $this->bareRuntime($app)->withSessionPromptMemo($memo));
        $this->assertSame($first, $second, 'the session froze instructions, repo map and environment at its first build');

        // Without the memo a turn re-reads — given a loader that has not
        // cached the documents itself (a forked turn child's copy never has:
        // the parent's loader is never warmed), modelled by a fresh App.
        $fresh = $fixture->app()->withSessionId('session-1');
        $unshared = $fixture->systemPrompt($fresh, $this->bareRuntime($fresh));
        $this->assertStringContainsString('EDITED INSTRUCTION', $unshared, 'without the memo every turn re-reads');
        $this->assertStringContainsString('(2 files)', $unshared);

        // The memo alone freezes the repo map; forget() is the refresh point.
        $this->assertStringContainsString('(1 files)', $fixture->systemPrompt($app, $this->bareRuntime($app)->withSessionPromptMemo($memo)));
        $memo->forget('session-1');
        $refreshed = $fixture->systemPrompt($fresh, $this->bareRuntime($fresh)->withSessionPromptMemo($memo));
        $this->assertStringContainsString('EDITED INSTRUCTION', $refreshed);
        $this->assertStringContainsString('(2 files)', $refreshed);

        // Another session id is another session.
        $other = $fixture->systemPrompt(
            $app->withSessionId('session-2'),
            $this->bareRuntime($app)->withSessionPromptMemo($memo),
        );
        $this->assertStringContainsString('(2 files)', $other);
    }

    public function testARulesToggleRebuildsTheStandingSlabMidSession(): void
    {
        $home = sys_get_temp_dir() . '/memo-home-' . bin2hex(random_bytes(4));
        mkdir($home . '/.sugar-crush/rulebooks', 0o700, true);
        file_put_contents($home . '/.sugar-crush/rulebooks/pack.md', "---\nname: pack\n---\nPACK RULE BODY\n");
        $this->useHomeSandbox($home);

        $fixture = $this->fixture();
        $state = new RulesState();
        $app = $fixture->app()->withSessionId('s')->withRulesState($state);
        $memo = SessionPromptMemo::new();

        $on = $fixture->systemPrompt($app, $this->runtime($app)->withSessionPromptMemo($memo));
        $this->assertStringContainsString('PACK RULE BODY', $on);

        $state->toggle('pack');
        $off = $fixture->systemPrompt($app, $this->runtime($app)->withSessionPromptMemo($memo));
        $this->assertStringNotContainsString('PACK RULE BODY', $off, 'the toggle set is part of the slot key');

        exec('rm -rf ' . escapeshellarg($home) . ' 2>&1');
    }

    public function testAModelSwitchRecapturesTheStaticEnvironment(): void
    {
        $fixture = $this->fixture();
        $memo = SessionPromptMemo::new();
        $provider = $fixture->app()->provider;
        $runtime = static fn (): Runtime => (new Runtime($provider, new HookManager(new HookRegistry())))->withSessionPromptMemo($memo);

        $a = $fixture->systemPrompt($fixture->app()->withSessionId('s'), $runtime());
        $b = $fixture->systemPrompt(
            App::new($provider, 'other-model')->withRoot($fixture->root())->withSessionId('s'),
            $runtime(),
        );

        $this->assertStringContainsString("\nModel: test-model\n", $a);
        $this->assertStringContainsString("\nModel: other-model\n", $b);
    }

    public function testWithSessionPromptMemoReturnsACopy(): void
    {
        $app = $this->fixture()->app();
        $runtime = $this->runtime($app);

        $this->assertNotSame($runtime, $runtime->withSessionPromptMemo(SessionPromptMemo::new()));
    }

    private function fixture(): PromptFixture
    {
        $fixture = new PromptFixture();
        $this->fixtures[] = $fixture;

        return $fixture;
    }

    private function bareRuntime(App $app): Runtime
    {
        return new Runtime($app->provider, new HookManager(new HookRegistry()));
    }

    private function runtime(App $app, string $now = '2026-01-15 12:00:00'): Runtime
    {
        return new Runtime(
            $app->provider,
            new HookManager(new HookRegistry()),
            new \SugarCraft\Crush\Context\EnvironmentBlock((string) $app->root, $app->model, new DateTimeImmutable($now), 'linux'),
        );
    }
}
