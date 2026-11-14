<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Plugin\ExternalModule;

/**
 * D-100 (sugar-dashboard-088 + 094) — the disable seam owns the reap.
 *
 * Two halves of one promise. First: a plugin whose binary cannot be spawned
 * used to throw a RuntimeException out of init(), so discovery auto-loading
 * one bad file bricked dashboard startup; init() now routes the refusal
 * through disable() and the panel says "binary not found" honestly. Second:
 * disable() itself only flipped a flag — the child and two of our pipe
 * descriptors survived until __destruct, so every disable during a live
 * session (wedged chatty plugin, protocol breach) leaked a process and its
 * fds until the host finally let the object die. disable() now closes the
 * three pipes and walks the bounded TERM→KILL ladder itself; __destruct is
 * the backstop for the never-disabled path.
 *
 * The chatty fake floods unparsable lines and never reads stdin: it is
 * alive, mid-write, when the first line disables the panel — the exact
 * shape the deferred reap could not reach. Its pid is announced in a file
 * before the flood, and the assertions ask the kernel, not the object.
 */
final class ExternalModuleDisableReapTest extends TestCase
{
    private string $childPath = '';

    private string $pidPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('the reap proof asks the POSIX kernel about a pid');
        }
        $this->childPath = __DIR__ . '/Support/disable-reap-chatty-child.php';
        $this->pidPath = \sys_get_temp_dir() . '/df-dash-reap-' . \getmypid() . '-' . \hrtime(true) . '.pid';
        @\unlink($this->pidPath);
    }

    protected function tearDown(): void
    {
        if ($this->pidPath !== '') {
            $pid = (int) @\file_get_contents($this->pidPath);
            if ($pid > 0 && $this->pidAlive($pid)) {
                // Regression leash: an unreaped chatty child must not
                // outlive this test process.
                @\posix_kill($pid, SIGKILL);
            }
            @\unlink($this->pidPath);
        }
        parent::tearDown();
    }

    public function testMissingBinaryInitDisablesThePanelInsteadOfThrowing(): void
    {
        $module = new ExternalModule('ghost-plugin', '/definitely/not/a/binary');

        // The whole point of the init-boundary catch: no exception escapes
        // into the host loop, and the state shape stays a panel description.
        $state = $module->init();

        $this->assertSame('ghost-plugin', $state['name']);
        $this->assertSame(0, $state['interval']);

        $rendered = $module->view(['unused'], 40, 4);
        $this->assertStringContainsString('ghost-plugin disabled', $rendered);
        $this->assertStringContainsString('binary not found: /definitely/not/a/binary', $rendered);
    }

    public function testDisableReapsTheChattyChildAtTheSeamNotAtDestruct(): void
    {
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('ext-posix is required for the kill -0 reap proof');
        }

        $module = new ExternalModule(
            'chatty',
            \PHP_BINARY,
            ['-f', $this->childPath, $this->pidPath],
        );

        // First unparsable line breaches the protocol, which disables the
        // panel from inside readResponse() mid-init().
        $state = $module->init();
        $this->assertSame(0, $state['interval'], 'a protocol-breach disable must not carry an interval');

        $pid = 0;
        $deadline = \microtime(true) + 5.0;
        while (\microtime(true) < $deadline) {
            $candidate = (int) @\file_get_contents($this->pidPath);
            if ($candidate > 0) {
                $pid = $candidate;
                break;
            }
            \usleep(10_000);
        }
        $this->assertGreaterThan(0, $pid, 'the chatty fixture never announced its pid');

        // Kernel truth, not object truth: proc_close inside the ladder has
        // WAITED, so a reaped child answers nothing to kill -0. An unreaped
        // one (flag-only disable, the old shape) is either live or a zombie —
        // both answer TRUE.
        $this->assertFalse(
            $this->pidAlive($pid),
            "disable() left child {$pid} alive: the reap must happen at the disable seam",
        );

        // And our three descriptors are gone with it (the fd-leak half of
        // sugar-dashboard-094: a wedged plugin plus two leaked pipes per
        // disable, until the object died).
        foreach (['stdin', 'stdout', 'stderr', 'process'] as $property) {
            $handle = (new \ReflectionProperty(ExternalModule::class, $property));
            $this->assertNull($handle->getValue($module), "the {$property} handle must be closed at disable()");
        }
    }

    private function pidAlive(int $pid): bool
    {
        return @\posix_kill($pid, 0);
    }
}
