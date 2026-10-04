<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;

/**
 * Step 1.A-2: EngineBackend holds ONE {@see \SugarCraft\Crush\Context\SessionPromptMemo}
 * for the session, primed in the PARENT before a turn's fork, and refreshed —
 * memo forgotten, instruction loader re-read — only at a refresh point the
 * history itself shows: `/clear`, a compaction or rewind (the history no
 * longer extends the last turn's), or a session switch.
 */
final class SessionPromptMemoPrimeTest extends TestCase
{
    use HomeSandboxTrait;

    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        foreach ($this->dirs as $dir) {
            exec('rm -rf ' . escapeshellarg($dir) . ' 2>&1');
        }
    }

    public function testTheParentPrimesTheMemoBeforeTheTurnForks(): void
    {
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('the forked turn path needs ext-pcntl');
        }

        $root = $this->project('RULE-ONE');
        $backend = $this->backend(new ScriptedProvider([new CompleteResponse(content: 'answer')]), $root)
            ->withSessionId('s-prime');

        $promise = $backend->completeAsync([Message::user('go')]);

        // Synchronously, before the loop has run the child at all: the
        // layers were read HERE, in the process that outlives the turn.
        $this->assertSame(['s-prime'], $backend->sessionPromptMemo()->sessions());

        $this->assertSame('answer', $this->drainUntilSettled($promise)->content);
    }

    public function testInstructionsStayFrozenUntilTheHistoryIsRewritten(): void
    {
        $root = $this->project('RULE-ONE');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'one'),
            new CompleteResponse(content: 'two'),
            new CompleteResponse(content: 'three'),
        ]);
        $backend = $this->backend($provider, $root);

        $backend->complete([Message::user('a')]);
        file_put_contents($root . '/CLAUDE.md', "RULE-TWO\n");

        $backend->complete([Message::user('a'), Message::assistant('one'), Message::user('b')]);
        $this->assertStringContainsString('RULE-ONE', (string) $provider->requests[1]->systemPrompt, 'an ordinary turn reuses the frozen layers: the prefix does not move');

        // `/clear`: the history no longer starts with the last turn's rows.
        $backend->complete([Message::user('c')]);
        $this->assertStringContainsString('RULE-TWO', (string) $provider->requests[2]->systemPrompt, 'a rewritten history is a refresh point');
        $this->assertStringNotContainsString('RULE-ONE', (string) $provider->requests[2]->systemPrompt);
    }

    public function testASessionSwitchIsARefreshPoint(): void
    {
        $root = $this->project('RULE-ONE');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'one'),
            new CompleteResponse(content: 'other'),
            new CompleteResponse(content: 'two'),
        ]);
        $backend = $this->backend($provider, $root);

        $backend->withSessionId('A')->complete([Message::user('a')]);
        file_put_contents($root . '/CLAUDE.md', "RULE-TWO\n");
        $backend->withSessionId('B')->complete([Message::user('b')]);

        // Back to A with A's own history extended — only the switch says refresh.
        $backend->withSessionId('A')->complete([Message::user('a'), Message::assistant('one'), Message::user('again')]);

        $this->assertStringContainsString('RULE-TWO', (string) $provider->requests[1]->systemPrompt);
        $this->assertStringContainsString('RULE-TWO', (string) $provider->requests[2]->systemPrompt, 'switching back re-reads, never reuses what A froze before the switch');
    }

    private function backend(ScriptedProvider $provider, string $root): EngineBackend
    {
        return EngineBackend::new($provider, 'm')
            ->withoutHooks()
            ->withRoot($root)
            ->withInstructionLoader(new InstructionFileLoader($root));
    }

    private function project(string $rule): string
    {
        $home = $this->tempDir();
        mkdir($home . '/.sugar-crush', 0o700, true);
        $this->useHomeSandbox($home);

        $root = $this->tempDir();
        file_put_contents($root . '/CLAUDE.md', $rule . "\n");

        return $root;
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/crush-memo-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o700, true);
        $this->dirs[] = $dir;

        return $dir;
    }

    private function drainUntilSettled(PromiseInterface $promise): Message
    {
        $loop = Loop::get();
        $settled = false;
        $value = null;
        $failure = null;

        $promise->then(
            static function ($v) use (&$settled, &$value, $loop): void {
                $settled = true;
                $value = $v;
                $loop->stop();
            },
            static function (\Throwable $e) use (&$settled, &$failure, $loop): void {
                $settled = true;
                $failure = $e;
                $loop->stop();
            },
        );

        if (!$settled) {
            $watchdog = $loop->addTimer(30.0, static function () use ($loop, &$failure): void {
                $failure = new \RuntimeException('the forked completion never settled within the safety window');
                $loop->stop();
            });
            $loop->run();
            $loop->cancelTimer($watchdog);
        }

        if ($failure !== null) {
            $this->fail('forked turn failed: ' . $failure->getMessage());
        }
        $this->assertInstanceOf(Message::class, $value);

        return $value;
    }
}
