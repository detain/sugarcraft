<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\LSP\LspClient;
use SugarCraft\Crush\LSP\LspLauncher;
use SugarCraft\Crush\Tools\BuiltIn\Read;

/**
 * Step 3.F (Zed's `read_file` outline): a file too long for one page, read
 * with no window named, gets an outline of its declarations after the first
 * page — from the file's language server when one is configured, else from the
 * source text — so the model can jump to the part it needs by `offset`.
 *
 * @see Read
 */
final class ReadOutlineTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = (string) realpath(sys_get_temp_dir()) . '/crush_readoutline_' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        // The names this file writes, removed by name: no directory walk.
        foreach (['Big.php', 'Short.php', 'plain.txt', 'Many.php'] as $name) {
            @unlink($this->dir . '/' . $name);
        }
        @rmdir($this->dir);
    }

    public function testALongFileReadWithNoWindowEndsWithAnOutline(): void
    {
        $path = $this->longPhpFile();

        $content = (new Read())->execute(['file_path' => $path])->content();

        $this->assertStringContainsString('call Read with offset=2001 to continue]', $content);
        $outlineAt = strpos($content, "[outline of this file — 3 declarations; call Read with offset=N to read from line N]\n");
        $this->assertIsInt($outlineAt, 'the outline follows the first page');
        $this->assertGreaterThan(strpos($content, 'to continue]'), $outlineAt);
        $this->assertStringEndsWith("3: class Big\n1500: function middle\n2900: function late", $content);
    }

    public function testANamedWindowOrAShortFileGetsNoOutline(): void
    {
        $long = $this->longPhpFile();
        foreach ([['offset' => 1], ['limit' => 2000], ['offset' => 2001]] as $window) {
            $this->assertStringNotContainsString('[outline of this file', (new Read())->execute(['file_path' => $long] + $window)->content());
        }

        $short = $this->dir . '/Short.php';
        file_put_contents($short, "<?php\nfinal class Short {}\n");
        $this->assertStringNotContainsString('[outline of this file', (new Read())->execute(['file_path' => $short])->content());
    }

    public function testAFileWithNoDeclarationsGetsNoOutline(): void
    {
        $path = $this->dir . '/plain.txt';
        file_put_contents($path, implode("\n", array_map(static fn (int $i): string => "line {$i}", range(1, 2500))) . "\n");

        $this->assertStringNotContainsString('[outline of this file', (new Read())->execute(['file_path' => $path])->content());
    }

    public function testTheOutlineIsBoundedAndSaysWhatItLeftOut(): void
    {
        $path = $this->dir . '/Many.php';
        $lines = ["<?php"];
        for ($i = 0; $i < 2300; $i++) {
            $lines[] = "function f{$i}() {}";
        }
        file_put_contents($path, implode("\n", $lines) . "\n");

        $content = (new Read())->execute(['file_path' => $path])->content();

        $this->assertStringContainsString('[outline of this file — 2300 declarations;', $content);
        $this->assertSame(200, preg_match_all('/^\d+: function f\d+$/m', substr($content, (int) strpos($content, '[outline'))));
        $this->assertStringEndsWith("\n… and 2100 more", $content);
    }

    public function testAConfiguredLanguageServerSuppliesTheOutline(): void
    {
        $path = $this->longPhpFile();
        [$client] = LspLauncher::fromConfig(['php' => [
            'command' => PHP_BINARY,
            'args' => ['-n', \dirname(__DIR__, 2) . '/fixtures/lsp/fake-lsp-server.php'],
            'timeout' => 5,
        ]], $this->dir)->launch();
        $this->assertInstanceOf(LspClient::class, $client);

        try {
            $content = (new Read($this->dir, lsp: $client))->execute(['file_path' => $path])->content();
        } finally {
            $client->disconnectAll();
        }

        // The fixture server nests each function under the class before it
        // and renames it, so its answer cannot be mistaken for the regex's.
        $this->assertStringEndsWith("3: class Big\n  1500: method middleFromServer\n  2900: method lateFromServer", $content);
    }

    private function longPhpFile(): string
    {
        $lines = array_fill(0, 3000, '// filler');
        $lines[0] = '<?php';
        $lines[2] = 'final class Big';
        $lines[1499] = '    public function middle(): void {}';
        $lines[2899] = '    public function late(): void {}';
        $path = $this->dir . '/Big.php';
        file_put_contents($path, implode("\n", $lines) . "\n");

        return $path;
    }
}
