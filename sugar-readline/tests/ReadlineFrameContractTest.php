<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Input\Driver\StreamInputDriver;
use SugarCraft\Readline\Readline;
use SugarCraft\Readline\TextPrompt;

/**
 * Frame and descriptor lifecycle contract of Readline::run() (findings
 * 070, 071, 080):
 *
 *  - every run() starts its frame series from row 0, so host output written
 *    to the same stream between submits is never stomped by a stale climb;
 *  - the dead ownedEofDelivered latch (its set-site sat unreachable behind
 *    the very return that would have set it) is gone — EOF honesty rides
 *    feof alone;
 *  - the lazily-owned php://stdin pump is released by the run that drained
 *    it, an un-drained pump survives for the next run (the pump-reuse law
 *    that keeps decoder-buffered bytes across submits), and __destruct is
 *    the final backstop.
 */
final class ReadlineFrameContractTest extends TestCase
{
    public function testHostOutputBetweenRunsIsNeverStompedByAStaleClimb(): void
    {
        $in = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $out = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($in);
        self::assertIsArray($out);
        [$iw, $ir] = $in;
        [$ow, $or] = $out;

        \fwrite($iw, "\r\r");
        \fclose($iw);

        $readline = new Readline(new StreamInputDriver($ir));
        try {
            $readline->run(TextPrompt::new('One'), $ow);
            \fwrite($ow, "HOST\n");
            $readline->run(TextPrompt::new('Two'), $ow);
        } finally {
            \fclose($ir);
            \fclose($ow);
        }

        $captured = '';
        while (false !== ($chunk = \fread($or, 65536)) && $chunk !== '') {
            $captured .= $chunk;
        }
        \fclose($or);

        self::assertSame(
            "\r\x1b[0J\x1b[1;36mOne\x1b[0m\x1b[7m \x1b[0m\n"
            . "HOST\n"
            . "\r\x1b[0J\x1b[1;36mTwo\x1b[0m\x1b[7m \x1b[0m\n",
            $captured,
            'the second run must open at row 0 — no CSI nA climb over the host line'
        );
        self::assertStringNotContainsString("\x1b[1A", $captured);
    }

    public function testDeadEofLatchIsGoneAndReleaseSeamsArePresent(): void
    {
        $source = \file_get_contents(__DIR__ . '/../src/Readline.php');
        self::assertIsString($source);

        self::assertSame(0, \substr_count($source, 'ownedEofDelivered'), 'the never-true latch must stay deleted');
        self::assertSame(1, \substr_count($source, 'private function closeOwnedPump'));
        self::assertSame(1, \substr_count($source, 'public function __destruct'));
        self::assertMatchesRegularExpression(
            '/finally \{.*?feof\(\$this->ownedStream\).*?closeOwnedPump\(\);.*?\}/s',
            $source,
            'the run-end finally must close the owned pump exactly when the stream reached EOF'
        );
    }

    public function testOwnedPumpClosesAfterTheRunThatDrainedStdin(): void
    {
        [$output, $status] = $this->runChild('drained', '');

        self::assertSame(0, $status, $output);
        self::assertStringContainsString("P1|\n", $output, 'empty stdin submits nothing and ends the run');
        self::assertStringContainsString("OWNED|NULL\n", $output, 'a drained owned pump must not outlive its run');
        self::assertStringContainsString("END\n", $output);
        self::assertStringNotContainsString('SPUN', $output);
    }

    public function testUnDrainedOwnedPumpSurvivesForTheNextRun(): void
    {
        [$output, $status] = $this->runChild('queued', "first\nsecond\n");

        self::assertSame(0, $status, $output);
        self::assertStringContainsString("P1|first\n", $output);
        self::assertStringContainsString("P2|second\n", $output, 'pump-reuse law: bytes buffered past the first submit feed the next run');
        self::assertStringContainsString("OWNED|OPEN\n", $output, 'an un-drained pump stays open; __destruct is its backstop');
        self::assertStringNotContainsString('SPUN', $output);
    }

    /**
     * @return array{0:string,1:int} child output and exit status
     */
    private function runChild(string $mode, string $stdin): array
    {
        $child = __DIR__ . '/Support/owned_pump_lifecycle_child.php';
        self::assertFileExists($child);

        $command = 'printf ' . \escapeshellarg($stdin === '' ? '' : $stdin)
            . ' | timeout 20 ' . \escapeshellarg(PHP_BINARY) . ' ' . \escapeshellarg($child) . ' '
            . \escapeshellarg($mode) . ' 2>&1';

        $handle = popen($command, 'r');
        self::assertIsResource($handle);

        $output = '';
        while (!feof($handle)) {
            $chunk = fgets($handle);
            if (false === $chunk) {
                break;
            }
            $output .= $chunk;
        }
        $status = pclose($handle);

        return [$output, $status];
    }
}
