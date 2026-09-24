<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\App;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Layout\Dock\DockLayout;
use SugarCraft\Layout\Dock\Side;

/**
 * The dock's persistence loop, closed at both ends.
 *
 * A mutation pushes its manifest out through the `onLayoutChange` closure
 * Bootstrap wires to `writeUserConfig(['layout' => $manifest])`; the next
 * launch pulls it back in through `dockFromUserConfig()`. This file pins the
 * three joints: the callback receives exactly the post-mutation manifest, the
 * bootstrap load path reconstructs a byte-equal dock from what was stored,
 * and anything malformed on disk degrades to the default fail-soft instead of
 * bricking startup (the layer is user-tier, so a hand-edit can break shape).
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

    public function testAMutationHandsTheCallbackExactlyThePostMutationManifest(): void
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
        self::assertSame($after->dock()->toArray(), end($captured));
        self::assertSame([25, 100], end($captured)['columnShare']['left']);
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
