<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Sessions;

use Closure;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Cli\HeadlessPermissionPrompt;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Background\BackgroundPermissionRelay;
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
        // The expired window appends its settle line (F4) beside the ask.
        self::assertCount(2, $lines, 'one ask, and its timeout closing it');
        $row = json_decode($lines[0], true);
        self::assertIsArray($row);
        self::assertSame('Bash', $row['tool']);
        self::assertSame(['command' => 'make -j4'], $row['arguments']);
        self::assertSame('Run make -j4?', $row['reason']);
        self::assertSame('auto', $row['mode']);
        self::assertSame(['once', 'reject'], $row['options']);
        self::assertSame('call-1', $row['toolCallId']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $row['askId']);
        // F2: the asking carries the token its answer must echo. 32 hex —
        // a shape the 16-hex id can never collide with.
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $row['askToken']);
        self::assertArrayHasKey('requestedAt', $row);
    }

    public function testAStaleTokenlessRowForTheFreshAskIdDoesNotSettleIt(): void
    {
        // F2 (ISS-004 rv5): the askId is a content hash, and answer files
        // are append-only — a replayed identical call WOULD settle off its
        // own stale answer if the id were the whole match. The token is the
        // other half: this pre-row names the exact askId with the exact
        // grant word and no token, and the fresh asking ignores it.
        $askId = \SugarCraft\Code\Backend\PendingAsk::askId('call-1', 'Edit', ['path' => 'a.txt']);
        $this->answer($askId, 'once');
        $legacyCalls = 0;
        $relay = $this->relay(PermissionMode::Auto, timeout: 0.005, legacy: $this->counting($legacyCalls, grant: false));

        self::assertFalse($relay->approver()($this->call(), HookResult::ask('Edit a file?')));
        self::assertSame(1, $legacyCalls, 'the stale row must not stand in for an answer to THIS asking');
    }

    public function testAForgeAtAnUnmintedPathIsIgnoredWhileTheMintedPathIsHonoured(): void
    {
        // F1 (ISS-004 rv5, MAJOR): the server mints `<stem>.answer-<nonce>`
        // and the daemon polls ONLY the path it was handed. A granted-shell
        // sleeper on the daemon's side that pre-plants the OLD fixed name —
        // token-perfect, id-perfect, in the same 0700 directory — writes to
        // a file this relay never opens.
        $minted = BackgroundPermissionRelay::mintAnswerPath($this->dir . '/sess_x.buffer');
        self::assertIsString($minted);
        $forgery = $this->answerOnceAt($this->answerPath);
        $legacyCalls = 0;
        $relay = $this->relay(PermissionMode::Auto, answerPath: $minted, timeout: 0.005, legacy: $this->counting($legacyCalls), sleeper: $forgery);

        self::assertFalse($relay->approver()($this->call(), HookResult::ask('Edit a file?')));
        self::assertSame(1, $legacyCalls, 'the unminted path is a file the answer never lands in');

        // The flip side, same forged bytes at the minted name: the channel
        // works and the pin is not vacuous. The ask file starts fresh — the
        // first phase's stale line would otherwise re-ask nothing.
        @unlink($this->askPath);
        $mintedCalls = 0;
        $working = $this->relay(PermissionMode::Auto, answerPath: $minted, timeout: 5.0, legacy: $this->counting($mintedCalls), sleeper: $this->answerOnceAt($minted));

        self::assertTrue($working->approver()($this->call(), HookResult::ask('Edit a file?')));
        self::assertSame(0, $mintedCalls);
        @unlink($minted);
    }

    /** Answers the first ask at $path — the writer a hostile daemon-side process would be. */
    private function answerOnceAt(string $path, string $reply = 'once'): Closure
    {
        $answered = false;

        return function () use (&$answered, $path, $reply): void {
            if ($answered) {
                return;
            }
            $row = $this->firstAskRow();
            if ($row === null) {
                return;
            }
            $answered = true;
            $this->answer((string) $row['askId'], $reply, (string) ($row['askToken'] ?? ''), $path);
        };
    }

    public function testTheRelayNeverCreatesItsAnswerFile(): void
    {
        // F1: the old writeAsk pre-created the derived `.answer` through the
        // narrowed write, which is the door a daemon-side pre-plant walked
        // through. After the mint the daemon creates nothing on the answer
        // side of the channel, and an unanswered ask leaves no answer file
        // behind at all.
        $ignored = 0;
        $relay = $this->relay(PermissionMode::Auto, timeout: 0.005, legacy: $this->counting($ignored));

        self::assertFalse($relay->approver()($this->call(), HookResult::ask('Edit a file?')));
        self::assertFalse(file_exists($this->answerPath));
    }

    public function testATimedOutAskLeavesASettleLineOnTheAskFile(): void
    {
        // F4: expiry is a fact the SERVER can read, not only one it must
        // have witnessed. The settle line closes the question in its own
        // record, so permission.pending stops lying about it.
        $ignored = 0;
        $relay = $this->relay(PermissionMode::Auto, timeout: 0.005, legacy: $this->counting($ignored));

        self::assertFalse($relay->approver()($this->call(), HookResult::ask('Edit a file?')));
        $rows = array_values(array_filter(array_map(
            static fn (string $line): mixed => trim($line) === '' ? null : json_decode($line, true),
            explode("\n", (string) file_get_contents($this->askPath)),
        ), static fn (mixed $row): bool => is_array($row)));
        self::assertCount(2, $rows, 'the ask, then its closure');
        self::assertSame('timeout', $rows[1]['settled'] ?? null);
        $askId = \SugarCraft\Code\Backend\PendingAsk::askId('call-1', 'Edit', ['path' => 'a.txt']);
        self::assertSame($askId, $rows[1]['askId'] ?? null);
    }

    public function testTheMintDoorShapesAndRefuses(): void
    {
        // F1's door, pinned directly: a mint belongs to its own buffer, in
        // its own directory, with exactly its own nonce shape — everything
        // else, including the pre-mint derived name, is refused.
        $buffer = $this->dir . '/sess_x.buffer';
        $mint = BackgroundPermissionRelay::mintAnswerPath($buffer);
        self::assertIsString($mint);
        self::assertTrue(BackgroundPermissionRelay::isMintedAnswerPath($buffer, $mint));
        self::assertFalse(BackgroundPermissionRelay::isMintedAnswerPath($buffer, $this->dir . '/sess_x.answer'), 'the unminted legacy name is not a mint');
        self::assertFalse(BackgroundPermissionRelay::isMintedAnswerPath($buffer, $this->dir . '/sess_y' . substr($mint, strlen($this->dir . '/sess_x.answer-'))), "another stem's nonce is not this buffer's door");
        self::assertFalse(BackgroundPermissionRelay::isMintedAnswerPath($buffer, $mint . '0'), 'a 33-hex tail is not a mint');
        self::assertFalse(BackgroundPermissionRelay::isMintedAnswerPath($buffer, substr($mint, 0, -1)), 'a 31-hex tail is not a mint');
        self::assertFalse(BackgroundPermissionRelay::isMintedAnswerPath($buffer, $this->dir . '/deep/sess_x.answer-0123456789abcdef0123456789abcdef'), 'a foreign directory is not this buffer\'s channel');
        self::assertFalse(BackgroundPermissionRelay::isMintedAnswerPath($this->dir . '/sess_x.sock', $mint));
        self::assertNull(BackgroundPermissionRelay::mintAnswerPath($this->dir . '/sess_x.sock'));
        // Two mints for one buffer differ — the nonce is drawn, not derived.
        self::assertNotSame($mint, BackgroundPermissionRelay::mintAnswerPath($buffer));
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

    /** Answers the first ask seen on the channel exactly once, with $reply, echoing its token (F2). */
    private function answerOnce(string $reply = 'once'): Closure
    {
        $answered = false;

        return function () use (&$answered, $reply): void {
            if ($answered) {
                return;
            }
            $row = $this->firstAskRow();
            if ($row === null) {
                return;
            }
            $answered = true;
            $this->answer((string) $row['askId'], $reply, (string) ($row['askToken'] ?? ''));
        };
    }

    private function call(string $name = 'Edit', array $arguments = ['path' => 'a.txt']): ToolCall
    {
        return new ToolCall('call-1', $name, $arguments);
    }

    /** @return array<string, mixed>|null */
    private function firstAskRow(): ?array
    {
        if (!file_exists($this->askPath)) {
            return null;
        }
        foreach (explode("\n", (string) file_get_contents($this->askPath)) as $line) {
            $row = json_decode(trim($line), true);
            if (is_array($row) && isset($row['askId'])) {
                return $row;
            }
        }

        return null;
    }

    /** @param resource|null $at answer file to append to; defaults to this fixture's channel */
    private function answer(string $askId, string $reply, string $askToken = '', ?string $at = null): void
    {
        file_put_contents($at ?? $this->answerPath, json_encode(
            [
                'askId' => $askId,
                'reply' => $reply,
                ...($askToken === '' ? [] : ['askToken' => $askToken]),
                'note' => '',
                'answeredAt' => date('c'),
            ],
            JSON_UNESCAPED_SLASHES,
        ) . "\n", FILE_APPEND);
    }
}
