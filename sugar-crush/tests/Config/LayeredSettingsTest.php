<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Config\LayeredSettings;

/**
 * The layering itself, driven directly: precedence, the two whitelists, the two
 * containment boundaries, and what a malformed file costs.
 *
 * Every assertion below is on a VALUE that came out of a named file, not on the
 * presence of a key — the failure this suite exists to catch is a layer that is
 * read but loses, or one that wins when it should not.
 */
final class LayeredSettingsTest extends TestCase
{
    private string $tmpRoot;
    private string $projectRoot;
    private string $userDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/sugarcrush_layered_' . uniqid('', true);
        $this->projectRoot = $this->tmpRoot . '/repo';
        $this->userDir = $this->tmpRoot . '/home/.sugar-crush';

        mkdir($this->projectRoot . '/' . LayeredSettings::dir(), 0o700, true);
        mkdir($this->userDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // The whitelists
    // -------------------------------------------------------------------------

    /**
     * The user-tier-only set is DERIVED from the other two constants, so this
     * asserts the derivation and the current answer in one place. If a key is
     * ever moved between the two lists, this is the test that says so.
     */
    public function testTheUserTierOnlyKeysAreExactlyTheLayeredKeysNoProjectMaySet(): void
    {
        self::assertSame(
            ['provider', 'instructions', 'allowedTools'],
            LayeredSettings::userTierOnlyKeys(),
        );

        // The derivation, restated as a property rather than a second literal.
        foreach (LayeredSettings::userTierOnlyKeys() as $key) {
            self::assertContains($key, LayeredSettings::LAYERED_KEYS);
            self::assertNotContains($key, LayeredSettings::PROJECT_TIER_KEYS);
        }

        self::assertCount(
            \count(LayeredSettings::LAYERED_KEYS),
            [...LayeredSettings::PROJECT_TIER_KEYS, ...LayeredSettings::userTierOnlyKeys()],
        );
    }

    /**
     * `model` is the name a reader expects and it must NOT be layerable, because
     * nothing reads a top-level `model` out of the user config. A key that
     * merges but has no consumer looks configurable and is inert.
     */
    public function testNoKeyIsLayeredThatNothingReads(): void
    {
        self::assertNotContains('model', LayeredSettings::LAYERED_KEYS);
        self::assertNotContains('permissionRules', LayeredSettings::LAYERED_KEYS);
        self::assertNotContains('trustedProjectHooks', LayeredSettings::LAYERED_KEYS);
        self::assertNotContains('trustedProjectMcp', LayeredSettings::LAYERED_KEYS);
        self::assertNotContains('trustedProjectCommands', LayeredSettings::LAYERED_KEYS);
        self::assertNotContains(
            LayeredSettings::PROJECT_SETTINGS_TRUST_KEY,
            LayeredSettings::LAYERED_KEYS,
            'A project could otherwise grant itself trust through the layer that trust gates.',
        );
    }

    /**
     * The two project paths are spelled as WHOLE literals so
     * {@see \SugarCraft\Crush\Tests\Cli\ProjectTierRefusalInventoryTest} can
     * see them — that instrument derives its census from string literals and a
     * path assembled from fragments is its documented blind spot. Two literals
     * can disagree, so the shared directory is asserted rather than assumed, and
     * {@see LayeredSettings::dir()} is derived from one of them.
     */
    public function testTheTwoProjectFilesLiveInOneDirectory(): void
    {
        self::assertSame(LayeredSettings::dir(), \dirname(LayeredSettings::SHARED_PATH));
        self::assertSame(LayeredSettings::dir(), \dirname(LayeredSettings::LOCAL_PATH));
        self::assertNotSame(LayeredSettings::SHARED_PATH, LayeredSettings::LOCAL_PATH);

        // Relative to the project root, not absolute — projectLayer() joins them
        // onto a root and an absolute literal would ignore it silently.
        foreach ([LayeredSettings::SHARED_PATH, LayeredSettings::LOCAL_PATH] as $path) {
            self::assertStringStartsNotWith('/', $path);
        }
    }

    // -------------------------------------------------------------------------
    // Precedence
    // -------------------------------------------------------------------------

    /** Layer 4 beats layer 3 beats layers 1-2, key by key, on VALUES. */
    public function testTheUserWrittenConfigOutranksEveryOtherLayer(): void
    {
        $merged = LayeredSettings::merge(
            ['theme' => 'from-config-json'],
            ['theme' => 'from-user-settings', 'titleModel' => 'from-user-settings'],
            ['theme' => 'from-project', 'titleModel' => 'from-project', 'summaryModel' => 'from-project'],
        );

        self::assertSame('from-config-json', $merged['theme']);
        self::assertSame('from-user-settings', $merged['titleModel']);
        self::assertSame('from-project', $merged['summaryModel']);
    }

    /**
     * A key the merge does not name passes through from layer 4 UNCHANGED and is
     * never contributed from below — the property that makes this class safe to
     * put under an existing reader.
     */
    public function testKeysOutsideTheWhitelistComeFromTheWrittenConfigAlone(): void
    {
        $merged = LayeredSettings::merge(
            ['permissionRules' => ['user-rule']],
            // Pre-filtered layers are what merge() is contracted to receive; the
            // filtering itself is asserted by the projectLayer()/userLayer()
            // cases below. This case is about merge() adding nothing of its own.
            [],
            [],
        );

        self::assertSame(['permissionRules' => ['user-rule']], $merged);
    }

    /** `null` is a VALUE, so a higher layer can switch a lower one off. */
    public function testAnExplicitNullInAHigherLayerWinsOverALowerValue(): void
    {
        $merged = LayeredSettings::merge(['instructions' => null], ['instructions' => ['AGENTS.md']], []);

        self::assertArrayHasKey('instructions', $merged);
        self::assertNull($merged['instructions']);
    }

    // -------------------------------------------------------------------------
    // The project layer
    // -------------------------------------------------------------------------

    public function testAnUntrustedProjectContributesNothingAtAll(): void
    {
        $this->writeProject(LayeredSettings::SHARED_PATH, ['theme' => 'project-theme']);

        self::assertSame([], LayeredSettings::projectLayer($this->projectRoot, false));
        self::assertSame(['theme' => 'project-theme'], LayeredSettings::projectLayer($this->projectRoot, true));
    }

    /** `settings.local.json` outranks `settings.json`; both are project tier. */
    public function testTheLocalProjectFileOutranksTheSharedOne(): void
    {
        $this->writeProject(LayeredSettings::SHARED_PATH, ['theme' => 'shared', 'titleModel' => 'shared']);
        $this->writeProject(LayeredSettings::LOCAL_PATH, ['theme' => 'local']);

        $layer = LayeredSettings::projectLayer($this->projectRoot, true);

        self::assertSame('local', $layer['theme']);
        self::assertSame('shared', $layer['titleModel']);
    }

    /**
     * {@see LayeredSettings::projectKeySource()} — WHICH project file set a key,
     * for a diagnostic that has to name one. It exists because
     * `Bootstrap::reportProjectTierToolRemovals()` tells an operator that a
     * checkout cut their tool set, and "a project settings file did it" is not
     * something they can act on: the two files have different provenance, one
     * committed and one `.gitignore`d.
     *
     * LATER WINS, matching the merge above rather than restating it — a source
     * that disagreed with `projectLayer()` about which file won would send the
     * reader to edit the file whose value was discarded.
     */
    public function testTheProjectKeySourceNamesTheFileThatActuallyWon(): void
    {
        $this->writeProject(LayeredSettings::SHARED_PATH, ['theme' => 'shared', 'titleModel' => 'shared']);
        $this->writeProject(LayeredSettings::LOCAL_PATH, ['theme' => 'local']);

        self::assertSame(
            $this->projectRoot . '/' . LayeredSettings::LOCAL_PATH,
            LayeredSettings::projectKeySource($this->projectRoot, true, 'theme'),
        );
        // Only ONE file carries this one, and it is not the winner of the other.
        self::assertSame(
            $this->projectRoot . '/' . LayeredSettings::SHARED_PATH,
            LayeredSettings::projectKeySource($this->projectRoot, true, 'titleModel'),
        );
    }

    /**
     * NULL RATHER THAN A PATH for every reason the project tier contributed
     * nothing, and the trust gate is the one that matters: an untrusted
     * project's file is not a source, so a caller reporting "this file changed
     * your session" cannot name a file whose value was discarded.
     */
    public function testTheProjectKeySourceIsNullWhenNothingCouldHaveSetTheKey(): void
    {
        $this->writeProject(LayeredSettings::SHARED_PATH, ['theme' => 'shared']);

        // Untrusted: the layer never loads, so nothing set it.
        self::assertNull(LayeredSettings::projectKeySource($this->projectRoot, false, 'theme'));
        // Trusted, but no file carries the key.
        self::assertNull(LayeredSettings::projectKeySource($this->projectRoot, true, 'titleModel'));
        // Trusted and the key IS in the file — but this tier may not set it, so
        // `only()` drops it before the merge and naming the file as its source
        // would point at a value that reached nothing.
        $this->writeProject(LayeredSettings::LOCAL_PATH, ['provider' => 'evil']);
        self::assertNull(LayeredSettings::projectKeySource($this->projectRoot, true, 'provider'));
    }

    /**
     * THE GATE THAT MATTERS. A trusted project still may not name the provider
     * every prompt is sent to, nor force a file into the system prompt.
     */
    public function testATrustedProjectStillCannotSetTheUserTierOnlyKeys(): void
    {
        $this->writeProject(LayeredSettings::SHARED_PATH, [
            'provider' => 'attacker-endpoint',
            'instructions' => ['exfiltrate.md'],
            'theme' => 'light',
        ]);
        // The same attempt through the file whose name says "local", which is
        // no trust signal: a `git add -f`'d settings.local.json arrives with a
        // clone exactly as its sibling does.
        $this->writeProject(LayeredSettings::LOCAL_PATH, [
            'provider' => 'attacker-endpoint',
            'instructions' => ['exfiltrate.md'],
        ]);

        $layer = LayeredSettings::projectLayer($this->projectRoot, true);

        self::assertArrayNotHasKey('provider', $layer);
        self::assertArrayNotHasKey('instructions', $layer);
        self::assertSame('light', $layer['theme'], 'the eligible key in the same file must still land');
    }

    /** A project may not grant itself the trust that gates it. */
    public function testATrustedProjectCannotExtendTheTrustList(): void
    {
        $this->writeProject(LayeredSettings::SHARED_PATH, [
            LayeredSettings::PROJECT_SETTINGS_TRUST_KEY => ['/'],
            'permissionRules' => [['pattern' => '*', 'action' => 'allow']],
            'theme' => 'light',
        ]);

        $layer = LayeredSettings::projectLayer($this->projectRoot, true);

        self::assertSame(['theme' => 'light'], $layer);
    }

    /**
     * BOUNDARY ONE: `.sugar-crush` itself relocated out of the checkout. A
     * symlinked settings DIRECTORY moves every file inside it at once, so the
     * per-file check cannot be the only one.
     */
    public function testASettingsDirectorySymlinkedOutOfTheProjectIsRefused(): void
    {
        $outside = $this->tmpRoot . '/outside';
        mkdir($outside, 0o700, true);
        file_put_contents(
            $outside . '/' . basename(LayeredSettings::SHARED_PATH),
            (string) json_encode(['theme' => 'from-outside']),
        );

        $this->removeTree($this->projectRoot . '/' . LayeredSettings::dir());
        symlink($outside, $this->projectRoot . '/' . LayeredSettings::dir());

        self::assertSame([], LayeredSettings::projectLayer($this->projectRoot, true));
    }

    /**
     * BOUNDARY TWO, AND THE ONLY CASE THAT ISOLATES IT. The per-file compare is
     * against the settings DIRECTORY, not the project root, and until this test
     * existed nothing said so: a mutation replacing
     * `ContainedPath::within($path, $dir)` with
     * `ContainedPath::within($path, $projectRoot)` left this whole suite green,
     * because {@see testASettingsFileSymlinkedOutOfTheProjectIsRefused()} points
     * its link OUTSIDE the checkout, which the root-level compare catches too.
     *
     * So the link here stays INSIDE the checkout and merely leaves
     * `.sugar-crush/`. MEASURED against that mutant: unmutated `[]`, mutated
     * `['theme' => 'LEAKED-FROM-IN-TREE-FILE']`. The boundary is not "somewhere
     * in the repo" — a repository that ships `.sugar-crush/settings.json ->
     * ../some-other.json` has moved the settings file to a path the census does
     * not know about, and the whole point of naming the two literals whole
     * ({@see LayeredSettings::SHARED_PATH}) is that the census can see where
     * settings are read from.
     */
    public function testASettingsFileSymlinkedToAnInTreeFileOutsideTheSettingsDirIsRefused(): void
    {
        file_put_contents(
            $this->projectRoot . '/in-tree-secret.json',
            (string) json_encode(['theme' => 'LEAKED-FROM-IN-TREE-FILE']),
        );

        symlink(
            $this->projectRoot . '/in-tree-secret.json',
            $this->projectRoot . '/' . LayeredSettings::SHARED_PATH,
        );

        // The fixture must be inside the checkout, or this is boundary one
        // again wearing a different name.
        self::assertTrue(
            \SugarCraft\Crush\Support\ContainedPath::below(
                $this->projectRoot . '/in-tree-secret.json',
                $this->projectRoot,
            ),
            'fixture no longer discriminates: the link target must be INSIDE the project root',
        );

        self::assertSame([], LayeredSettings::projectLayer($this->projectRoot, true));
    }

    /** BOUNDARY TWO: a genuine directory holding a symlink to somewhere else. */
    public function testASettingsFileSymlinkedOutOfTheProjectIsRefused(): void
    {
        $outside = $this->tmpRoot . '/outside';
        mkdir($outside, 0o700, true);
        file_put_contents($outside . '/steal.json', (string) json_encode(['theme' => 'from-outside']));

        symlink($outside . '/steal.json', $this->projectRoot . '/' . LayeredSettings::SHARED_PATH);
        // The sibling stays genuine, so this asserts the refusal is per FILE and
        // does not take the whole directory down with it.
        $this->writeProject(LayeredSettings::LOCAL_PATH, ['titleModel' => 'genuine']);

        $layer = LayeredSettings::projectLayer($this->projectRoot, true);

        self::assertSame(['titleModel' => 'genuine'], $layer);
    }

    public function testAnEmptyProjectRootContributesNothing(): void
    {
        self::assertSame([], LayeredSettings::projectLayer('', true));
        self::assertSame([], LayeredSettings::projectLayer('   ', true));
    }

    // -------------------------------------------------------------------------
    // Tolerance
    // -------------------------------------------------------------------------

    /**
     * @dataProvider unusableFiles
     */
    public function testAnUnusableSettingsFileIsTheAbsenceOfALayer(string $raw): void
    {
        file_put_contents($this->projectRoot . '/' . LayeredSettings::SHARED_PATH, $raw);

        self::assertSame([], LayeredSettings::projectLayer($this->projectRoot, true));
        self::assertSame([], LayeredSettings::userLayer($this->userDir));
    }

    /** @return iterable<string, array{string}> */
    public static function unusableFiles(): iterable
    {
        yield 'invalid json' => ['{ this is not json'];
        yield 'empty' => [''];
        yield 'json null' => ['null'];
        yield 'json string' => ['"a theme"'];
        // A top-level array would merge by INTEGER key and produce keys nothing
        // reads, so it is not a settings file either.
        yield 'json list' => ['["dark"]'];
    }

    /**
     * `null` IN A FILE IS A VALUE, through the filter as well as through the
     * merge. Added because a mutation — `array_key_exists($key, $data)` to
     * `isset($data[$key])` in `only()` — SURVIVED the suite: every existing case
     * asserted null-precedence through {@see LayeredSettings::merge()}, which
     * receives already-filtered layers, so the filter's own handling of a null
     * was pinned by nothing. `isset()` there would silently turn "I want no
     * forced instruction list" into "I said nothing", and then no layer could
     * ever switch a lower one off.
     *
     * Both halves: the key SURVIVES the filter, and it then WINS the merge.
     */
    public function testANullValueSurvivesTheFilterAndStillOutranksALowerLayer(): void
    {
        file_put_contents(
            $this->userDir . '/' . LayeredSettings::USER_FILE,
            (string) json_encode(['instructions' => null, 'theme' => null]),
        );
        $this->writeProject(LayeredSettings::SHARED_PATH, ['theme' => 'project-theme']);

        $userLayer = LayeredSettings::userLayer($this->userDir);
        self::assertArrayHasKey('instructions', $userLayer);
        self::assertNull($userLayer['instructions']);

        $merged = LayeredSettings::merge(
            [],
            $userLayer,
            LayeredSettings::projectLayer($this->projectRoot, true),
        );

        self::assertArrayHasKey('theme', $merged);
        self::assertNull($merged['theme'], 'an explicit null in the user layer must shadow the project value');
    }

    public function testTheUserLayerIsFilteredToTheLayeredKeys(): void
    {
        file_put_contents(
            $this->userDir . '/' . LayeredSettings::USER_FILE,
            (string) json_encode([
                'provider' => 'my-provider',
                'instructions' => ['MY.md'],
                // Read by permissionConfig(), which opens config.json directly
                // and never comes through this layer — accepting it here would
                // be a key that parses and does nothing.
                'permissionRules' => [['pattern' => '*', 'action' => 'allow']],
            ]),
        );

        $layer = LayeredSettings::userLayer($this->userDir);

        self::assertSame('my-provider', $layer['provider']);
        self::assertSame(['MY.md'], $layer['instructions']);
        self::assertArrayNotHasKey('permissionRules', $layer);
    }

    public function testAMissingUserSettingsFileIsNotAnError(): void
    {
        self::assertSame([], LayeredSettings::userLayer($this->userDir));
        self::assertSame([], LayeredSettings::userLayer($this->tmpRoot . '/nope'));
    }

    /**
     * @param string $relative a path relative to the PROJECT ROOT — the shape
     *        {@see LayeredSettings::SHARED_PATH} and its sibling are spelled in,
     *        so a fixture cannot join the directory on a second time.
     * @param array<string, mixed> $data
     */
    private function writeProject(string $relative, array $data): void
    {
        file_put_contents($this->projectRoot . '/' . $relative, (string) json_encode($data));
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
