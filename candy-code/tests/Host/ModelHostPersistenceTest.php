<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\Settings\SettingsWriter;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Tests\Support\BackendSelectionEnvSandboxTrait;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\ScriptedProvider;

/**
 * W7a-2 (WEB-2 seam): a served `/model <provider> <model>` must PERSIST the
 * model choice through the native mechanism — the workspace's
 * {@see SettingsWriter} via {@see \SugarCraft\Code\Config\Settings\ModelChoice::persist()},
 * saved BEFORE the backend is built, exactly like `Chat::selectPaletteProvider()`.
 * Before this, the host's switch was session-only by design, so a serve
 * restart or `--continue` resume silently lost the selection the user had
 * made from the web picker.
 *
 * The provider axis stays unpersisted on BOTH surfaces (only the `models`
 * map rides settings), and a save that cannot happen — no writer, a name off
 * the provider roster — is reported rather than fatal, with the switch still
 * landing for the session.
 */
final class ModelHostPersistenceTest extends TestCase
{
    use BackendSelectionEnvSandboxTrait;
    use HomeSandboxTrait;

    private string $dir = '';

    private EnhancedSessionStore $store;

    /** @var string|false */
    private string|false $originalCustomKey = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/crush-model-persist-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->useHomeSandbox($this->dir . '/home');
        $this->clearBackendSelectionEnv();
        Bootstrap::useConfigPath(null);
        $this->store = new EnhancedSessionStore($this->dir . '/session.db');
        $this->store->createSession('s', 'p', 'm');

        // `custom` is a roster name the persist door will accept; the probe
        // factory answers the build so no network is ever dialed.
        $this->originalCustomKey = getenv('CUSTOM_API_KEY');
        putenv('CUSTOM_API_KEY=test-key');
    }

    protected function tearDown(): void
    {
        $this->originalCustomKey === false
            ? putenv('CUSTOM_API_KEY')
            : putenv('CUSTOM_API_KEY=' . $this->originalCustomKey);
        Bootstrap::useConfigPath(null);
        $this->restoreBackendSelectionEnv();
        $this->restoreHomeSandbox();
        foreach (glob($this->dir . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function testAHostedModelChoiceIsSavedBeforeTheBuildAndNamedInTheReply(): void
    {
        $host = $this->persistingHost();

        $host->runCommand('model', 'custom chosen-model');

        $config = Bootstrap::readUserConfig();
        self::assertSame('chosen-model', $config['models']['custom'] ?? null,
            'the choice rides the same per-provider models map the TUI writes');

        $rows = $host->history();
        $reply = $rows[count($rows) - 1];
        self::assertInstanceOf(Message::class, $reply);
        self::assertStringContainsString("Switched to provider 'custom', model 'chosen-model'", $reply->content);
        self::assertStringContainsString('saved as models.custom in', $reply->content,
            'the reply names the file the entry landed in');

        self::assertSame('chosen-model', self::liveModel($host), 'the session backend carries the choice too');
    }

    /** The saved entry is what the NEXT launch's resolution reads — the resume proof. */
    public function testTheSavedEntryIsWhatNativeLaunchResolutionReads(): void
    {
        $host = $this->persistingHost();
        $host->runCommand('model', 'custom chosen-model');

        $read = new \ReflectionMethod(Bootstrap::class, 'selectedModelName');
        self::assertSame('chosen-model', $read->invoke(null, 'custom'),
            'a serve restart resolves the chosen id through the same accessor the launch factory uses');
    }

    public function testTheSavedMapMergesInsteadOfMaskingOtherProviders(): void
    {
        Bootstrap::writeUserConfig(['models' => ['openai' => 'keep-me']]);
        $host = $this->persistingHost();

        $host->runCommand('model', 'custom chosen-model');

        $models = Bootstrap::readUserConfig()['models'];
        self::assertSame('keep-me', $models['openai'] ?? null, 'the whole merged map is written, not a lone key');
        self::assertSame('chosen-model', $models['custom'] ?? null);
    }

    public function testAHostWithoutAWriterStillSwitchesAndSaysItCannotSave(): void
    {
        $host = $this->plainHost(withWriter: false);

        $host->runCommand('model', 'custom chosen-model');

        self::assertSame('chosen-model', self::liveModel($host), 'the switch happens for this session regardless');
        $rows = $host->history();
        $reply = $rows[count($rows) - 1];
        self::assertStringContainsString('for this session only: nothing here saves a model choice', $reply->content);
        self::assertArrayNotHasKey('models', Bootstrap::readUserConfig(), 'no writer, no write, no lie');
    }

    /**
     * The native guard: never save a model under a name that is not a
     * provider — the entry would sit in config.json, read by nothing. The
     * session switch still lands (the factory can build it); only the write
     * is declined.
     */
    public function testANameOffTheProviderRosterIsNeverSaved(): void
    {
        $host = $this->persistingHost();

        $host->runCommand('model', 'not-a-provider ghost-model');

        $config = Bootstrap::readUserConfig();
        self::assertSame([], $config['models'] ?? [], 'the persist leg declined an off-roster name');
        self::assertSame('ghost-model', self::liveModel($host), "the session switch is the factory's business, not the roster's");
    }

    /** A save failure is reported, never fatal, and the switch still lands. */
    public function testAFailingWriterIsReportedAndTheSwitchStillLands(): void
    {
        $host = $this->hostOnWorkspace(
            WorkspaceContext::new(
                sessionStore: $this->store,
                backend: new EchoBackend(),
                backendFactory: $this->probeFactory(),
            )->withService(SettingsWriter::class, SettingsWriter::new(
                Bootstrap::userConfigPath(),
                static function (array $set, array $unset): never {
                    throw new \RuntimeException('the settings door is closed');
                },
            )),
        );

        $host->runCommand('model', 'custom chosen-model');

        $rows = $host->history();
        $reply = $rows[count($rows) - 1];
        self::assertStringContainsString("Switched to provider 'custom', model 'chosen-model'", $reply->content);
        self::assertStringContainsString('not saved:', $reply->content);
        self::assertSame('chosen-model', self::liveModel($host));
    }

    // ------------------------------------------------------------------

    private function persistingHost(): SessionHost
    {
        return $this->hostOnWorkspace(
            WorkspaceContext::new(
                sessionStore: $this->store,
                backend: new EchoBackend(),
                backendFactory: $this->probeFactory(),
            )->withService(SettingsWriter::class, $this->writer()),
        );
    }

    private function plainHost(bool $withWriter): SessionHost
    {
        $context = WorkspaceContext::new(
            sessionStore: $this->store,
            backend: new EchoBackend(),
            backendFactory: $this->probeFactory(),
        );
        if ($withWriter) {
            $context = $context->withService(SettingsWriter::class, $this->writer());
        }

        return $this->hostOnWorkspace($context);
    }

    private function hostOnWorkspace(WorkspaceContext $context): SessionHost
    {
        return SessionHost::new('s', $context, history: [], customCommands: []);
    }

    /** @return \Closure(string): Backend */
    private function probeFactory(): \Closure
    {
        return static fn (string $provider): Backend => EngineBackend::new(new ScriptedProvider([]), 'stale-' . $provider);
    }

    private function writer(): SettingsWriter
    {
        return SettingsWriter::new(
            Bootstrap::userConfigPath(),
            static fn (array $set, array $unset) => Bootstrap::writeUserConfig($set, $unset),
        );
    }

    private static function liveModel(SessionHost $host): ?string
    {
        $backend = (new \ReflectionMethod(SessionHost::class, 'backend'))->invoke($host);

        return $backend instanceof EngineBackend ? $backend->model() : null;
    }
}
