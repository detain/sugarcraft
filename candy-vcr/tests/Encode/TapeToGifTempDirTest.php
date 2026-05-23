<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Encode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Encode\TapeToGif;

/**
 * Regression: TapeToGif creates a fresh temp directory per render call.
 *
 * Bug fixed in d070e742: the old temp dir was derived from getmypid()
 * only, so two parallel `TapeToGif::render()` calls (the same process
 * spawning concurrent renders, or just two back-to-back renders) would
 * stomp each other's PNG frames in `/tmp/candy-vcr-t2g-<pid>/`. The fix
 * appends `bin2hex(random_bytes(4))` so each call gets a unique suffix.
 *
 * We can't easily fork the process from a unit test, so the contract is
 * checked structurally: hook into `createTempDir()` via reflection and
 * verify two calls return distinct paths.
 */
final class TapeToGifTempDirTest extends TestCase
{
    public function testCreateTempDirReturnsDistinctPathsAcrossCalls(): void
    {
        $renderer = TapeToGif::create(['encoder' => 'php', 'backend' => 'gd']);

        $method = new \ReflectionMethod($renderer, 'createTempDir');
        $method->setAccessible(true);

        $created = [];
        try {
            $a = (string) $method->invoke($renderer);
            $b = (string) $method->invoke($renderer);
            $created[] = $a;
            $created[] = $b;

            $this->assertNotSame($a, $b, "Two createTempDir() calls must return distinct paths; got '{$a}' twice");
            $this->assertDirectoryExists($a);
            $this->assertDirectoryExists($b);

            // Sanity: both paths share the same prefix but differ in suffix.
            $this->assertStringStartsWith(sys_get_temp_dir() . '/candy-vcr-t2g-', $a);
            $this->assertStringStartsWith(sys_get_temp_dir() . '/candy-vcr-t2g-', $b);
        } finally {
            foreach ($created as $dir) {
                @rmdir($dir);
            }
        }
    }
}
