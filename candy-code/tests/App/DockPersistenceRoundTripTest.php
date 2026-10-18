<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\App;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\LayeredSettings;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Tui\Pane;
use SugarCraft\Code\Tui\Renderer as TuiRenderer;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Constraints\Dock\DockLayout;
use SugarCraft\Constraints\Dock\Side;

/**
 * The dock's persistence loop, closed at both ends.
 *
 * A mutation pushes its layout blob out through the `onLayoutChange` closure
 * Bootstrap wires to `writeUserConfig(['layout' => $blob])`; the next
 * launch pulls it back in through `dockFromUserConfig()` and, since
 * ISS-049, `paneAutoShowFromUserConfig()` — the blob carries the
 * user-toggled pane list under `userToggled` so manual closes survive a
 * restart. This file pins the joints: the callback receives exactly the
 * post-mutation blob, the bootstrap load path reconstructs a byte-equal dock
 * from what was stored and folds the toggled roster onto the auto-show
 * memory, and anything malformed on disk degrades to the default fail-soft
 * instead of bricking startup (the layer is user-tier, so a hand-edit can
 * break shape).
 */
final class DockPersistenceRoundTripTest extends TestCase
{
    use HomeSandboxTrait;

    private ProviderInterface $provider;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        TuiRenderer::setSize(200, 60); // deterministic default (round-61 flake; see tests/bootstrap.php)
        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('TestProvider');

        $this->tmpDir = sys_get_temp_dir() . '/dock-persist-' . uniqid('p', true);
        // lane-cf law: snapshot HOME BEFORE any env mutation; the trait creates the dir.
        $this->useHomeSandbox($this->tmpDir . '/home');
    }

    protected function tearDown(): void
    {
        Bootstrap::useProjectRootForSettings(null);
        Bootstrap::useConfigPath(null);
        $this->restoreHomeSandbox();
        $this->removeTree($this->tmpDir);
        TuiRenderer::setSize(200, 60);
        parent::tearDown();
    }

    public function testAMutationHandsTheCallbackThePostMutationBlob(): void
    {
        $captured = [];
        $app = App::new($this->provider, 'test-model')
            ->withOnLayoutChange(static function (array $manifest) use (&$captured): void {
                $captured[] = $manifest;
            });
        [$measured] = $app->update(new WindowSizeMsg(100, 40));
        \assert($measured instanceof App);

        $after = $measured->setPaneSide(Pane::Tools, Side::Left);

        self::assertCount(1, $captured, 'one mutation, one persist');
        self::assertSame($after->layoutBlob(), end($captured));
        self::assertSame([25, 100], end($captured)['columnShare']['left']);
        // ISS-049: the blob is the manifest plus the hand-touched roster —
        // the dock geometry keys carry through byte-identical, the list is
        // purely additive, and the manifest itself never sees the key.
        self::assertSame($after->dock()->toArray(), array_diff_key(end($captured), ['userToggled' => 1]));
        self::assertSame(['tools'], end($captured)['userToggled']);
    }

    public function testTheSecondLaunchLoadsStoredManifestIntoAnEqualDock(): void
    {
        [$measured] = App::new($this->provider, 'test-model')->update(new WindowSizeMsg(100, 40));
        \assert($measured instanceof App);
        $manifest = $measured->setPaneSide(Pane::Tools, Side::Left)->dock()->toArray();

        Bootstrap::writeUserConfig(['layout' => $manifest]);
        $loaded = $this->loadViaBootstrap();

        self::assertInstanceOf(DockLayout::class, $loaded);
        self::assertSame($manifest, $loaded->toArray());
    }

    public function testTheManifestSurvivesMoreThanOneRoundTrip(): void
    {
        // fromArray/toArray is the storage codec; pin it idempotent so repeat
        // launches cannot drift the layout a column at a time.
        [$measured] = App::new($this->provider, 'test-model')->update(new WindowSizeMsg(100, 40));
        \assert($measured instanceof App);
        $manifest = $measured->setPaneSide(Pane::Tools, Side::Left)->dock()->toArray();

        self::assertSame($manifest, DockLayout::fromArray($manifest)->toArray());
        self::assertSame($manifest, DockLayout::fromArray(DockLayout::fromArray($manifest)->toArray())->toArray());
    }

    public function testAMalformedStoredLayoutDegradesToTheDefaultFailSoft(): void
    {
        foreach (['not-an-array' => 'x', 'empty-array' => [], 'wrong-version' => ['version' => 2]] as $label => $stored) {
            $this->writeRawLayout($stored);

            self::assertNull($this->loadViaBootstrap(), "malformed stored layout ({$label}) must load as null, not throw");
        }

        // null dock on the way in => App answers the default, unchanged.
        $app = App::new($this->provider, 'test-model')->withDock($this->loadViaBootstrap());
        self::assertSame(App::defaultDock()->toArray(), $app->dock()->toArray());
    }

    public function testTheLayoutKeyIsLayeredSettingsRosteredAndShapeRejectedAtTheBoundary(): void
    {
        // The cheap LayeredSettings-side pin: 'layout' rides LAYERED_KEYS (the
        // roster test already censuses the list) and the parse boundary
        // refuses junk loudly so dockFromUserConfig's catch has something
        // typed to catch.
        self::assertContains('layout', LayeredSettings::LAYERED_KEYS);
        $this->expectException(\InvalidArgumentException::class);
        DockLayout::fromArray([]);
    }

    private function loadViaBootstrap(): ?DockLayout
    {
        return (new ReflectionMethod(Bootstrap::class, 'dockFromUserConfig'))->invoke(null);
    }

    public function testStoredUserToggledRowsLoadIntoTheLaunchAutoShowMemory(): void
    {
        [$measured] = App::new($this->provider, 'test-model')->update(new WindowSizeMsg(100, 40));
        \assert($measured instanceof App);

        Bootstrap::writeUserConfig(['layout' => $measured->togglePaneDocking(Pane::Files)->layoutBlob()]);
        $autoShow = (new ReflectionMethod(Bootstrap::class, 'paneAutoShowFromUserConfig'))->invoke(null);

        self::assertTrue($autoShow->settled(Pane::Files), 'the closed pane stays settled on the next launch');
        self::assertFalse($autoShow->settled(Pane::Agents), 'and it never poisons untouched panes');
    }

    public function testJunkToggledRowsAreDroppedAndABlobWithoutTheListStartsClean(): void
    {
        $this->writeRawLayout(['version' => 1, 'center' => 'chat', 'sides' => ['left' => [], 'right' => []],
            'columnShare' => ['left' => [1, 3], 'right' => [1, 3]], 'minimums' => [24, 20],
            'userToggled' => ['agents', 42, 'chat', 'not-a-pane', 'agents']]);
        $autoShow = (new ReflectionMethod(Bootstrap::class, 'paneAutoShowFromUserConfig'))->invoke(null);

        self::assertSame(['agents'], $autoShow->userToggled(), 'strings naming real dockable panes, deduped');

        $this->writeRawLayout(['version' => 1, 'center' => 'chat', 'sides' => ['left' => [], 'right' => []],
            'columnShare' => ['left' => [1, 3], 'right' => [1, 3]], 'minimums' => [24, 20]]);
        $clean = (new ReflectionMethod(Bootstrap::class, 'paneAutoShowFromUserConfig'))->invoke(null);
        self::assertSame([], $clean->userToggled());

        $this->writeRawLayout('not-an-array');
        $junk = (new ReflectionMethod(Bootstrap::class, 'paneAutoShowFromUserConfig'))->invoke(null);
        self::assertSame([], $junk->userToggled(), 'a malformed layout key must not brick the reader');
    }

    private function writeRawLayout(mixed $stored): void
    {
        $path = Bootstrap::userConfigPath();
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, (string) json_encode(['layout' => $stored]));
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var \SplFileInfo $entry */
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($dir);
    }
}
