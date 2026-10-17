<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Context\InstructionFileLoader;
use SugarCraft\Code\Context\RulePathNudge;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Skills\SkillPathNudge;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Tools\BuiltIn\Glob;
use SugarCraft\Code\Tools\BuiltIn\Read;
use SugarCraft\Code\Tools\CarriesSessionState;
use SugarCraft\Code\Tools\ReadLedger;
use SugarCraft\Code\Tools\ToolCall;

/**
 * ISS-018: the announce-once marks — nested instruction documents, instruction
 * refusals, skill and rule nudges — cross the turn fork on the result frame's
 * `sessionState` key, the same way the read ledger crosses on its own, so a
 * child that showed `pkg/CLAUDE.md` does not leave the parent free to show it
 * again on every later turn.
 *
 * The tool-level fork already had this seam ({@see \SugarCraft\Code\Runtime::collectChildResult()});
 * the turn-level fork only ever carried the ledger back, which is the failure
 * this file pins from both sides: through {@see EngineBackend::settleFromResultFrame()}
 * directly, and through one real `completeAsync()` turn whose child reads a
 * file under a nested instruction document.
 */
final class SessionStateForkCarryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-session-state-fork-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);
        $this->dir = (string) realpath($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->dir);
    }

    public function testTheUnionCollectsEveryCarriersMarksAndSkipsTheLedgerKey(): void
    {
        $loader = new InstructionFileLoader($this->dir);
        $skills = new SkillRegistry();
        $nudge = SkillPathNudge::new($skills);
        $rules = RulePathNudge::fromLoader(static fn (): array => []);
        $read = new Read($this->dir, instructionLoader: $loader, skillNudge: $nudge, ruleNudge: $rules, readLedger: ReadLedger::new());
        $glob = new Glob($this->dir, instructionLoader: $loader, skillNudge: $nudge, ruleNudge: $rules);
        $read->mergeSessionState(['emittedInstructionPaths' => ['/pkg/CLAUDE.md'], 'announcedSkills' => ['skills/a.md']]);

        $union = $this->union([$read, $glob, new \stdClass()]);

        $this->assertSame(['/pkg/CLAUDE.md'], $union['emittedInstructionPaths'], 'two carriers of one loader union to one list');
        $this->assertSame(['skills/a.md'], $union['announcedSkills']);
        $this->assertArrayNotHasKey(ReadLedger::SESSION_STATE_KEY, $union, 'the ledger crosses on its own frame key');
    }

    public function testTheResultFrameMergesTheMarksOnSuccessAndOnFailure(): void
    {
        $childLoader = new InstructionFileLoader($this->dir);
        $child = new Read($this->dir, instructionLoader: $childLoader);
        $child->mergeSessionState(['emittedInstructionPaths' => ['/pkg/CLAUDE.md', '/pkg/sub/CLAUDE.md']]);
        $wire = unserialize(serialize($this->union([$child])), ['allowed_classes' => false]);

        foreach ([['ok' => true, 'content' => 'x'], ['ok' => false, 'error' => 'boom']] as $frame) {
            $loader = new InstructionFileLoader($this->dir);
            $backend = EngineBackend::new(new ScriptedProvider([]), 'm')->withoutHooks()
                ->withTools([new Read($this->dir, instructionLoader: $loader)]);
            $deferred = new Deferred();
            $deferred->promise()->then(null, static fn () => null);

            (new \ReflectionMethod($backend, 'settleFromResultFrame'))->invoke($backend, $frame + ['sessionState' => $wire], $deferred, null);

            $this->assertSame(
                ['/pkg/CLAUDE.md', '/pkg/sub/CLAUDE.md'],
                $loader->emittedPaths(),
                "a failed turn's announcements still happened",
            );
        }
    }

    public function testAMalformedPayloadCostsTheMarksNeverTheTurn(): void
    {
        $hostile = new class implements \SugarCraft\Code\Tools\Tool, CarriesSessionState {
            public function name(): string
            {
                return 'hostile';
            }
            public function description(): string
            {
                return 'throws on merge';
            }
            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }
            public function execute(array $args): \SugarCraft\Code\Tools\ToolResult
            {
                return new \SugarCraft\Code\Tools\ToolResult(toolCallId: '', content: 'ok');
            }
            public function exportSessionState(): array
            {
                return [];
            }
            public function mergeSessionState(array $state): void
            {
                throw new \RuntimeException('bad merge');
            }
        };
        $settle = new \ReflectionMethod(EngineBackend::class, 'settleFromResultFrame');
        $backend = EngineBackend::new(new ScriptedProvider([]), 'm')->withoutHooks()->withTools([$hostile]);
        $deferred = new Deferred();
        $settled = null;
        $deferred->promise()->then(static function (Message $msg) use (&$settled): void {
            $settled = $msg->content;
        });

        $settle->invoke($backend, ['ok' => true, 'content' => 'turn survived'] + ['sessionState' => ['emittedInstructionPaths' => 'not-a-list']], $deferred, null);

        $this->assertSame('turn survived', $settled, 'a throwing merge is bookkeeping, not a verdict');
    }

    public function testAReadInTheForkedTurnAnnouncesTheNestedDocToTheParentOnceAcrossTurns(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            self::markTestSkipped('completeAsync() takes the blocking fallback without pcntl, so nothing crosses a turn fork');
        }

        mkdir($this->dir . '/pkg', 0o700, true);
        file_put_contents($this->dir . '/pkg/CLAUDE.md', "PKG RULES\n");
        file_put_contents($this->dir . '/pkg/a.txt', "alpha\n");
        file_put_contents($this->dir . '/pkg/b.txt', "beta\n");

        $loader = new InstructionFileLoader($this->dir);
        $read = new Read($this->dir, instructionLoader: $loader);
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [
                new ToolCall('r1', 'Read', ['file_path' => 'pkg/a.txt', 'description' => 'inspect a']),
            ]),
            new CompleteResponse(content: 'read a'),
            new CompleteResponse(content: '', toolCalls: [
                new ToolCall('r2', 'Read', ['file_path' => 'pkg/b.txt', 'description' => 'inspect b']),
            ]),
            new CompleteResponse(content: 'read b'),
        ], contextWindow: 1_000_000);
        $backend = EngineBackend::new($provider, 'm')->withoutHooks()->withRoot($this->dir)->withTools([$read]);

        $first = $this->drainUntilSettled($backend->completeAsync([Message::user('read a')]));
        $this->assertInstanceOf(Message::class, $first);
        $this->assertStringContainsString('PKG RULES', $this->turnText($first), 'the first turn shows the nested document');

        $reply = $this->drainUntilSettled($backend->completeAsync([Message::user('read a'), Message::assistant('read a'), Message::user('read b')]));
        $this->assertInstanceOf(Message::class, $reply);

        $this->assertStringNotContainsString(
            'PKG RULES',
            $this->turnText($reply),
            'the second turn forks a parent that already knows: the mark came home on the first turn\'s frame',
        );
    }

    /** Every row of the turn's transcript, plus the reply itself, as one haystack. */
    private function turnText(Message $reply): string
    {
        $text = $reply->content;
        foreach ($reply->turnTranscript as $row) {
            $text .= "\n" . $row->content;
        }

        return $text;
    }

    /** @param list<object> $tools @return array<string, list<string>> */
    private function union(array $tools): array
    {
        return (new \ReflectionMethod(EngineBackend::class, 'sessionStateFromTools'))->invoke(null, $tools);
    }

    private function drainUntilSettled(PromiseInterface $promise): mixed
    {
        $loop = Loop::get();
        $value = null;
        $failure = null;

        $promise->then(
            static function ($v) use (&$value, &$failure, $loop): void {
                $value = $v;
                $loop->stop();
            },
            static function (\Throwable $e) use (&$failure, $loop): void {
                $failure = $e;
                $loop->stop();
            },
        );

        $watchdog = $loop->addTimer(30.0, static function () use ($loop, &$failure): void {
            $failure = new \RuntimeException('the forked completion never settled within the safety window');
            $loop->stop();
        });
        $loop->run();
        $loop->cancelTimer($watchdog);

        if ($failure !== null) {
            $this->fail('forked turn failed: ' . $failure->getMessage());
        }

        return $value;
    }
}
