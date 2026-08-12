<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Session\EnhancedSessionStore;

final class BootstrapTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $savedEnv = [];

    private ?string $tmpHome = null;

    protected function setUp(): void
    {
        foreach (['HOME', 'SUGARCRUSH_PROVIDER', 'SUGARCRUSH_BACKEND_CMD', 'SUGARCRUSH_MODEL', 'SUGARCRUSH_TITLE_MODEL'] as $name) {
            $this->savedEnv[$name] = getenv($name);
        }

        // Ambient provider env would otherwise decide which backend path
        // these tests exercise; each test opts in to what it needs.
        foreach (['SUGARCRUSH_PROVIDER', 'SUGARCRUSH_BACKEND_CMD', 'SUGARCRUSH_MODEL', 'SUGARCRUSH_TITLE_MODEL'] as $name) {
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv("{$name}={$value}");
            }
        }

        if ($this->tmpHome !== null) {
            self::removeTree($this->tmpHome);
            $this->tmpHome = null;
        }
    }

    public function testAvailableProvidersIncludesEveryBuiltInType(): void
    {
        $providers = Bootstrap::availableProviders();

        foreach (['openai', 'anthropic', 'claude-code', 'sglang', 'bedrock', 'vertex', 'custom'] as $type) {
            $this->assertArrayHasKey($type, $providers);
        }
    }

    public function testAvailableProvidersIncludesProjectConfigProviders(): void
    {
        // .sugar-crush/config.dev.json (checked in) declares 'dev-sglang' as
        // its defaultProvider - the palette's Switch Model action needs it
        // listed here, not only the built-in generic 'sglang' type.
        $providers = Bootstrap::availableProviders();
        $this->assertArrayHasKey('dev-sglang', $providers);
        $this->assertSame('sglang', $providers['dev-sglang']['type'] ?? null);
    }

    public function testBackendForBuildsARealEngineBackendForAKnownType(): void
    {
        // 'sglang' construction only builds config + an HTTP client object
        // (SglangProvider::openAiCompatible()) - no eager network call, so
        // this is safe to build without a reachable server.
        $backend = Bootstrap::backendFor('sglang');
        $this->assertInstanceOf(EngineBackend::class, $backend);
    }

    public function testBackendForThrowsForAnUnknownProviderName(): void
    {
        $this->expectException(\Throwable::class);
        Bootstrap::backendFor('this-provider-does-not-exist');
    }

    public function testSeedSessionCreatesASessionWhenTheStoreIsEmpty(): void
    {
        $store = $this->store();
        $this->assertSame([], $store->listSessions());

        [$id, $name] = Bootstrap::seedSession($store, 'sglang', 'MiniMax-M2.7');

        $this->assertNotSame('', $id);
        $this->assertNull($name);

        $rows = $store->listSessions();
        $this->assertCount(1, $rows);
        $this->assertSame($id, $rows[0]['id']);
        $this->assertSame('sglang', $rows[0]['provider']);
        $this->assertSame('MiniMax-M2.7', $rows[0]['model']);
    }

    public function testSeedSessionResumesTheMostRecentSessionWithItsName(): void
    {
        $store = $this->store();
        $store->createSession('older', 'echo', 'echo');
        $store->createSession('newest', 'echo', 'echo', null, 'Fix the parser');

        [$id, $name] = Bootstrap::seedSession($store);

        $this->assertSame('newest', $id);
        // Handing the existing name back is what stops Chat's auto-title
        // call re-naming (and overwriting) an already-titled session on the
        // first turn of every later launch.
        $this->assertSame('Fix the parser', $name);
        $this->assertCount(2, $store->listSessions(), 'resuming must not create a second row');
    }

    public function testSeedSessionReportsAResumedUnnamedSessionAsUnnamed(): void
    {
        $store = $this->store();
        $store->createSession('solo', 'echo', 'echo');

        $this->assertSame(['solo', null], Bootstrap::seedSession($store));
    }

    /**
     * The regression this whole step exists for: before it, Bootstrap::chat()
     * handed Chat a store it had never written to and no currentSessionId,
     * so listSessions() stayed empty for the process's whole lifetime and
     * every session-keyed feature was a guaranteed no-op.
     */
    public function testChatIsSeededWithARealSessionRow(): void
    {
        $home = $this->isolatedHome();

        $chat = Bootstrap::chat($home);

        $sessionId = $chat->currentSessionId();
        $this->assertNotNull($sessionId, 'Bootstrap::chat() must seed a session id');

        $store = $chat->sessionStore();
        $this->assertNotNull($store);
        $this->assertSame([$sessionId], array_column($store->listSessions(), 'id'));
    }

    public function testChatReusesTheSeededSessionOnASecondLaunch(): void
    {
        $home = $this->isolatedHome();

        $first = Bootstrap::chat($home);
        $second = Bootstrap::chat($home);

        $this->assertSame($first->currentSessionId(), $second->currentSessionId());
        $this->assertCount(1, $second->sessionStore()?->listSessions() ?? []);
    }

    public function testTitleBackendIsNullWithoutASelectedProvider(): void
    {
        $this->isolatedHome();
        putenv('SUGARCRUSH_PROVIDER');
        putenv('SUGARCRUSH_BACKEND_CMD');

        // No provider to build a cheap backend from; Chat's own fallback to
        // the main backend costs nothing on the Echo/command paths.
        $this->assertNull(Bootstrap::titleBackend());
    }

    public function testTitleBackendIsAToollessBackendOnTheSelectedProvider(): void
    {
        $this->isolatedHome();
        putenv('SUGARCRUSH_PROVIDER=sglang');
        putenv('SUGARCRUSH_TITLE_MODEL=tiny-model');

        $backend = Bootstrap::titleBackend();
        $this->assertInstanceOf(EngineBackend::class, $backend);

        // The whole point of the separate backend: none of the main turn's
        // expensive apparatus rides along on the title call.
        $this->assertSame('tiny-model', self::peek($backend, 'model'));
        $this->assertSame([], self::peek($backend, 'tools'));
        $this->assertNull(self::peek($backend, 'hookManager'));
        $this->assertNull(self::peek($backend, 'skillRegistry'));
        $this->assertNull(self::peek($backend, 'instructionLoader'));
    }

    public function testTitleBackendIgnoresTheMainConversationModelOverride(): void
    {
        $this->isolatedHome();
        putenv('SUGARCRUSH_PROVIDER=sglang');
        putenv('SUGARCRUSH_MODEL=the-big-expensive-model');
        putenv('SUGARCRUSH_TITLE_MODEL');

        $backend = Bootstrap::titleBackend();
        $this->assertInstanceOf(EngineBackend::class, $backend);
        $this->assertNotSame('the-big-expensive-model', self::peek($backend, 'model'));
    }

    public function testChatCarriesTheCheapTitleBackend(): void
    {
        $home = $this->isolatedHome();
        putenv('SUGARCRUSH_PROVIDER=sglang');

        $chat = Bootstrap::chat($home);

        $titleBackend = self::peek($chat, 'titleBackend');
        $this->assertInstanceOf(EngineBackend::class, $titleBackend);
        $this->assertNotSame($chat->backend(), $titleBackend);
    }

    private function store(): EnhancedSessionStore
    {
        $dir = $this->isolatedHome();

        return new EnhancedSessionStore($dir . '/seed-session.db');
    }

    /**
     * Point $HOME (which Bootstrap::configDir() reads) at a throwaway
     * directory so a test never reads or writes the developer's real
     * ~/.sugar-crush state.
     */
    private function isolatedHome(): string
    {
        if ($this->tmpHome === null) {
            $dir = sys_get_temp_dir() . '/crush-bootstrap-' . bin2hex(random_bytes(6));
            mkdir($dir, 0700, true);
            $this->tmpHome = $dir;
        }

        putenv("HOME={$this->tmpHome}");

        return $this->tmpHome;
    }

    private static function peek(object $object, string $property): mixed
    {
        $prop = new \ReflectionProperty($object, $property);

        return $prop->getValue($object);
    }

    private static function removeTree(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
