<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Hooks\BoundedHookInterface;
use SugarCraft\Crush\Hooks\BuiltIn\AutoCommitHook;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Support\ProcessContainment;
use SugarCraft\Crush\Tests\Support\CheckpointedRepoTrait;
use SugarCraft\Crush\Workspace\AutoCommitter;

/**
 * Step 3.G, `autoCommit: edit`: the built-in `PostToolUse` hook commits each
 * `Write`/`Edit` as it lands, with the call's own description as the subject,
 * tells the model the commit it made — and never refuses.
 *
 * @see AutoCommitHook
 */
final class AutoCommitHookTest extends TestCase
{
    use CheckpointedRepoTrait;

    protected function setUp(): void
    {
        $this->setUpCheckpointedRepo();
    }

    protected function tearDown(): void
    {
        $this->tearDownCheckpointedRepo();
    }

    public function testItIsABoundedPostToolUseHookOnTheTwoEditingTools(): void
    {
        $hook = $this->hook();

        self::assertSame('auto-commit', $hook->name());
        self::assertSame(HookEvent::PostToolUse, $hook->event());
        self::assertInstanceOf(BoundedHookInterface::class, $hook);
        self::assertSame(AutoCommitter::COMMIT_TIMEOUT_SECONDS, $hook->timeoutSeconds());
        self::assertSame(3.0, $hook->withTimeoutSeconds(3.0)->timeoutSeconds());
        foreach (['Write' => 1, 'Edit' => 1, 'Bash' => 0, 'Read' => 0] as $tool => $matches) {
            self::assertSame($matches, preg_match('/' . $hook->matcher() . '/', $tool), "matcher vs {$tool}");
        }
    }

    public function testAnEditIsCommittedWithItsDescriptionAndTheModelIsTold(): void
    {
        file_put_contents($this->repo . '/file.txt', "the user's\n");
        $this->turnCheckpoint([], 'prompt');
        file_put_contents($this->repo . '/file.txt', "the user's\nthe model's\n");

        $result = $this->hook()->execute($this->context('file.txt', 'Append the model line'));

        self::assertTrue($result->isAllowed());
        self::assertSame(
            ['chore: append the model line', AutoCommitter::SNAPSHOT_SUBJECT, 'base'],
            explode("\n", $this->gitAt('log', '--format=%s')),
        );
        self::assertStringStartsWith('Auto-committed ' . substr($this->gitAt('rev-parse', 'HEAD'), 0, 7) . ': chore: append the model line.', $result->additionalContext);
        self::assertStringContainsString('earlier uncommitted changes to it were committed first', $result->additionalContext);
        self::assertTrue(AutoCommitter::new($this->repo)->withSessionId('undo-session')->hasCommits(), 'recorded under the hook\'s session');
    }

    public function testAFailedCommitStillAllowsAndSaysTheChangeIsUncommitted(): void
    {
        file_put_contents($this->repo . '/.git/hooks/pre-commit', "#!/bin/sh\necho nope >&2\nexit 1\n");
        chmod($this->repo . '/.git/hooks/pre-commit', 0o755);
        $this->turnCheckpoint([], 'prompt');
        file_put_contents($this->repo . '/file.txt', "model\n");

        $result = $this->hook()->execute($this->context('file.txt', 'Change it'));

        self::assertTrue($result->isAllowed(), 'the edit happened; a failed commit never withholds its result');
        self::assertStringStartsWith('Auto-commit skipped for file.txt: git commit failed: nope.', $result->additionalContext);
        self::assertSame('base', $this->gitAt('log', '-1', '--format=%s'));
    }

    public function testNothingToCommitOrAPathOutsideTheProjectAddsNothing(): void
    {
        file_put_contents($this->tmp . '/outside.txt', "x\n");
        $hook = $this->hook();

        foreach ([['file_path' => 'file.txt'], ['file_path' => '../outside.txt'], ['file_path' => 9], []] as $args) {
            $result = $hook->execute(new HookContext('undo-session', 'Edit', $args, '', '', 'm', 'p', $this->repo));
            self::assertTrue($result->isAllowed());
            self::assertSame('', $result->additionalContext);
        }
        self::assertSame('base', $this->gitAt('log', '-1', '--format=%s'));
    }

    public function testBootstrapRegistersItOnlyInEditMode(): void
    {
        foreach (['off' => false, 'turn' => false, 'edit' => true] as $mode => $registered) {
            $hook = $this->launchChain(['autoCommit' => $mode])->hook(HookEvent::PostToolUse->value, AutoCommitHook::NAME);
            self::assertSame($registered, $hook instanceof AutoCommitHook, "autoCommit: {$mode}");
        }
    }

    private function hook(): AutoCommitHook
    {
        $store = $this->store;

        return new AutoCommitHook(
            AutoCommitter::new($this->repo),
            static fn (string $session): ?array => $store->listCheckpoints($session, 1)[0]['state_data'][\SugarCraft\Crush\Session\EnhancedSessionStore::CHECKPOINT_WORKSPACE_KEY] ?? null,
        );
    }

    private function context(string $path, string $description): HookContext
    {
        return new HookContext('undo-session', 'Edit', ['file_path' => $path, 'description' => $description], '', '', 'm', 'p', $this->repo);
    }

    /**
     * Bootstrap's launch chain under the sandboxed HOME with $config.
     *
     * @param array<string, mixed> $config
     */
    private function launchChain(array $config): HookManager
    {
        $home = $this->tmp . '/home';
        @mkdir($home . '/.sugar-crush', 0o700, true);
        file_put_contents($home . '/.sugar-crush/config.json', (string) json_encode($config));
        chmod($home . '/.sugar-crush/config.json', 0o600);
        Bootstrap::useConfigPath(null);
        Bootstrap::useProjectRootForSettings(null);

        try {
            $hooks = (new \ReflectionMethod(Bootstrap::class, 'hooks'))->invoke(null, null, $this->repo);
        } finally {
            ProcessContainment::useSecretEnvAllowlist([]);
            @unlink($home . '/.sugar-crush/config.json');
        }
        self::assertInstanceOf(HookManager::class, $hooks);

        return $hooks;
    }
}
