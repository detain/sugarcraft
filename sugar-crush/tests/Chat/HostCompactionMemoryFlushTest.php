<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CacheReusingSummaryBackend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Backend\SummarisesWithCache;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Context\Compaction\MemoryFlush;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\Context\ContextCompactor;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\HistoryCompactedMsg;
use SugarCraft\Crush\Host\CompactionService;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Memory\MemoryWriter;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\BuiltIn\MemoryTool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Usage;

/**
 * Roadmap 2.11, the host's half: `/compact` (and the 85% tier, through the
 * same {@see Chat::buildSummarizationRequest()}) runs the pre-compaction
 * memory flush before its summary — once per compaction cycle, claimed on the
 * session's context ledger, the count the in-turn flush keeps too.
 */
final class HostCompactionMemoryFlushTest extends TestCase
{
    private string $sandbox;

    private string|false $originalHome;

    private mixed $originalServerHome = null;

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('the flush is observed from the summary\'s forked child');
        }
        $this->sandbox = sys_get_temp_dir() . '/crush-hostflush-' . bin2hex(random_bytes(6));
        mkdir($this->sandbox . '/home', 0o700, true);
        mkdir($this->sandbox . '/repo', 0o700, true);
        mkdir($this->sandbox . '/notes', 0o700, true);
        $this->originalHome = getenv('HOME');
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        putenv('HOME=' . $this->sandbox . '/home');
        $_SERVER['HOME'] = $this->sandbox . '/home';
    }

    protected function tearDown(): void
    {
        if (!isset($this->sandbox)) {
            return;
        }
        $this->originalHome === false ? putenv('HOME') : putenv('HOME=' . $this->originalHome);
        if ($this->originalServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $this->originalServerHome;
        }
        exec('rm -rf ' . escapeshellarg($this->sandbox) . ' 2>&1');
    }

    public function testCompactFlushesOncePerCycleAndAgainAfterTheCompactionLanded(): void
    {
        $chat = $this->chat(self::history());

        [$after, $first] = $this->compact($chat);
        $this->assertSame(1, $this->flushes(), '`/compact` flushed before its summary');
        $this->assertCount(1, (new MemoryStore($this->sandbox . '/notes'))->list('user'), 'the note the flush saved is kept');
        $this->assertNull($first->error);
        $this->assertSame(1, self::ledger($after)->compactionCycle, 'the landing started the next cycle');
        $this->assertTrue(self::ledger($after)->memoryFlushDue());

        $more = (new \ReflectionMethod($after, 'mutate'))->invoke($after, [
            'history' => [...$after->history, ...self::history(4, 'later')],
            'inputBuf' => '/compact',
        ]);
        $this->compact($more);
        $this->assertSame(2, $this->flushes(), 'a new cycle flushes again');
    }

    public function testACycleTheSessionAlreadyFlushedIsNotFlushedAgain(): void
    {
        $chat = $this->chat(self::history());
        // As an in-turn flush whose summary failed leaves it.
        $context = (new \ReflectionMethod($chat, 'sessionContextLedger'))->invoke($chat);
        $runner = (new \ReflectionMethod($chat, 'turnRunner'))->invoke($chat);
        $runner->saveLedger((new \ReflectionMethod($chat, 'transcripts'))->invoke($chat), null, $context->withMemoryFlushed());

        [, $landed] = $this->compact($chat);

        $this->assertSame(0, $this->flushes());
        $this->assertNull($landed->error, 'the summary still ran');
    }

    /** Only an engine flushes: a summary backend that is not one is never asked to claim the cycle. */
    public function testOnlyAnEngineSummaryClaimsTheCycle(): void
    {
        $claims = 0;
        $plain = new class () implements Backend, SummarisesWithCache {
            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant('1.');
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                return \React\Promise\resolve(Message::assistant('1.'));
            }

            public function summariseAsync(array $history, string $instruction, ?CancellationToken $cancellation = null): PromiseInterface
            {
                return \React\Promise\resolve(Message::assistant('1.'));
            }
        };

        $request = CompactionService::new()->buildSummarizationRequest(
            $plain,
            new ContextCompactor(CompactorConfig::new()->withRecentPreserveCount(2)),
            self::history(),
            null,
            claimMemoryFlush: static function () use (&$claims): bool {
                $claims++;

                return true;
            },
        );
        $this->assertNotNull($request);
        $this->assertSame(0, $claims, 'nothing is claimed before the request is sent');
        self::settle(($request['promise'])());
        $this->assertSame(0, $claims);
    }

    /** @return array{0: Chat, 1: HistoryCompactedMsg} */
    private function compact(Chat $chat): array
    {
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNotNull($cmd);
        $asyncCmd = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $asyncCmd);
        $landed = self::settle($asyncCmd->promise);
        $this->assertInstanceOf(HistoryCompactedMsg::class, $landed);
        [$after] = $next->update($landed);

        return [$after, $landed];
    }

    /** @param list<Message> $history */
    private function chat(array $history): Chat
    {
        $engine = EngineBackend::new($this->provider(), 'm')->withoutHooks()->withRoot($this->sandbox . '/repo')
            ->withTools([new MemoryTool(MemoryWriter::new(new MemoryStore($this->sandbox . '/notes'), $this->sandbox . '/repo'))]);

        return new Chat(
            history: $history,
            inputBuf: '/compact',
            backend: new EchoBackend(),
            compactorConfig: CompactorConfig::new()->withRecentPreserveCount(2),
            summaryBackend: CacheReusingSummaryBackend::new(new EchoBackend(), $engine),
        );
    }

    /** Runs in the summary's child: each flush request leaves a line in a file. */
    private function provider(): ScriptedProvider
    {
        $marker = $this->sandbox . '/flushes';

        return new ScriptedProvider([
            static function (CompleteRequest $request) use ($marker): CompleteResponse {
                $last = $request->messages[array_key_last($request->messages)] ?? null;
                if ($last instanceof UserMessage && $last->content() === MemoryFlush::INSTRUCTION) {
                    file_put_contents($marker, "flush\n", FILE_APPEND);

                    return new CompleteResponse(content: '', toolCalls: [
                        new ToolCall('f1', 'Memory', ['action' => 'save', 'content' => 'deploys go through the staging gate', 'scope' => 'user', 'type' => 'convention']),
                    ], usage: Usage::new(60, 0.0, 50, 10, 0));
                }

                return new CompleteResponse(content: self::records(8), usage: Usage::new(60, 0.0, 50, 10, 0));
            },
        ]);
    }

    private function flushes(): int
    {
        $marker = $this->sandbox . '/flushes';

        return is_file($marker) ? substr_count((string) file_get_contents($marker), "flush\n") : 0;
    }

    private static function ledger(Chat $chat): ContextLedger
    {
        return (new \ReflectionMethod($chat, 'sessionContextLedger'))->invoke($chat);
    }

    /** @return list<Message> */
    private static function history(int $pairs = 6, string $tag = 'question'): array
    {
        $out = [];
        for ($i = 1; $i <= $pairs; $i++) {
            $out[] = Message::user("{$tag} {$i}");
            $out[] = Message::assistant("answer {$i} " . str_repeat('detail ', 60));
        }

        return $out;
    }

    private static function records(int $count): string
    {
        $lines = [];
        for ($n = 1; $n <= $count; $n++) {
            $lines[] = "{$n}.\nasked: recorded {$n}";
        }

        return implode("\n", $lines);
    }

    private static function settle(PromiseInterface $promise): mixed
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
                $failure = new \RuntimeException('never settled');
                $loop->stop();
            });
            $loop->run();
            $loop->cancelTimer($watchdog);
        }
        if ($failure !== null) {
            self::fail('the promise failed: ' . $failure->getMessage());
        }

        return $value;
    }
}
