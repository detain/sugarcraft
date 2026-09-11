<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Plugin\ExternalModule;

/**
 * Teardown behaviour of {@see ExternalModule} — E366.
 *
 * The destructor used to close the three pipes and walk into a bare
 * `proc_close()`. E366 measured both failure shapes of that pair on this
 * tree: `proc_close()` WAITS, so a plugin that ignores stdin EOF pinned the
 * dashboard's shutdown to a third-party binary forever; and a dropped
 * handle without any close ABANDONS a live child to init, holding every
 * descriptor above 2 this process owned. The fix is the bounded
 * SIGTERM→poll→SIGKILL ladder; these tests drive each rung with REAL
 * children, because every assertion here is about what the kernel says
 * afterwards.
 *
 * The spawn goes through `ExternalModule::startProcess()` by reflection,
 * skipping the JSON init handshake: the ladder's contract concerns the
 * process handle alone, and a protocol-complete stub would couple these
 * teardown assertions to fixture chatter.
 *
 * Every test that arms a live child also arms a background killer
 * (`sh -c 'sleep N; pkill -9 -f MARKER'`) so a regression that breaks the
 * ladder can hang ONE test at the harness timeout rather than wedge the
 * suite — the watchdog shape `sugar-reel`'s AudioPlayerTest already uses.
 */
final class ExternalModuleTeardownTest extends TestCase
{
    /** Unique substring so the pkill watchdog matches only this file's children. */
    private const MARKER = 'sugar-dash-teardown-child';

    /** Ready-file path to clean up in tearDown ('' when no fixture armed one). */
    private string $readyPath = '';

    public function testDestructKillsAPluginThatIgnoresStdinEofAndSigtermAndLeavesNoChild(): void
    {
        if (!\function_exists('pcntl_signal')) {
            $this->markTestSkipped('the stubborn-child fixture needs ext-pcntl in the child');
        }

        // Ready-file handshake: proc_open() answers once the KERNEL has the
        // child — microseconds before PHP bootstraps to `pcntl_signal`. A
        // TERM in that window kills a child whose stubbornness was never
        // installed, and the bounded assertions would pass for a reason they
        // did not earn (measured racing this way under phpunit next door).
        $ready = \sys_get_temp_dir() . '/df-dash-' . \getmypid() . '.ready';
        @\unlink($ready);
        $module = $this->spawn(
            '-r',
            'if (function_exists("pcntl_signal")) { pcntl_signal(SIGTERM, SIG_IGN); }'
            . " touch('" . $ready . "'); // " . self::MARKER . "\n"
            . 'while (true) { usleep(50000); }',
        );

        try {
            $deadline = \microtime(true) + 5.0;
            while (!\file_exists($ready) && \microtime(true) < $deadline) {
                \usleep(10_000);
            }
            $this->assertFileExists($ready, 'the stubborn fixture never reached its signal-handler line');
            $this->readyPath = $ready;
            $pid = $this->childPid($module);
            $this->assertTrue(
                \posix_kill($pid, 0),
                'the stubborn child must be alive before the destructor runs',
            );

            $start = \microtime(true);
            unset($module);
            $elapsed = \microtime(true) - $start;

            // Ladder budget: grace 2s + TERM 1s + KILL-confirm 1s, with a
            // second of slack for a loaded runner. Without the escalation
            // this is where proc_close() blocks forever instead.
            $this->assertLessThan(
                6.0,
                $elapsed,
                'the destructor must bound its wait even against a child that ignores EOF and SIGTERM',
            );
            $this->assertFalse(
                \posix_kill($pid, 0),
                'the escalated SIGKILL must have ended the child, and proc_close() must have reaped it',
            );
        } finally {
            $this->cancelWatchdog();
        }
    }

    public function testDestructExitsFastForAChildThatClosesOnStdinEof(): void
    {
        // `cat` reads until EOF and exits — the polite plugin shape the
        // grace rung exists for. The destructor must not wait out the full
        // grace window for a child that is already leaving.
        $module = $this->spawn('/bin/cat');

        $start = \microtime(true);
        unset($module);
        $elapsed = \microtime(true) - $start;

        $this->assertLessThan(
            1.0,
            $elapsed,
            'a cooperative child must be released on the grace rung, not the escalation',
        );
    }

    public function testDestructAnswersImmediatelyForAnAlreadyExitedChild(): void
    {
        $module = $this->spawn('-r', 'exit(0);');

        // Let the child actually die first, so this measures the exited path
        // rather than the grace poll.
        $deadline = \microtime(true) + 2.0;
        while (\microtime(true) < $deadline) {
            if (!$this->childRunning($module)) {
                break;
            }
            \usleep(20_000);
        }
        $this->assertFalse($this->childRunning($module), 'the child should have exited by now');

        $start = \microtime(true);
        unset($module);
        $elapsed = \microtime(true) - $start;

        $this->assertLessThan(0.5, $elapsed, 'an already-exited child must reap without signalling');
    }

    // ─── harness ────────────────────────────────────────────────────────────

    /** @var resource|null */
    private $watchdogHandle = null;

    /**
     * Start the module's process directly, skipping the init handshake.
     *
     * The first argument is either an absolute program path or `-r`, which
     * makes the command `PHP_BINARY` + code — the same spawn shape the real
     * plugin path takes (argv array, pipes for stdio).
     */
    private function spawn(string $firstArg, string $codeOrPath = ''): ExternalModule
    {
        $args = $firstArg === '-r'
            ? ['-r', $codeOrPath]
            : [];
        $command = $firstArg === '-r' ? \PHP_BINARY : $firstArg;

        $module = new ExternalModule('teardown-test', $command, $args);
        (new \ReflectionMethod($module, 'startProcess'))->invoke($module);

        if ($firstArg === '-r' && str_contains($codeOrPath, self::MARKER)) {
            $this->armWatchdog();
        }

        return $module;
    }

    private function armWatchdog(): void
    {
        // If the ladder regresses and the child outlives the test, this kills
        // every marked child after 25s. `timeout` cannot do this job: the
        // hang would be inside proc_close() of the test process itself.
        $this->watchdogHandle = \proc_open(
            ['sh', '-c', 'sleep 25; pkill -9 -f ' . self::MARKER],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($this->watchdogHandle);
        foreach ($pipes as $pipe) {
            \fclose($pipe);
        }
    }

    protected function tearDown(): void
    {
        if ($this->readyPath !== '' && \is_file($this->readyPath)) {
            \unlink($this->readyPath);
        }
        $this->readyPath = '';
    }

    private function cancelWatchdog(): void
    {
        if ($this->watchdogHandle !== null && \is_resource($this->watchdogHandle)) {
            \proc_terminate($this->watchdogHandle);
            \proc_close($this->watchdogHandle);
        }
        $this->watchdogHandle = null;
    }

    private function childPid(ExternalModule $module): int
    {
        $property = new \ReflectionProperty($module, 'process');
        $handle = $property->getValue($module);
        $this->assertIsResource($handle);

        return (int) (\proc_get_status($handle)['pid'] ?? -1);
    }

    private function childRunning(ExternalModule $module): bool
    {
        $property = new \ReflectionProperty($module, 'process');
        $handle = $property->getValue($module);
        $this->assertIsResource($handle);

        return (bool) (\proc_get_status($handle)['running'] ?? false);
    }
}
