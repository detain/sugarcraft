<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
use SugarCraft\Crush\Tools\BuiltIn\Glob;
use SugarCraft\Crush\Tools\BuiltIn\Read;

/**
 * R19: bin/sugarcrush previously built `new Chat(backend: $backend)` with no
 * SessionStore/MemoryStore/InstructionFileLoader ever constructed, and built
 * Read/Edit/Glob with no InstructionFileLoader either -- leaving the
 * already-built /branch, /rewind, /memory, and nested-instruction-loading
 * features (P6.S9/S11/S12/S15) unreachable through the real CLI binary.
 *
 * This exercises SugarCraft\Crush\Cli\Bootstrap -- the construction logic
 * extracted out of bin/sugarcrush's IIFE -- directly, rather than shelling
 * out to bin/sugarcrush itself: the bin script ends in Program::run(), which
 * attaches to a real TTY and blocks, so it cannot be driven from a
 * deterministic, CI-safe test.
 */
final class BinSugarcrushWiringTest extends TestCase
{
    private string $tempDir;
    private string $originalHome;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sugarcrush_bin_wiring_' . uniqid('', true);
        mkdir($this->tempDir . '/home', 0700, true);
        mkdir($this->tempDir . '/repo', 0755, true);

        // Isolate the real ~/.sugar-crush/ from this test's session db and
        // memory directory, same convention as SessionTest/WorkflowEngineTest.
        $this->originalHome = getenv('HOME') ?: '';
        putenv('HOME=' . $this->tempDir . '/home');
    }

    protected function tearDown(): void
    {
        if ($this->originalHome !== '') {
            putenv('HOME=' . $this->originalHome);
        } else {
            putenv('HOME');
        }
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    public function testChatIsWiredWithANonNullSessionStore(): void
    {
        $chat = Bootstrap::chat($this->tempDir . '/repo');

        $this->assertInstanceOf(EnhancedSessionStore::class, $chat->sessionStore());
    }

    public function testChatIsWiredWithANonNullMemoryStore(): void
    {
        $chat = Bootstrap::chat($this->tempDir . '/repo');

        $this->assertInstanceOf(MemoryStore::class, $chat->memoryStore());
    }

    public function testSessionStoreDatabaseIsCreatedUnderTheUserConfigDir(): void
    {
        Bootstrap::chat($this->tempDir . '/repo');

        $this->assertFileExists($this->tempDir . '/home/.sugar-crush/session.db');
    }

    public function testMemoryStoreDirectoryIsCreatedUnderTheUserConfigDir(): void
    {
        Bootstrap::chat($this->tempDir . '/repo');

        $this->assertDirectoryExists($this->tempDir . '/home/.sugar-crush/memory');
    }

    public function testReadEditGlobEachReceiveANonNullInstructionLoader(): void
    {
        $byClass = $this->toolsByClass();

        foreach ([Read::class, Edit::class, Glob::class] as $class) {
            $this->assertArrayHasKey($class, $byClass, "Expected {$class} among the built-in tools");
            $this->assertInstanceOf(
                InstructionFileLoader::class,
                $this->instructionLoaderOf($byClass[$class]),
                "{$class} must be wired with a non-null InstructionFileLoader",
            );
        }
    }

    public function testReadEditGlobShareTheSameInstructionLoaderInstance(): void
    {
        // loadForPath() tracks "already injected this session" per loader
        // instance (InstructionFileLoader::$injectedPaths) -- a shared
        // instance across the three tools is what makes the nested
        // CLAUDE.md/AGENTS.md dedup semantics apply CLI-wide instead of
        // once per tool.
        $byClass = $this->toolsByClass();

        $readLoader = $this->instructionLoaderOf($byClass[Read::class]);
        $editLoader = $this->instructionLoaderOf($byClass[Edit::class]);
        $globLoader = $this->instructionLoaderOf($byClass[Glob::class]);

        $this->assertSame($readLoader, $editLoader);
        $this->assertSame($editLoader, $globLoader);
    }

    /**
     * R20.fix regression (reviewer-reported): `Bootstrap::chat()` never
     * constructs/passes an `agentManager:` -- confirming that here in the
     * same test file that already exercises `Bootstrap::chat()` directly
     * documents the gap where a future reader will actually see it, rather
     * than only in a docblock. See `Renderer.php`'s "R20.fix" note.
     */
    public function testChatHasNoAgentManagerSinceBootstrapDoesNotConstructOne(): void
    {
        $chat = Bootstrap::chat($this->tempDir . '/repo');

        $this->assertNull($chat->agentManager());
    }

    /**
     * R20.fix regression: with the gap above in place, typing "/agents" (or
     * pressing Ctrl+A) against a real `Bootstrap::chat()`-constructed Chat
     * used to throw an uncaught `RuntimeException('AgentManager not set')`
     * straight out of `Chat::update()` -- candy-core's `Program` has no
     * try/catch around its synchronous update() dispatch, so this crashed
     * the live CLI outright (and skipped `teardownTerminal()`). It must now
     * degrade to a plain "not configured" response instead.
     */
    public function testAgentsCommandDoesNotCrashARealBootstrapConstructedChat(): void
    {
        $chat = Bootstrap::chat($this->tempDir . '/repo');
        $ref = new \ReflectionMethod($chat, 'withInputBuf');
        $ref->setAccessible(true);
        $chat = $ref->invoke($chat, '/agents');

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertStringContainsString('Agent manager not configured', $next->history[array_key_last($next->history)]->content);
    }

    /**
     * @return array<class-string, object>
     */
    private function toolsByClass(): array
    {
        $byClass = [];
        foreach (Bootstrap::tools($this->tempDir . '/repo') as $tool) {
            $byClass[$tool::class] = $tool;
        }

        return $byClass;
    }

    private function instructionLoaderOf(object $tool): ?InstructionFileLoader
    {
        $property = new \ReflectionProperty($tool, 'instructionLoader');
        $property->setAccessible(true);

        /** @var InstructionFileLoader|null $value */
        $value = $property->getValue($tool);

        return $value;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
