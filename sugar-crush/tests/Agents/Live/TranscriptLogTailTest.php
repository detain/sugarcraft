<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents\Live;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Live\AgentTranscriptTail;
use SugarCraft\Crush\Agents\Live\SubAgentTranscriptLog;
use SugarCraft\Crush\Role;

/**
 * Roadmap P-C1: a delegated run's JSONL transcript — written by the process
 * that runs it, tailed by offset, projected into transcript rows.
 */
final class TranscriptLogTailTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/sc_transcript_log_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testTheLogLivesUnderTheSessionWithOwnerOnlyModes(): void
    {
        $log = SubAgentTranscriptLog::forRun('9f2c', 'subagent_12_ab.cd', $this->root);

        $this->assertSame($this->root . '/9f2c/subagent_12_ab.cd.jsonl', $log->path());
        $this->assertTrue($log->user('map the module'));
        $this->assertSame(0o700, fileperms(\dirname($log->path())) & 0o777);
        $this->assertSame(0o600, fileperms($log->path()) & 0o777);
        $this->assertTrue(SubAgentTranscriptLog::isLogPath($log->path(), $this->root));
    }

    public function testNoIdCanClimbOutOfItsDirectory(): void
    {
        $log = SubAgentTranscriptLog::forRun('../../etc', '../passwd', $this->root);

        $this->assertStringStartsWith($this->root . '/', $log->path());
        $this->assertNotContains('..', explode('/', substr($log->path(), \strlen($this->root))), 'no segment is a parent reference');
        $this->assertFalse(SubAgentTranscriptLog::isLogPath($this->root . '/../x/y.jsonl', $this->root));
        $this->assertFalse(SubAgentTranscriptLog::isLogPath('/etc/passwd', $this->root));
        $this->assertFalse(SubAgentTranscriptLog::isLogPath($this->root . '/s/a.txt', $this->root));
        $this->assertFalse(SubAgentTranscriptLog::isLogPath($this->root . '/a.jsonl', $this->root), 'a log is two segments down');
    }

    public function testTheTailReadsCompleteLinesOnlyAndAdvancesPastThem(): void
    {
        $log = SubAgentTranscriptLog::forRun('s', 'a', $this->root);
        $log->user('first');
        $log->assistant('second');

        [$items, $tail] = AgentTranscriptTail::of($log->path())->next();
        $this->assertSame(['user', 'assistant'], array_column($items, 't'));
        clearstatcache();
        $this->assertSame(filesize($log->path()), $tail->offset());

        // A line the writer has not finished is read again next time.
        file_put_contents($log->path(), '{"t":"assistant","text":"half', FILE_APPEND);
        [$none, $same] = $tail->next();
        $this->assertSame([], $none);
        $this->assertSame($tail->offset(), $same->offset());

        file_put_contents($log->path(), "\"}\n", FILE_APPEND);
        [$rest] = $same->next();
        $this->assertSame('half', $rest[0]['text']);
    }

    public function testMalformedAndUnknownLinesAreDropped(): void
    {
        $log = SubAgentTranscriptLog::forRun('s', 'a', $this->root);
        $log->user('kept');
        file_put_contents($log->path(), "not json\n{\"t\":\"exploit\"}\n[1,2]\n", FILE_APPEND);
        $log->status('complete', 'complete', null);

        $this->assertSame(['user', 'status'], array_column(AgentTranscriptTail::readAll($log->path()), 't'));
    }

    public function testATailReadsAtMostItsBudgetPerCall(): void
    {
        $log = SubAgentTranscriptLog::forRun('s', 'a', $this->root);
        for ($i = 0; $i < 50; $i++) {
            $log->assistant(str_repeat('x', 2000));
        }

        [$first, $tail] = AgentTranscriptTail::of($log->path())->next();
        $this->assertLessThan(50, \count($first), 'one call reads one 64 KB window');
        $this->assertLessThanOrEqual(AgentTranscriptTail::MAX_BYTES_PER_READ, $tail->offset());
        $this->assertCount(50, AgentTranscriptTail::readAll($log->path()), 'readAll keeps going to the end');
    }

    public function testAToolResultIsClippedAndEveryLineFitsAWindow(): void
    {
        $log = SubAgentTranscriptLog::forRun('s', 'a', $this->root);
        $log->toolResult('c1', 'Read', true, str_repeat('é', 20000));
        $log->toolResult('c2', 'Bash', true, str_repeat("\x01", 16000));

        $items = AgentTranscriptTail::readAll($log->path());
        $this->assertCount(2, $items);
        $this->assertTrue($items[0]['truncated']);
        $this->assertLessThanOrEqual(SubAgentTranscriptLog::MAX_RESULT_BYTES, \strlen($items[0]['content']));
        $this->assertSame(1, preg_match('//u', $items[0]['content']), 'clipped on a codepoint boundary');
        foreach (file($log->path()) ?: [] as $line) {
            $this->assertLessThan(SubAgentTranscriptLog::MAX_LINE_BYTES, \strlen($line));
        }
    }

    public function testTheProjectionPairsCallsWithResultsAndCarriesThoughts(): void
    {
        $log = SubAgentTranscriptLog::forRun('s', 'a', $this->root);
        $log->user('Map the login flow');
        $log->thinking('look at the routes first');
        $log->toolCall('c1', 'Grep', ['pattern' => 'LoginController']);
        $log->toolResult('c1', 'Grep', true, 'routes/web.php:12');
        $log->toolCall('c2', 'Read', ['file_path' => 'missing.php']);
        $log->toolResult('c2', 'Read', false, 'no such file');
        $log->assistant('The controller is wired in routes/web.php.');
        $log->status('complete', 'complete', null);

        $rows = AgentTranscriptTail::messages(AgentTranscriptTail::readAll($log->path()));

        $this->assertCount(5, $rows);
        $this->assertSame(Role::User, $rows[0]->role);
        $this->assertSame('routes/web.php:12', $rows[1]->toolResults[0]->result);
        $this->assertSame('look at the routes first', $rows[1]->reasoning, 'the thought rides the row it led to');
        $this->assertNull($rows[1]->pendingToolCallId, 'a call with a result is a finished row, not a placeholder');
        $this->assertSame('no such file', $rows[2]->toolResults[0]->error);
        $this->assertSame('The controller is wired in routes/web.php.', $rows[3]->content);
        $this->assertSame('Sub-agent complete', $rows[4]->content);
        $this->assertTrue($rows[4]->uiOnly);
    }

    public function testACallWithNoResultStaysARunningPlaceholder(): void
    {
        $log = SubAgentTranscriptLog::forRun('s', 'a', $this->root);
        $log->toolCall('c1', 'Bash', ['command' => 'sleep 99']);

        $rows = AgentTranscriptTail::messages(AgentTranscriptTail::readAll($log->path()));

        $this->assertSame('c1', $rows[0]->pendingToolCallId);
    }

    public function testLoggedTextIsUntrusted(): void
    {
        $log = SubAgentTranscriptLog::forRun('s', 'a', $this->root);
        $log->assistant("ok\x1b[2J\x1b]0;pwned\x07 \u{E000}zone\u{E001} done\x07");

        $rows = AgentTranscriptTail::messages(AgentTranscriptTail::readAll($log->path()));

        $this->assertSame('ok zone done', $rows[0]->content);
    }

    public function testTheSuiteNeverWritesIntoTheRealHome(): void
    {
        $this->assertStringNotContainsString('/.sugar-crush/', SubAgentTranscriptLog::defaultRoot(), 'tests/bootstrap.php pins the transcript root into the sandbox');
    }

    /**
     * Two processes appending at once never interleave inside a line: each
     * line is one write under an exclusive lock.
     */
    public function testTwoForkedWritersNeverInterleave(): void
    {
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('needs ext-pcntl');
        }

        $log = SubAgentTranscriptLog::forRun('s', 'a', $this->root);
        $log->user('start');
        $pids = [];
        foreach (['A', 'B'] as $who) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                for ($i = 0; $i < 200; $i++) {
                    $log->assistant(str_repeat($who, 1500) . $i);
                }
                \SugarCraft\Crush\Support\ForkedChild::exitNow(0);
            }
            $this->assertGreaterThan(0, $pid);
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        $items = AgentTranscriptTail::readAll($log->path());
        $this->assertCount(401, $items, 'every line decoded whole');
        foreach (\array_slice($items, 1) as $item) {
            $this->assertMatchesRegularExpression('/^(A{1500}|B{1500})\d+$/', $item['text']);
        }
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }
            @rmdir($path);

            return;
        }
        @unlink($path);
    }
}
