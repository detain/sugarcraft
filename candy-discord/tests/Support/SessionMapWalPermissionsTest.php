<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Tests\Support;

use CandyCode\Bundles\Discord\Support\SessionMap;
use PHPUnit\Framework\TestCase;

/**
 * §7.1/D4 privacy law over the WAL shadow: the constructor chmods the
 * main .db 0600, but in WAL mode SQLite creates `db-wal` and `db-shm` at
 * the first write in the SHAPE OF THE PROCESS UMASK — and the sidecars
 * carry the very rows the main file is kept private for (bindings,
 * reply-address refs) until they are checkpointed away (cc-review-1111
 * register B-52). These pins fix a loose umask for the duration so the
 * birth mode is deterministic, then demand 0600-class on every piece
 * that exists — fresh, and re-asserted on prior-era leftovers at reopen.
 */
final class SessionMapWalPermissionsTest extends TestCase
{
    private string $dir;
    private int $priorUmask;

    protected function setUp(): void
    {
        // The world-readable shape is what the old code shipped under any
        // default umask; pin the loose one so the red-before/green-after
        // polarity does not depend on the host's mask.
        $this->priorUmask = \umask(0o022);
        $this->dir = \sys_get_temp_dir() . '/n16-wal-' . \getmypid() . '-' . \uniqid('', true);
        \mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        \umask($this->priorUmask);
        foreach (['', '-wal', '-shm'] as $suffix) {
            $file = $this->dir . '/sessions.db' . $suffix;
            if (\file_exists($file)) {
                @\unlink($file);
            }
        }
        @\rmdir($this->dir);
    }

    /** @return array<string, int> path => permission bits */
    private function modes(): array
    {
        \clearstatcache();
        $seen = [];
        foreach (['', '-wal', '-shm'] as $suffix) {
            $file = $this->dir . '/sessions.db' . $suffix;
            if (\file_exists($file)) {
                $seen[$suffix] = \fileperms($file) & 0o777;
            }
        }

        return $seen;
    }

    public function testTheWalSidecarsAreBornPrivateNotUmaskShaped(): void
    {
        $map = new SessionMap($this->dir . '/sessions.db');
        $map->bind('discord:acct-1:chan-1', 'sess-aaaaaaaa', 'peer-1');

        $modes = $this->modes();
        self::assertArrayHasKey('', $modes, 'the main db file exists');
        self::assertArrayHasKey('-wal', $modes, 'a WAL sidecar exists after the first write');
        foreach ($modes as $suffix => $mode) {
            self::assertSame(0o600, $mode, "state piece '{$suffix}' must be owner-only (D4)");
        }
    }

    public function testReopenReAssertsPermissionsOnPriorEraSidecars(): void
    {
        $path = $this->dir . '/sessions.db';
        $first = new SessionMap($path);
        $first->bind('discord:acct-1:chan-1', 'sess-aaaaaaaa', 'peer-1');

        // Plant the prior-era leak: sidecars sitting world-readable while
        // an open connection keeps them on disk (a crash from the old
        // binary leaves exactly this shape for the next boot).
        \chmod($path, 0o644);
        \chmod($path . '-wal', 0o644);
        \chmod($path . '-shm', 0o644);
        \clearstatcache();
        self::assertSame(0o644, \fileperms($path . '-wal') & 0o777);

        new SessionMap($path);

        foreach ($this->modes() as $suffix => $mode) {
            self::assertSame(0o600, $mode, "reopen must tighten prior-era piece '{$suffix}'");
        }
    }
}
