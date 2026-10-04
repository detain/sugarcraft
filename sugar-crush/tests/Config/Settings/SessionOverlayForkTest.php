<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config\Settings;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\Settings\SessionSettings;
use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tests\Support\ReapsForkedChildrenTrait;

/**
 * Roadmap N-P3 (Appendix N §4.3): the session tier reaches the turn child.
 *
 * `EngineBackend` re-reads `Bootstrap::readUserConfig()` at every turn start,
 * INSIDE the child it `pcntl_fork()`s — so the session overlay is honoured only
 * because a fork copies process state. A `/bg` daemon is a fresh process
 * (`proc_open`), so it starts from the files alone; the settings docs and the
 * README both promise that split, and this pins both halves.
 */
final class SessionOverlayForkTest extends TestCase
{
    use HomeSandboxTrait;
    use ReapsForkedChildrenTrait;

    private string $home = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = sys_get_temp_dir() . '/crush-session-fork-' . bin2hex(random_bytes(6));
        mkdir($this->home . '/.sugar-crush', 0o700, true);
        file_put_contents($this->home . '/.sugar-crush/config.json', json_encode(['maxOutputTokens' => 100, 'theme' => 'light']));
        $this->useHomeSandbox($this->home);
        SessionSettings::reset();
    }

    protected function tearDown(): void
    {
        $this->reapTrackedForkedChildren();
        SessionSettings::reset();
        $this->restoreHomeSandbox();
        @unlink($this->home . '/.sugar-crush/config.json');
        @rmdir($this->home . '/.sugar-crush');
        @rmdir($this->home);

        parent::tearDown();
    }

    public function testTheOverlayOutranksConfigJsonAndAResetFallsBackToIt(): void
    {
        SessionSettings::apply(['maxOutputTokens' => 4096]);
        self::assertSame(4096, Bootstrap::readUserConfig()['maxOutputTokens']);
        self::assertSame('light', Bootstrap::readUserConfig()['theme'], 'a key the session did not set still comes from the file');

        SessionSettings::apply([], ['maxOutputTokens']);
        self::assertSame(100, Bootstrap::readUserConfig()['maxOutputTokens']);
    }

    public function testANonLayeredKeyNeverLeavesTheOverlay(): void
    {
        // permissionMode is read straight from config.json by its own strict
        // reader; an overlay value would sit in the merged view doing nothing.
        SessionSettings::apply(['permissionMode' => 'plan']);
        self::assertArrayNotHasKey('permissionMode', SessionSettings::all());
        self::assertArrayNotHasKey('permissionMode', Bootstrap::readUserConfig());
    }

    public function testAForkedTurnChildReadsTheOverlay(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required to fork a turn child');
        }

        SessionSettings::apply(['maxOutputTokens' => 4096]);
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);

        $pid = $this->forkTracked();
        if ($pid === 0) {
            fclose($pair[0]);
            fwrite($pair[1], (string) json_encode(Bootstrap::readUserConfig()['maxOutputTokens'] ?? null));
            fclose($pair[1]);
            // The child of a PHPUnit process must not run shutdown handlers.
            ForkedChild::exitNow(0);
        }

        fclose($pair[1]);
        $seen = stream_get_contents($pair[0]);
        fclose($pair[0]);
        pcntl_waitpid($pid, $status);

        self::assertSame('4096', $seen, 'the turn child must see the session value, not the file');
    }

    public function testAFreshProcessLikeABackgroundDaemonDoesNot(): void
    {
        SessionSettings::apply(['maxOutputTokens' => 4096]);

        $autoload = \dirname(__DIR__, 3) . '/vendor/autoload.php';
        $script = 'require ' . var_export($autoload, true) . ';'
            . 'echo json_encode(\SugarCraft\Crush\Cli\Bootstrap::readUserConfig()["maxOutputTokens"] ?? null);';
        $process = proc_open([\PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        self::assertSame('100', $out, 'a separate process starts from the files alone');
    }
}
