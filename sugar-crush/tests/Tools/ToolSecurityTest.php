<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tools\BuiltIn\Bash;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
use SugarCraft\Crush\Tools\BuiltIn\Glob;
use SugarCraft\Crush\Tools\BuiltIn\Grep;
use SugarCraft\Crush\Tools\BuiltIn\Read;
use SugarCraft\Crush\Tools\BuiltIn\WebFetch;

/**
 * Security regression tests for tools.
 */
final class ToolSecurityTest extends TestCase
{
    private string $tmpDir;
    private string $markerFile;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sugarcrush_security_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        $this->markerFile = $this->tmpDir . '/injection_marker_' . uniqid();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->markerFile)) {
            unlink($this->markerFile);
        }
        if (is_dir($this->tmpDir)) {
            array_map('unlink', glob($this->tmpDir . '/*'));
            rmdir($this->tmpDir);
        }
    }

    public function testGrepIncludeInjectionBlocked(): void
    {
        $grep = new Grep($this->tmpDir);
        $injectionPayload = 'a; touch ' . escapeshellarg($this->markerFile) . ' #';

        $result = $grep->execute([
            'pattern' => 'test_pattern',
            'path' => $this->tmpDir,
            'include' => $injectionPayload,
        ]);

        $this->assertTrue($result->isError() || $result->content() === '');
        $this->assertFalse(file_exists($this->markerFile), 'Injection payload was executed - marker file exists');
    }

    public function testBashPathJailRunsInJailDirectory(): void
    {
        $bash = new Bash($this->tmpDir);

        $result = $bash->execute([
            'command' => 'pwd',
        ]);

        $this->assertFalse($result->isError());
        $this->assertStringContainsString($this->tmpDir, $result->content());
    }

    public function testReadPathJailPreventsEtcPasswd(): void
    {
        $read = new Read($this->tmpDir);

        $result = $read->execute([
            'file_path' => '/etc/passwd',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('workspace root', $result->content());
    }

    public function testEditPathJailPreventsEtcPasswd(): void
    {
        $edit = new Edit($this->tmpDir);

        $result = $edit->execute([
            'file_path' => '/etc/passwd',
            'old_string' => 'test',
            'new_string' => 'replaced',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('workspace root', $result->content());
    }

    public function testGlobPathJailPreventsOutsideAccess(): void
    {
        $glob = new Glob($this->tmpDir);

        $result = $glob->execute([
            'pattern' => '**/*',
            'path' => '/etc',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('workspace root', $result->content());
    }

    public function testGrepPathJailPreventsOutsideAccess(): void
    {
        $grep = new Grep($this->tmpDir);

        $result = $grep->execute([
            'pattern' => 'test',
            'path' => '/etc',
            'include' => '*.conf',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('workspace root', $result->content());
    }

    public function testWebFetchBlocksLocalhost(): void
    {
        $webFetch = new WebFetch();

        $result = $webFetch->execute([
            'url' => 'http://127.0.0.1/test',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('localhost', $result->content());
    }

    public function testWebFetchBlocks169254Metadata(): void
    {
        $webFetch = new WebFetch();

        $result = $webFetch->execute([
            'url' => 'http://169.254.169.254/latest/meta-data/',
        ]);

        $this->assertTrue($result->isError());
    }

    public function testWebFetchBlocksPrivateIpRanges(): void
    {
        $webFetch = new WebFetch();

        $privateIps = [
            'http://10.0.0.1/test',
            'http://172.16.0.1/test',
            'http://192.168.1.1/test',
        ];

        foreach ($privateIps as $url) {
            $result = $webFetch->execute(['url' => $url]);
            $this->assertTrue($result->isError(), "Expected $url to be blocked");
        }
    }

    public function testReadSizeCapTruncatesLargeFile(): void
    {
        $read = new Read(null, 1024);

        $largeFile = $this->tmpDir . '/large_file.txt';
        file_put_contents($largeFile, str_repeat('x', 2048));

        $result = $read->execute([
            'file_path' => $largeFile,
        ]);

        $this->assertFalse($result->isError());
        $this->assertStringContainsString('[truncated]', $result->content());
        $this->assertLessThanOrEqual(1024 + 50, strlen($result->content()));
    }

    public function testEditNonUniqueOldStringErrors(): void
    {
        $testFile = $this->tmpDir . '/test_edit.txt';
        file_put_contents($testFile, "line1\nfoo\nline2\nfoo\nline3\n");

        $edit = new Edit($this->tmpDir);

        $result = $edit->execute([
            'file_path' => $testFile,
            'old_string' => 'foo',
            'new_string' => 'bar',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('not unique', $result->content());
    }

    public function testEditWithReplaceAllAllowsMultiMatch(): void
    {
        $testFile = $this->tmpDir . '/test_edit2.txt';
        file_put_contents($testFile, "line1\nfoo\nline2\nfoo\nline3\n");

        $edit = new Edit($this->tmpDir);

        $result = $edit->execute([
            'file_path' => $testFile,
            'old_string' => 'foo',
            'new_string' => 'bar',
            'replace_all' => true,
        ]);

        $this->assertFalse($result->isError());
        $content = file_get_contents($testFile);
        $this->assertEquals("line1\nbar\nline2\nbar\nline3\n", $content);
    }
}
