<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\StdioMcpServer;

/**
 * A CLOSED PIPE RESOURCE IS AN EXCEPTION WAITING TO HAPPEN, AND `@` DOES NOT
 * CATCH IT.
 *
 * {@see \SugarCraft\Crush\LSP\LspConnection} guards every pipe access with
 * `is_resource()` and carries the reasoning at
 * {@see \SugarCraft\Crush\LSP\LspConnection::drainStderr()}. This class did not:
 * {@see StdioMcpServer::readLine()}, {@see StdioMcpServer::writeLine()} and
 * {@see StdioMcpServer::absorbStderr()} reached `$this->pipes[N]` behind only a
 * `!== null` check, and `!== null` is not the same question.
 *
 * MEASURED on this host (PHP 8.3.6, Linux 6.8), three consecutive takes,
 * identical every time, against `proc_open()` pipes that had been `fclose()`d:
 *
 *     stream_select(), closed fd the ONLY entry in all three arrays
 *                                  ->  ValueError: No stream arrays were passed
 *     stream_select(), closed fd beside an open one
 *                                  ->  TypeError: supplied resource is not a
 *                                      valid stream resource
 *     fread()  on a closed pipe    ->  TypeError
 *     feof()   on a closed pipe    ->  TypeError
 *     fwrite() on a closed pipe    ->  TypeError
 *
 * All five are EXCEPTIONS, which is why `@` — present on two of those call sites
 * for EINTR and for broken-pipe notices — suppresses none of them. WHICH of the
 * two `stream_select()` raises depends on whether ANY SELECTABLE descriptor
 * survives PHP's filter — not, as it is tempting to write, on whether any valid
 * RESOURCE does. Here that is decided by whether `stderrOpen` put fd 2 in the
 * read set; the first draft of the control row below reached for `STDIN` as its
 * open companion and got the other polarity, because this suite's stdin is not
 * selectable. A guard written to catch one class by name would miss the other;
 * that both are exceptions is the load-bearing half.
 *
 * ⚠️ THE WINDOW IS THIS CLASS'S OWN AND IT IS NOT SHORT. {@see StdioMcpServer::stop()}
 * closes the pipes FIRST — deliberately, because the EOF on fd 0 is what lets a
 * well-behaved server leave without paying the SIGTERM escalation — and only
 * nulls the field after `proc_close()` has returned. So `$this->pipes` holds
 * three CLOSED resources for the whole terminate grace, the signal-9 grace and
 * the wait. Nothing in this synchronous class re-enters that window today, and
 * saying so is the point: it is exactly what `LspConnection` could have said
 * about its own identical window and chose not to.
 *
 * ⚠️ WHAT THIS FILE IS NOT. It is NOT "make the two classes agree". They are
 * behind each other in DIFFERENT respects and the pairwise framing hides that:
 * on this question `StdioMcpServer` is the one missing a guard the sibling
 * documents as necessary, and on the between-exchange stderr drain (E440 and
 * E475) the two are identical and BOTH still open. A change that made them
 * merely match would have closed one of those and not the other.
 */
final class StdioMcpServerClosedPipeGuardTest extends TestCase
{
    private string $tempDir = '';

    /** @var list<array{0: resource, 1: array<int, resource>}> companion children, reaped in tearDown() */
    private array $companions = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sc_stdio_closedpipe_' . getmypid() . '_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        // Only the children this test spawned, by handle — never a pattern
        // sweep. Leaking a process out of a suite is how a sibling run inherits
        // a stranger.
        foreach ($this->companions as [$handle, $pipes]) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_terminate($handle, 9);
            proc_close($handle);
        }
        $this->companions = [];

        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);

        parent::tearDown();
    }

    /**
     * THE KNOWN-POSITIVE CONTROL, and it runs FIRST because everything below it
     * is an assertion that something did NOT throw.
     *
     * A row that says "this returned rather than throwing" is satisfied by a PHP
     * in which closed pipes are harmless, by a fixture whose pipes were never
     * closed, and by a `closePipes()` that has stopped closing anything. This
     * pushes the very resources the rows below hand to the guarded methods
     * through the SAME five call shapes unguarded, and requires every one of
     * them to throw. If this row goes green-by-silence the rest of the file is
     * worthless, and it is written so that it cannot.
     */
    public function testTheClosedPipeHazardIsRealOnThisHostAndPhpVersion(): void
    {
        [$server, $pipes] = $this->startedServerWithClosedPipes();

        try {
            foreach ([0, 1, 2] as $fd) {
                $this->assertFalse(
                    is_resource($pipes[$fd]),
                    "fd {$fd} is still open, so closePipes() did not do what this file assumes",
                );
            }

            $this->assertThrows(
                \ValueError::class,
                static function () use ($pipes): void {
                    $read = [];
                    $write = [$pipes[0]];
                    $except = [];
                    @stream_select($read, $write, $except, 0, 1000);
                },
                'stream_select() with the closed fd as the only entry',
            );

            // ⚠️ THE COMPANION HAS TO BE A REAL, SELECTABLE DESCRIPTOR, and
            // finding that out is what this row is for. MEASURED, PHP 8.3.6,
            // three consecutive takes each, a closed pipe in the write set
            // beside:
            //
            //     another proc_open() pipe  ->  TypeError
            //     `STDIN` on a plain CLI    ->  TypeError
            //     `STDIN` UNDER THIS SUITE  ->  ValueError
            //     a `php://memory` stream   ->  ValueError
            //
            // So the discriminator is not "a valid resource survived the filter"
            // — which is how the sibling's doc-block reads — but "a SELECTABLE
            // one did". A memory stream has no descriptor and is dropped like the
            // closed pipe, leaving every array empty, which is the ValueError
            // path; and this suite's stdin is not selectable either, so the
            // obvious companion silently produced the wrong polarity here.
            $companion = $this->liveCompanionPipe();

            $this->assertThrows(
                \TypeError::class,
                static function () use ($pipes, $companion): void {
                    $read = [$companion];
                    $write = [$pipes[0]];
                    $except = [];
                    @stream_select($read, $write, $except, 0, 1000);
                },
                'stream_select() with the closed fd beside an open one',
            );

            $this->assertThrows(\TypeError::class, static fn () => @fread($pipes[2], 8192), 'fread()');
            $this->assertThrows(\TypeError::class, static fn () => @feof($pipes[1]), 'feof()');
            $this->assertThrows(\TypeError::class, static fn () => @fwrite($pipes[0], 'x'), 'fwrite()');
        } finally {
            $server->stop();
        }
    }

    /**
     * And the three methods survive the window rather than throwing out of it.
     *
     * Each is driven directly, because no path in this synchronous class reaches
     * the window on its own — that is the finding, not a gap in the test: the
     * guard is defensive, and a defensive guard still has to be pinned or the
     * next reader deletes it.
     */
    public function testTheThreePipeReadersReturnRatherThanThrowInsideTheTeardownWindow(): void
    {
        [$server, ] = $this->startedServerWithClosedPipes();

        try {
            $readLine = new \ReflectionMethod($server, 'readLine');
            $writeLine = new \ReflectionMethod($server, 'writeLine');
            $absorb = new \ReflectionMethod($server, 'absorbStderr');

            $this->assertNull(
                $readLine->invoke($server, microtime(true) + 0.2),
                'readLine() must report "nothing more" for a closed stdout rather than raising',
            );
            $this->assertFalse(
                $writeLine->invoke($server, '{"jsonrpc":"2.0","method":"x"}', microtime(true) + 0.2),
                'writeLine() must report a failed write for a closed stdin rather than raising',
            );

            $absorb->invoke($server);
            $this->addToAssertionCount(1);
        } finally {
            $server->stop();
        }
    }

    /**
     * THE OTHER POLARITY. With the pipes OPEN the same three methods still do
     * their job — otherwise a guard that returned early unconditionally would
     * satisfy every row above while breaking the class outright.
     */
    public function testTheGuardsDoNotShortCircuitAHealthySession(): void
    {
        $server = $this->startedServer();

        try {
            $writeLine = new \ReflectionMethod($server, 'writeLine');
            $readLine = new \ReflectionMethod($server, 'readLine');

            $this->assertTrue(
                $writeLine->invoke($server, '{"jsonrpc":"2.0","id":"9","method":"echo"}', microtime(true) + 5.0),
                'writeLine() refused a healthy pipe, so the guard is short-circuiting the live path',
            );

            $line = $readLine->invoke($server, microtime(true) + 5.0);

            $this->assertIsString($line, 'readLine() returned nothing from a server that answered');
            $this->assertStringContainsString('"id":"9"', $line, 'the reply was not the one this request asked for');
        } finally {
            $server->stop();
        }
    }

    /**
     * The window this file is about is a real window, not an argument: after
     * {@see StdioMcpServer::closePipes()} the FIELD is still populated.
     *
     * Pinned separately from the rows above because it is what makes them a
     * statement about `pipes !== null` being insufficient rather than a
     * statement about reflection. Mutating `closePipes()` to null the field
     * would satisfy every other row here and reds exactly this one — and it
     * would also destroy the EOF-before-signal ordering
     * {@see StdioMcpServer::stop()} depends on, which is why the answer is a
     * guard rather than an earlier null.
     */
    public function testClosePipesLeavesThePopulatedFieldBehindWhichIsWhyNullIsTheWrongQuestion(): void
    {
        $server = $this->startedServer();

        try {
            $pipesProp = new \ReflectionProperty(StdioMcpServer::class, 'pipes');
            $before = $pipesProp->getValue($server);

            $this->assertIsArray($before, 'the control: a started server has pipes at all');

            (new \ReflectionMethod($server, 'closePipes'))->invoke($server);
            $after = $pipesProp->getValue($server);

            $this->assertIsArray(
                $after,
                'closePipes() nulled the field, so the window this file guards no longer exists — '
                . 'check that stop() still closes the pipes BEFORE the escalation ladder, because '
                . 'that ordering is worth a hundredfold on a server that traps SIGTERM',
            );
            $this->assertFalse(is_resource($after[1]), 'closePipes() left fd 1 open');
        } finally {
            $server->stop();
        }
    }

    // =========================================================================
    // Fixtures and helpers
    // =========================================================================

    /**
     * A live, SELECTABLE stream to sit beside a closed one, and the child that
     * owns it is reaped in {@see tearDown()}.
     *
     * A `proc_open()` pipe rather than `STDIN` or `php://memory` — see the row
     * that uses it for the measurement of why those two give the other polarity.
     *
     * @return resource
     */
    private function liveCompanionPipe()
    {
        $handle = proc_open(
            [PHP_BINARY, '-r', 'usleep(3000000);'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($handle, 'could not spawn the companion child');
        $this->companions[] = [$handle, $pipes];

        return $pipes[2];
    }

    /** @param callable(): mixed $run */
    private function assertThrows(string $class, callable $run, string $what): void
    {
        try {
            $run();
        } catch (\Throwable $e) {
            $this->assertInstanceOf(
                $class,
                $e,
                $what . ' raised ' . get_class($e) . ' rather than ' . $class . ': ' . $e->getMessage(),
            );

            return;
        }

        $this->fail(
            $what . ' on a closed pipe did not throw on ' . \PHP_VERSION . '. Either this host no '
            . 'longer needs the guards this file pins, or the pipes were not closed — check the '
            . 'is_resource() assertions above before touching the guards.',
        );
    }

    private function startedServer(): StdioMcpServer
    {
        $script = $this->tempDir . '/echo.php';
        file_put_contents($script, self::ECHO_SERVER);

        $server = new StdioMcpServer(
            name: 'closedpipe',
            command: PHP_BINARY,
            args: [$script],
            env: [],
            startTimeoutSeconds: 5.0,
        );
        $server->start();

        return $server;
    }

    /**
     * A started server whose pipes have been closed with the field left
     * populated — i.e. the exact state {@see StdioMcpServer::stop()} spends its
     * whole escalation budget in.
     *
     * @return array{0: StdioMcpServer, 1: array<int, resource>}
     */
    private function startedServerWithClosedPipes(): array
    {
        $server = $this->startedServer();
        /** @var array<int, resource> $pipes */
        $pipes = (new \ReflectionProperty(StdioMcpServer::class, 'pipes'))->getValue($server);

        (new \ReflectionMethod($server, 'closePipes'))->invoke($server);

        return [$server, $pipes];
    }

    /**
     * Answers the handshake and then echoes every request's id back, so the
     * healthy-path row has something to assert ON rather than merely "did not
     * throw".
     */
    private const ECHO_SERVER = <<<'PHP'
        <?php
        $in = fopen('php://stdin', 'rb');
        while (($line = fgets($in)) !== false) {
            $message = json_decode(trim($line), true);
            if (!is_array($message) || !isset($message['id'])) {
                continue;   // a notification: `initialized` expects no reply
            }
            fwrite(STDOUT, json_encode([
                'jsonrpc' => '2.0',
                'id' => (string) $message['id'],
                'result' => ['tools' => [], 'echoed' => $message['method'] ?? null],
            ]) . "\n");
            fflush(STDOUT);
        }
        PHP;
}
