<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Sessions;

use Closure;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Cli\HeadlessPermissionPrompt;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Sessions\BackgroundPermissionRelay;
use SugarCraft\Code\Tools\ToolCall;

use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function json_decode;
use function is_array;
use function json_encode;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function usleep;

use const DIRECTORY_SEPARATOR;
use const FILE_APPEND;
use const JSON_UNESCAPED_SLASHES;

/**
 * ISS-004: the worker-side relay answers a permission ask from the IPC answer
 * channel when a server client answers, and falls back to the no-terminal
 * approver's refusal when nobody does.
 */
final class BackgroundPermissionRelayTest extends TestCase
{
    private string $dir;

    private string $askPath;

    private string $answerPath;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bg-relay-' . uniqid('', true);
        self::assertTrue(mkdir($this->dir, 0o700, true));
        $this->askPath = $this->dir . '/sess_x.ask';
        $this->answerPath = $this->dir . '/sess_x.answer';
    }

    protected function tearDown(): void
    {
        @unlink($this->askPath);
        @unlink($this->answerPath);
        @rmdir($this->dir);
    }

    public function testAByPassClassModeStillGrantsWhatDoesNotDemandAnAnswer(): void
    {
        // 6bad89ebe semantics ride through: a genuinely bypass-mode worker
        // never relays and never touches the channel.
        $legacyCalls = 0;
        $relay = $this->relay(PermissionMode::BypassPermissions, legacy: $this->counting($legacyCalls));

        self::assertTrue($relay->approver()($this->call(), HookResult::ask('Edit a file?')));
        self::assertSame(0, $legacyCalls);
        self::assertFalse(file_exists($this->askPath), 'a bypass grant never touches the channel');
    }

    public function testARosteredAskStillRelaysUnderBypass(): void
    {
        HeadlessPermissionPrompt::requireUnattendedAnswer('Rostered Relay Probe');
        try {
            $legacyCalls = 0;
            $relay = $this->relay(PermissionMode::BypassPermissions, timeout: 0.005, legacy: $this->counting($legacyCalls, false));

            self::assertFalse($relay->approver()($this->call(), new HookResult(HookResult::ASK, 'Stop the session?', null, '', ['Rostered Relay Probe'])));
            self::assertSame(1, $legacyCalls, 'the rostered ask reaches the timeout fallback, not a silent bypass grant');
        } finally {
            HeadlessPermissionPrompt::forgetUnattendedAnswers();
        }
    }

    public function testTheAskLineCarriesEverythingThePanelNeeds(): void
    {
        $ignored = 0;
        $relay = $this->relay(PermissionMode::Auto, timeout: 0.005, legacy: $this->counting($ignored));

        self::assertFalse($relay->approver()($this->call('Bash', ['command' => 'make -j4']), HookResult::ask('Run make -j4?')));

        $lines = array_values(array_filter(
            explode("\n", (string) file_get_contents($this->askPath)),
            static fn (string $line): bool => trim($line) !== '',
        ));
        self::assertCount(1, $lines, 'one line per ask');
        $row = json_decode($lines[0], true);
        self::assertIsArray($row);
        self::assertSame('Bash', $row['tool']);
        self::assertSame(['command' => 'make -j4'], $row['arguments']);
        self::assertSame('Run make -j4?', $row['reason']);
        self::assertSame('auto', $row['mode']);
        self::assertSame(['once', 'reject'], $row['options']);
        self::assertSame('call-1', $row['toolCallId']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $row['askId']);
        self::assertArrayHasKey('requestedAt', $row);
    }

    public function testAnAnswerOfOnceGrantsWithoutReachingTheLegacyApprover(): void
    {
        $legacyCalls = 0;
        $relay = $this->relay(PermissionMode::Auto, timeout: 5.0, legacy: $this->counting($legacyCalls), sleeper: $this->answerOnce());

        self::assertTrue($relay->approver()($this->call(), HookResult::ask('Edit a file?')));
        self::assertSame(0, $legacyCalls);
    }

    public function testAnAnswerOfAlwaysGrantsEvenThoughTheDaemonTreatsItAsOnce(): void
    {
        $ignored = 0;
        $ignored = 0;
        $relay = $this->relay(PermissionMode::Auto, timeout: 5.0, legacy: $this->counting($ignored), sleeper: $this->answerOnce('always'));

        self::assertTrue($relay->approver()($this->call(), HookResult::ask('Edit a file?')));
    }

    public function testAnAnswerOfRejectRefusesWithoutReachingTheLegacyApprover(): void
    {
        $legacyCalls = 0;
        $relay = $this->relay(PermissionMode::Auto, timeout: 5.0, legacy: $this->counting($legacyCalls), sleeper: $this->answerOnce('reject'));

        self::assertFalse($relay->approver()($this->call(), HookResult::ask('Edit a file?')));
        self::assertSame(0, $legacyCalls, 'a human answered; this is not an unattended denial');
    }

    public function testAStaleAnswerForAnotherAskIsIgnored(): void
    {
        $this->answer('0000000000000000', 'reject');
        $ignored = 0;
        $relay = $this->relay(PermissionMode::Auto, timeout: 0.005, legacy: $this->counting($ignored, grant: true), sleeper: $this->answerOnce('once'));

        self::assertTrue($relay->approver()($this->call(), HookResult::ask('Edit a file?')));
    }

    public function testTheNewestAnswerLineForTheAskWinsOverAnEarlierOne(): void
    {
        // A stale answer to the SAME hash (a replayed call id) must not
        // settle the fresh question first — the daemon reads the tail.
        $askId = \SugarCraft\Code\Backend\PendingAsk::askId('call-1', 'Edit', ['path' => 'a.txt']);
        $this->answer($askId, 'reject');
        $ignored = 0;
        $relay = $this->relay(PermissionMode::Auto, timeout: 5.0, legacy: $this->counting($ignored), sleeper: $this->answerOnce('once'));

        self::assertTrue($relay->approver()($this->call(), HookResult::ask('Edit a file?')));
    }

    public function testAnUnansweredAskFallsBackToTheLegacyApprover(): void
    {
        $legacyCalls = 0;
        $relay = $this->relay(PermissionMode::Auto, timeout: 0.005, legacy: $this->counting($legacyCalls, grant: true));

        self::assertTrue($relay->approver()($this->call(), HookResult::ask('Edit a file?')));
        self::assertSame(1, $legacyCalls);
    }

    public function testAMissingChannelDirectoryFallsBackWithoutTouchingTheFilesystem(): void
    {
        $legacyCalls = 0;
        $relay = $this->relay(
            PermissionMode::Auto,
            askPath: $this->dir . '/gone/sess_x.ask',
            answerPath: $this->dir . '/gone/sess_x.answer',
            legacy: $this->counting($legacyCalls),
        );

        self::assertFalse($relay->approver()($this->call(), HookResult::ask('Edit a file?')));
        self::assertSame(1, $legacyCalls);
        self::assertFalse(file_exists($this->dir . '/gone/sess_x.answer'));
    }

    public function testANegativeWindowIsRefusedRatherThanWaitingForever(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->relay(PermissionMode::Auto, timeout: -1.0);
    }

    public function testPathsForOnlyDerivesFromABufferPath(): void
    {
        self::assertSame(
            ['askPath' => '/tmp/x/s_1.ask', 'answerPath' => '/tmp/x/s_1.answer'],
            BackgroundPermissionRelay::pathsFor('/tmp/x/s_1.buffer'),
        );
        self::assertNull(BackgroundPermissionRelay::pathsFor('/tmp/x/s_1.sock'));
        self::assertNull(BackgroundPermissionRelay::pathsFor(''));
    }

    // ── fixtures ───────────────────────────────────────────────────────

    private function relay(
        PermissionMode $mode,
        ?string $askPath = null,
        ?string $answerPath = null,
        float $timeout = 0.01,
        ?Closure $legacy = null,
        ?callable $sleeper = null,
    ): BackgroundPermissionRelay {
        return new BackgroundPermissionRelay(
            mode: $mode,
            askPath: $askPath ?? $this->askPath,
            answerPath: $answerPath ?? $this->answerPath,
            timeoutSeconds: $timeout,
            legacyApprover: $legacy,
            sleeper: $sleeper ?? static function (): void {
                usleep(1000);
            },
        );
    }

    /** @return Closure(ToolCall, HookResult): bool */
    private function counting(int &$calls, bool $grant = false): Closure
    {
        return function () use (&$calls, $grant): bool {
            $calls++;

            return $grant;
        };
    }

    /** Answers the first ask seen on the channel exactly once, with $reply. */
    private function answerOnce(string $reply = 'once'): Closure
    {
        $answered = false;

        return function () use (&$answered, $reply): void {
            if ($answered) {
                return;
            }
            $askId = $this->firstAskId();
            if ($askId === null) {
                return;
            }
            $answered = true;
            $this->answer($askId, $reply);
        };
    }

    private function call(string $name = 'Edit', array $arguments = ['path' => 'a.txt']): ToolCall
    {
        return new ToolCall('call-1', $name, $arguments);
    }

    private function firstAskId(): ?string
    {
        if (!file_exists($this->askPath)) {
            return null;
        }
        foreach (explode("\n", (string) file_get_contents($this->askPath)) as $line) {
            $row = json_decode(trim($line), true);
            if (is_array($row) && isset($row['askId'])) {
                return (string) $row['askId'];
            }
        }

        return null;
    }

    private function answer(string $askId, string $reply): void
    {
        file_put_contents($this->answerPath, json_encode(
            ['askId' => $askId, 'reply' => $reply, 'note' => '', 'answeredAt' => date('c')],
            JSON_UNESCAPED_SLASHES,
        ) . "\n", FILE_APPEND);
    }
}
