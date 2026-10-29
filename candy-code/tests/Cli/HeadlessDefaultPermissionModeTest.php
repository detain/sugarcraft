<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\HeadlessPermissionPrompt;
use SugarCraft\Code\Cli\LaunchNotices;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Tools\ToolCall;

/**
 * C-SEC-7.a: `-p` starts in the ASKING `default` mode; locally spawned `/bg`
 * daemons keep `bypass-permissions` (plan §6.1, reversing rv-sec-1 for the
 * headless surface only).
 *
 * The split rides the `$consoleSurface` parameter of
 * {@see Bootstrap::permissionGate()}: `-p` reaches the gate through
 * {@see NonInteractive::consoleBackend()}, which names the HEADLESS surface,
 * while the daemon's own build passes no gate at all and lands on the plain
 * (BACKGROUND) resolution. These pins cover the resolution, both launch
 * notices the split produces, the unattended refusal's hint, and the
 * production reachability of the asking gate on the real `-p` builder —
 * because a constant flipped in isolation would let a later refactor put
 * `-p` back on the plain surface silently.
 */
final class HeadlessDefaultPermissionModeTest extends TestCase
{
    private string $home = '';
    private string $originalHome = '';
    private mixed $originalServerHome = null;
    private string|false $originalMode = false;
    private string $project = '';

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/csec7a_default_home_' . uniqid('', true);
        mkdir($this->home . '/.candy-code', 0700, true);
        $this->project = $this->home . '/project';
        mkdir($this->project, 0700, true);

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

        $this->originalMode === false ? putenv('CANDY_CODE_PERMISSION_MODE') : putenv('CANDY_CODE_PERMISSION_MODE=' . $this->originalMode);
        putenv('HOME=' . $this->originalHome);
        if ($this->originalServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $this->originalServerHome;
        }

        $this->removeDirectoryTree($this->home);
    }

    // -------------------------------------------------- the resolution split --

    public function testTheHeadlessSurfaceResolvesToTheAskingDefault(): void
    {
        $gate = Bootstrap::permissionGate(
            announceBypassMode: false,
            consoleSurface: Bootstrap::PERMISSION_SURFACE_HEADLESS,
        );

        $this->assertSame(PermissionMode::Default, $gate->mode());
        $this->assertSame(
            'the built-in default',
            $gate->modeSource(),
            'C-SEC-7.a moved -p by CHANGING the built-in default, not by faking a source',
        );
    }

    public function testThePlainSurfaceIsStillThePermissiveDaemonOne(): void
    {
        $this->assertSame(
            PermissionMode::BypassPermissions,
            Bootstrap::permissionGate(announceBypassMode: false)->mode(),
            'plan §6.1: locally spawned /bg daemons keep bypass — the no-gate build path must not move',
        );
    }

    // ------------------------------------------------------- the launch notices --

    public function testTheAskingHeadlessDefaultAnnouncesItselfOnce(): void
    {
        Bootstrap::permissionGate(consoleSurface: Bootstrap::PERMISSION_SURFACE_HEADLESS);
        Bootstrap::permissionGate(consoleSurface: Bootstrap::PERMISSION_SURFACE_HEADLESS);

        $hits = array_filter(
            Bootstrap::launchNotices(),
            static fn (string $n): bool => str_contains($n, 'starts in permission mode default'),
        );
        $this->assertCount(1, $hits, 'the store de-dupes by message identity; the flip must say so once, not per re-resolution');
    }

    public function testTheDaemonSurfaceNeverCarriesTheHeadlessAnnouncement(): void
    {
        Bootstrap::permissionGate();

        $this->assertStringContainsString('bypass-permissions', $this->headlessAwareNotices());
        $this->assertStringNotContainsString(
            'starts in permission mode default',
            $this->headlessAwareNotices(),
            'the daemon resolves bypass legitimately; the asking-default line is about -p only',
        );
    }

    public function testTheAskingAnnouncementIsSilentForReadOnlyProbes(): void
    {
        Bootstrap::permissionGate(
            announceBypassMode: false,
            consoleSurface: Bootstrap::PERMISSION_SURFACE_HEADLESS,
        );

        $this->assertSame(
            '',
            $this->headlessAwareNotices(),
            'doctor diagnoses a policy, it does not begin a session that will refuse (audit v3:U-F3 applies to the new line too)',
        );
    }

    public function testAnExplicitlyNamedModeNeverTriggersTheBuiltInAnnouncement(): void
    {
        putenv('CANDY_CODE_PERMISSION_MODE=default');

        Bootstrap::permissionGate(consoleSurface: Bootstrap::PERMISSION_SURFACE_HEADLESS);

        $this->assertSame('', $this->headlessAwareNotices(), 'a source that CHOSE asking already knows; the announcement is for the fall-through');
    }

    public function testAnExplicitBypassOnTheHeadlessSurfaceStillAnnouncesBypass(): void
    {
        putenv('CANDY_CODE_PERMISSION_MODE=bypass-permissions');

        $gate = Bootstrap::permissionGate(consoleSurface: Bootstrap::PERMISSION_SURFACE_HEADLESS);

        $this->assertSame(PermissionMode::BypassPermissions, $gate->mode());
        $this->assertStringContainsString(
            'every tool call runs without a prompt',
            $this->headlessAwareNotices(),
            'the rv-sec-1 banner is about the effective state; it survives the split for real bypass launches',
        );
        $this->assertStringNotContainsString('starts in permission mode default', $this->headlessAwareNotices());
    }

    // ------------------------------------------------------- the refusal hint --

    public function testAnUnattendedRefusalNamesTheAskingDefaultAndTheOneFlagFix(): void
    {
        $err = fopen('php://memory', 'r+b');
        $prompt = new HeadlessPermissionPrompt(PermissionMode::Default, null, $err);

        $allowed = $prompt(
            new ToolCall('h1', 'Bash', ['command' => 'make deploy']),
            new HookResult(HookResult::ASK, 'the model wants to run a command', null, ''),
        );

        self::assertFalse($allowed, 'an unattended ask under the new default fails closed');
        $written = $this->drainTo($err);
        self::assertStringContainsString('--permission-mode', $written);
        self::assertStringContainsString('starts in the asking', $written, 'the hint says WHY a no-flag run asks at all now');
        self::assertStringContainsString('fail closed', $written);
    }

    // ------------------------------------------------- production reachability --

    public function testConsoleBackendInstallsTheAskingGateOnTheRealPath(): void
    {
        $backend = NonInteractive::consoleBackend($this->project, null);

        $this->assertInstanceOf(EngineBackend::class, $backend);
        $gate = $backend->permissionGate();
        $this->assertNotNull($gate);
        $this->assertSame(
            PermissionMode::Default,
            $gate->mode(),
            'the -p run() route must carry the headless surface — a gate resolved anywhere else would keep -p permissive',
        );

        $approver = $this->promptAttachedTo($backend);
        $this->assertInstanceOf(HeadlessPermissionPrompt::class, $approver);
    }

    public function testConsoleBackendHonoursAnExplicitModeOverTheNewDefault(): void
    {
        putenv('CANDY_CODE_PERMISSION_MODE=plan');

        $backend = NonInteractive::consoleBackend($this->project, null);

        $this->assertInstanceOf(EngineBackend::class, $backend);
        $this->assertSame(
            PermissionMode::Plan,
            $backend->permissionGate()?->mode(),
            'the surface parameter must not outrank flag/env/config (same precedence walk as every path)',
        );
    }

    // ------------------------------------------------------------ helpers --

    /** @return string the notice store joined, for contains/not-contains legs. */
    private function headlessAwareNotices(): string
    {
        return implode("\n", Bootstrap::launchNotices());
    }

    /** @param resource $stream */
    private function drainTo($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    private function promptAttachedTo(EngineBackend $backend): ?object
    {
        $closure = (new \ReflectionProperty(EngineBackend::class, 'permissionApprover'))->getValue($backend);
        if (!$closure instanceof \Closure) {
            return null;
        }

        $bound = (new \ReflectionFunction($closure))->getClosureThis();

        return $bound instanceof HeadlessPermissionPrompt ? $bound : null;
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

    private function removeDirectoryTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
