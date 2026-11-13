<?php

declare(strict_types=1);

namespace SugarCraft\SshLauncher\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SugarCraft\SshLauncher\Config;

/**
 * -016: the loader's ceilings. A wishlist file is a directory, not a
 * data lake: bytes past MAX_CONFIG_BYTES and list items past
 * MAX_LIST_ITEMS are refused by name before the parse is paid for —
 * the historical legs were the quadratic bucket copy-per-item and an
 * unbounded slurp of whatever path --config happened to name.
 */
final class ConfigHardeningTest extends TestCase
{
    /** @var list<string> */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $path) {
            @unlink($path);
        }
        $this->tmp = [];
    }

    private function file(string $contents, string $ext = 'yml'): string
    {
        $path = sys_get_temp_dir() . '/sshl-hardening-' . bin2hex(random_bytes(4)) . '.' . $ext;
        file_put_contents($path, $contents);
        $this->tmp[] = $path;
        return $path;
    }

    public function testOversizedConfigIsRefusedBeforeTheRead(): void
    {
        // One byte past the ceiling (as pure padding comments — the parse
        // itself would trivially succeed; the guard is about the READ).
        $pad  = (4 * 1024 * 1024) + 1;
        $path = $this->file(str_repeat('#', $pad));

        try {
            Config::load($path);
            $this->fail('expected the oversize config to be refused');
        } catch (RuntimeException $e) {
            // The refusal names both the limit and the offending path.
            $this->assertStringContainsString('4194304-byte limit', $e->getMessage());
            $this->assertStringContainsString($path, $e->getMessage());
        }
    }

    public function testJustUnderTheCeilingStillLoads(): void
    {
        $body = "- name: prod\n  host: prod.example.com\n";
        $pad  = str_repeat('#', (4 * 1024 * 1024) - strlen($body));
        $path = $this->file($body . $pad);

        $endpoints = Config::load($path);
        $this->assertCount(1, $endpoints);
        $this->assertSame('prod', $endpoints[0]->name);
    }

    public function testListAtTheCapLoadsInFull(): void
    {
        $raw = "- name: prod\n  host: prod.example.com\n  options:\n";
        for ($i = 0; $i < Config::MAX_LIST_ITEMS; $i++) {
            $raw .= "    - Opt{$i}=v\n";
        }

        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertCount(Config::MAX_LIST_ITEMS, $endpoints[0]->options);
    }

    public function testListOnePastTheCapRefusesFailLoud(): void
    {
        $raw = "- name: prod\n  host: prod.example.com\n  options:\n";
        for ($i = 0; $i <= Config::MAX_LIST_ITEMS; $i++) {
            $raw .= "    - Opt{$i}=v\n";
        }

        try {
            Config::parse($raw, 'wishlist.yml');
            $this->fail('expected the over-cap list to be refused');
        } catch (RuntimeException $e) {
            // Deterministic refusal: names the field AND the limit (-016).
            $this->assertStringContainsString('[options]', $e->getMessage());
            $this->assertStringContainsString((string) Config::MAX_LIST_ITEMS, $e->getMessage());
        }
    }

    public function testSshConfigRepeatedOptionSharesTheCap(): void
    {
        // The ssh_config ingest path collects repeated keys into list
        // buckets too — one IdentityFile per line, a thousand-and-first
        // arrives through the same door and must hit the same refusal.
        $raw  = "Host web\n";
        for ($i = 0; $i <= Config::MAX_LIST_ITEMS; $i++) {
            $raw .= "  IdentityFile /keys/id{$i}\n";
        }
        $path = $this->file($raw, 'config');

        try {
            Config::importFromSshConfig($path);
            $this->fail('expected the over-cap option list to be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString((string) Config::MAX_LIST_ITEMS, $e->getMessage());
        }
    }

    public function testLongListParseIsLinearInItems(): void
    {
        // The copy-per-item bug made bucket building O(n²): doubling the
        // list quadrupled the time. Post-fix, four times the items cost
        // no more than ~8× the (small) base time — a generous bound that
        // still reddens hard on quadratic behaviour at these sizes.
        $build = function (int $n): string {
            $raw = "- name: prod\n  host: prod.example.com\n  options:\n";
            for ($i = 0; $i < $n; $i++) {
                $raw .= "    - Opt{$i}=v\n";
            }
            return $raw;
        };

        $t1 = microtime(true);
        Config::parse($build(200), 'wishlist.yml');
        $small = microtime(true) - $t1;

        $t2 = microtime(true);
        Config::parse($build(1000), 'wishlist.yml');
        $big = microtime(true) - $t2;

        // Linear expectation: 5× items ≈ ≤5× time plus slack. Quadratic
        // (25×) would blow past this even on a loaded runner.
        $this->assertLessThan(max(0.05, $small * 10), $big);
    }
}
