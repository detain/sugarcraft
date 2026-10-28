<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\App\App;
use SugarCraft\Code\App\StatusMsg;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchContext;
use SugarCraft\Code\Config\Settings\SettingsWriter;
use SugarCraft\Code\Config\Trust\TrustTarget;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Tui\TrustDialog;

/**
 * C-SEC-5.d: the launch trust dialog is OPENED by the interactive shell and
 * ANSWERED through the one grant door. These tests walk the wiring end to
 * end at the model layer — the census seeds the dialog at launch, keys move
 * it, and a grant leaves as a Cmd answered with a notice, landing (or
 * loudly failing) in the operator's own user config. The structural legs pin
 * the law the whole family exists for: `-p` and serve never build an App, so
 * they must never build a dialog either.
 */
final class LaunchTrustDialogTest extends TestCase
{
    private ProviderInterface $ldProvider;

    private string $ldHomeDir = '';

    private string $ldOriginalHome = '';

    private ?string $ldOriginalServerHome = null;

    /** @var list<string> */
    private array $ldProjectDirs = [];

    protected function setUp(): void
    {
        $this->ldProvider = $this->createMock(ProviderInterface::class);
        $this->ldProvider->method('name')->willReturn('stub');
        $this->ldArmHomeSandbox();
        LaunchContext::resetTrustDialogLatchForTesting();
    }

    protected function tearDown(): void
    {
        LaunchContext::resetTrustDialogLatchForTesting();
        foreach ($this->ldProjectDirs as $dir) {
            exec('rm -rf ' . \escapeshellarg($dir) . ' 2>&1');
        }
        $this->ldProjectDirs = [];
        $this->ldReleaseHomeSandbox();
    }

    public function testAnUntrustedHooksDeclarationOpensTheDialogAtLaunch(): void
    {
        $root = $this->ldProjectDir(withHooks: true);

        $dialog = LaunchContext::launchTrustDialog($root);

        self::assertInstanceOf(TrustDialog::class, $dialog);
        self::assertSame($root, $dialog->root());
        self::assertSame([TrustTarget::Hooks], $dialog->targets(), 'the census rows are the dialog rows');
    }

    public function testABareRootStaysSilentAndTheQuestionIsAskedOncePerRoot(): void
    {
        $bare = $this->ldProjectDir(withHooks: false);
        self::assertNull(LaunchContext::launchTrustDialog($bare), 'nothing pending, nothing asked');
        self::assertNull(LaunchContext::launchTrustDialog(null), 'no root, no question');

        $root = $this->ldProjectDir(withHooks: true);
        self::assertNotNull(LaunchContext::launchTrustDialog($root));
        self::assertNull(
            LaunchContext::launchTrustDialog($root),
            'the second launch of the same root must not re-ask what this process already asked',
        );

        LaunchContext::resetTrustDialogLatchForTesting();
        self::assertNotNull(LaunchContext::launchTrustDialog($root), 'the latch is the ask, not the census');
    }

    /**
     * The design's hard law, pinned structurally: `LaunchContext::app()` is
     * the dialog's only seed site — `-p` (NonInteractive) and serve never
     * build an App, and the headless file must carry no trace of the dialog
     * or the grant door.
     */
    public function testHeadlessLaunchesNeverBuildTheDialog(): void
    {
        $srcDir = __DIR__ . '/../../src';
        $withDialog = [];
        $launchSeeds = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($srcDir) + 1);
            $contents = (string) file_get_contents($file->getPathname());
            if (str_contains($contents, 'withTrustDialog(')) {
                $withDialog[] = $relative;
            }
            if (str_contains($contents, 'launchTrustDialog(')) {
                $launchSeeds[] = $relative;
            }
        }

        sort($withDialog);
        self::assertSame(
            ['App/App.php', 'Cli/LaunchContext.php'],
            $withDialog,
            'only the model wrapper and the interactive app() builder may speak to the dialog field',
        );
        self::assertSame(
            ['Cli/LaunchContext.php'],
            $launchSeeds,
            'app() is the census seed site, exactly once',
        );

        $headless = (string) file_get_contents($srcDir . '/Cli/NonInteractive.php');
        foreach (['TrustDialog', 'trustDialog', 'launchTrustDialog', 'grantProjectTrust'] as $token) {
            self::assertStringNotContainsString(
                $token,
                $headless,
                "the headless surface must not even name '{$token}'",
            );
        }
    }

    public function testGrantingARowWalksAppThroughTheOneDoorAndAnswersAsANotice(): void
    {
        $root = $this->ldProjectDir(withHooks: true);
        file_put_contents(Bootstrap::userConfigPath(), "{}\n");
        $app = $this->ldApp($root)->withTrustDialog(TrustDialog::new($root, [TrustTarget::Hooks]));

        [$settled, $cmd] = $app->update(new KeyMsg(KeyType::Char, 'y'));

        self::assertNull($settled->trustDialog, 'answering the last row closes the dialog');
        self::assertNotNull($cmd, 'a grant leaves as a Cmd, never inside the model');

        $notice = $cmd();
        self::assertInstanceOf(StatusMsg::class, $notice);
        self::assertSame(
            Lang::t('tui.trust.granted', ['targets' => Lang::t('tui.trust.target.hooks')]),
            $notice->message,
        );

        [$shown] = $settled->update($notice);
        self::assertSame($notice->message, $shown->status, 'the notice rides the status row like a settings save');

        $onDisk = json_decode((string) file_get_contents(Bootstrap::userConfigPath()), true);
        self::assertSame(
            [realpath($root)],
            $onDisk['trustedProjectHooks'],
            'the grant lands in the operator config as a canonical path',
        );
    }

    public function testSkippingOrDismissingWritesNothingAndCarriesNoNotice(): void
    {
        $root = $this->ldProjectDir(withHooks: true);
        file_put_contents(Bootstrap::userConfigPath(), "{}\n");

        [$skipped, $skipCmd] = $this->ldApp($root)
            ->withTrustDialog(TrustDialog::new($root, [TrustTarget::Hooks]))
            ->update(new KeyMsg(KeyType::Char, 'n'));
        self::assertNull($skipped->trustDialog, 'a completed walk closes even when nothing was granted');
        self::assertNull($skipCmd, 'refusal is the default; it needs no sentence repeating it');

        [$dismissed, $dismissCmd] = $this->ldApp($root)
            ->withTrustDialog(TrustDialog::new($root, [TrustTarget::Hooks]))
            ->update(new KeyMsg(KeyType::Escape));
        self::assertNull($dismissed->trustDialog, 'Escape closes the dialog...');
        self::assertNull($dismissCmd, '...and a dismissal answers NOTHING');

        $onDisk = json_decode((string) file_get_contents(Bootstrap::userConfigPath()), true);
        self::assertSame([], $onDisk, 'neither way out touched the operator config');
    }

    public function testAFailingWriteIsAnsweredAsALoudRefusalSentence(): void
    {
        $root = $this->ldProjectDir(withHooks: true);
        $writer = SettingsWriter::new(
            Bootstrap::userConfigPath(),
            static function (array $set, array $unset): void {
                throw new \RuntimeException('ld door refused');
            },
        );
        $app = $this->ldApp($root)
            ->withSettingsWriter($writer)
            ->withTrustDialog(TrustDialog::new($root, [TrustTarget::Hooks]));

        [, $cmd] = $app->update(new KeyMsg(KeyType::Char, 'y'));
        self::assertNotNull($cmd);

        $notice = $cmd();
        self::assertInstanceOf(StatusMsg::class, $notice);
        self::assertSame(
            Lang::t('tui.trust.failed', ['error' => 'ld door refused']),
            $notice->message,
            'the door never throws; its error surfaces as the notice the user reads',
        );
    }

    // ------------------------------------------------------------------

    private function ldApp(string $root): App
    {
        return App::new($this->ldProvider, 'gpt-4')
            ->withRoot($root)
            ->withSettingsWriter(SettingsWriter::new(
                Bootstrap::userConfigPath(),
                static function (array $set, array $unset): void {
                    Bootstrap::writeUserConfig($set, $unset);
                },
            ));
    }

    /** @return string the canonical temp project root */
    private function ldProjectDir(bool $withHooks): string
    {
        $dir = sys_get_temp_dir() . '/crush-ldialog-root-' . bin2hex(random_bytes(5));
        mkdir($dir . '/.candy-code', 0o700, true);
        if ($withHooks) {
            file_put_contents($dir . '/.candy-code/hooks.yaml', "# declared, untrusted\n");
        }
        $this->ldProjectDirs[] = $dir;

        return $dir;
    }

    private function ldArmHomeSandbox(): void
    {
        $dir = sys_get_temp_dir() . '/crush-ldialog-home-' . bin2hex(random_bytes(5));
        mkdir($dir . '/home/.candy-code', 0o700, true);
        $this->ldHomeDir = $dir;

        // Snapshot BEFORE overwriting (lane-cf law).
        $this->ldOriginalHome = getenv('HOME') ?: '';
        $this->ldOriginalServerHome = $_SERVER['HOME'] ?? null;
        putenv('HOME=' . $dir . '/home');
        $_SERVER['HOME'] = $dir . '/home';
        Bootstrap::useConfigPath(null);
    }

    private function ldReleaseHomeSandbox(): void
    {
        if ($this->ldHomeDir === '') {
            return;
        }

        Bootstrap::useConfigPath(null);
        putenv('HOME=' . $this->ldOriginalHome);
        if ($this->ldOriginalServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $this->ldOriginalServerHome;
        }
        exec('rm -rf ' . \escapeshellarg($this->ldHomeDir) . ' 2>&1');
        $this->ldHomeDir = '';
    }
}
