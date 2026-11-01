<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Server\ErrorLog;
use SugarCraft\Code\Server\LogTail;
use SugarCraft\Code\Server\StateDir;

/**
 * C-WEB-5.f: the tail both log doors read. The CLI `serve logs` verb and
 * the admin-scoped `server.logs` RPC share {@see LogTail}, so the pins name
 * exactly what the shared code owes: the window walk answers the last lines
 * across a rotation boundary (the generations {@see StateDir::rotateLog()}
 * stacks), each line reaches the wire flattened to
 * {@see ErrorLog::flatten()}'s shape, a planted non-regular file is refused
 * rather than followed, and the walk covers only the generations a server
 * actually keeps.
 */
final class LogTailTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/crush-logtail-' . \bin2hex(\random_bytes(6));
        \mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (['server.log', 'server.log.1', 'server.log.2', 'server.log.3'] as $name) {
            @\unlink($this->dir . '/' . $name); // unlink takes the file or the symlink itself
        }
        @\unlink($this->dir . '/elsewhere.txt');
        @\rmdir($this->dir);
    }

    public function testTailAnswersTheLastLinesAndTheSizeOffset(): void
    {
        $contents = "line1\nline2\nline3\nline4\nline5\n";
        \file_put_contents($this->dir . '/server.log', $contents);

        [$lines, $offset] = LogTail::tail($this->dir . '/server.log', 2);

        self::assertSame(['line4', 'line5'], $lines);
        self::assertSame(\strlen($contents), $offset);
    }

    public function testAnswerCrossesARotationBoundary(): void
    {
        $state = StateDir::open($this->dir);
        \file_put_contents($state->logPath(), "old-1\nold-2\nold-3\n");
        self::assertTrue($state->rotateLog(0), 'a non-empty log rotates at a zero limit');
        \file_put_contents($state->logPath(), "new-1\nnew-2\nnew-3\n");

        self::assertSame(
            ['lines' => ['old-3', 'new-1', 'new-2', 'new-3'], 'reason' => null],
            LogTail::answer($this->dir, 4),
            'the newest lines come from the live file and the walk continues into .1',
        );
    }

    public function testANonRegularLogFileIsRefused(): void
    {
        // A directory planted at the log name: absent, not tailed.
        \mkdir($this->dir . '/server.log');
        self::assertSame(
            ['lines' => [], 'reason' => 'no_log'],
            LogTail::answer($this->dir, 10),
        );
        \rmdir($this->dir . '/server.log');

        // A symlink out of the state dir: lstat sees the link, not a regular
        // file, so the answer refuses without ever following it.
        \file_put_contents($this->dir . '/elsewhere.txt', "secret-1\nsecret-2\n");
        \symlink($this->dir . '/elsewhere.txt', $this->dir . '/server.log');
        self::assertSame(
            ['lines' => [], 'reason' => 'no_log'],
            LogTail::answer($this->dir, 10),
            'a symlink at server.log is never followed',
        );
    }

    public function testAnswerFlattensControlBytesAndClipsEachLine(): void
    {
        \file_put_contents(
            $this->dir . '/server.log',
            "alpha\x01\x1fbeta\n" . \str_repeat('x', 300) . "\n",
        );

        $answer = LogTail::answer($this->dir, 10);

        self::assertSame('alpha beta', $answer['lines'][0], 'the control-byte run collapses to one space');
        self::assertSame(ErrorLog::MAX_MESSAGE_BYTES, \strlen($answer['lines'][1]), 'each line carries the message bound');
        self::assertStringNotContainsString("\x01", (string) \json_encode($answer['lines']));
        self::assertNull($answer['reason']);
    }

    public function testTheWalkCoversOnlyTheStackedGenerations(): void
    {
        // rotateLog keeps LOG_FILES generations: the live file, .1 and .2.
        // A file planted at .3 is older than anything the rotation retains
        // and must never reach the answer.
        \file_put_contents($this->dir . '/server.log', "top-1\n");
        \file_put_contents($this->dir . '/server.log.1', "mid-1\n");
        \file_put_contents($this->dir . '/server.log.2', "deep-1\n");
        \file_put_contents($this->dir . '/server.log.3', "gone-1\n");

        self::assertSame(
            ['lines' => ['deep-1', 'mid-1', 'top-1'], 'reason' => null],
            LogTail::answer($this->dir, 10),
        );
    }

    public function testAnEmptyLiveFileAnswersEmptyWithoutAReason(): void
    {
        \file_put_contents($this->dir . '/server.log', '');

        self::assertSame(
            ['lines' => [], 'reason' => null],
            LogTail::answer($this->dir, 10),
            'an existing empty log is not the no_log case — there is one, it says nothing',
        );
    }

    public function testTheAnswerCountIsClampedToTheLineBound(): void
    {
        $lines = [];
        for ($n = 1; $n <= LogTail::MAX_LINES + 5; $n++) {
            $lines[] = 'l' . $n;
        }
        \file_put_contents($this->dir . '/server.log', \implode("\n", $lines) . "\n");

        $answer = LogTail::answer($this->dir, LogTail::MAX_LINES + 5);

        self::assertCount(LogTail::MAX_LINES, $answer['lines']);
        self::assertSame('l' . (LogTail::MAX_LINES + 5), \end($answer['lines']), 'the newest line always survives');
        self::assertSame('l6', $answer['lines'][0], 'the oldest asked-for lines are trimmed');
    }
}
