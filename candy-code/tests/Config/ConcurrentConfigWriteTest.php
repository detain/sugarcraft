<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\ProviderKeyStore;
use SugarCraft\Code\MCP\McpTrustPins;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * S-O20: the cross-process lock is held across read -> merge -> write, so a
 * second writer that read BEFORE the first one's write cannot overwrite it.
 */
final class ConcurrentConfigWriteTest extends TestCase
{
    use HomeSandboxTrait;

    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = \sys_get_temp_dir() . '/candy_code_concurrent_' . \uniqid('', true);
        \mkdir($this->root . '/home', 0700, true);
        $this->useHomeSandbox($this->root . '/home');
        Bootstrap::useConfigPath($this->root . '/config.json');
    }

    protected function tearDown(): void
    {
        Bootstrap::useConfigPath(null);
        $this->restoreHomeSandbox();
        foreach (\glob($this->root . '/{,.}*', \GLOB_BRACE) ?: [] as $f) {
            if (\is_file($f)) {
                @\unlink($f);
            }
        }
        foreach (\glob($this->root . '/home/{,.}*', \GLOB_BRACE) ?: [] as $f) {
            if (\is_file($f)) {
                @\unlink($f);
            }
        }
        @\rmdir($this->root . '/home');
        @\rmdir($this->root);
        parent::tearDown();
    }

    public function testParallelLoginKeepsBothProvidersKeys(): void
    {
        $config = $this->root . '/config.json';
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';
        $script = $this->root . '/holder.php';
        \file_put_contents($script, <<<'PHP'
<?php
require $argv[1];
\SugarCraft\Code\Cli\Bootstrap::useConfigPath($argv[2]);
\SugarCraft\Code\Cli\Bootstrap::mutateUserConfig(static function (array $existing): array {
    echo "locked\n";
    \fflush(\STDOUT);
    \usleep(600000);
    $providers = \is_array($existing['providers'] ?? null) ? $existing['providers'] : [];
    $providers['openai'] = ['type' => 'openai', 'apiKey' => 'sk-one'];

    return [['providers' => $providers], []];
});
PHP);

        $proc = \proc_open([\PHP_BINARY, $script, $autoload, $config], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        $this->assertSame("locked\n", \fgets($pipes[1]));

        // The holder owns the lock and has read an empty file; this second
        // "login" must wait and then merge onto what the holder wrote.
        $this->assertNull(ProviderKeyStore::store('anthropic', 'sk-two'));

        \fclose($pipes[1]);
        \fclose($pipes[2]);
        \proc_close($proc);

        $providers = \json_decode((string) \file_get_contents($config), true)['providers'] ?? [];
        $this->assertSame('sk-one', $providers['openai']['apiKey'] ?? null);
        $this->assertSame('sk-two', $providers['anthropic']['apiKey'] ?? null);
    }

    public function testTrustPinsSaveKeepsARootRecordedByAConcurrentInstance(): void
    {
        $path = $this->root . '/pins.json';
        $pin = ['fingerprint' => 'f', 'summary' => 's'];

        $a = McpTrustPins::load($path);
        $b = McpTrustPins::load($path);
        $a->withRoot('/r1', ['x' => $pin])->save();
        $b->withRoot('/r2', ['y' => $pin])->save();

        $now = McpTrustPins::load($path);
        $this->assertNotNull($now->forRoot('/r1'));
        $this->assertNotNull($now->forRoot('/r2'));
    }
}
