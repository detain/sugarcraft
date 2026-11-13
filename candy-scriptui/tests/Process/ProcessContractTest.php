<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Process;

use PHPUnit\Framework\TestCase;
use SugarCraft\ScriptUi\Process\FakeProcess;
use SugarCraft\ScriptUi\Process\Process;
use SugarCraft\ScriptUi\Process\RealProcess;

/**
 * Pins the consolidated process contract (scriptui-01, gate G1): one
 * class serves spin in production, the lifecycle verbs live on the
 * interface, and the deprecated double-copy shape stays gone. If the
 * sugar-pty pump-vocabulary rider later lands and the implementation
 * folds onto PosixProcess, THIS test is the proof the fold kept shape.
 */
final class ProcessContractTest extends TestCase
{
    protected function setUp(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('ProcessContractTest spawns /bin/sh children (Unix only).');
        }
    }

    /**
     * The interface itself must carry the lifecycle verbs, not just the
     * two implementations — typed call sites (SpinCommand) and test
     * doubles are then checked against the same roster.
     */
    public function testInterfaceCarriesTheLifecycleVocabulary(): void
    {
        $methods = \array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(Process::class))->getMethods(),
        );

        foreach ([
            'pid', 'exited', 'wait', 'exitCode', 'kill',      // sugar-pty contract
            'stdoutBytes', 'stderrBytes',                      // capture accessors
            'drain', 'terminate', 'close', 'stdout', 'stderr', // spin-chain additions
        ] as $verb) {
            $this->assertContains($verb, $methods, "Process interface must declare {$verb}()");
        }
    }

    public function testBothImplementationsHonourTheContract(): void
    {
        $this->assertContains(Process::class, \class_implements(RealProcess::class));
        $this->assertContains(Process::class, \class_implements(FakeProcess::class));
        $this->assertTrue(\class_exists(RealProcess::class));
    }

    /**
     * The consolidation proof: no file under src/Process keeps a
     * @deprecated banner, and the canonical handle no longer delegates
     * to a second in-tree lifecycle copy (sugar-pty PosixProcess).
     */
    public function testDeprecatedDoubleCopyShapeIsGone(): void
    {
        $files = \glob(\dirname(__DIR__, 2) . '/src/Process/*.php');
        $this->assertIsArray($files);
        $this->assertNotCount(0, $files);

        foreach ($files as $file) {
            $source = \file_get_contents($file);
            $this->assertIsString($source);
            $this->assertStringNotContainsString(
                '@deprecated',
                $source,
                \basename($file) . ' must not carry a deprecated-shape banner after scriptui-01',
            );
        }

        $canonical = \file_get_contents(\dirname(__DIR__, 2) . '/src/Process/RealProcess.php');
        $this->assertIsString($canonical);
        $this->assertStringNotContainsString('use SugarCraft\Pty\Posix\PosixProcess;', $canonical,
            'one copy owns the lifecycle: no import of the second lifecycle copy');
        $this->assertStringNotContainsString('PosixProcess::', $canonical,
            'one copy owns the lifecycle: no static delegation to the second copy');
        $this->assertStringNotContainsString('$this->inner', $canonical,
            'one copy owns the lifecycle: no delegating inner handle');
        $this->assertStringContainsString('proc_open', $canonical, 'the canonical copy owns the spawn');
        $this->assertStringContainsString('SIGKILL', $canonical, 'the canonical copy owns the reap ladder');
    }

    /**
     * drain() is the run-loop pump vocabulary: bytes appended incrementally
     * while the child is still writing, with no full-buffer re-copy. The
     * child phases its output so an early drain sees ONLY the first chunk.
     */
    public function testDrainPumpsNewBytesWhileChildRuns(): void
    {
        $proc = RealProcess::spawn(
            ['/bin/sh', '-c', 'printf first; sleep 1.5; printf second; exit 0'],
            captureStdout: true,
        );

        $deadline = \microtime(true) + 2.0;
        while (\microtime(true) < $deadline) {
            $proc->drain();
            if ($proc->stdoutBytes() === 'first') {
                break;
            }
            \usleep(5_000);
        }
        $this->assertSame('first', $proc->stdoutBytes(), 'early drain must see only the first phase');
        $this->assertNull($proc->exitCode(), 'child must still be running at the first-phase check');

        $this->assertSame(0, $proc->wait());
        $this->assertSame('firstsecond', $proc->stdoutBytes(), 'final state must hold both phases');
        $this->assertSame(0, $proc->close());
    }

    /**
     * terminate() signals without reaping; close() is the sole bounded
     * reap and is idempotent — second call returns the same code and
     * terminate after close is a no-op (the pid-reuse guard).
     */
    public function testTerminateCloseIdempotenceLadder(): void
    {
        $proc = RealProcess::spawn(['/bin/sh', '-c', 'sleep 60']);
        $this->assertNull($proc->exitCode());

        $proc->terminate();
        $proc->terminate(); // idempotent: must not re-signal
        $code = $proc->close();

        $this->assertTrue($proc->exited());
        $this->assertContains($code, [-1, 143], 'SIGTERM death must yield 143 or the -1 reap-race convention');
        $this->assertSame($code, $proc->close(), 'close() must be idempotent');
        $proc->terminate(); // after close: must be a silent no-op
        $this->assertSame($code, $proc->close());
    }

    public function testFakeProcessRecordsPumpCalls(): void
    {
        $fake = new FakeProcess();
        $this->assertSame(0, $fake->drainCount);
        $fake->drain();
        $fake->drain();
        $this->assertSame(2, $fake->drainCount, 'the pump call must be observable from tests');
    }
}
