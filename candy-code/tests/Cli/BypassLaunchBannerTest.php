<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchNotices;
use SugarCraft\Code\Permissions\PermissionMode;

/**
 * rv-sec-1 (fix 7): whenever the EFFECTIVE permission mode for a launch is
 * `bypass-permissions` — the shipped headless default, and any explicit
 * choice in config or env — the operator now gets one unavoidable line in
 * the same launch-notice store `/notices` reads (and one stderr pass, via
 * the channel's own once-per-process writer). No new mechanism: the banner
 * rides the exact seam E653 built for "this session can no longer DO X".
 *
 * The owner ruling these pins keep honest, AS AMENDED by C-SEC-7.a: rv-sec-1's
 * permissive `-p` default was REVERSED — `-p` now resolves
 * HEADLESS_DEFAULT_PERMISSION_MODE (`default`, asking), and only the spawned
 * daemon keeps BACKGROUND_DEFAULT_PERMISSION_MODE (`bypass-permissions`).
 * Row 6 pins that split in place, and the interactive default is still
 * `Default`, so a plain TUI launch stays silent (row 2) — the banner accuses,
 * correctly, only launches that truly run every tool without a prompt.
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
        mkdir($this->home . '/.candy-code', 0700, true);

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

        foreach (glob($this->home . '/.candy-code/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->home . '/.candy-code');
        @rmdir($this->home);
    }

    public function testTheBackgroundDaemonDefaultIsBypassAndItAnnouncesItself(): void
    {
        $gate = Bootstrap::permissionGate(interactive: false);

        $this->assertSame(PermissionMode::BypassPermissions, $gate->mode());
        $this->assertStringContainsString(
            'bypass-permissions: every tool call runs without a prompt',
            $this->notices(),
            'the daemon default is exactly the state rv-sec says must never be silent'
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
            $this->home . '/.candy-code/config.json',
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
        putenv('CANDY_CODE_PERMISSION_MODE=default');

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

    public function testTheShippedDefaultsAreTheSplitCSec7aPinnedThemTo(): void
    {
        // rv-sec-1 asked for SIGNAL, not policy change; C-SEC-7.a then made
        // the policy change §6.1 rules ("-p defaults to Manual/deny like
        // Claude Code; locally spawned /bg daemons keep bypass"). If a later
        // campaign flips either half, this pin must go red loudly and with
        // both rulings quoted — not silently via a renamed constant.
        $class = new \ReflectionClass(Bootstrap::class);

        $this->assertFalse(
            $class->getConstant('DEFAULT_PERMISSION_MODE') !== false,
            'the single permissive console constant is gone; the split is the shape now'
        );
        $this->assertSame(
            PermissionMode::Default,
            $class->getConstant('HEADLESS_DEFAULT_PERMISSION_MODE'),
            '-p starts in the asking mode (C-SEC-7.a reversal of rv-sec-1)'
        );
        $this->assertSame(
            PermissionMode::BypassPermissions,
            $class->getConstant('BACKGROUND_DEFAULT_PERMISSION_MODE'),
            'the spawned daemon keeps the permissive default (§6.1: bypass stays for /bg)'
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
            $property = new \ReflectionProperty(LaunchNotices::class, $name);
            $property->setAccessible(true);
            $property->setValue(null, $value);
        }
    }
}
