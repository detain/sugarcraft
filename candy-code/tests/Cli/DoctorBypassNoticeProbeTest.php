<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchNotices;
use SugarCraft\Code\Permissions\PermissionMode;

/**
 * v3:U-F3 — `doctor` is a READ-ONLY diagnosis, yet its permission-policy
 * probe resolved the gate through the very method real launches use, and the
 * rv-sec-1 bypass banner fired: the launch-notice store grew a row, and the
 * once-per-process stderr writer printed a line advertising a session that
 * doctor never starts. "every tool call runs without a prompt" is a
 * statement ABOUT THE SESSION ABOUT TO RUN; on a probe it is a lie.
 *
 * The remedy is the announceBypassMode flag on
 * {@see Bootstrap::permissionGate()}: probe call sites pass false, launch
 * call sites keep the default. These pins hold BOTH polarities — the probe
 * in-process and through the real binary — and the real launch's banner.
 */
final class DoctorBypassNoticeProbeTest extends TestCase
{
    private const BANNER_FRAGMENT = 'bypass-permissions: every tool call runs without a prompt';

    private string $home = '';
    private string $originalHome = '';
    private mixed $originalServerHome = null;
    private string|false $originalMode = false;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/doctor_bypass_home_' . uniqid('', true);
        mkdir($this->home . '/.candy-code', 0700, true);
        file_put_contents(
            $this->home . '/.candy-code/config.json',
            (string) json_encode(['permissionMode' => 'bypass-permissions']),
        );

        $this->originalHome = getenv('HOME') ?: '';
        putenv('HOME=' . $this->home);
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->home;

        $this->originalMode = getenv('CANDY_CODE_PERMISSION_MODE');
        putenv('CANDY_CODE_PERMISSION_MODE');

        $this->resetNoticeStatics();
    }

    protected function tearDown(): void
    {
        $this->resetNoticeStatics();

        $this->originalMode === false
            ? putenv('CANDY_CODE_PERMISSION_MODE')
            : putenv('CANDY_CODE_PERMISSION_MODE=' . $this->originalMode);
        putenv('HOME=' . $this->originalHome);
        if ($this->originalServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $this->originalServerHome;
        }

        @unlink($this->home . '/.candy-code/config.json');
        @rmdir($this->home . '/.candy-code');
        @rmdir($this->home);
    }

    public function testTheDefaultHeadlessGateStillAnnouncesBypass(): void
    {
        $gate = Bootstrap::permissionGate();

        $this->assertSame(PermissionMode::BypassPermissions, $gate->mode());
        $this->assertStringContainsString(
            self::BANNER_FRAGMENT,
            implode("\n", Bootstrap::launchNotices()),
            'the launch path keeps the rv-sec-1 banner — the flag must not widen',
        );
    }

    public function testTheProbeCallResolvesTheSameGateWithoutARow(): void
    {
        $gate = Bootstrap::permissionGate(announceBypassMode: false);

        $this->assertSame(
            PermissionMode::BypassPermissions,
            $gate->mode(),
            'the flag gates the ANNOUNCEMENT only; the gate it hands back is identical',
        );
        $this->assertSame(
            [],
            Bootstrap::launchNotices(),
            'a read-only probe may not spend a transcript row on a session that is never launched',
        );
    }

    public function testTheInteractiveProbeCallStaysSilentToo(): void
    {
        $gate = Bootstrap::permissionGate(interactive: true, announceBypassMode: false);

        // The config names bypass explicitly, so even the interactive default
        // resolves to it — and the probe arm still records nothing.
        $this->assertSame(PermissionMode::BypassPermissions, $gate->mode());
        $this->assertSame([], Bootstrap::launchNotices());
    }

    public function testTheRealDoctorBinaryPrintsThePolicyAndNeverTheBanner(): void
    {
        $run = $this->runDoctor();

        $this->assertSame(0, $run['status'], 'stderr: ' . $run['stderr']);
        // The diagnosis itself keeps reporting the mode — U-F3 removes the
        // NOTICE, not the KNOWLEDGE.
        $this->assertStringContainsString('bypass-permissions', $run['stdout']);
        $this->assertStringNotContainsString(
            self::BANNER_FRAGMENT,
            $run['stderr'],
            'doctor used to trip the rv-sec-1 banner on stderr through the probe call',
        );
        $this->assertStringNotContainsString(
            self::BANNER_FRAGMENT,
            $run['stdout'],
        );
    }

    /**
     * @return array{status: int, stdout: string, stderr: string}
     */
    private function runDoctor(): array
    {
        $bin = \dirname(__DIR__, 2) . '/bin/candy-code';
        $env = [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => $this->home,
            'TMPDIR' => sys_get_temp_dir(),
        ];
        foreach (['CANDY_CODE_PERMISSION_MODE', 'CANDY_CODE_BACKEND_CMD'] as $scrub) {
            if (getenv($scrub) !== false) {
                $env[$scrub] = (string) getenv($scrub);
            }
        }
        $env['CANDY_CODE_PERMISSION_MODE'] = '';

        $stdout = '';
        $stderr = '';
        $process = proc_open(
            [\PHP_BINARY, $bin, '--root', $this->home, 'doctor'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env,
        );
        $this->assertIsResource($process);
        stream_set_timeout($pipes[1], 30);
        stream_set_timeout($pipes[2], 30);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function resetNoticeStatics(): void
    {
        foreach ([
            'launchNotices' => [],
            'launchNoticesDropped' => [],
            'reportedPermissionConfigWarnings' => [],
        ] as $name => $value) {
            $property = new \ReflectionProperty(LaunchNotices::class, $name);
            $property->setAccessible(true);
            $property->setValue(null, $value);
        }
    }
}
