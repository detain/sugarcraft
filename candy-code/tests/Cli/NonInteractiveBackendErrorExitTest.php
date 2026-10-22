<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Message;

/**
 * 1021 v1:F-5 — a command-tier nonzero exit must cost the caller an exit 1.
 *
 * THE DEFECT: `CANDY_CODE_BACKEND_CMD`/`_STREAM` failures do not throw — the
 * command backends answer with an error-marked message ("_[error: backend
 * exited 3]_") and `NonInteractive::run()` printed it and returned EXIT_OK.
 * A CI pipeline whose delegated backend died exited 0; the failure was
 * observable only by text-scanning stdout, which nothing obliges a caller to
 * do. The fix maps `$message->error` on the final return to EXIT_FAILURE —
 * the answer still prints, the session still persists, SessionEnd still
 * fires; the change is status-code-only and DISCLOSED as intentional.
 *
 * THE CONTROL ARM matters as much as the keystone: an unmarked answer must
 * still exit 0 (the TUI's leniency and every healthy pipeline ride it), and
 * the child run proves the mapping reaches the real binary through the real
 * command backend, not just through an injected double.
 */
final class NonInteractiveBackendErrorExitTest extends TestCase
{
    private const TIMEOUT_SECONDS = 20;

    private string $tempHome = '';

    protected function tearDown(): void
    {
        if ($this->tempHome !== '' && is_dir($this->tempHome)) {
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempHome, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            ) as $node) {
                $node->isDir() ? @rmdir($node->getPathname()) : @unlink($node->getPathname());
            }
            @rmdir($this->tempHome);
        }
        $this->tempHome = '';
    }

    private function errorMarkedBackend(string $reply): Backend
    {
        return new class($reply) implements Backend {
            public function __construct(private readonly string $reply)
            {
            }

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant($this->reply)->withUiOnly()->withError();
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): \React\Promise\PromiseInterface
            {
                return \React\Promise\resolve($this->complete($history, $onToken));
            }
        };
    }

    private function cleanBackend(string $reply): Backend
    {
        return new class($reply) implements Backend {
            public function __construct(private readonly string $reply)
            {
            }

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant($this->reply);
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): \React\Promise\PromiseInterface
            {
                return \React\Promise\resolve($this->complete($history, $onToken));
            }
        };
    }

    public function testAnErrorMarkedAnswerExitsFailureAndStillPrintsTheAnswer(): void
    {
        $args = ArgvParser::parse(['candy-code', '-p', 'hello']);

        \ob_start();
        $code = NonInteractive::run($args, $this->errorMarkedBackend('_[error: backend exited 3]_'));
        $output = (string) \ob_get_clean();

        $this->assertSame(NonInteractive::EXIT_FAILURE, $code, 'F-5: an error-marked answer must not exit 0');
        $this->assertStringContainsString('backend exited 3', $output, 'the mapping is status-only: the answer text still rides stdout');
    }

    public function testACleanAnswerStillExitsOk(): void
    {
        $args = ArgvParser::parse(['candy-code', '-p', 'hello']);

        \ob_start();
        $code = NonInteractive::run($args, $this->cleanBackend('all good'));
        $output = (string) \ob_get_clean();

        $this->assertSame(NonInteractive::EXIT_OK, $code);
        $this->assertStringContainsString('all good', $output);
    }

    public function testACommandBackendNonzeroExitMakesTheRealBinaryExitOne(): void
    {
        [$stdout, $stderr, $exitCode] = $this->runOneShotChild([
            'CANDY_CODE_BACKEND_CMD' => "printf 'boom\\n' >&2; exit 3",
        ]);

        $this->assertSame(
            1,
            $exitCode,
            'F-5 through the real command tier: exit 0 here is the silent-pipeline defect. stderr: ' . $stderr
        );
        $this->assertStringContainsString('backend exited 3', $stdout);
    }

    /**
     * Compact one-shot child of `bin/candy-code` with a curated env (no
     * inherited CANDY_CODE_* — the harness trap the program records: any
     * launch-brick repro must run WITHOUT a live CANDY_CODE_BACKEND_CMD, and
     * the same discipline keeps a leaked operator value from deciding what
     * this test's child actually runs).
     *
     * @param array<string,string> $overrides
     * @return array{0:string,1:string,2:int}
     */
    private function runOneShotChild(array $overrides): array
    {
        $root = dirname(__DIR__, 2);
        $this->tempHome = sys_get_temp_dir() . '/nih_be_exit_' . getmypid() . '_' . uniqid('', true);
        mkdir($this->tempHome, 0700, true);

        $command = 'exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/candy-code')
            . ' ' . escapeshellarg('-p') . ' ' . escapeshellarg('ping');

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, $root, array_merge([
            'PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME' => $this->tempHome,
            'TMPDIR' => $this->tempHome,
        ], $overrides));
        $this->assertIsResource($process);

        fclose($pipes[0]);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        while (microtime(true) < $deadline) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            if (!proc_get_status($process)['running']) {
                break;
            }
            usleep(20000);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [$stdout, $stderr, $exitCode];
    }
}
