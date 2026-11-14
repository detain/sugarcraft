<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Input\Driver\StreamInputDriver;
use SugarCraft\Readline\Readline;
use SugarCraft\Readline\TextPrompt;

/**
 * Paint-by-default (finding 039): omitting the output argument to run()
 * paints to STDOUT and logs a one-time steering notice; an explicit null
 * keeps the old headless contract — state advances, nothing is written,
 * no notice. Pinned in child processes because STDOUT resolution and the
 * one-time static only mean something per process.
 */
final class ReadlinePaintDefaultTest extends TestCase
{
    public function testOmittedOutputPaintsToStdoutAndNoticesExactlyOnce(): void
    {
        $output = $this->runChild('omitted');
        self::assertStringContainsString('DONE|one|two', $output);
        self::assertLessThan(\strpos($output, 'DONE'), \strpos($output, "\x1b[0J"), 'frames precede the DONE marker');
        self::assertGreaterThanOrEqual(4, \substr_count($output, "\x1b[0J"), 'both runs painted frames');
        self::assertSame(1, \substr_count($output, 'paints to STDOUT'), 'the steering notice is one-time per process');
    }

    public function testExplicitNullKeepsHeadlessDrivingSilent(): void
    {
        $output = $this->runChild('null');
        self::assertStringContainsString('DONE|one|two', $output, 'state still advances');
        self::assertStringNotContainsString("\x1b[0J", $output);
        self::assertStringNotContainsString('paints to STDOUT', $output);
    }

    public function testSentinelIsTheSignatureDefaultAndNullIsItsOwnValue(): void
    {
        $param = (new \ReflectionMethod(Readline::class, 'run'))->getParameters()[1];
        self::assertTrue($param->isDefaultValueAvailable());
        self::assertSame(Readline::PAINT_DEFAULT, $param->getDefaultValue());
    }

    public function testExplicitNullPromptDrivesInProcess(): void
    {
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        [$w, $r] = $pair;
        \fwrite($w, "hi\r");
        \fclose($w);
        $final = (new Readline(new StreamInputDriver($r)))->run(new TextPrompt('> '), null);
        \fclose($r);
        self::assertTrue($final->isSubmitted());
        self::assertSame('hi', $final->value());
    }

    private function runChild(string $mode): string
    {
        $child = __DIR__ . '/Support/paint_default_child.php';
        self::assertFileExists($child);
        $command = "printf 'one\\ntwo\\n' | timeout 20 "
            . \escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg($child)
            . ' ' . \escapeshellarg($mode) . ' 2>&1';
        $handle = \popen($command, 'r');
        self::assertIsResource($handle);
        $output = '';
        while (!\feof($handle)) {
            $chunk = \fread($handle, 8192);
            if (false === $chunk || $chunk === '') {
                break;
            }
            $output .= $chunk;
        }
        \pclose($handle);
        return $output;
    }
}
