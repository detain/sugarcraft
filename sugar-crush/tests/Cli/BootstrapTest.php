<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\View;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Renderer as LiveRenderer;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tools\Tool;

final class BootstrapTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $savedEnv = [];

    private ?string $tmpHome = null;

    protected function setUp(): void
    {
        // The mouse flags ride along because the hosted hit-test tests below
        // need clicks live; either flag left set by another suite would turn
        // Chat::zoneAt() into a constant null and pass them vacuously.
        $volatile = [
            'SUGARCRUSH_PROVIDER',
            'SUGARCRUSH_BACKEND_CMD',
            'SUGARCRUSH_MODEL',
            'SUGARCRUSH_TITLE_MODEL',
            'SUGARCRUSH_DISABLE_MOUSE',
            'SUGARCRUSH_DISABLE_MOUSE_CLICKS',
        ];

        foreach (['HOME', ...$volatile] as $name) {
            $this->savedEnv[$name] = getenv($name);
        }

        // Ambient provider env would otherwise decide which backend path
        // these tests exercise; each test opts in to what it needs.
        foreach ($volatile as $name) {
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

    /**
     * The wiring W3.M3 exists for: before it, nothing in `src/` or `bin/`
     * ever built an App with a hosted Chat, so the whole pane shell was
     * unreachable from a real run.
     */
    public function testAppHostsTheChatBootstrapAlreadyBuilds(): void
    {
        $home = $this->isolatedHome();

        $app = Bootstrap::app($home);

        $this->assertInstanceOf(App::class, $app);
        $this->assertInstanceOf(Chat::class, $app->chat);
        // The shell reads the id off the hosted chat rather than deriving a
        // second one, so the two can never name different sessions.
        $this->assertNotNull($app->chat->currentSessionId());
        $this->assertSame($app->chat->currentSessionId(), $app->sessionId);
    }

    public function testAppSeedsExactlyOneSessionRowPerLaunch(): void
    {
        $home = $this->isolatedHome();

        $first = Bootstrap::app($home);
        $rows = $first->chat?->sessionStore()?->listSessions() ?? [];
        $this->assertSame([$first->sessionId], array_column($rows, 'id'));

        // Re-seeding inside app() instead of reusing chat()'s seeding would
        // show up here as a second row (and a new id) on every launch.
        $second = Bootstrap::app($home);
        $this->assertSame($first->sessionId, $second->sessionId);
        $this->assertCount(1, $second->chat?->sessionStore()?->listSessions() ?? []);
    }

    public function testAppPopulatesTheShellsOwnPanes(): void
    {
        $home = $this->isolatedHome();

        $app = Bootstrap::app($home);

        // An empty Tools/Skills sidebar in the newly-wired shell is the
        // failure mode this wave is fixing, not an acceptable default.
        $this->assertNotEmpty($app->tools);
        $this->assertContainsOnlyInstancesOf(Tool::class, $app->tools);
        $this->assertInstanceOf(SkillRegistry::class, $app->availableSkills);
    }

    public function testAppFallsBackToTheEchoProviderLabelWithoutASelectedProvider(): void
    {
        $home = $this->isolatedHome();

        $app = Bootstrap::app($home);

        $this->assertSame('echo', $app->provider->name());
        $this->assertSame('echo', $app->model);
    }

    public function testAppLabelsTheShellWithTheSelectedProviderAndModel(): void
    {
        $home = $this->isolatedHome();
        putenv('SUGARCRUSH_PROVIDER=sglang');
        putenv('SUGARCRUSH_MODEL=MiniMax-M2.7');

        $app = Bootstrap::app($home);

        $this->assertSame('sglang', $app->provider->name());
        $this->assertSame('MiniMax-M2.7', $app->model);
    }

    /**
     * The live chain this step claims: bin/sugarcrush -> Bootstrap::app() ->
     * App::view() -> Tui\Renderer -> ChatPane -> the live Renderer. The
     * transcript body must come from src/Renderer.php (the content model that
     * carries every Wave 1/2 feature), inside the shell's own chrome.
     */
    public function testAppViewDrawsTheShellChromeAroundTheLiveRenderersTranscript(): void
    {
        $home = $this->isolatedHome();

        // Size arrives as a WindowSizeMsg in production; drive the same path
        // rather than rendering against the cached terminal-size probe.
        [$sized] = Bootstrap::app($home)->update(new WindowSizeMsg(120, 40));
        $this->assertInstanceOf(App::class, $sized);

        $view = $sized->view();
        $body = $view instanceof View ? $view->body : $view;
        $plain = (string) preg_replace('/\e\[[0-9;]*m/', '', $body);

        // Shell chrome (Tui\Renderer -> MenuBar).
        $this->assertStringContainsString('Currently: Chat', $plain);
        // Content (ChatPane -> src/Renderer.php's own empty-transcript text).
        $this->assertStringContainsString('empty conversation', $plain);

        // Invariant 1/3 of Renderer::renderView(): the frame is clipped, never
        // merely padded — an over-tall or over-wide frame desynchronises
        // candy-core's absolute-cursor repaint (PR #1403).
        $lines = explode("\n", $plain);
        $this->assertLessThanOrEqual(40, count($lines));
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(120, mb_strwidth($line));
        }
    }

    /**
     * Booting the shell must not break the Wave-2 mouse chain.
     *
     * `Chat` scans its OWN frame for click zones, and in the hosted
     * arrangement that frame is a sub-frame: it sits inside the chat pane's
     * box, below the menu bar and beside the left sidebar. Mouse reports stay
     * terminal-absolute, so the zones have to be re-based onto the composed
     * frame or every click lands on the wrong cell — the regression this
     * asserts against is a `pane:menu` hit two rows above, and ~35 columns
     * left of, the `Ctrl+P menu` text actually painted on screen.
     */
    public function testHostedFrameHitTestsAClickWhereTheTargetIsReallyPainted(): void
    {
        $home = $this->isolatedHome();

        [$sized] = Bootstrap::app($home)->update(new WindowSizeMsg(120, 40));
        $this->assertInstanceOf(App::class, $sized);
        $view = $sized->view();
        $body = $view instanceof View ? $view->body : $view;

        // The on-screen position of a marked element, measured on the frame a
        // user would be looking at rather than on the sub-frame it came from.
        [$col, $row] = self::locate($body, 'Ctrl+P menu');

        $this->assertSame('pane:menu', Chat::zoneAt($col, $row)?->id);
        $this->assertSame('pane:menu', Chat::zoneAt($col + 4, $row)?->id);
        // The cell before the hint is box padding, not a control.
        $this->assertNull(Chat::zoneAt($col - 1, $row));
        // ... and so is the position the un-rebased zone used to occupy.
        $this->assertNull(Chat::zoneAt($col - 30, $row - 2));
    }

    /**
     * The offset above is per-frame state, so a `Chat` rendered on its own
     * after a hosted frame must hit-test against its own coordinates again.
     * {@see \SugarCraft\Crush\Renderer::scanRoot()} resets the origin on every
     * scan for exactly this reason.
     */
    public function testStandaloneChatKeepsHitTestingInItsOwnCoordinatesAfterAHostedFrame(): void
    {
        $home = $this->isolatedHome();

        [$sized] = Bootstrap::app($home)->update(new WindowSizeMsg(120, 40));
        $this->assertInstanceOf(App::class, $sized);
        $sized->view();

        $frame = LiveRenderer::render(Bootstrap::chat($home)->withSize(120, 40));
        [$col, $row] = self::locate($frame, 'Ctrl+P menu');

        $this->assertSame('pane:menu', Chat::zoneAt($col, $row)?->id);
    }

    /**
     * 1-based terminal column/row of $needle in a rendered frame, ignoring SGR.
     *
     * @return array{0: int, 1: int}
     */
    private static function locate(string $frame, string $needle): array
    {
        $plain = (string) preg_replace('/\e\[[0-9;]*m/', '', $frame);

        foreach (explode("\n", $plain) as $index => $line) {
            $at = mb_strpos($line, $needle);
            if ($at !== false) {
                return [mb_strwidth(mb_substr($line, 0, $at)) + 1, $index + 1];
            }
        }

        self::fail("'{$needle}' is not painted anywhere in the frame");
    }

    /**
     * W1.E3's regression, re-asserted against the shell: `--help` must be
     * answered by ArgvParser/Help before any TUI is constructed. Booting the
     * pane shell above that dispatch would put the user back in a blocking
     * alt-screen for `--help`.
     *
     * Shelling out is the only way to assert this — the ordering lives in the
     * bin script itself, not in a class.
     */
    public function testHelpIsAnsweredBeforeAnyTuiOrSessionIsConstructed(): void
    {
        $home = $this->isolatedHome();
        $lib = dirname(__DIR__, 2);

        [$status, $stdout] = self::runBin([$lib . '/bin/sugarcrush', '--help'], $lib, $home);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('Usage:', $stdout);
        // No alt-screen switch => Program::run() was never reached.
        $this->assertStringNotContainsString("\e[?1049h", $stdout);
        // Bootstrap::app() would have created the session db on its way to
        // building the Chat; its absence proves nothing was bootstrapped.
        $this->assertFileDoesNotExist($home . '/.sugar-crush/session.db');
    }

    /**
     * Run the CLI binary to completion under a deadline, so a regression that
     * re-introduces a blocking TUI fails the test instead of hanging CI.
     *
     * @param list<string> $command
     *
     * @return array{0: int, 1: string} exit status, stdout
     */
    private static function runBin(array $command, string $cwd, string $home): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = ['HOME' => $home, 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'];

        $process = proc_open([PHP_BINARY, ...$command], $descriptors, $pipes, $cwd, $env);
        self::assertIsResource($process);

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $deadline = microtime(true) + 30.0;
        while (microtime(true) < $deadline) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            if (!(proc_get_status($process)['running'] ?? false)) {
                break;
            }
            usleep(20_000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $status = proc_get_status($process);
        if ($status['running'] ?? false) {
            proc_terminate($process, SIGKILL);
            proc_close($process);
            self::fail('bin/sugarcrush did not exit — a TUI was constructed before the flag dispatch');
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return [$status['exitcode'] ?? -1, $stdout];
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
