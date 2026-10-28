<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\Subcommands;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tools\Sandbox\Bubblewrap;

/**
 * C-TOOL-4.a: the doctor's `sandbox` row.
 *
 * The row answers three questions through the SAME code the Bash tool uses —
 * the configured `bashSandbox` mode, where `bwrap` resolves, and whether the
 * jail actually STARTS — because presence is not capability (ISS-083): Ubuntu
 * 24.04 ships a `bwrap` that AppArmor's userns restriction kills before it
 * runs, and until this row `doctor` said nothing while every `Bash` call
 * refused. The start/stop half is exercised through
 * {@see \SugarCraft\Code\Tools\BuiltIn\Bash::sandboxRefusal()}, the extracted
 * probe `execute()` itself asks — so the row cannot drift into a second,
 * more optimistic opinion about the jail.
 *
 * The verdict arms are reached through the probe's test seams ($jail,
 * $sysctlPath, the forced host family), which is what makes every arm
 * pinnable on EVERY host — including this devbox, where the real bwrap is
 * present and the real sysctl reads 1. The row never FAILs: a dead jail next
 * to a supported `off` setting is a WARN with a remedy, not a broken install,
 * and that promise is pinned structurally as well as per arm.
 */
final class DoctorSandboxProbeTest extends TestCase
{
    use HomeSandboxTrait;

    /** The exact Ubuntu 24.04 AppArmor death — same string BashSandboxTest shims. */
    private const REFUSAL = 'bwrap: setting up uid map: Permission denied';

    private string $tmpDir = '';

    private string $configPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/doctor_sandbox_' . getmypid() . '_' . uniqid('', true);
        $home = $this->tmpDir . '/home';
        mkdir($home . '/.candy-code', 0o700, true);
        $this->useHomeSandbox($home);

        $this->configPath = $this->tmpDir . '/config.json';
        $this->armConfig([]);
    }

    protected function tearDown(): void
    {
        Bootstrap::useConfigPath(null);
        Bootstrap::useProjectRootForSettings(null);
        $this->restoreHomeSandbox();

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($this->tmpDir);

        parent::tearDown();
    }

    /**
     * Point the launch's config file at a temp file carrying exactly
     * $settings, so `bashSandbox` is decided by the test rather than by
     * whatever the ambient home carries.
     *
     * @param array<string, mixed> $settings
     */
    private function armConfig(array $settings): void
    {
        file_put_contents($this->configPath, (string) json_encode($settings));
        chmod($this->configPath, 0o600);
        Bootstrap::useConfigPath($this->configPath);
    }

    /**
     * The private seam, driven with its test arms.
     *
     * @return array{status: string, detail: string}
     */
    private function sandboxProbeRow(?\Closure $jail = null, ?string $sysctlPath = null, string $osFamily = 'Linux'): array
    {
        $method = new \ReflectionMethod(Subcommands::class, 'sandboxProbe');
        $method->setAccessible(true);

        /** @var array{status: string, detail: string} $row */
        $row = $method->invoke(null, $jail, $sysctlPath, $osFamily);

        return $row;
    }

    /** @return array<string, \Closure(): array{status: string, detail: string}> */
    private function probeRows(): array
    {
        $method = new \ReflectionMethod(Subcommands::class, 'doctorProbes');
        $method->setAccessible(true);

        /** @var array<string, \Closure> $rows */
        $rows = $method->invoke(null, ArgvParser::parse(['candy-code', 'doctor']));

        return $rows;
    }

    private function sysctlHolding(string $value): string
    {
        $path = $this->tmpDir . '/sysctl-' . uniqid('', true);
        file_put_contents($path, $value . "\n");

        return $path;
    }

    public function testTheSandboxRowSitsDirectlyBehindThePolicyItGrowsOutOf(): void
    {
        $labels = array_keys($this->probeRows());

        $this->assertContains('sandbox', $labels);
        $policy = (int) array_search('permission policy', $labels, true);
        $sandbox = (int) array_search('sandbox', $labels, true);
        $provider = (int) array_search('provider', $labels, true);
        $this->assertSame($policy + 1, $sandbox, 'the jail question belongs next to the policy question it extends');
        $this->assertLessThan($provider, $sandbox);
    }

    public function testTheLiveUnconfiguredRowIsOkAndHonestAboutBeingOff(): void
    {
        $rows = $this->probeRows();
        $row = ($rows['sandbox'])();

        // Nothing configured: OK on every host and every jail verdict — the
        // off arms answer OK whether or not the jail would start.
        $this->assertSame('OK', $row['status'], $row['detail']);
        $this->assertStringStartsWith('bashSandbox is off; bwrap', $row['detail']);
        $this->assertStringNotContainsString('cli.doctor.', $row['detail'], 'a raw key means a typo slipped past the catalogue');
    }

    public function testOffWithAStartingJailStaysOkAndSaysItWouldStart(): void
    {
        $row = $this->sandboxProbeRow(static fn (): ?string => null);

        $this->assertSame('OK', $row['status'], $row['detail']);
        $this->assertStringStartsWith('bashSandbox is off; bwrap', $row['detail']);
        $this->assertStringContainsString('and the jail would start here if it were enabled', $row['detail']);
        $this->assertStringNotContainsString('cli.doctor.', $row['detail']);
    }

    public function testOffWithADyingJailStillStaysOkButNamesTheReason(): void
    {
        $row = $this->sandboxProbeRow(static fn (): ?string => self::REFUSAL);

        $this->assertSame('OK', $row['status'], 'off is a supported configuration on a host whose jail cannot run');
        $this->assertStringContainsString('and the jail would not start here even if it were enabled (' . self::REFUSAL . ')', $row['detail']);
        $this->assertStringNotContainsString('cli.doctor.', $row['detail']);
    }

    public function testConfiguredWithAStartingJailIsOkAndPrintsTheMode(): void
    {
        $this->armConfig(['bashSandbox' => 'on']);

        $row = $this->sandboxProbeRow(static fn (): ?string => null);

        $this->assertSame('OK', $row['status'], $row['detail']);
        $this->assertStringStartsWith('bashSandbox "on"; bwrap', $row['detail']);
        $this->assertStringContainsString('and the jail starts', $row['detail']);
        $this->assertStringNotContainsString('cli.doctor.', $row['detail']);
    }

    public function testConfiguredWithADyingJailWarnsWithTheAppArmorRemedy(): void
    {
        $this->armConfig(['bashSandbox' => 'on']);

        $row = $this->sandboxProbeRow(
            static fn (): ?string => self::REFUSAL,
            $this->sysctlHolding('1'),
        );

        $this->assertSame('WARN', $row['status'], $row['detail']);
        $this->assertStringContainsString('bashSandbox "on" is configured but the jail does not start (' . self::REFUSAL . ')', $row['detail']);
        // The host fact flips the advice: a restricted kernel means installing
        // bubblewrap again changes nothing.
        $this->assertStringContainsString('/etc/apparmor.d', $row['detail']);
        $this->assertStringContainsString('apparmor_restrict_unprivileged_userns sysctl is the blunt fallback', $row['detail']);
        $this->assertStringNotContainsString('set bashSandbox to "off"', $row['detail'], 'the generic remedy must not ride the AppArmor arm');
        $this->assertStringNotContainsString('cli.doctor.', $row['detail']);
    }

    public function testConfiguredWithADyingJailOnAnUnknownOrPermissiveSysctlGivesTheGenericRemedy(): void
    {
        $this->armConfig(['bashSandbox' => 'no-network']);

        // Absent interface (null) and a readable non-restriction (false) both
        // answer with the honest generic line — absence is never "no".
        foreach ([$this->tmpDir . '/no-such-sysctl-arm', $this->sysctlHolding('0')] as $sysctl) {
            $row = $this->sandboxProbeRow(static fn (): ?string => self::REFUSAL, $sysctl);

            $this->assertSame('WARN', $row['status'], $row['detail']);
            $this->assertStringContainsString('bashSandbox "no-network" is configured', $row['detail']);
            $this->assertStringContainsString('install bubblewrap, or set bashSandbox to "off"', $row['detail']);
            $this->assertStringNotContainsString('apparmor.d', $row['detail']);
            $this->assertStringNotContainsString('cli.doctor.', $row['detail']);
        }
    }

    public function testANonLinuxHostReportsTheLinuxOnlyLine(): void
    {
        $off = $this->sandboxProbeRow(osFamily: 'Darwin');
        $this->assertSame('OK', $off['status'], $off['detail']);
        $this->assertSame('bashSandbox is off; bubblewrap is Linux-only and this host is Darwin', $off['detail']);

        $this->armConfig(['bashSandbox' => 'on']);
        $on = $this->sandboxProbeRow(osFamily: 'Darwin');
        $this->assertSame('WARN', $on['status'], 'a configured jail that can never run here is worth a WARN');
        $this->assertSame('bashSandbox "on" is configured but bubblewrap is Linux-only and this host is Darwin', $on['detail']);
    }

    public function testTheSysctlReaderAnswersTrueFalseAndUnknown(): void
    {
        $this->assertTrue(Bubblewrap::appArmorRestrictsUserns($this->sysctlHolding('1')));
        $this->assertFalse(Bubblewrap::appArmorRestrictsUserns($this->sysctlHolding('0')));
        $this->assertNull(
            Bubblewrap::appArmorRestrictsUserns($this->tmpDir . '/no-such-sysctl-direct'),
            'a kernel without the interface answers unknown, never no',
        );
        $this->assertSame(
            '/proc/sys/kernel/apparmor_restrict_unprivileged_userns',
            Bubblewrap::APPARMOR_RESTRICT_USERNS_PATH,
            'the named interface is the one Ubuntu restricts users on; a silent rename would blind the remedy arm',
        );
    }

    public function testTheRowAsksBashInsteadOfReimplementingTheProbe(): void
    {
        $src = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Cli/Subcommands.php');

        $start = (int) strpos($src, 'private static function sandboxProbe');
        $this->assertGreaterThan(0, $start, 'the probe body must be findable by name');
        $slice = substr($src, $start, (int) strpos(substr($src, $start), "\n    }"));

        // The capability question is asked through Bash's own extracted probe,
        // and the remedy arm through Bubblewrap's own reader — no second
        // bwrap opinion lives here.
        $this->assertStringContainsString('->sandboxRefusal()', $slice);
        $this->assertStringContainsString('Bubblewrap::appArmorRestrictsUserns(', $slice);
        $this->assertSame(0, substr_count($slice, 'proc_open'), 'the row must add no spawn site of its own');
        $this->assertSame(0, substr_count($slice, "'FAIL'"), 'the sandbox row is allowed OK and WARN only');

        $bash = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Tools/BuiltIn/Bash.php');
        $extracted = (int) strpos($bash, 'public function sandboxRefusal');
        $probes = (int) strpos($bash, '$this->sandbox->probe(');
        $asked = (int) strpos($bash, '$refusal = $this->sandboxRefusal();');
        $this->assertGreaterThan(0, $extracted);
        $this->assertGreaterThan($extracted, $probes, 'the probe closure must live inside the extracted method');
        $this->assertGreaterThan($probes, $asked, 'execute() must ASK the extracted method, not carry its own copy');
        $this->assertSame(1, substr_count($bash, '$this->sandbox->probe('));
        $this->assertSame(1, substr_count($bash, '$refusal = $this->sandboxRefusal();'));
    }
}
