<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\RepoMap;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\RepoMap\RepoMapBuilder;
use SugarCraft\Code\RepoMap\TagCache;

/**
 * S-O22: a capture takes the stamp vector once (for the cache key) and hands
 * it to summaries() instead of letting it stat every file a second time.
 */
final class RepoMapStatPassTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/repomap_stat_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
        file_put_contents($this->dir . '/A.php', "<?php\nfinal class A { public function a(): void {} }\n");
        file_put_contents($this->dir . '/B.php', "<?php\nfinal class B { public function b(): void {} }\n");
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*.php') ?: []);
        rmdir($this->dir);
    }

    public function testPrecomputedStampsAreHonouredWithoutAFreshStatPass(): void
    {
        $real = (string) realpath($this->dir);
        $builder = RepoMapBuilder::new($real, TagCache::open(':memory:'));
        $php = ['A.php' => $real . '/A.php', 'B.php' => $real . '/B.php'];

        $stamps = $builder->stamps($php, []);
        self::assertCount(2, $stamps);

        // B.php vanishes after the stamp pass: a re-stat would drop it, the
        // handed-in vector must not.
        unlink($real . '/B.php');
        $withStamps = $builder->summaries($php, [], null, null, $stamps);
        self::assertArrayHasKey('B.php', $withStamps['definitions']);

        $fresh = $builder->summaries($php, []);
        self::assertArrayNotHasKey('B.php', $fresh['definitions']);
        file_put_contents($real . '/B.php', '<?php');
    }
}
