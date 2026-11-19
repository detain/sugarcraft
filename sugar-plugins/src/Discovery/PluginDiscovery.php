<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Discovery;

/**
 * The generic scan of a plugin install tree: walks the directories it is given,
 * records every directory that carries a manifest, and refuses any bundle whose
 * real path has escaped the tree it was found in.
 *
 * A root is either a bundle itself (it carries a manifest) or a container whose
 * direct children are judged one by one, sorted so two identical trees discover
 * identically. A directory under a container without a manifest is not a bundle
 * and is passed over quietly; one whose manifest exists but will not parse is
 * recorded in {@see warnings()} by name, because that difference is the one an
 * operator needs to see.
 *
 * The library knows nothing about manifests or bundle types. The host supplies
 * three callables: whether a directory carries a manifest, how to read a bundle
 * from a directory (null when unreadable), and the words for a refusal. Which
 * tiers exist, who owns a tree, and where the roots come from all stay with the
 * host.
 */
final class PluginDiscovery
{
    /** @var list<object> */
    private array $bundles = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<string, string> directory as spelled => why it was refused */
    private array $refusedDirectories = [];

    /**
     * @param \Closure(string): bool $hasManifest                                   fn(dir): whether dir carries a manifest
     * @param \Closure(string, string, ?string): ?object $read                       fn(dir, tier, boundary): the bundle, or null when unreadable
     * @param \Closure(string, string, string, string): string $refusalReason        fn(dir, real, tier, boundary): why a bundle was refused
     */
    public function __construct(
        private readonly \Closure $hasManifest,
        private readonly \Closure $read,
        private readonly \Closure $refusalReason,
    ) {
    }

    /** Clear every bundle, warning and refusal from the previous walk. */
    public function reset(): void
    {
        $this->bundles = [];
        $this->warnings = [];
        $this->refusedDirectories = [];
    }

    /**
     * Scan each root in order. A root that is missing or not a directory is
     * skipped without a word, so a missing install floor stays silent.
     *
     * @param list<Root> $roots
     */
    public function scan(array $roots): void
    {
        foreach ($roots as $root) {
            $this->scanTier($root->dir, $root->tier, $root->boundary);
        }
    }

    /** Append a per-bundle problem worded for a launch notice. */
    public function addWarning(string $message): void
    {
        $this->warnings[] = $message;
    }

    /** @return list<object> every readable bundle, in scan order */
    public function bundles(): array
    {
        return $this->bundles;
    }

    /** @return list<string> per-bundle problems, worded for a launch notice */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Which bundle directories this walk refused for escaping the tree they were
     * found in. Keyed on the path AS GIVEN, not on its realpath: the reader prints
     * the directory the operator can go and look at, and the realpath is the far
     * end of the link that caused the refusal.
     *
     * @return array<string, string>
     */
    public function refusedDirectories(): array
    {
        return $this->refusedDirectories;
    }

    /**
     * Scan a conventional install root: a manifest on the root itself makes it a
     * single bundle; otherwise its direct children are judged one by one.
     */
    private function scanTier(string $root, string $tier, ?string $boundary): void
    {
        $real = realpath($root);

        if ($real === false || !is_dir($real)) {
            return;
        }

        if (($this->hasManifest)($real)) {
            $this->guard($real, $tier, $boundary);

            return;
        }

        $this->scanContainer($real, $tier, $boundary);
    }

    /**
     * @param ?string $boundary confinement anchor, or null for operator-named
     *                          paths, which are trusted as given
     */
    private function scanContainer(string $container, string $tier, ?string $boundary): void
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

            if (!($this->hasManifest)($child)) {
                continue;
            }

            $this->guard($child, $tier, $boundary);
        }
    }

    /**
     * Apply the tier's confinement promise, then record. A bundle whose real path
     * has escaped the tree it was found in is refused by name. A null boundary is
     * an operator-named path, trusted as given.
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

        if ($real !== $boundaryReal && !\SugarCraft\Core\Util\PathJail::below($real, $boundaryReal)) {
            $this->refusedDirectories[$dir] = ($this->refusalReason)($dir, $real, $tier, $boundary);
            $this->warnings[] = 'bundle "' . basename($dir) . '" resolves outside the '
                . $tier . ' tree it was found in and was skipped: ' . $dir;

            return;
        }

        $this->record($real, $tier, $boundary);
    }

    private function record(string $dir, string $tier, ?string $boundary): void
    {
        $bundle = ($this->read)($dir, $tier, $boundary);

        if ($bundle === null) {
            $this->warnings[] = 'bundle manifest under ' . $dir . ' could not be read or parsed and was skipped';

            return;
        }

        $this->bundles[] = $bundle;
    }
}
