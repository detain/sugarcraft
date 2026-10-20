<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Session;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Session\SessionStore;
use SugarCraft\Code\Support\SqliteConnect;
use SugarCraft\Code\Tests\Support\RunsWallClockBoundedChildTrait;
use SugarCraft\Code\Usage;

/**
 * SES-1: two real processes writing one sessions.db both succeed, because
 * every connection in `src/` now arms a bounded busy wait — and the
 * negative control shows the same contention refused INSTANTLY without it.
 *
 * This is the shape the concurrent waves shipped (a TUI and `candy-code
 * serve` on one file); the dropped durable spend row it re-proves was
 * silent because `appendUsageRecord()` fail-softs by design. The fail-soft
 * stays (a busy database must never cost the user their turn); what the fix
 * adds is the wait that makes the drop rare and the notice that makes it
 * loud. The loudness half is pinned in `SessionStoreBusyTimeoutTest`; this
 * file pins the wait, across a process boundary, because the busy handler
 * only ever fires against another CONNECTION.
 *
 * Bounded wall clock: the holding child sleeps 0.8 s inside its transaction
 * and exits regardless — no unbounded child here; the negative child is
 * bounded through the shared `timeout -s KILL` trait wrapper.
 */
final class SessionStoreBusyContentionTest extends TestCase
{
    use RunsWallClockBoundedChildTrait;

    /** How long the holding child keeps its write transaction open. */
    private const CHILD_HOLD_MICROSECONDS = 800_000;

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/busycontention_' . \uniqid('', true);
        \mkdir($this->dir, 0700, true);
        EnhancedSessionStore::forgetDroppedUsageNotice();
        RuntimeNoticeSink::reset();
    }

    protected function tearDown(): void
    {
        RuntimeNoticeSink::reset();
        foreach (\glob($this->dir . '/*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->dir);
    }

    public function testTwoProcessesAppendingTheSameLedgerBothSucceed(): void
    {
        $db = $this->dir . '/sessions.db';
        // Both stores exist BEFORE the race so neither constructor's schema
        // work rides inside the timed window.
        $parent = new EnhancedSessionStore($db);
        $this->assertSame(
            SqliteConnect::BUSY_TIMEOUT_MS,
            (int) (new ReflectionProperty($parent, 'pdo'))
                ->getValue($parent)
                ->query('PRAGMA busy_timeout')
                ->fetchColumn(),
            'the store the fix arms must answer the wait it claims',
        );

        $child = \proc_open(
            [PHP_BINARY, $this->writeChildScript('holder'), $db, \dirname(__DIR__, 2) . '/vendor/autoload.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        \is_resource($child) or self::fail('could not spawn the holding child');
        $settled = false;

        try {
            // Read the child's HELD line with a bounded deadline: the child
            // is only proof once it actually holds the write lock.
            $held = $this->readLineWithDeadline($pipes[1], 8.0);
            self::assertSame('HELD', $held, 'the child never reported holding the write lock');

            $started = \microtime(true);
            $appended = $parent->appendUsageRecord('sibling-of-holder', Usage::new(120, 0.002), 'test-model');
            $waited = \microtime(true) - $started;

            self::assertTrue(
                $appended,
                'SES-1 regression: with the busy timeout armed a concurrent writer must wait, not drop',
            );
            self::assertGreaterThanOrEqual(0.1, $waited, 'the append did not actually contend — an uncontended pass proves nothing');
            self::assertLessThan(SqliteConnect::BUSY_TIMEOUT_MS / 1000.0, $waited, 'the wait outran the bound the connector arms');

            // The drop notice must NOT have fired on a happy race.
            self::assertSame([], RuntimeNoticeSink::drain(), 'a successful append must announce nothing');

            [$status, $lines] = $this->finish($child, $pipes);
            $settled = true;
            self::assertSame(0, $status, 'the holding child failed: ' . implode(' | ', $lines));
            self::assertContains('COMMITTED', $lines);
        } finally {
            // Never leak a live child on a red: bounded reap, drain then close.
            if (!$settled) {
                $this->forceGone($child, $pipes);
            }
        }

        $totals = $parent->usageTotals('sibling-of-holder');
        self::assertNotNull($totals, 'the durable row the wait won must be readable');
        self::assertSame(1, $totals['calls']);
    }

    public function testTheSameContentionFailsImmediatelyWithoutTheWait(): void
    {
        $db = $this->dir . '/sessions-bare.db';
        $holder = new SessionStore($db);
        $childScript = $this->writeChildScript('bare');

        // The parent holds the write lock in an armed BEGIN IMMEDIATE (the
        // wait is irrelevant to HOLDING); the child — the shape every store
        // had before SES-1, with the busy wait explicitly DISARMED —
        // attempts from inside that held window. It must come back BUSY
        // instantly rather than waiting: that is the negative control
        // showing the mechanism, not the machine being fast.
        [$status, $lines] = $holder->immediateTransaction(
            fn(): array => $this->runWallClockBoundedChild(
                '',
                \escapeshellarg(PHP_BINARY) . ' ' . \escapeshellarg($childScript) . ' ' . \escapeshellarg($db) . ' 2>&1',
            ),
        );

        self::assertSame(0, $status, 'the bare child must report, not crash: ' . implode(' | ', $lines));
        self::assertContains('BUSY', $lines, 'without the armed wait a held lock must refuse at once — SES-1 chose the wait precisely because this is the alternative');
    }

    /**
     * Read one newline-terminated line, refusing to block forever.
     *
     * @param resource $pipe
     */
    private function readLineWithDeadline($pipe, float $seconds): ?string
    {
        $deadline = \microtime(true) + $seconds;
        $buffer = '';
        \stream_set_blocking($pipe, false);
        while (\microtime(true) < $deadline) {
            $chunk = \fread($pipe, 4096);
            if (\is_string($chunk) && $chunk !== '') {
                $buffer .= $chunk;
                $cut = \strpos($buffer, "\n");
                if ($cut !== false) {
                    return \rtrim(\substr($buffer, 0, $cut), "\r");
                }
            }
            \usleep(10_000);
        }

        return null;
    }

    /**
     * @param array<int, resource> $pipes
     *
     * @return array{0: int, 1: list<string>}
     */
    private function finish($child, array $pipes): array
    {
        // Drain-to-EOF before judging, reap first (the pipe-EOF/
        // proc_get_status race law from r82-rv-q9).
        foreach ([1, 2] as $fd) {
            \stream_set_blocking($pipes[$fd], true);
        }
        $err = (string) \stream_get_contents($pipes[2]);
        $out = \explode("\n", \rtrim((string) \stream_get_contents($pipes[1]), "\n"));
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $status = \proc_close($child);
        if ($err !== '') {
            $out[] = 'STDERR: ' . $err;
        }

        return [$status, $out];
    }

    /**
     * @param array<int, resource> $pipes
     */
    private function forceGone($child, array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                @\fclose($pipe);
            }
        }
       @\proc_terminate($child);
        $deadline = \microtime(true) + 2.0;
        while (\microtime(true) < $deadline && \proc_get_status($child)['running']) {
            \usleep(20_000);
        }
        if (\proc_get_status($child)['running']) {
            \proc_terminate($child, 9);
        }
        @\proc_close($child);
    }

    private function writeChildScript(string $name): string
    {
        $body = $name === 'bare' ? $this->bareChildScriptBody() : $this->holderChildScriptBody();
        $path = $this->dir . '/' . $name . '.php';
        \file_put_contents($path, $body);

        return $path;
    }

    private function bareChildScriptBody(): string
    {
        return <<<'PHP'
            <?php
            $pdo = new PDO('sqlite:' . $argv[1]);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA busy_timeout=0');
            try {
                $pdo->exec('BEGIN IMMEDIATE');
                echo "OK\n";
            } catch (Throwable $e) {
                echo "BUSY\n";
                exit(0);
            }
            exit(0);
            PHP;
    }

    private function holderChildScriptBody(): string
    {
        $holdUs = self::CHILD_HOLD_MICROSECONDS;

        return <<<PHP
            <?php
            require \$argv[2];
            \$db = \$argv[1];
            \$store = new SugarCraft\\Code\\Session\\EnhancedSessionStore(\$db);
            \$pdo = (new ReflectionProperty(\$store, 'pdo'))->getValue(\$store);
            \$pdo->exec('BEGIN IMMEDIATE');
            \$pdo->prepare('INSERT INTO sessions (id, provider, model) VALUES (?, ?, ?)')
                ->execute(['holder-' . getmypid(), 'candy-code', 'busy-test']);
            echo "HELD\\n";
            fflush(STDOUT);
            usleep({$holdUs});
            \$pdo->exec('COMMIT');
            echo "COMMITTED\\n";
            exit(0);
            PHP;
    }
}
