<?php

declare(strict_types=1);

namespace SugarCraft\QuickPick\Tests\History;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SugarCraft\QuickPick\FilteredItem;
use SugarCraft\QuickPick\History\FileHistory;

/**
 * Trust-boundary pins for FileHistory (fixes C2, M17, M8, M9, mm10 from
 * the 2026-11 review). The old docs taught world-writable /tmp history
 * paths; the old code happily appended through a planted symlink and
 * swallowed write failures silently. These pins lock the new contract:
 * private-by-construction creation, symlink refusal on every operation,
 * loud append, truncate-clear, and substitute-not-throw on invalid UTF-8.
 */
final class FileHistorySecurityTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        // Remove links/files first, then dirs (the confine test plants a
        // symlink inside its base dir).
        foreach ([['is_link', 'unlink'], ['is_file', 'unlink'], ['is_dir', 'rmdir']] as [$test, $rm]) {
            foreach ($this->paths as $path) {
                if ($test($path)) {
                    $rm($path);
                }
            }
        }
        $this->paths = [];
    }

    private function tempPath(string $suffix = '.jsonl'): string
    {
        $path = sys_get_temp_dir() . '/qp-sec-' . uniqid('', true) . $suffix;
        $this->paths[] = $path;

        return $path;
    }

    /**
     * M17/C2: a planted symlink at the history path must never be followed.
     * This is the exact attack the old code permitted: plant
     * /tmp/attacker-victim pointing at a file the victim owns, then let the
     * victim's history appender write attacker-chosen JSONL lines THROUGH
     * the link.
     */
    public function testAppendRefusesAPlantedSymlinkAndLeavesTheTargetUntouched(): void
    {
        $victim = $this->tempPath('-victim.txt');
        file_put_contents($victim, "secret-account-list\n");
        $link = $this->tempPath();
        self::assertTrue(symlink($victim, $link));

        $history = new FileHistory($link);

        $before = filesize($victim);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('symlink');

        try {
            $history->append(new FilteredItem(1, 'injected-value'));
        } finally {
            clearstatcache();
            self::assertSame($before, filesize($victim), 'symlinked append must not write through to the target');
        }
    }

    public function testReadRefusesASymlinkedHistoryFile(): void
    {
        $victim = $this->tempPath('-victim.txt');
        file_put_contents($victim, json_encode(['n' => 9, 'v' => 'stolen-via-read']) . "\n");
        $link = $this->tempPath();
        self::assertTrue(symlink($victim, $link));

        $history = new FileHistory($link);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('symlink');
        $history->all();
    }

    public function testClearRefusesASymlinkedHistoryFileAndSparesTheTarget(): void
    {
        $victim = $this->tempPath('-victim.txt');
        file_put_contents($victim, "do-not-truncate-me\n");
        $link = $this->tempPath();
        self::assertTrue(symlink($victim, $link));

        $history = new FileHistory($link);

        try {
            $history->clear();
            self::fail('clear() through a symlinked history path must throw');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('symlink', $e->getMessage());
        }

        clearstatcache();
        self::assertSame("do-not-truncate-me\n", file_get_contents($victim));
    }

    /**
     * The confine() hardening half: a symlinked leaf inside the base dir is
     * refused at CONSTRUCTION, so the confined path can never resolve
     * outside the jail even before the first write.
     */
    public function testConfineRefusesASymlinkedLeafInsideTheBaseDir(): void
    {
        $base = $this->tempPath('-base');
        mkdir($base, 0700, true);
        $outside = $this->tempPath('-outside.txt');
        file_put_contents($outside, "escape\n");
        self::assertTrue(symlink($outside, $base . '/confined.jsonl'));
        $this->paths[] = $base . '/confined.jsonl';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('symlink');
        new FileHistory('confined.jsonl', baseDir: $base);
    }

    /**
     * A dangling symlink (link exists, target does not) previously took the
     * fopen('a') success path and created the target as a side effect.
     */
    public function testAppendRefusesADanglingSymlinkToo(): void
    {
        $missingTarget = $this->tempPath('-nope.txt');
        $link = $this->tempPath();
        self::assertTrue(symlink($missingTarget, $link));

        $history = new FileHistory($link);

        try {
            $history->append(new FilteredItem(1, 'x'));
            self::fail('append through a dangling symlink must throw');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('symlink', $e->getMessage());
        }

        clearstatcache();
        self::assertFalse(file_exists($missingTarget), 'the symlink target must not be created');
    }

    /** C2: fresh history files are private-by-construction, not umask roulette. */
    public function testFreshHistoryFileIsCreatedPrivate(): void
    {
        $path = $this->tempPath();
        $history = new FileHistory($path);
        $history->append(new FilteredItem(1, 'first'));

        clearstatcache();
        self::assertSame(0600, fileperms($path) & 0777);
    }

    /** M8: a failed open is loud — the old code returned void in silence. */
    public function testAppendThrowsWhenTheFileCannotBeOpened(): void
    {
        $history = new FileHistory('/this-directory-does-not-exist/qp-history.jsonl');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot open QuickPick history file for append');
        $history->append(new FilteredItem(1, 'lost-in-silence'));
    }

    /** M9: invalid UTF-8 in a value must not fatal the appender. */
    public function testAppendSubstitutesInvalidUtf8InsteadOfThrowing(): void
    {
        $path = $this->tempPath();
        $history = new FileHistory($path);

        $history->append(new FilteredItem(7, "bad\xFFbyte"));

        $raw = (string) file_get_contents($path);
        self::assertStringNotContainsString("\xFF", $raw, 'raw invalid byte must not reach the file');

        $items = $history->all();
        self::assertCount(1, $items);
        self::assertSame("bad\u{FFFD}byte", $items[0]->value(), 'the invalid byte lands substituted, not fatal');
    }

    /** mm10: clear() empties the file in place; concurrent readers never see an absent path. */
    public function testClearTruncatesInPlaceAndKeepsTheFileUsable(): void
    {
        $path = $this->tempPath();
        $history = new FileHistory($path);
        $history->append(new FilteredItem(1, 'one'));
        $history->append(new FilteredItem(2, 'two'));

        $inodeBefore = (int) (stat($path)['ino'] ?? -1);
        $history->clear();
        clearstatcache();

        self::assertFileExists($path, 'clear() must not unlink the history path out from under other writers');
        self::assertSame(0, filesize($path));
        self::assertSame($inodeBefore, (int) (stat($path)['ino'] ?? -2), 'same inode: truncated in place');

        $history->append(new FilteredItem(3, 'after-clear'));
        $items = $history->all();
        self::assertCount(1, $items);
        self::assertSame('after-clear', $items[0]->value());
    }

    /**
     * S-17/-016: an EXISTING history file that cannot be opened must throw
     * like append()/clear() do — the silent `[]` laundered "unreadable data"
     * as "empty history". (The genuinely-absent arm keeps answering []: no
     * history is a legitimate state, pinned by FileHistoryTest::
     * testAllReturnsEmptyArrayWhenNoFile.)
     */
    public function testAllThrowsOnExistingUnreadableFile(): void
    {
        if (\function_exists('posix_geteuid') === false || \posix_geteuid() === 0) {
            self::markTestSkipped('mode-0000 refusal requires a non-root POSIX runner');
        }

        $path = $this->tempPath();
        (new FileHistory($path))->append(new FilteredItem(1, 'visible-then-blocked'));
        chmod($path, 0000);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Cannot open QuickPick history file for read');
            (new FileHistory($path))->all();
        } finally {
            chmod($path, 0600);
        }
    }

    /** Round-trip sanity after the hardened writes: values that ARE safe still land. */
    public function testSafeValuesStillRoundTrip(): void
    {
        $path = $this->tempPath();
        $history = new FileHistory($path);
        $history->append(new FilteredItem(1, 'plain-value'));

        self::assertSame([1], array_map(static fn($i) => $i->number(), $history->all()));
    }

    /**
     * S-16/-008: the pre-open symlink check cannot speak for the inode the
     * open LANDS on. A regular-but-readable-by-others file (the classic
     * shared-/tmp pre-plant: attacker creates the path first, world can
     * write, victim happily appends) must be refused on the OPEN descriptor,
     * not just on the path.
     */
    public function testEveryOperationRefusesAPrePlantedNonPrivateFile(): void
    {
        $item = new FilteredItem(1, 'victim-selection');

        foreach ([
            'append' => static fn(FileHistory $h) => $h->append($item),
            'read'   => static fn(FileHistory $h) => $h->all(),
            'clear'  => static fn(FileHistory $h) => $h->clear(),
        ] as $op => $drive) {
            $path = $this->tempPath();
            file_put_contents($path, '');
            chmod($path, 0644);

            $history = new FileHistory($path);
            try {
                $drive($history);
                self::fail("{$op}() through a group-readable history file must throw");
            } catch (RuntimeException $e) {
                self::assertStringContainsString('not private to this user', $e->getMessage());
                self::assertStringContainsString("during {$op}", $e->getMessage());
            }

            self::assertSame('', (string) file_get_contents($path), "{$op}() must not write to a hostile pre-plant");
        }
    }

    /** S-16: the same file at 0600 IS trusted — the refusal is about the bits, not the hand-plant. */
    public function testAPrePlantedPrivateFilePassesVerificationAndReads(): void
    {
        $path = $this->tempPath();
        file_put_contents($path, json_encode(['n' => 4, 'v' => 'honest-row']) . "\n");
        chmod($path, 0600);

        $items = (new FileHistory($path))->all();

        self::assertCount(1, $items);
        self::assertSame('honest-row', $items[0]->value());
    }

    /**
     * S-16/-007 deterministic superset of the swap-during-open race (which
     * single-threaded tests cannot schedule): a handle whose device+inode
     * differ from the path's fresh lstat is the symlinked/crossed-inode
     * state, and it must throw on the identity arm BEFORE the mode arm.
     */
    public function testIdentityCheckRefusesAHandleLandingOnADifferentInode(): void
    {
        $historyPath = $this->tempPath();
        (new FileHistory($historyPath))->append(new FilteredItem(1, 'anchor'));

        $foreign = $this->tempPath('-foreign.txt');
        file_put_contents($foreign, '');
        chmod($foreign, 0600); // private AND foreign inode: only the identity arm can catch this

        $handle = fopen($foreign, 'r');
        self::assertNotFalse($handle);

        $verify = new \ReflectionMethod(FileHistory::class, 'verifyHandle');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('does not match its path');
            $verify->invoke(new FileHistory($historyPath), $handle, 'read');
        } finally {
            fclose($handle);
        }
    }
}
