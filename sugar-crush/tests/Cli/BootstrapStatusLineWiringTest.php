<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Config\StatusLineCommand;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * The `statusLine` key reaching the runner from a real launch.
 *
 * Without this the whole feature can be unwired by deleting one line in
 * {@see Bootstrap::chat()} and every other test in the bundle stays green:
 * the parser, the runner, the sanitiser and the paint would all still pass
 * against a key nothing ever reads. That is the exact failure this key started
 * as — `statusLine` was absent from {@see LayeredSettings::LAYERED_KEYS}, so a
 * settings file naming it was SILENTLY DROPPED.
 *
 * {@see Bootstrap::app()} is the live root model (`bin/sugarcrush` builds it),
 * and it calls `chat()`, so pinning `chat()` covers both entry points.
 */
final class BootstrapStatusLineWiringTest extends TestCase
{
    use HomeSandboxTrait;

    private ?string $home = null;
    private ?string $project = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = sys_get_temp_dir() . '/crush-statusline-home-' . bin2hex(random_bytes(6));
        $this->project = sys_get_temp_dir() . '/crush-statusline-repo-' . bin2hex(random_bytes(6));
        mkdir($this->home . '/.sugar-crush', 0o700, true);
        mkdir($this->project . '/' . LayeredSettings::dir(), 0o700, true);

        $this->useHomeSandbox($this->home);
    }

    protected function tearDown(): void
    {
        Bootstrap::useProjectRootForSettings(null);
        Bootstrap::useConfigPath(null);
        StatusLineCommand::reset();
        $this->restoreHomeSandbox();

        foreach ([$this->home, $this->project] as $dir) {
            if ($dir !== null) {
                self::removeTree($dir);
            }
        }

        parent::tearDown();
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    private function writeUserSettings(array $data): void
    {
        file_put_contents(
            $this->home . '/.sugar-crush/' . LayeredSettings::USER_FILE,
            (string) json_encode($data),
        );
    }

    private function writeProjectSettings(array $data): void
    {
        file_put_contents($this->project . '/' . LayeredSettings::SHARED_PATH, (string) json_encode($data));
    }

    /**
     * THE WIRING. A `statusLine` in the user's own `settings.json` is installed
     * by the launch, before any frame is painted.
     */
    public function testTheUsersStatusLineIsInstalledByTheLaunch(): void
    {
        $this->writeUserSettings([
            StatusLineCommand::KEY => ['type' => 'command', 'command' => 'echo wired'],
        ]);

        Bootstrap::chat($this->project);

        self::assertNotNull(StatusLineCommand::active());
        self::assertSame('echo wired', StatusLineCommand::active()?->command);
    }

    /**
     * AND A PROJECT'S IS NOT — the tier claim measured through the real launch
     * rather than through {@see LayeredSettings::projectLayer()} alone. The
     * project is TRUSTED here (the operator's own
     * {@see LayeredSettings::PROJECT_SETTINGS_TRUST_KEY} grant), which is the
     * strongest position a checkout can be in, and it still may not choose what
     * this process executes.
     *
     * The `theme` beside it is the control: it is a key this tier MAY set, so a
     * launch that took neither would not have proved anything about the gate.
     */
    public function testATrustedProjectsStatusLineIsNotInstalledByTheLaunch(): void
    {
        file_put_contents(
            $this->home . '/.sugar-crush/config.json',
            (string) json_encode([
                LayeredSettings::PROJECT_SETTINGS_TRUST_KEY => [$this->project],
            ]),
        );
        $this->writeProjectSettings([
            'theme' => 'light',
            StatusLineCommand::KEY => ['type' => 'command', 'command' => 'echo pwned'],
        ]);

        Bootstrap::chat($this->project);

        // The control, read back through the same merged view the launch used:
        // `theme` IS a project-tier key, so its arrival proves the trust grant
        // is in force and the project layer was actually consulted.
        $merged = Bootstrap::readUserConfig();
        self::assertSame('light', $merged['theme'] ?? null, 'the trust grant must actually be in force');
        self::assertArrayNotHasKey(StatusLineCommand::KEY, $merged);

        self::assertNull(
            StatusLineCommand::active(),
            'a trusted project chose a command this process would run on a timer',
        );
    }

    /**
     * AND IT RUNS IN THE ROOT THE SESSION WAS LAUNCHED AGAINST, not in the
     * process's own cwd. {@see Bootstrap::chat()} passes `$root` for this
     * reason and `--root <lib>` in a monorepo is exactly where the two differ:
     * a `git`-shaped status command that inherited `getcwd()` would report a
     * different repository than the session is working in, silently and
     * plausibly.
     *
     * Measured through the LAUNCH rather than through
     * {@see \SugarCraft\Crush\Config\StatusLineCommand::configure()}'s own
     * parameter, which `StatusLineCommandTest::testTheConfiguredWorkingDirectoryIsWhereTheCommandRuns()`
     * already pins: at db20c568 that test stayed green with `$root` in this
     * call replaced by `null`, because nothing asserted which argument the
     * launch supplied. The `assertNotSame` below is the control — it is what
     * says the two directories really are different in this fixture, so a
     * `pwd` that answers the project root cannot have inherited it.
     */
    public function testTheCommandRunsInTheRootTheSessionWasLaunchedAgainst(): void
    {
        $this->writeUserSettings([
            StatusLineCommand::KEY => ['type' => 'command', 'command' => 'pwd'],
        ]);

        self::assertNotSame(
            realpath((string) getcwd()),
            realpath((string) $this->project),
            'the fixture must launch against a root that is NOT the process cwd',
        );

        Bootstrap::chat($this->project);
        StatusLineCommand::refresh();

        self::assertSame(
            realpath((string) $this->project),
            realpath(StatusLineCommand::line()),
            'the status command inherited the process cwd instead of the launched root',
        );
    }

    /**
     * A LAUNCH WITH NO `statusLine` CLEARS whatever a previous one installed.
     * The call is unconditional for this reason: in a process that builds more
     * than one Chat — a test suite, `/resume`, a second `Bootstrap::chat()` —
     * the second session must not keep painting the first's text.
     */
    public function testALaunchWithNoStatusLineClearsThePreviousOne(): void
    {
        $this->writeUserSettings([
            StatusLineCommand::KEY => ['type' => 'command', 'command' => 'echo first'],
        ]);
        Bootstrap::chat($this->project);
        self::assertNotNull(StatusLineCommand::active());

        $this->writeUserSettings(['theme' => 'dark']);
        Bootstrap::chat($this->project);

        self::assertNull(StatusLineCommand::active());
    }
}
