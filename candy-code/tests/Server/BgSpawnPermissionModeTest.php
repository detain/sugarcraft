<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Sessions\BackgroundSupervisor;
use SugarCraft\Code\Tests\Sessions\StoppableDaemonFixtureTrait;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Server\Support\WireClient;

/**
 * ISS-004: a daemon born from a `serve` runs in the server session's
 * permission mode, not in the headless bypass default the bare `/bg` keeps.
 *
 * The proof is the daemon's own answer: the backend stub echoes
 * `MODE[$CANDY_CODE_PERMISSION_MODE]`, so what the test reads back is the
 * very environment {@see \SugarCraft\Code\Sessions\BackgroundSessionRunner::backend()}
 * settled before building the gate — spawn config → argv → putenv → backend
 * env walked end to end, with no reflection onto internals.
 *
 * The polarities matter as much as the threading: an unwired supervisor (every
 * local TUI `/bg`) must still name NO mode and keep the documented daemon
 * default, and a mode the server would not admit must never ride out to a
 * daemon at all.
 */
final class BgSpawnPermissionModeTest extends TestCase
{
    use StoppableDaemonFixtureTrait;

    /** Scratch temp root for the supervisor; short, the socket path lives inside it. */
    private string $tempRoot = '';

    private ?ProtocolFixture $fixture = null;

    /** The test process's own mode env, so an inherited value cannot fake a pin. */
    private string|false $savedMode = false;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['proc_open', 'pcntl_fork', 'posix_setsid', 'stream_socket_server'] as $fn) {
            if (!\function_exists($fn)) {
                self::markTestSkipped("{$fn}() unavailable");
            }
        }

        $this->tempRoot = \sys_get_temp_dir() . '/bgmode' . \bin2hex(\random_bytes(4));
        \mkdir($this->tempRoot, 0o700);

        $this->savedMode = \getenv('CANDY_CODE_PERMISSION_MODE');
        putenv('CANDY_CODE_PERMISSION_MODE');
    }

    protected function tearDown(): void
    {
        putenv($this->savedMode === false ? 'CANDY_CODE_PERMISSION_MODE' : 'CANDY_CODE_PERMISSION_MODE=' . $this->savedMode);
        $this->fixture?->tearDown();
        if ($this->fixtureHome !== '') {
            $this->tearDownDaemonFixture();
        }
        ProtocolFixture::removeTree($this->tempRoot);

        parent::tearDown();
    }

    public function testBgSpawnRunsTheDaemonInTheServerSessionMode(): void
    {
        $supervisor = new BackgroundSupervisor(tempRoot: $this->tempRoot);
        $fixture = $this->serve($supervisor, PermissionMode::AcceptEdits, allowBypass: false);
        $client = $fixture->client();

        $answer = $this->spawnViaWire($client, $supervisor);

        self::assertSame('accept-edits', $answer, 'the daemon resolved the gate from a mode the server did not name');
    }

    /**
     * The host-side `/bg` (and `/fork`) name no mode per spawn — they lean on
     * the default {@see \SugarCraft\Code\Protocol\ServerContext} wires into
     * the supervisor at construction, which is the same call
     * `BackgroundCommand` makes through the host.
     */
    public function testAServeWiresItsSupervisorSoAHostSpawnedDaemonInheritsTheMode(): void
    {
        $supervisor = new BackgroundSupervisor(tempRoot: $this->tempRoot);
        $fixture = $this->serve($supervisor, PermissionMode::Plan, allowBypass: false);
        self::assertSame('plan', $supervisor->spawnPermissionMode(), 'ServerContext::new() must wire the admitted mode');

        $answer = $this->spawnDirect($supervisor, $fixture->root);

        self::assertSame('plan', $answer, 'the host path must ride the server mode without naming one');
    }

    /**
     * The other polarity, pinned so the fix cannot silently widen: a bare
     * supervisor — the local TUI's shape — names no mode, the variable stays
     * absent, and the stub sees nothing (the daemon's own documented default
     * is the GATE's fallback, not an environment value; the `NONE` the echo
     * answers with is exactly the pre-ISS-004 spawn).
     */
    public function testAnUnwiredSupervisorStillLeavesTheDaemonOnItsDocumentedDefault(): void
    {
        $supervisor = new BackgroundSupervisor(tempRoot: $this->tempRoot);
        $this->echoBackendCmd();
        $this->setUpDaemonFixture();

        $answer = $this->spawnDirect($supervisor, $this->tempRoot);

        self::assertSame('NONE', $answer, 'no ServerContext means no mode key, exactly the pre-ISS-004 spawn');
        self::assertNull($supervisor->spawnPermissionMode());
    }

    /**
     * A bypass-class mode reaches a daemon only through a server whose
     * operator permitted it. `problems()` refuses that config at boot; this
     * pins the application-point check that answers a directly constructed
     * config (the fixture's shape, and any future caller that skips the boot
     * door) by downgrading to the mode whose asks the no-terminal approver
     * refuses — never by shipping the withheld bypass out in argv.
     */
    public function testAnUnadmittedBypassModeIsDowngradedNotSpawned(): void
    {
        $supervisor = new BackgroundSupervisor(tempRoot: $this->tempRoot);
        $fixture = $this->serve($supervisor, PermissionMode::BypassPermissions, allowBypass: false);
        $client = $fixture->client();

        $answer = $this->spawnViaWire($client, $supervisor);

        self::assertSame('default', $answer, 'a withheld bypass-class mode must downgrade to the asking mode');
    }

    public function testAnAdmittedBypassServerPassesItsModeThrough(): void
    {
        $supervisor = new BackgroundSupervisor(tempRoot: $this->tempRoot);
        $fixture = $this->serve($supervisor, PermissionMode::BypassPermissions, allowBypass: true);
        $client = $fixture->client();

        $answer = $this->spawnViaWire($client, $supervisor);

        self::assertSame('bypass-permissions', $answer, 'the operator said --allow-bypass; the daemon runs in it');
    }

    // ── fixtures ───────────────────────────────────────────────────────

    private function serve(BackgroundSupervisor $supervisor, PermissionMode $mode, bool $allowBypass): ProtocolFixture
    {
        $this->setUpDaemonFixture();
        $this->echoBackendCmd();

        return $this->fixture = ProtocolFixture::new(
            config: ServerConfig::new($this->tempRoot . '/state')->withPermissionMode($mode)->withAllowBypass($allowBypass),
            supervisor: $supervisor,
        );
    }

    /** The daemon's whole backend is an echo of the mode variable it was given. */
    private function echoBackendCmd(): void
    {
        \putenv('CANDY_CODE_BACKEND_CMD=cat >/dev/null; printf "MODE[%s]" "${CANDY_CODE_PERMISSION_MODE:-NONE}"');
    }

    private function spawnViaWire(WireClient $client, BackgroundSupervisor $supervisor): string
    {
        $spawned = $client->call('bg.spawn', ['task' => 'echo the mode', 'idempotencyKey' => 'mode-' . \bin2hex(\random_bytes(4))]);

        return $this->awaitAnswer($supervisor, (string) $spawned['bgId']);
    }

    private function spawnDirect(BackgroundSupervisor $supervisor, string $workingDirectory): string
    {
        $session = $supervisor->spawnSession(
            name: 'mode probe',
            agent: $this->agent(),
            task: 'echo the mode',
            workingDirectory: $workingDirectory,
        );

        return $this->awaitAnswer($supervisor, $session->id);
    }

    /** The daemon's answered buffer once the task settles, mode token extracted. */
    private function awaitAnswer(BackgroundSupervisor $supervisor, string $bgId): string
    {
        $ipc = $this->ipcOf($supervisor, $bgId);
        $this->fixtureDaemons[] = ['pid' => $ipc['pid'], 'startTime' => $ipc['startTime'] ?? null];
        \array_push($this->fixtureFiles, $ipc['socketPath'], $ipc['bufferPath'], $ipc['bufferPath'] . '.log', $ipc['tokenPath'] ?? '');

        $deadline = \microtime(true) + 20.0;
        while (\microtime(true) < $deadline) {
            $text = (string) @file_get_contents((string) $ipc['bufferPath']);
            if (\preg_match('/MODE\[([^\]]*)\]/', $text, $one) === 1) {
                return $one[1];
            }
            if (\str_contains($text, '[session:task:failed]')) {
                self::fail("the daemon failed before answering: {$text}");
            }
            \usleep(100_000);
        }

        self::fail("the spawned daemon never answered: {$ipc['bufferPath']}");
    }

    private function agent(): Agent
    {
        return new Agent(
            name: 'mode-agent',
            description: 'echoes its gate mode',
            prompt: '',
            model: '',
            provider: '',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
        );
    }
}
