<?php

declare(strict_types=1);

namespace SugarCraft\SshLauncher\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SugarCraft\SshLauncher\Config;

/**
 * -017: the read choke's encoding gate. Both file readers — the wishlist
 * loader and the ssh_config importer — pass through readOrThrow(), so one
 * BOM strip and one UTF-16 refusal protect every path bytes can enter on.
 * Config::parse() itself stays byte-exact: it is the advertised
 * programmatic API for callers who already hold decoded text.
 */
final class ConfigEncodingTest extends TestCase
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
        $path = sys_get_temp_dir() . '/sshl-encoding-' . bin2hex(random_bytes(4)) . '.' . $ext;
        file_put_contents($path, $contents);
        $this->tmp[] = $path;
        return $path;
    }

    public function testUtf8BomIsStrippedBeforeTheFirstLine(): void
    {
        // The historical failure: Notepad's BOM glued to `- name:` broke
        // the entry regex, and the user got 'unparseable line 1' pointing
        // at bytes no terminal could render.
        $path = $this->file("\xEF\xBB\xBF- name: prod\n  host: prod.example.com\n");

        $endpoints = Config::load($path);
        $this->assertCount(1, $endpoints);
        $this->assertSame('prod', $endpoints[0]->name);
    }

    public function testBomlessFileIsUntouched(): void
    {
        $path = $this->file("- name: prod\n  host: prod.example.com\n");
        $this->assertCount(1, Config::load($path));
    }

    public function testUtf16LeIsRefusedByName(): void
    {
        $path = $this->file("\xFF\xFE" . "- name: prod\n");

        try {
            Config::load($path);
            $this->fail('expected the UTF-16 file to be refused, not gibbered');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('UTF-16', $e->getMessage());
            $this->assertStringContainsString('UTF-8', $e->getMessage());
            $this->assertStringContainsString($path, $e->getMessage());
        }
    }

    public function testUtf16BeIsRefusedToo(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UTF-16');
        Config::load($this->file("\xFE\xFF" . "- name: prod\n"));
    }

    public function testSshConfigImportSharesTheGate(): void
    {
        // The importer reads through the same choke, so a BOM-saved
        // ssh_config imports cleanly and its first Host block parses.
        $bommed = $this->file("\xEF\xBB\xBFHost web\n  HostName w.test\n", 'config');
        $endpoints = Config::importFromSshConfig($bommed);
        $this->assertCount(1, $endpoints);
        $this->assertSame('web', $endpoints[0]->name);
        $this->assertSame('w.test', $endpoints[0]->host);

        $utf16 = $this->file("\xFF\xFE" . "Host web\n", 'config');
        try {
            Config::importFromSshConfig($utf16);
            $this->fail('expected the UTF-16 ssh_config to be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('UTF-16', $e->getMessage());
        }
    }
}
