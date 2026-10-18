<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Sessions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Sessions\BackgroundPermissionRelay;
use SugarCraft\Code\Sessions\BackgroundSupervisor;

use function json_encode;

/**
 * ISS-004 server side of the relay: the supervisor owns the ask channel's
 * files — the derivation from a session's buffer path, the read that skips
 * what it cannot trust, and the append of one human answer.
 */
final class BackgroundSupervisorAskChannelTest extends TestCase
{
    use StoppableDaemonFixtureTrait;

    private string $root = '';

    private string $project = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (!\function_exists('posix_getuid')) {
            self::markTestSkipped('the session index is named by uid and needs ext-posix');
        }

        $this->root = \sys_get_temp_dir() . '/ac' . \bin2hex(\random_bytes(4));
        \mkdir($this->root, 0o700);
        $this->project = $this->root . '/project';
        \mkdir($this->project, 0o700);
    }

    protected function tearDown(): void
    {
        if ($this->fixtureHome !== '') {
            $this->tearDownDaemonFixture();
        }
        $this->removeTree($this->root);

        parent::tearDown();
    }

    public function testASessionThisProcessHasNoIpcForHasNoChannel(): void
    {
        $supervisor = new BackgroundSupervisor(tempRoot: $this->root);

        self::assertNull($supervisor->permissionAskChannel('sess_20261017000000_aaaaaaaa'));
        self::assertSame([], $supervisor->permissionAsks('sess_20261017000000_aaaaaaaa'));
        self::assertFalse($supervisor->answerPermissionAsk('sess_20261017000000_aaaaaaaa', 'a1b2c3d4e5f60718', 'once'));
    }

    public function testTheChannelOfAnAdoptedSessionDerivesFromItsBufferPath(): void
    {
        $files = $this->adopt('sess_20261017000000_bbbbbbbb');

        self::assertSame(
            ['askPath' => $files['dir'] . '/sess_20261017000000_bbbbbbbb.ask', 'answerPath' => $files['dir'] . '/sess_20261017000000_bbbbbbbb.answer'],
            $this->adopted->permissionAskChannel('sess_20261017000000_bbbbbbbb'),
        );
        self::assertSame([], $this->adopted->permissionAsks('sess_20261017000000_bbbbbbbb'), 'no ask file yet means no questions');
    }

    public function testAsksAreReadOldestFirstMalformedLinesSkippedAndTheNewestLinePerAskWins(): void
    {
        $files = $this->adopt('sess_20261017000000_cccccccc');
        $ask = fn (string $askId, string $tool): string => (string) json_encode(['askId' => $askId, 'toolCallId' => 'c', 'tool' => $tool, 'arguments' => [], 'reason' => 'r', 'source' => 'gate', 'mode' => 'auto', 'options' => ['once', 'reject']]);

        $lines = $ask('1111111111111111', 'Bash') . "\n"
            . '{not json at all' . "\n"
            . $ask('2222222222222222', 'Edit') . "\n"
            . (string) json_encode(['askId' => 'zz', 'tool' => 'Nope']) . "\n"
            . (string) json_encode(['askId' => '3333333333333333', 'tool' => '']) . "\n"
            . $ask('1111111111111111', 'Read') . "\n";
        \file_put_contents($files['dir'] . '/sess_20261017000000_cccccccc.ask', $lines);

        $asks = $this->adopted->permissionAsks('sess_20261017000000_cccccccc');

        self::assertSame(['1111111111111111', '2222222222222222'], \array_column($asks, 'askId'));
        self::assertSame('Read', $asks[0]['tool'], 'the newest line for one ask id is the ask');
    }

    public function testAnAnswerAppendsOneLineToTheAnswerFile(): void
    {
        $files = $this->adopt('sess_20261017000000_dddddddd');

        self::assertTrue($this->adopted->answerPermissionAsk('sess_20261017000000_dddddddd', '1111111111111111', 'once', 'looks safe'));

        $answerPath = $files['dir'] . '/sess_20261017000000_dddddddd.answer';
        $rows = [];
        foreach (explode("\n", (string) \file_get_contents($answerPath)) as $line) {
            if (trim($line) !== '') {
                $rows[] = json_decode($line, true);
            }
        }
        self::assertCount(1, $rows);
        self::assertSame('1111111111111111', $rows[0]['askId']);
        self::assertSame('once', $rows[0]['reply']);
        self::assertSame('looks safe', $rows[0]['note']);
        self::assertArrayHasKey('answeredAt', $rows[0]);
    }

    public function testTheRelayWritesIntoAndReadsOutOfTheSameChannelTheSupervisorDerives(): void
    {
        $files = $this->adopt('sess_20261017000000_eeeeeeee');
        $channel = $this->adopted->permissionAskChannel('sess_20261017000000_eeeeeeee');
        self::assertNotNull($channel);

        $relay = new BackgroundPermissionRelay(
            mode: \SugarCraft\Code\Permissions\PermissionMode::Auto,
            askPath: $channel['askPath'],
            answerPath: $channel['answerPath'],
            timeoutSeconds: 0.005,
            legacyApprover: static fn (): bool => false,
            sleeper: static function (): void {
                \usleep(1000);
            },
        );
        self::assertFalse($relay->approver()(
            new \SugarCraft\Code\Tools\ToolCall('call-1', 'Edit', ['path' => 'a.txt']),
            new \SugarCraft\Code\Hooks\HookResult(\SugarCraft\Code\Hooks\HookResult::ASK, 'Edit a file?'),
        ), 'nobody answers within the budget');

        $asks = $this->adopted->permissionAsks('sess_20261017000000_eeeeeeee');
        self::assertCount(1, $asks);
        self::assertSame('Edit', $asks[0]['tool']);

        self::assertTrue($this->adopted->answerPermissionAsk('sess_20261017000000_eeeeeeee', (string) $asks[0]['askId'], 'reject'));
        self::assertSame('reject', $this->lastReplyTheRelayWouldSee($channel['answerPath'], (string) $asks[0]['askId']));
    }

    // ── fixtures ───────────────────────────────────────────────────────

    private BackgroundSupervisor $adopted;

    /**
     * Leave a record whose daemon is THIS process (so it stays live) and whose
     * owner is dead, then adopt it — the one public path that fills the
     * supervisor's IPC map, exactly as a restarted server would.
     *
     * @return array{record: string, socket: string, buffer: string, log: string, token: string, dir: string}
     */
    private function adopt(string $id): array
    {
        $files = $this->plantRecord($id, (int) getmypid(), $this->deadOwner());

        $this->adopted = new BackgroundSupervisor(tempRoot: $this->root);
        self::assertCount(1, $this->adopted->reconnect($this->project), 'the planted session is adopted');

        return $files;
    }

    /** @return array{record: string, socket: string, buffer: string, log: string, token: string, dir: string} */
    private function plantRecord(string $id, int $daemonPid, array $owner): array
    {
        $dir = $this->root . '/' . BackgroundSupervisor::IPC_DIR_PREFIX . posix_getuid() . '_' . \bin2hex(\random_bytes(8));
        \mkdir($dir, 0o700);
        $files = [
            'socket' => $dir . '/' . $id . '.sock',
            'buffer' => $dir . '/' . $id . '.buffer',
            'log' => $dir . '/' . $id . '.buffer.log',
            'token' => $dir . '/' . $id . '.token',
        ];
        \file_put_contents($files['socket'], '');
        \file_put_contents($files['buffer'], "[session:heartbeat]\n");
        \file_put_contents($files['log'], '');
        \file_put_contents($files['token'], 'token');

        $index = $this->root . '/' . BackgroundSupervisor::IPC_DIR_PREFIX . posix_getuid() . BackgroundSupervisor::INDEX_DIR_SUFFIX;
        if (!\is_dir($index)) {
            \mkdir($index, 0o700);
        }
        $record = $index . '/' . $id . '.json';
        \file_put_contents($record, (string) json_encode([
            'id' => $id,
            'name' => 'ask channel job',
            'task' => 'hold a question',
            'workingDirectory' => $this->project,
            'timeoutSeconds' => 120,
            'tags' => [],
            'createdAt' => time() - 60,
            'agent' => $this->agent()->toArray(),
            'socketPath' => $files['socket'],
            'bufferPath' => $files['buffer'],
            'tokenPath' => $files['token'],
            'pid' => $daemonPid,
            'startTime' => BackgroundSupervisor::procStartTime($daemonPid),
            'owner' => $owner,
        ]));
        \chmod($record, 0o600);

        return ['record' => $record] + $files + ['dir' => $dir];
    }

    /** @return array{pid: int, startTime: ?int} */
    private function deadOwner(): array
    {
        $proc = \proc_open([PHP_BINARY, '-r', 'exit(0);'], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'a'], ['file', '/dev/null', 'a']], $pipes);
        self::assertIsResource($proc);
        $pid = (int) \proc_get_status($proc)['pid'];
        \proc_close($proc);

        return ['pid' => $pid, 'startTime' => 1];
    }

    private function agent(): Agent
    {
        return new Agent(
            name: 'bg-agent',
            description: 'Background agent',
            prompt: '',
            model: '',
            provider: '',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
        );
    }

    private function lastReplyTheRelayWouldSee(string $answerPath, string $askId): ?string
    {
        $reply = null;
        foreach (explode("\n", (string) \file_get_contents($answerPath)) as $line) {
            $row = json_decode(trim($line), true);
            if (\is_array($row) && ($row['askId'] ?? null) === $askId) {
                $reply = (string) $row['reply'];
            }
        }

        return $reply;
    }

    private function removeTree(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach (\scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            \is_dir($path) ? $this->removeTree($path) : @\unlink($path);
        }
        @\rmdir($dir);
    }
}
