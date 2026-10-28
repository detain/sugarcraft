<?php

declare(strict_types=1);

namespace SugarCraft\Code\Bundles;

use SugarCraft\Code\Support\ContainedPath;
use SugarCraft\Code\Support\HomeDirectory;
use SugarCraft\Code\Support\PrivateDir;
use SugarCraft\Core\Util\AtomicJsonFile;

/**
 * The user-tier record of ADDED marketplaces — and the gate every install from
 * one must pass.
 *
 * CC-new #12 (plan_comparison.md §3, C-EXT-4.a). The file is
 * `~/.candy-code/marketplaces.json`, `{version: 1, marketplaces: [row, ...]}`
 * with rows `{name, sourceType, source, sha, addedAt}`, written through
 * {@see AtomicJsonFile} at 0600 under a home {@see HomeDirectory::owned()}
 * vouchsafed, exactly like every other store in this family. Checkouts live
 * under `~/.candy-code/marketplaces/<name>/`; this class names those
 * directories and gates reads from them, and the fetch leg that fills one
 * belongs to the install verb, which uses this package's EXISTING clone seam
 * — no new spawn site is born here, and the child-lifetime roster is
 * unchanged because of it.
 *
 * REFUSE BY DEFAULT, in three places, because a marketplace is a catalog of
 * other people's repositories and reading one is not consenting to anything:
 *
 *  - the catalog starts EMPTY. There is no pre-added marketplace, built-in
 *    list, or "official" row anywhere in this file's construction — an
 *    unregistered name is not in it, full stop (the pinned-empty shape);
 *  - {@see installGate()} refuses any entry offered by a marketplace that was
 *    never explicitly {@see add()}ed, whatever bytes the checkout carries;
 *  - the same gate refuses when the registered row has no pinned commit — the
 *    sha is written at add time from the checkout's git state (before any
 *    `.git` strip), and an unpinned row is an unpinnable install. When the
 *    caller supplies a non-empty allowlist, the entry's repo-or-URL source key
 *    must appear in it too; the plan's §6.1 R13 floor makes that check the
 *    caller's option for bundle manifests, not this class's default.
 *
 * A registry with no vouchsafe home still exists — it answers the empty
 * catalog, refuses to persist loudly, and its gate refuses everything, which
 * is the right shape for a machine that cannot say whose home it is standing
 * in.
 */
final class MarketplaceRegistry
{
    /** Wire-format version of marketplaces.json; unknown majors refuse to load. */
    public const VERSION = 1;

    /** Where the record lives under an owned home. */
    public const RECORD_RELATIVE_PATH = '.candy-code/marketplaces.json';

    /** Where added marketplaces are checked out, under an owned home. */
    public const CHECKOUTS_RELATIVE_DIR = '.candy-code/marketplaces';

    /** Source types a row may carry — the same vocabulary as the entries. */
    public const ROW_SOURCE_TYPES = [
        MarketplaceEntry::SOURCE_PATH,
        MarketplaceEntry::SOURCE_GITHUB,
        MarketplaceEntry::SOURCE_URL,
        MarketplaceEntry::SOURCE_GIT,
    ];

    /** @var array<string, array{name: string, sourceType: string, source: string, sha: string, addedAt: int}> */
    private array $rows = [];

    /** @var list<string> */
    private array $loadErrors = [];

    private function __construct(
        private readonly ?string $file,
        private readonly ?string $checkoutsRoot,
    ) {
    }

    /**
     * The home-backed registry: `~/.candy-code/marketplaces.json`. No
     * vouchsafe home means no backing file — the empty catalog answers, and
     * every mutation refuses rather than writing wherever `~` fell back to.
     */
    public static function loadDefault(): self
    {
        $home = HomeDirectory::owned();
        $registry = new self(
            $home === null ? null : rtrim($home, '/') . '/' . self::RECORD_RELATIVE_PATH,
            $home === null ? null : rtrim($home, '/') . '/' . self::CHECKOUTS_RELATIVE_DIR,
        );
        $registry->load();

        return $registry;
    }

    /** A registry over explicit paths (tests, --config homes). */
    public static function atPaths(string $file, string $checkoutsRoot): self
    {
        $registry = new self($file, $checkoutsRoot);
        $registry->load();

        return $registry;
    }

    /** A registry with NO backing file: reads answer empty, writes refuse. */
    public static function inMemory(): self
    {
        return new self(null, null);
    }

    /**
     * Read the record file. A shape this build cannot honour is reported
     * through {@see loadErrors()} and loads NOTHING — half a catalog is worse
     * than none, because an install that finds its marketplace "registered"
     * from a corrupt half-read file is exactly the trust this file exists to
     * decide.
     */
    public function load(): void
    {
        $this->loadErrors = [];
        $this->rows = [];

        if ($this->file === null) {
            return;
        }

        try {
            $document = AtomicJsonFile::new($this->file)->read();
        } catch (\Throwable $e) {
            $this->loadErrors[] = 'marketplaces.json could not be read: ' . $e->getMessage();

            return;
        }

        if ($document === []) {
            return; // an absent file is the empty catalog, by construction
        }

        $version = $document['version'] ?? null;

        if ($version !== self::VERSION) {
            $shown = is_scalar($version) ? var_export($version, true) : get_debug_type($version);
            $this->loadErrors[] = "marketplaces.json declares version {$shown}; this build writes version " . self::VERSION . '.';

            return;
        }

        $rows = $document['marketplaces'] ?? null;

        if (!is_array($rows)) {
            $this->loadErrors[] = 'marketplaces.json has no "marketplaces" array.';

            return;
        }

        foreach (array_values($rows) as $raw) {
            if (!is_array($raw)) {
                $this->loadErrors[] = 'a marketplace row was not an object and was skipped.';

                continue;
            }

            $name = $raw['name'] ?? null;
            $sourceType = $raw['sourceType'] ?? null;
            $source = $raw['source'] ?? null;
            $sha = $raw['sha'] ?? null;

            if (!is_string($name) || !MarketplaceEntry::isValidName($name)
                || !is_string($sourceType) || !in_array($sourceType, self::ROW_SOURCE_TYPES, true)
                || !is_string($source) || !is_string($sha)) {
                $this->loadErrors[] = 'marketplace row ' . json_encode($name) . ' is malformed and was skipped.';

                continue;
            }

            $this->rows[$name] = [
                'name' => $name,
                'sourceType' => $sourceType,
                'source' => $source,
                'sha' => $sha,
                'addedAt' => is_int($raw['addedAt'] ?? null) ? $raw['addedAt'] : 0,
            ];
        }
    }

    /** @return list<string> per-file problems, worded for an announce line */
    public function loadErrors(): array
    {
        return $this->loadErrors;
    }

    /** The empty-catalog law, made testable: a fresh registry offers nothing. */
    public function isBacked(): bool
    {
        return $this->file !== null;
    }

    /** @return list<string> added marketplace names, registration order */
    public function names(): array
    {
        return array_keys($this->rows);
    }

    public function has(string $name): bool
    {
        return isset($this->rows[$name]);
    }

    /** The stored row, or null when the name was never added. */
    public function record(string $name): ?array
    {
        return $this->rows[$name] ?? null;
    }

    /**
     * Register a marketplace. The refusal sentence names the rule that bit;
     * null means it landed (and landed DURABLE — an unbacked registry
     * refuses, because a catalog that evaporates at process exit would let a
     * later install believe it had been added).
     *
     * The sha floor is the design's: 40 hex characters, captured from the
     * checkout's git state at add time. There is no default, empty-sha shape.
     *
     * @param string $source repo (`github`), https URL (`url`/`git`), or directory (`path`)
     */
    public function add(string $name, string $sourceType, string $source, string $sha): ?string
    {
        if (!$this->isBacked()) {
            return 'marketplaces cannot be registered: this host has no vouchsafe home to keep '
                . self::RECORD_RELATIVE_PATH . ' in';
        }

        if (!MarketplaceEntry::isValidName($name)) {
            return 'marketplace name "' . $name . '" is not a valid bundle name';
        }

        if ($this->has($name)) {
            return 'marketplace "' . $name . '" is already added; remove it first to re-point it';
        }

        if (!in_array($sourceType, self::ROW_SOURCE_TYPES, true)) {
            return 'marketplace "' . $name . '" names unknown source type "' . $sourceType . '"';
        }

        if (!self::isPinnedSha($sha)) {
            return 'marketplace "' . $name . '" arrives with no pinned commit; install refuses '
                . 'anything the add-time sha cannot name';
        }

        if ($sourceType === MarketplaceEntry::SOURCE_PATH) {
            $real = realpath($source);

            if ($real === false || !is_dir($real)) {
                return 'marketplace "' . $name . '" points at a directory that cannot be found';
            }

            $source = $real;
        } elseif ($this->checkoutsRoot !== null) {
            // The checkout directory is made at add time, private at birth,
            // so the containment compare in checkoutDir() has a real
            // directory to vouch for before any clone ever fills it.
            try {
                PrivateDir::ensure($this->checkoutsRoot, 'marketplace checkout base directory');
            } catch (\Throwable $e) {
                return 'marketplace checkout base could not be prepared: ' . $e->getMessage();
            }

            if (!is_dir($this->checkoutsRoot . '/' . $name) && !@mkdir($this->checkoutsRoot . '/' . $name, 0700) && !is_dir($this->checkoutsRoot . '/' . $name)) {
                return 'marketplace checkout directory could not be created';
            }
        }

        $this->rows[$name] = [
            'name' => $name,
            'sourceType' => $sourceType,
            'source' => $source,
            'sha' => $sha,
            'addedAt' => time(),
        ];

        $refusal = $this->persist();

        if ($refusal !== null) {
            unset($this->rows[$name]);

            return $refusal;
        }

        return null;
    }

    /** Retire a marketplace's registration. The checkout directory stays — deleting is not this verb's job. */
    public function remove(string $name): ?string
    {
        if (!$this->isBacked()) {
            return 'marketplaces cannot be changed: this host has no vouchsafe home to keep '
                . self::RECORD_RELATIVE_PATH . ' in';
        }

        if (!$this->has($name)) {
            return 'marketplace "' . $name . '" is not added';
        }

        $was = $this->rows;
        unset($this->rows[$name]);
        $refusal = $this->persist();

        if ($refusal !== null) {
            $this->rows = $was;

            return $refusal;
        }

        return null;
    }

    /** Re-pin a registered marketplace to a new commit (the update leg's write). */
    public function repin(string $name, string $sha): ?string
    {
        if (!$this->has($name)) {
            return 'marketplace "' . $name . '" is not added';
        }

        if (!self::isPinnedSha($sha)) {
            return 'marketplace "' . $name . '" cannot be pinned to a commit that is not a full sha';
        }

        $was = $this->rows[$name]['sha'];
        $this->rows[$name]['sha'] = $sha;
        $refusal = $this->persist();

        if ($refusal !== null) {
            $this->rows[$name]['sha'] = $was;

            return $refusal;
        }

        return null;
    }

    /**
     * Where a registered marketplace's checkout lives — and the ONLY way any
     * caller should name it. Remote rows land under the base this registry
     * was built with, with the name as one validated segment; the
     * containment compare re-checks the result, so a base this process does
     * not own and a planted link out of it answer null rather than a path.
     * Path rows are the operator's explicit word (the same verdict every
     * other user-tier path setting draws) and carry the directory itself.
     */
    public function checkoutDir(string $name): ?string
    {
        $row = $this->record($name);

        if ($row === null) {
            return null;
        }

        if ($row['sourceType'] === MarketplaceEntry::SOURCE_PATH) {
            return is_dir($row['source']) ? $row['source'] : null;
        }

        if ($this->checkoutsRoot === null) {
            return null;
        }

        $dir = rtrim($this->checkoutsRoot, '/') . '/' . $name;

        return ContainedPath::below($dir, $this->checkoutsRoot) ? $dir : null;
    }

    /** The parsed catalog of a registered marketplace, or null when it has no readable one. */
    public function marketplace(string $name): ?Marketplace
    {
        $dir = $this->checkoutDir($name);

        return $dir === null ? null : Marketplace::read($dir);
    }

    /**
     * THE install gate — refuse by default, in sentence form. Null means the
     * install may proceed; every other answer is why it may not:
     *
     *  1. the offering marketplace was never added to this machine (an empty
     *     catalog refuses everything, which is the same rule as every other
     *     name — the "pre-added" shape this design forbids has nothing to
     *     match against);
     *  2. the registered row carries no pinned commit (re-checked here rather
     *     than trusted from add time, because the file is the record);
     *  3. the name is not in this manifest at all;
     *  4. when the caller passes a non-empty $allowlist: the entry's
     *     repo-or-URL source key is not on it.
     */
    public function installGate(Marketplace $marketplace, string $entryName, array $allowlist = []): ?string
    {
        $row = $this->record($marketplace->name);

        if ($row === null) {
            return 'marketplace "' . $marketplace->name . '" has not been added to this machine; '
                . 'nothing installs from a catalog that was never added';
        }

        if (!self::isPinnedSha($row['sha'])) {
            return 'marketplace "' . $marketplace->name . '" is registered without a pinned commit; '
                . 'installing from it would record provenance this machine cannot name';
        }

        $entry = $marketplace->entry($entryName);

        if ($entry === null) {
            return 'marketplace "' . $marketplace->name . '" offers no plugin named "' . $entryName . '"';
        }

        if ($allowlist !== []) {
            $pinned = array_map(
                static fn (string $allowed): string => strtolower($allowed),
                $allowlist,
            );

            if (!in_array($entry->sourceKey(), $pinned, true)) {
                return 'plugin "' . $entryName . '" sources from "' . $entry->sourceKey()
                    . '", which is not on the pinned allowlist';
            }
        }

        return null;
    }

    /**
     * The entry an install may act on, with the registry's pinned commit
     * stamped onto its provenance, or null when {@see installGate()} refuses.
     */
    public function installEntry(Marketplace $marketplace, string $entryName, array $allowlist = []): ?MarketplaceEntry
    {
        if ($this->installGate($marketplace, $entryName, $allowlist) !== null) {
            return null;
        }

        $row = $this->record($marketplace->name);
        $entry = $marketplace->entry($entryName);

        return $entry === null || $row === null ? null : $entry->withPinnedSha($row['sha']);
    }

    /**
     * Create the directory a remote checkout will be cloned into, and say
     * where. The record's own directory is PrivateDir-verified at persist
     * time; a checkout directory is made at 0700 here and the clone leg
     * fills it later — this only establishes that the path is the one
     * {@see checkoutDir()} would vouch for, and nothing else.
     */
    public function prepareCheckout(string $name): ?string
    {
        $dir = $this->checkoutDir($name);

        if ($dir === null) {
            return null;
        }

        if (!is_dir($dir) && !@mkdir($dir, 0700) && !is_dir($dir)) {
            return null;
        }

        return is_dir($dir) ? $dir : null;
    }

    /** Write the catalog, 0600, atomically; null means it is on disk. */
    private function persist(): ?string
    {
        if ($this->file === null || $this->checkoutsRoot === null) {
            return 'marketplaces cannot be persisted: this host has no vouchsafe home';
        }

        try {
            PrivateDir::ensure(dirname($this->file), 'marketplace record directory');

            $envelope = [
                'version' => self::VERSION,
                'marketplaces' => array_values($this->rows),
            ];

            AtomicJsonFile::new($this->file)->withPermissions(0o600)->write($envelope);
        } catch (\Throwable $e) {
            return 'marketplaces.json could not be written: ' . $e->getMessage();
        }

        return null;
    }

    /** A full 40-hex commit id, and nothing that merely looks keen. */
    private static function isPinnedSha(string $sha): bool
    {
        return $sha !== '' && preg_match('/^[0-9a-f]{40}$/', $sha) === 1;
    }
}
