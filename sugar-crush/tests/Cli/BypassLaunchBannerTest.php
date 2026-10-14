<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Permissions\PermissionMode;

/**
 * rv-sec-1 (fix 7): whenever the EFFECTIVE permission mode for a launch is
 * `bypass-permissions` — the shipped headless default, and any explicit
 * choice in config or env — the operator now gets one unavoidable line in
 * the same launch-notice store `/notices` reads (and one stderr pass, via
 * the channel's own once-per-process writer). No new mechanism: the banner
 * rides the exact seam E653 built for "this session can no longer DO X".
 *
 * The owner ruling that these pins keep honest: DEFAULT_PERMISSION_MODE is
 * NOT changed by this lane (row 6 pins the constant in place), and the
 * interactive default is already `Default`, so a plain TUI launch stays
 * silent (row 2) — the banner accuses, correctly, only launches that truly
 * run every tool without a prompt.
 */
final class BypassLaunchBannerTest extends TestCase
{
    private string $home = '';
    private string $originalHome = '';
    private mixed $originalServerHome = null;
    private string|false $originalMode = false;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/bypass_banner_home_' . uniqid('', true);
        mkdir($this->home . '/.sugar-crush', 0700, true);

        $this->originalHome = getenv('HOME') ?: '';
        putenv('HOME=' . $this->home);
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->home;

        $this->originalMode = getenv('SUGARCRUSH_PERMISSION_MODE');
        putenv('SUGARCRUSH_PERMISSION_MODE');

        $this->resetNoticeStatics();
    }

    protected function tearDown(): void
    {
        $this->resetNoticeStatics();

        $this->originalMode === false ? putenv('SUGARCRUSH_PERMISSION_MODE') : putenv('SUGARCRUSH_PERMISSION_MODE=' . $this->originalMode);
        putenv('HOME=' . $this->originalHome);
        if ($this->originalServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $this->originalServerHome;
        }

        foreach (glob($this->home . '/.sugar-crush/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->home . '/.sugar-crush');
        @rmdir($this->home);
    }

    public function testTheHeadlessDefaultIsBypassAndItAnnouncesItself(): void
    {
        $gate = Bootstrap::permissionGate(interactive: false);

        $this->assertSame(PermissionMode::BypassPermissions, $gate->mode());
        $this->assertStringContainsString(
            'bypass-permissions: every tool call runs without a prompt',
            $this->notices(),
            'the -p/daemon default is exactly the state rv-sec says must never be silent'
        );
    }

    public function testTheInteractiveDefaultStaysSilent(): void
    {
        $gate = Bootstrap::permissionGate(interactive: true);

        $this->assertNotSame(PermissionMode::BypassPermissions, $gate->mode());
        $this->assertSame('', $this->notices(), 'the TUI does not default to bypass; a banner there would train dismissal');
    }

    public function testAnExplicitBypassChoiceAnnouncesItselfEvenInteractively(): void
    {
        file_put_contents(
            $this->home . '/.sugar-crush/config.json',
            (string) json_encode(['permissionMode' => 'bypass-permissions'])
        );

        $gate = Bootstrap::permissionGate(interactive: true);

        $this->assertSame(PermissionMode::BypassPermissions, $gate->mode());
        $this->assertStringContainsString('bypass-permissions', $this->notices());
        $this->assertStringContainsString(
            '/permissions',
            $this->notices(),
            'the line must say how to change it, not only that it is on'
        );
    }

    public function testAnOptedOutModeStaysSilent(): void
    {
        putenv('SUGARCRUSH_PERMISSION_MODE=default');

        $gate = Bootstrap::permissionGate(interactive: false);

        $this->assertNotSame(PermissionMode::BypassPermissions, $gate->mode());
        $this->assertSame('', $this->notices());
    }

    public function testTheBannerIsRecordedOnceNoMatterHowOftenTheGateResolves(): void
    {
        Bootstrap::permissionGate(interactive: false);
        Bootstrap::permissionGate(interactive: false);
        Bootstrap::permissionGate(interactive: false);

        $hits = array_filter(Bootstrap::launchNotices(), static fn (string $n): bool => str_contains($n, 'bypass-permissions'));
        $this->assertCount(1, $hits, 'the store de-dupes by message identity; three resolutions are one notice');
    }

    public function testTheShippedDefaultsThemselvesAreUntouchedByThisLane(): void
    {
        // Owner ruling: rv-sec-1's ask is SIGNAL, not policy change. If a
        // later campaign flips the default, this pin must go red loudly and
        // with the ruling quoted — not silently via a renamed constant.
        $class = new \ReflectionClass(Bootstrap::class);

        $this->assertSame(
            PermissionMode::BypassPermissions,
            $class->getConstant('DEFAULT_PERMISSION_MODE'),
            'the headless default was deliberately NOT changed (owner ruling, campaign 2026-10-14)'
        );
        $this->assertSame(
            PermissionMode::Default,
            $class->getConstant('INTERACTIVE_DEFAULT_PERMISSION_MODE'),
            'the interactive default stays Default; the banner exists for the paths that ARE bypass'
        );
    }

    private function notices(): string
    {
        return implode("\n", Bootstrap::launchNotices());
    }

    private function resetNoticeStatics(): void
    {
        foreach ([
            'launchNotices' => [],
            'launchNoticesDropped' => [],
            'reportedPermissionConfigWarnings' => [],
        ] as $name => $value) {
            $property = new \ReflectionProperty(Bootstrap::class, $name);
            $property->setAccessible(true);
            $property->setValue(null, $value);
        }
    }
}
