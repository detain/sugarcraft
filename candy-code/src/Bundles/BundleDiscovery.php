<?php

declare(strict_types=1);

namespace SugarCraft\Code\Bundles;

use SugarCraft\Code\Lang;
use SugarCraft\Code\Support\HomeDirectory;
use SugarCraft\Plugins\Discovery\PluginDiscovery;
use SugarCraft\Plugins\Discovery\Root;

/**
 * Finds installed plugin bundles on disk. Three source roots, scanned in this
 * order, each bundle recorded with the tier its root implies:
 *
 *  1. `<projectRoot>/.candy-code/bundles/` — tier `project`. A bundle that
 *     arrives with a `git clone` is repository-authored, so it is confined to
 *     the checkout exactly like a cloned `.claude/skills` tree is, and it
 *     lands BELOW the plain foreign directories in every surface's precedence
 *     (the mapping is per-loader; see `Cli\Bootstrap`'s bundle wiring).
 *  2. `~/.candy-code/bundles/` — tier `user`, the destination
 *     `candy-code bundles install` writes to. A bundle directory symlink may
 *     point anywhere in home and nowhere outside it, but its manifest must
 *     resolve inside that bundle (see {@see Bundle::manifestPath()}): a
 *     manifest link that escapes the bundle is refused, never followed.
 *  3. Every directory listed in the `pluginPaths` setting — tier `user`, the
 *     operator's explicit word: a listed path is trusted as given (like every
 *     other user-tier path setting), and it may be either a bundle itself
 *     (it carries a manifest) or a container of bundle directories.
 *
 * Discovery never reads a surface file and never runs anything: it locates
 * bundles, and each loader consumes what it can. A directory under a container
 * without a manifest is not a bundle and is passed over quietly; one whose
 * manifest exists but will not parse is recorded in {@see warnings()} BY NAME
 * — the difference is a `.git` checkout directory versus a broken install,
 * and only the second deserves a line at launch.
 */
final class BundleDiscovery
{
    /** The layered setting naming extra container/bundle directories. */
    public const SETTING_KEY = 'pluginPaths';

    /** The generic walk: containment, manifest detection, warnings and refusals. */
    private readonly PluginDiscovery $scanner;

    /** @param list<string> $configPaths the user-tier pluginPaths entries */
    public function __construct(
        private readonly string $projectRoot,
        private readonly array $configPaths = [],
    ) {
        // A manifest that links outside its bundle is not a bundle, and it is not
        // passed over quietly either: warnRefusedLink() names the link. It is NOT
        // handed to the reader, which would report it as an unparseable manifest,
        // and a refused container entry is not walked into (its children would
        // otherwise be loaded from a bundle whose own manifest was refused).
        $this->scanner = new PluginDiscovery(
            function (string $dir): bool {
                if (Bundle::hasManifest($dir)) {
                    return true;
                }

                $this->warnRefusedLink($dir);

                return false;
            },
            static fn (string $dir, string $tier, ?string $boundary): ?Bundle
                => Bundle::fromDir($dir, $tier, self::ownerOf($tier, $boundary)),
            static fn (string $dir, string $real, string $tier, string $boundary): string
                => Lang::t('bundles.dir-outside', [
                    'dir' => $dir,
                    'real' => $real,
                    'tier' => $tier,
                    'anchor' => $boundary,
                ]),
        );
    }

    /**
     * @return list<Bundle> every readable bundle, project tier first
     */
    public function discover(): array
    {
        $this->scanner->reset();

        $home = HomeDirectory::owned();
        $userFloor = $home === null ? null : $home . '/' . Bundle::INSTALLED_RELATIVE_PATH;
        $projectFloor = $this->projectRoot . '/' . Bundle::INSTALLED_RELATIVE_PATH;

        // A project root that IS the home directory names the user floor a second
        // time. Scanning it as the project tier would list every user bundle twice
        // and hand the user's own hooks to the project-tier trust path, so the user
        // tier wins — the same first-root-wins rule `bundles update` applies.
        if (!self::isSameDirectory($projectFloor, $userFloor)) {
            $this->scanRoot(new Root($projectFloor, 'project', $this->projectRoot));
        }

        if ($home !== null) {
            $this->scanRoot(new Root($userFloor, 'user', $home));
        }

        foreach ($this->configPaths as $path) {
            $path = trim($path);

            if ($path === '') {
                continue;
            }

            $real = realpath($path);

            if ($real === false || !is_dir($real)) {
                $this->scanner->addWarning('plugin path "' . $path . '" is not a directory and was skipped');
                continue;
            }

            // An operator-named path is trusted as given — the same verdict every
            // other user-tier path setting draws — so it carries no boundary even
            // though it is badged `user`. A bundle directory and a container both
            // resolve through the generic scan with that null boundary.
            $this->scanRoot(new Root($real, 'user', null));
        }

        /** @var list<Bundle> $bundles */
        $bundles = $this->scanner->bundles();

        return $bundles;
    }

    /**
     * Scan one root, unless the root itself is a bundle whose manifest links
     * outside it: that root is named and skipped whole, so its children are
     * never read as bundles of their own.
     */
    private function scanRoot(Root $root): void
    {
        if ($this->warnRefusedLink($root->dir)) {
            return;
        }

        $this->scanner->scan([$root]);
    }

    /**
     * Name $dir when its manifest links outside the bundle, the way the
     * installer's bundlesNotBundle() names the same refusal. True when $dir was
     * refused that way (and the warning was recorded).
     */
    private function warnRefusedLink(string $dir): bool
    {
        $link = Bundle::refusedManifest($dir);

        if ($link === null) {
            return false;
        }

        $this->scanner->addWarning(Lang::t('bundles.manifest-link-refused', [
            'dir' => $dir,
            'link' => $link,
        ]));

        return true;
    }

    /** @return list<string> per-bundle problems, worded for a launch notice */
    public function warnings(): array
    {
        return $this->scanner->warnings();
    }

    /**
     * Which bundle directories this walk refused for escaping the tree it was
     * found in, directory as spelled => why — the shape every other feeder of
     * {@see \SugarCraft\Code\Cli\Bootstrap::$projectTierRefusals} carries.
     * Keyed on the path AS GIVEN, not on its realpath: the collector's readers
     * print the directory the operator can go and look at, and the realpath is
     * the far end of the link that caused the refusal.
     *
     * @return array<string, string>
     */
    public function refusedDirectories(): array
    {
        return $this->scanner->refusedDirectories();
    }

    /**
     * True when both floors name the same existing directory once symlinks are
     * resolved. A missing floor is never "the same", so an absent home or project
     * root leaves the other tier's scan untouched.
     */
    private static function isSameDirectory(string $a, ?string $b): bool
    {
        if ($b === null) {
            return false;
        }

        $realA = realpath($a);
        $realB = realpath($b);

        return $realA !== false && $realB !== false && $realA === $realB;
    }

    /**
     * Who must OWN this bundle's tree, in the sense
     * {@see \SugarCraft\Code\Skills\ForeignSkillDiscovery::tiers()} gives its
     * user tier: the user install root was established as THIS user's home by
     * {@see HomeDirectory::owned()} before anything under it was read, so every
     * surface loader re-checks entry ownership against it. A project bundle
     * needs no owner widening — the checkout is its whole boundary — and a
     * `pluginPaths` bundle is trusted as given, so both carry null.
     */
    private static function ownerOf(string $tier, ?string $boundary): ?string
    {
        return $tier === 'user' ? $boundary : null;
    }
}
