<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\Sandbox;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Background\BackgroundSupervisor;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchContext;
use SugarCraft\Code\Config\LayeredSettings;
use SugarCraft\Code\Config\Settings\SettingsSchema;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tools\Sandbox\Bubblewrap;
use SugarCraft\Code\Tools\Sandbox\EgressBridge;
use SugarCraft\Code\Tools\Sandbox\EgressProxyProcess;
use SugarCraft\Code\Tools\Sandbox\SandboxBackend;
use SugarCraft\Code\Tools\Sandbox\SandboxFactory;
use SugarCraft\Code\Tools\Sandbox\Seatbelt;

/**
 * C-TOOL-4.g: `bashSandbox: "allowlist"` and `sandboxAllowedDomains`.
 *
 * The jail's argv, the two backends' readings of the new word, the launch's
 * proxy lifecycle behind {@see Bootstrap::egressProxy()}, and the settings
 * row — pinned here as one family, because the mode's whole promise is the
 * chain setting → proxy → socket → bridge → 403, and a pin on any one link
 * alone would let the others drift silently.
 *
 * NO SKIPS ON PURPOSE on the live leg, in the house's
 * confines-or-refuses shape: a host where bwrap cannot start asserts the
 * refusal arm honestly; a host where it can must round-trip an allowed
 * request through the real proxy AND watch an unlisted host get the 403.
 * This box cannot start the full jail (unprivileged netns are restricted —
 * the documented fail-closed family), so locally the refusal arm is what
 * executes; the round-trip arm is the same code the plan's DoD names,
 * ungated, for capable hosts.
 */
final class EgressAllowlistModeTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tmpDir;
    private string $root;
    private string $home;
    private string $configDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/allowlist_egress_' . getmypid() . '_' . uniqid('', true);
        $this->root = $this->tmpDir . '/repo';
        self::assertTrue(mkdir($this->root, 0700, true));
        $this->home = $this->tmpDir . '/home';
        $this->configDir = $this->home . '/.candy-code';
        self::assertTrue(mkdir($this->configDir, 0700, true));
        $real = realpath($this->root);
        self::assertIsString($real);
        $this->root = $real;

        $this->useHomeSandbox($this->home);
        Bootstrap::useConfigPath(null);
        Bootstrap::useProjectRootForSettings(null);
        LaunchContext::useEgressProxyForTesting(null, false);
    }

    protected function tearDown(): void
    {
        LaunchContext::stopEgressProxy();
        LaunchContext::useEgressProxyForTesting(null, false);
        Bootstrap::useConfigPath(null);
        Bootstrap::useProjectRootForSettings(null);
        $this->restoreHomeSandbox();

        if (!is_dir($this->tmpDir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            @($item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()));
        }
        @rmdir($this->tmpDir);
    }

    public function testTheModeVocabularyGainsAllowlist(): void
    {
        self::assertSame(['off', 'on', 'no-network', 'allowlist'], SandboxBackend::MODES);
        self::assertSame('allowlist', Bubblewrap::fromSetting('allowlist')?->mode());
        // macOS reads the word as no-network and SAYS so — the point of the
        // follow-up note is that a Mac user is not silently on a broken jail.
        self::assertSame(SandboxBackend::MODE_NO_NETWORK, Seatbelt::fromSetting('allowlist')?->mode());
        self::assertStringContainsString(
            'loopback-port arm is a tracked follow-up',
            Seatbelt::fromSetting('allowlist')?->describe($this->root) ?? '',
        );
    }

    public function testTheAllowlistArgvCarriesSocketBindProxyEnvAndBridgeTail(): void
    {
        $jail = Bubblewrap::fromSetting('allowlist')->withEgress('/host-side/sock.s', ['egress.example.test']);
        $argv = $jail->argv($this->root);

        self::assertNotContains('--share-net', $argv, 'allowlist must never re-share the network');

        $bindAt = self::indexOfArm($argv, '--bind', '/host-side/sock.s', Bubblewrap::JAIL_EGRESS_SOCKET);
        $tmpfsAt = self::indexOfArm($argv, '--tmpfs', '/tmp');
        self::assertGreaterThan(-1, $bindAt);
        self::assertGreaterThan($tmpfsAt, $bindAt, 'the proxy socket bind must land AFTER --tmpfs /tmp, or the tmpfs erases it');

        $proxyUrl = 'http://127.0.0.1:' . EgressBridge::JAIL_TCP_PORT;
        foreach (['HTTP_PROXY', 'HTTPS_PROXY', 'http_proxy', 'https_proxy'] as $name) {
            self::assertGreaterThan(
                -1,
                self::indexOfArm($argv, '--setenv', $name, $proxyUrl),
                "{$name} must point jailed clients at the bridge port",
            );
        }

        self::assertGreaterThan(
            -1,
            self::indexOfArm($argv, '--setenv', 'NO_PROXY', 'localhost,127.0.0.1'),
            'loopback self-calls must keep working inside the jail',
        );

        $tail = [PHP_BINARY, '-r', EgressBridge::stub(BackgroundSupervisor::autoloadPath()), '--', Bubblewrap::JAIL_EGRESS_SOCKET, (string) EgressBridge::JAIL_TCP_PORT, '--'];
        $argvTail = array_slice($argv, count($argv) - count($tail));
        self::assertSame($tail, $argvTail, 'the bridge is the jail\'s first program, in-jail socket name and fixed port, then -- for the command');
        self::assertSame('--', $argv[count($argv) - count($tail) - 1], 'the bridge tail sits after bwrap\'s own --');
        $stubAt = count($argv) - count($tail) + 2;
        self::assertStringContainsString(
            var_export(BackgroundSupervisor::autoloadPath(), true),
            $argv[$stubAt],
            'the stub reaches the autoloader through the same baked path the proxy child uses',
        );
    }

    public function testAnAllowlistJailWithoutTheProxyStaysPlainNoNetwork(): void
    {
        $jail = Bubblewrap::fromSetting('allowlist')->withAllowedDomains(['egress.example.test']);
        $argv = $jail->argv($this->root);

        self::assertNotContains('--share-net', $argv);
        self::assertNotContains(Bubblewrap::JAIL_EGRESS_SOCKET, $argv, 'no proxy, no socket to bind — the door does not exist');

        $lastDash = -1;
        foreach ($argv as $i => $token) {
            if ($token === '--') {
                $lastDash = $i;
            }
        }
        self::assertSame([], array_slice($argv, $lastDash + 1), 'no bridge prefix when there is nothing to relay to');

        self::assertStringContainsString(
            'did not start, so there is NO network access at all',
            $jail->describe($this->root),
        );
    }

    public function testTheDescriptionNamesTheGrantedHostsOrTheEmptyList(): void
    {
        $granted = Bubblewrap::fromSetting('allowlist')->withEgress('/host-side/sock.s', ['egress.example.test']);
        $said = $granted->describe($this->root);
        self::assertStringContainsString('egress.example.test', $said);
        self::assertStringContainsString('403', $said, 'the promise every other host gets is part of the warning the model reads');
        self::assertStringContainsString('ssh', $said, 'non-HTTP failing is part of what a user must know before trusting the door');

        $empty = Bubblewrap::fromSetting('allowlist')->withEgress('/host-side/sock.s', []);
        self::assertStringContainsString('sandboxAllowedDomains', $empty->describe($this->root));
        self::assertStringNotContainsString('did not start', $empty->describe($this->root));
    }

    public function testTheBridgeRefusesAMalformedArgvPrefix(): void
    {
        // Every shape lands on exit 2 WITHOUT running anything — the command
        // never executes unsandboxed-relay, so the refusal is the whole of
        // what these legs can do to the world. The success leg is exercised
        // by the live driver below, never in-suite (fork law).
        self::assertSame(2, EgressBridge::runFromArgv([]));
        self::assertSame(2, EgressBridge::runFromArgv(['/tmp/s', 'abc', '--', 'p']));
        self::assertSame(2, EgressBridge::runFromArgv(['/tmp/s', '9', '-', 'p']));
        self::assertSame(2, EgressBridge::runFromArgv(['/tmp/s', '9', '--', '']));
    }

    public function testTheBridgeResolvesBareProgramsThroughPath(): void
    {
        self::assertSame('/x/y', EgressBridge::resolveProgram('/x/y'));
        self::assertSame('', EgressBridge::resolveProgram(''));
        $missing = 'definitely-not-a-command-' . uniqid('', true);
        self::assertSame($missing, EgressBridge::resolveProgram($missing), 'an unfindable word stays the bare word — the child keeps the honest 127');
        $sh = EgressBridge::resolveProgram('sh');
        self::assertNotSame('sh', $sh, 'a bare word the PATH holds must arrive absolute, or execv refuses it and every ordinary allowlisted command dies 127');
        self::assertTrue(is_executable($sh));
    }

    public function testTheFactoryHandsTheShelfSocketToTheJail(): void
    {
        $proxy = new EgressProxyProcess(['egress.example.test']);
        $proxy->start();
        LaunchContext::useEgressProxyForTesting($proxy);

        $jail = SandboxFactory::fromSetting('allowlist', 'Linux', ['sandboxAllowedDomains' => ['egress.example.test']]);
        self::assertInstanceOf(Bubblewrap::class, $jail);
        $argv = $jail->argv($this->root);
        self::assertGreaterThan(
            -1,
            self::indexOfArm($argv, '--bind', $proxy->socketPath(), Bubblewrap::JAIL_EGRESS_SOCKET),
            'the jail binds THE launch proxy\'s socket — a second scaffold would relay to nobody',
        );
        self::assertStringContainsString('egress.example.test', $jail->describe($this->root));

        $proxy->stop();
    }

    public function testTheFactoryWithoutAShelfJailsNoNetwork(): void
    {
        LaunchContext::useEgressProxyForTesting(null, true);

        $jail = SandboxFactory::fromSetting('allowlist', 'Linux', ['sandboxAllowedDomains' => ['egress.example.test']]);
        self::assertInstanceOf(Bubblewrap::class, $jail);
        self::assertNotContains(Bubblewrap::JAIL_EGRESS_SOCKET, $jail->argv($this->root));
        self::assertStringContainsString('did not start, so there is NO network access at all', $jail->describe($this->root));
    }

    public function testTheLaunchDoorStartsTheProxyOnceAndStopsIt(): void
    {
        $this->seedEgressSettings(['bashSandbox' => 'allowlist', 'sandboxAllowedDomains' => ['egress.example.test', 'junk entry!!']]);

        $first = Bootstrap::egressProxy();
        self::assertInstanceOf(EgressProxyProcess::class, $first);
        self::assertTrue($first->running());
        self::assertSame($first, Bootstrap::egressProxy(), 'the door answers from the shelf — a second start would orphan the first scaffold');
        self::assertTrue(LaunchContext::egressProxyResolved());

        LaunchContext::stopEgressProxy();
        self::assertFalse($first->running(), 'the shelf handle stops with the launch — that is what the shutdown hook calls');
        self::assertNull(LaunchContext::egressProxyInstance());
        self::assertNull(Bootstrap::egressProxy(), 'a stopped launch never respawns — resolved-null is final');
    }

    public function testTheSchemaRowIsUserTierOnlyAndDefaultsEmpty(): void
    {
        $definition = SettingsSchema::byKey('sandboxAllowedDomains');
        self::assertInstanceOf(\SugarCraft\Code\Config\Settings\SettingDefinition::class, $definition);
        self::assertSame([], $definition->default);
        self::assertTrue($definition->layered);
        self::assertFalse($definition->projectSettable, 'a checkout must not open the egress door');
        self::assertContains('sandboxAllowedDomains', LayeredSettings::LAYERED_KEYS);
        self::assertNotContains('sandboxAllowedDomains', LayeredSettings::PROJECT_TIER_KEYS);
    }

    public function testTheLiveJailEgressRefusesOrRoundTrips(): void
    {
        $driver = $this->tmpDir . '/egress-driver.php';
        self::assertGreaterThan(0, file_put_contents($driver, $this->allowlistDriverSource()));
        chmod($driver, 0600);

        $proc = proc_open([PHP_BINARY, '-d', 'error_log=/dev/null', $driver], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($proc, 'the driver must at least start — a php that cannot run is a broken box, not a test result');

        $deadline = microtime(true) + 150.0;
        $out = '';
        while (microtime(true) < $deadline) {
            $read = [$pipes[1]];
            $w = $e = null;
            if (stream_select($read, $w, $e, 1) > 0) {
                $chunk = fread($pipes[1], 8192);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $out .= $chunk;
                if (str_contains($out, 'JAIL-REFUSED') || str_contains($out, 'LIVE-OK-DENY') || str_contains($out, 'DRIVER-FAILED')) {
                    break;
                }
            }
            usleep(20000);
        }

        if (!str_contains($out, 'JAIL-REFUSED') && !str_contains($out, 'LIVE-OK-DENY') && !str_contains($out, 'DRIVER-FAILED')) {
            proc_terminate($proc);
        }
        $status = proc_close($proc);

        self::assertStringNotContainsString('DRIVER-FAILED', $out, 'the driver\'s own scaffolding broke (port/listener/proxy-start) — that is a box fault, not a jail verdict: ' . $out);
        $refused = str_contains($out, 'JAIL-REFUSED');
        $roundTrip = str_contains($out, 'LIVE-OK-ALLOW') && str_contains($out, 'LIVE-OK-DENY');
        self::assertTrue(
            $refused || $roundTrip,
            "the jail either refuses to start here (fail closed) or must allow the listed host and 403 the unlisted one; got: {$out} (status {$status})",
        );
    }

    /**
     * The in-jail end-to-end program, run as its own process (fork law: the
     * suite never pcntl_forks). Probes the real jail first; only a jail that
     * starts gets to claim the round-trip. The allowed leg targets this
     * box's own public address (a hairpin the proxy's real SSRF sweep can
     * pass — a loopback entry would be blocked as private by design); the
     * denied leg names TEST-NET-2, which the grant check refuses BEFORE any
     * resolver is consulted, so the suite stays hermetic even when the jail
     * works.
     */
    private function allowlistDriverSource(): string
    {
        $autoload = var_export(BackgroundSupervisor::autoloadPath(), true);
        $root = var_export($this->root, true);

        return str_replace(
            ['AUTOLOADE', 'ROOTE'],
            [$autoload, $root],
            <<<'PHP'
<?php
require AUTOLOADE;

use SugarCraft\Code\Tools\Sandbox\Bubblewrap;
use SugarCraft\Code\Tools\Sandbox\EgressProxyProcess;

$root = ROOTE;

// Arm one: does the full jail start at all here?
$probe = proc_open(Bubblewrap::new()->wrap('/bin/echo jailed-ok', $root, false), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
if ($probe === false) {
    echo "JAIL-REFUSED\n";
    exit(0);
}
$jailed = (string) stream_get_contents($pp[1]);
fclose($pp[1]);
fclose($pp[2]);
$probeStatus = proc_close($probe);
if ($probeStatus !== 0 || !str_contains($jailed, 'jailed-ok')) {
    echo "JAIL-REFUSED\n";
    exit(0);
}

// Arm two: real proxy, fake upstream on this box's public hairpin address.
$public = '216.158.226.14';
$errno = 0;
$error = '';
$server = @stream_socket_server('tcp://0.0.0.0:0', $errno, $error);
if ($server === false) {
    echo "DRIVER-FAILED listener\n";
    exit(0);
}
$name = (string) stream_socket_get_name($server, true);
$port = (int) substr($name, (int) strrpos($name, ':') + 1);

$proxy = new EgressProxyProcess(["$public:$port"]);
try {
    $proxy->start();
} catch (\Throwable) {
    echo "DRIVER-FAILED proxy\n";
    exit(0);
}

$sb = Bubblewrap::fromSetting('allowlist')->withEgress($proxy->socketPath(), ["$public:$port"]);
$token = uniqid('ale', true);

$allowed = proc_open(
    $sb->wrap('curl -s -m 15 -o /dev/null -w "%{http_code}" http://' . $public . ':' . $port . '/allow-' . $token, $root, false),
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $ap,
);
$code = '';
if ($allowed !== false) {
    $conn = @stream_socket_accept($server, 25);
    if ($conn !== false) {
        stream_set_timeout($conn, 10);
        $head = '';
        while (!feof($conn)) {
            $chunk = fread($conn, 4096);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $head .= $chunk;
            if (str_contains($head, "\r\n\r\n") || strlen($head) > 8192) {
                break;
            }
        }
        $body = 'up-' . $token;
        fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
        fclose($conn);
    }
    $code = trim((string) stream_get_contents($ap[1]));
    fclose($ap[1]);
    fclose($ap[2]);
    proc_close($allowed);
}
echo $code === '200' ? "LIVE-OK-ALLOW\n" : "LIVE-ALLOW-GOT($code)\n";

// TEST-NET-2 is not in the grant: EgressPolicy refuses at grant-matching,
// before resolving, so nothing leaves this box.
$denied = proc_open(
    $sb->wrap('curl -s -m 15 -o /dev/null -w "%{http_code}" http://198.51.100.23/deny', $root, false),
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $dp,
);
$denyCode = '';
if ($denied !== false) {
    $denyCode = trim((string) stream_get_contents($dp[1]));
    fclose($dp[1]);
    fclose($dp[2]);
    proc_close($denied);
}
echo $denyCode === '403' ? "LIVE-OK-DENY\n" : "LIVE-DENY-GOT($denyCode)\n";

$proxy->stop();
exit(0);
PHP,
        );
    }

    /**
     * Seed the user-tier settings the launch door reads.
     *
     * @param array<string, mixed> $settings
     */
    private function seedEgressSettings(array $settings): void
    {
        self::assertGreaterThan(0, file_put_contents(
            $this->configDir . '/' . LayeredSettings::USER_FILE,
            (string) json_encode($settings, JSON_PRETTY_PRINT),
        ));
        chmod($this->configDir . '/' . LayeredSettings::USER_FILE, 0600);
    }

    /**
     * First start index of a consecutive token run inside an argv list.
     *
     * @param list<string> $argv
     */
    private static function indexOfArm(array $argv, string ...$needle): int
    {
        $span = count($needle);
        foreach ($argv as $i => $token) {
            if ($token !== $needle[0] || $i + $span > count($argv)) {
                continue;
            }
            if (array_slice($argv, $i, $span) === $needle) {
                return $i;
            }
        }

        return -1;
    }
}
