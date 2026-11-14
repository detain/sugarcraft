<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests\History;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\History\FileHistory;

/**
 * S-44 (findings 021/022): the Windows permission arm and the swap ladder.
 *
 * The ladder (rename, retry-once, copy-under-lock) is driven through the
 * @internal rename seam; the icacls arm is platform code Linux never reaches
 * at runtime, so its legs are structural (the S-61 precedent, disclosed)
 * plus a child that invokes the decline path by reflection.
 */
final class FileHistorySwapArmsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/srl-s44-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        FileHistory::armRenameSeamForTests(null);
        foreach (\glob($this->dir . '/*') ?: [] as $f) {
            @chmod($f, 0600);
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function store(int $cap = 0): FileHistory
    {
        return new FileHistory($this->dir . '/h', $cap, $this->dir);
    }

    /**
     * A store whose file carries an unterminated crash fragment: the append
     * fast-path refuses it, forcing the rewrite whose swap runs the ladder.
     */
    private function seededStore(int $cap = 0): FileHistory
    {
        \file_put_contents($this->dir . '/h', 'crash-fragment');
        return new FileHistory($this->dir . '/h', $cap, $this->dir);
    }

    public function testSwapLadderRetriesOnceThenRenames(): void
    {
        $calls = 0;
        FileHistory::armRenameSeamForTests(static function (string $from, string $to) use (&$calls): bool {
            $calls++;
            return $calls === 1 ? false : \rename($from, $to);
        });
        $h = $this->seededStore();
        $h->push('alpha');
        $h->flush();
        self::assertSame(2, $calls, 'one refusal must be answered by exactly one retry');
        self::assertSame(['alpha'], $h->entries());
        self::assertSame("alpha\n", (string) \file_get_contents($this->dir . '/h'), 'fragment dropped, entry landed via the retry rung');
    }

    public function testSwapLadderFallsBackToCopyUnderPermanentRefusal(): void
    {
        FileHistory::armRenameSeamForTests(static fn (string $from, string $to): bool => false);
        $h = $this->seededStore();
        $h->push('beta');
        $h->flush();
        self::assertSame(['beta'], $h->entries());
        self::assertSame("beta\n", (string) \file_get_contents($this->dir . '/h'), 'the copy rung reproduced the temp content');
        // The copy arm consumes the temp file: no stray hist* leftovers.
        self::assertCount(0, \glob($this->dir . '/hist*') ?: [], 'copy fallback must remove its temp file');
    }

    public function testExhaustedLadderStaysLoudAndKeepsOldBytes(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root defeats the 0444 write refusal this pin needs (disclosed, finding 022)');
        }
        $h = $this->store();
        $h->push('keep');
        $h->flush();
        $path = $this->dir . '/h';
        @chmod($path, 0444);
        FileHistory::armRenameSeamForTests(static fn (string $from, string $to): bool => false);
        try {
            $h->clear();
            self::fail('every ladder rung refused: the write must throw, not half-write');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('cannot replace history file', $e->getMessage());
        } finally {
            @chmod($path, 0600);
        }
        self::assertSame("keep\n", (string) \file_get_contents($path), 'a refused swap leaves the previous bytes untouched');
        self::assertSame(['keep'], $h->entries(), 'persist-then-swap: memory keeps its honest contract');
    }

    public function testNoSwapSiteBypassesTheLadder(): void
    {
        $src = (string) \file_get_contents((new \ReflectionClass(FileHistory::class))->getFileName());
        self::assertSame(0, \substr_count($src, '@rename($tempFile'), 'call sites must route through swapIntoPlace()');
        self::assertSame(2, \substr_count($src, '@$rename($tempFile, $this->filePath)'), 'two rename rungs: first try and retry');
        self::assertSame(1, \substr_count($src, 'stream_copy_to_stream'), 'the copy rung exists once');
        self::assertSame(1, \substr_count($src, 'usleep(50_000)'), 'one beat between rungs');
        // Every content-bearing chmod routes through the honest wrapper; the
        // only bare chmods left are the wrapper body and the sidecar hygiene.
        self::assertSame(0, \substr_count($src, 'chmod($tempFile'), 'temp chmods must route through protectContent()');
        self::assertSame(0, \substr_count($src, 'chmod($this->filePath'), 'target chmods must route through protectContent()');
        self::assertSame(2, \substr_count($src, '\\chmod('), 'wrapper body + sidecar hygiene only');
        self::assertSame(1, \substr_count($src, 'if (!self::protectContent($this->filePath))'), 'constructor still throws on a refused tighten');
    }

    public function testWindowsPermissionArmIsGuardedAndLoudOnDecline(): void
    {
        $src = (string) \file_get_contents((new \ReflectionClass(FileHistory::class))->getFileName());
        self::assertSame(1, \substr_count($src, "\\PHP_OS_FAMILY === 'Windows'"), 'the lie-covered arm runs on Windows only');
        $start = \strpos($src, 'private static function protectContent');
        $end = \strpos($src, 'private static function windowsOwnerOnlyGrant');
        self::assertIsInt($start);
        self::assertIsInt($end);
        self::assertGreaterThan($start, $end);
        $body = \substr($src, $end, \strpos($src, "\n    }\n", $end) - $end);
        self::assertStringContainsString("'icacls '", $body, 'the grant runs icacls');
        self::assertStringContainsString('/inheritance:r /grant:r ', $body, 'owner-only, inherited ACLs stripped');
        self::assertStringContainsString('\\escapeshellarg($path)', $body, 'external CLI arg escaped (AGENTS law)');
        self::assertStringContainsString('\\escapeshellarg($who . ' . "':F')", $body, 'the grant identity is escaped too');
        $decline = \strpos($body, 'if (self::$aclDeclineNote === null)');
        $log = \strpos($body, '\\error_log(');
        self::assertIsInt($decline);
        self::assertIsInt($log);
        self::assertGreaterThan($decline, $log, 'the disclosure fires exactly once, inside the guard');
    }

    public function testDeclineArmDisclosesOnceWhenRun(): void
    {
        $cmd = 'timeout 30 php ' . \escapeshellarg(__DIR__ . '/../Support/acl_decline_child.php') . ' 2>&1';
        $proc = \popen($cmd, 'r');
        self::assertIsResource($proc);
        stream_set_timeout($proc, 30);
        $out = '';
        while (!feof($proc)) {
            $chunk = fread($proc, 4096);
            if ($chunk === false) {
                break;
            }
            $out .= $chunk;
        }
        $status = pclose($proc);
        self::assertSame(0, $status, 'child: ' . $out);
        self::assertStringContainsString('NOTESAME|yes', $out, 'the stored note is the single one-time instance');
        self::assertStringContainsString('DONE', $out);
        self::assertSame(1, \substr_count($out, 'advisory'), 'two declined grants, one error_log disclosure');
    }
}
