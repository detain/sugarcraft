<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Recorder;

/**
 * F2 (docs-fix-1023 M8) — `Recorder::open()` creates cassettes owner-only.
 * Cassettes embed captured terminal output and — under `record --env-all` —
 * the whole environment in plaintext, so a default-umask 0644 file on a
 * shared runner is a secret exposure. The tightening must hold even when a
 * looser file already exists at the path (`fopen('wb')` truncates but never
 * re-permissions), so the explicit chmod is the load-bearing step.
 */
final class RecorderFilePermissionsTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
    }

    public function testFreshCassetteIsWrittenOwnerOnly(): void
    {
        $path = $this->freshPath('fresh');
        $recorder = Recorder::open($path, self::header());
        $recorder->close();

        clearstatcache(true, $path);
        $this->assertSame(
            0600,
            fileperms($path) & 0777,
            "cassettes may carry plaintext secrets — Recorder::open() must leave them 0600",
        );
    }

    public function testPreExistingLooserFileIsTightenedByOpen(): void
    {
        $path = $this->freshPath('looser');
        file_put_contents($path, 'stale');
        chmod($path, 0644);
        clearstatcache(true, $path);
        $this->assertSame(0644, fileperms($path) & 0777, 'fixture must start loose');

        $recorder = Recorder::open($path, self::header());
        $recorder->close();

        clearstatcache(true, $path);
        $this->assertSame(0600, fileperms($path) & 0777);
    }

    /**
     * Structural pin: the chmod sits inside open() itself, so no future
     * convenience factory can bypass it by constructing over a raw handle.
     */
    public function testPermissionTighteningLivesInOpen(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Recorder.php');
        $openStart = strpos($source, 'public static function open(');
        $this->assertIsInt($openStart);
        $openBody = substr($source, $openStart, (int) strpos(substr($source, $openStart), "\n    }"));

        $this->assertStringContainsString('chmod($path, 0600)', $openBody);
    }

    private function freshPath(string $tag): string
    {
        $path = sys_get_temp_dir() . "/candy-vcr-perms-{$tag}-" . bin2hex(random_bytes(4)) . '.cas';
        $this->paths[] = $path;
        return $path;
    }

    private static function header(): CassetteHeader
    {
        return new CassetteHeader(
            version: 1,
            createdAt: '2026-10-23T00:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'test',
        );
    }
}
