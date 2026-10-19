<?php

declare(strict_types=1);

namespace SugarCraft\Code\Bundles;

use SugarCraft\Code\Lang;
use SugarCraft\Code\Support\ContainedPath;
use SugarCraft\Code\Support\HomeDirectory;

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
 *     `candy-code bundles install` writes to. A symlink inside it may point
 *     anywhere in home and nowhere outside it.
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

    /** @var list<Bundle> */
    private array $bundles = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<string, string> directory as spelled => why it was refused */
    private array $refusedDirectories = [];

    /** @param list<string> $configPaths the user-tier pluginPaths entries */
    public function __construct(
        private readonly string $projectRoot,
        private readonly array $configPaths = [],
    ) {
    }

    /**
     * @return list<Bundle> every readable bundle, project tier first
     */
    public function discover(): array
    {
        $this->bundles = [];
        $this->warnings = [];
        $this->refusedDirectories = [];

        $this->scanTier($this->projectRoot . '/' . Bundle::INSTALLED_RELATIVE_PATH, 'project', $this->projectRoot);

        $home = HomeDirectory::owned();

        if ($home !== null) {
            $this->scanTier($home . '/' . Bundle::INSTALLED_RELATIVE_PATH, 'user', $home);
        }

        foreach ($this->configPaths as $path) {
            $path = trim($path);

            if ($path === '') {
                continue;
            }

            $real = realpath($path);

            if ($real === false || !is_dir($real)) {
                $this->warnings[] = 'plugin path "' . $path . '" is not a directory and was skipped';
                continue;
            }

            if (Bundle::hasManifest($real)) {
                // An operator-named path is trusted as given — the same
                // verdict every other user-tier path setting draws — so it
                // carries no owner anchor even though it is badged `user`.
                $this->record($real, 'user', null);
                continue;
            }

            $this->scanContainer($real, 'user', null);
        }

        return $this->bundles;
    }

    /** @return list<string> per-bundle problems, worded for a launch notice */
    public function warnings(): array
    {
        return $this->warnings;
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
        return $this->refusedDirectories;
    }

    /**
     * Scan a conventional install root: a manifest on the root itself makes it
     * a single bundle; otherwise its direct children, sorted so two identical
     * trees discover identically, are judged one by one.
     */
    private function scanTier(string $root, string $tier, string $boundary): void
    {
        $real = realpath($root);

        if ($real === false || !is_dir($real)) {
            return;
        }

        if (Bundle::hasManifest($real)) {
            $this->guard($real, $tier, $boundary);

            return;
        }

        $this->scanContainer($real, $tier, $boundary);
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
    private function ownerOf(string $tier, ?string $boundary): ?string
    {
        return $tier === 'user' ? $boundary : null;
    }

    /**
     * @param ?string $boundary confinement anchor, or null for operator-named
     *                          paths, which are trusted as given
     */
    private function scanContainer(string $container, string $tier, ?string $boundary = null): void
    {
        $children = glob($container . '/*');

        if ($children === false) {
            return;
        }

        sort($children);

        foreach ($children as $child) {
            if (!is_dir($child)) {
                continue;
            }

            if (!Bundle::hasManifest($child)) {
                continue;
            }

            $this->guard($child, $tier, $boundary);
        }
    }

    /**
     * Apply the tier's confinement promise, then record. A bundle whose real
     * path has escaped the tree it was found in is refused by name — for the
     * project tier that is the same argument the cloned skills trees get:
     * "inside the checkout" is the boundary a repository cannot forge. A null
     * boundary is an operator-named path, trusted as given.
     */
    private function guard(string $dir, string $tier, ?string $boundary): void
    {
        $real = realpath($dir);

        if ($real === false) {
            return;
        }

        if ($boundary === null) {
            $this->record($real, $tier, null);

            return;
        }

        $boundaryReal = realpath($boundary);

        if ($boundaryReal === false) {
            return;
        }

        if ($real !== $boundaryReal && !ContainedPath::below($real, $boundaryReal)) {
            $this->refusedDirectories[$dir] = Lang::t('bundles.dir-outside', [
                'dir' => $dir,
                'real' => $real,
                'tier' => $tier,
                'anchor' => $boundary,
            ]);
            $this->warnings[] = 'bundle "' . basename($dir) . '" resolves outside the '
                . $tier . ' tree it was found in and was skipped: ' . $dir;

            return;
        }

        $this->record($real, $tier, $this->ownerOf($tier, $boundary));
    }

    private function record(string $dir, string $tier, ?string $ownedBy): void
    {
        $bundle = Bundle::fromDir($dir, $tier, $ownedBy);

        if ($bundle === null) {
            $this->warnings[] = 'bundle manifest under ' . $dir . ' could not be read or parsed and was skipped';

            return;
        }

        $this->bundles[] = $bundle;
    }
}
