<?php

declare(strict_types=1);

namespace SugarCraft\Code\Config;

use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchContext;

/**
 * Process-global fingerprint of the settings files the session reads, so a
 * hand edit on disk reaches the running session at the next turn boundary
 * instead of waiting for the next launch (C-TUI-5.c, sources: GAP-CFG-05).
 *
 * The keys that are re-read every turn needed nothing; the live keys only
 * moved through {@see \SugarCraft\Code\Chat::applySettings()}, which the
 * settings view drives after ITS writer ran — an edit made outside the app
 * (another window, `jq -r`, a git checkout) therefore waited for a restart.
 * This class answers one question cheaply at each settle: "did anything the
 * merged settings would now read differently actually change on disk?"
 *
 * The check is a ladder. Every watched path is stat-compared (mtime + size +
 * existence); when nothing moved — the overwhelmingly common case — that is
 * all it costs. A path whose stat moved is re-read and hashed; when every
 * hash matches its recorded fingerprint the stat is refreshed and the answer
 * is still none (an editor that touches a file without changing it, a
 * `git checkout` that rewrites identical bytes). Only a changed HASH triggers
 * the expensive step: re-derive the merged view exactly as the launch does
 * ({@see Bootstrap::readUserConfig()}) and diff it key by key against the
 * previously observed merge. File churn that does not move the merge — a
 * non-layered key edited, a project file the trust gate refuses — is caught
 * here and still answers none.
 *
 * Two deliberate behaviours protect the running session:
 *
 * - The FIRST observation of a watch-set seeds the fingerprint and reports
 *   nothing. The launch merge and the seed snapshot are read from the same
 *   files, so a process that has seen no settle yet has no "changed" — there
 *   is no baseline to be stale against.
 * - When the SET of watched paths itself differs (the --config location, the
 *   user settings dir, the project root or its trust moved between two
 *   settles, as between two test sandboxes sharing one process), the watch
 *   silently RE-SEEDS and reports nothing. Values from a different tier tree
 *   are not "changes" the running session should adopt, and a fingerprint
 *   captured under one home must never produce a toast under another.
 *
 * A save made through the settings view re-applies identically at the next
 * settle: the values are already equal, so the re-apply changes nothing but
 * the summary shows once more. A fingerprint cannot tell its own writer from
 * a hand — only that the bytes moved after it last looked — and pretending
 * otherwise would mean every self-save path here reaching INTO this class.
 */
final class SettingsFileWatch
{
    /**
     * Fingerprint per watched path: existence, mtime, size, content hash.
     *
     * @var array<string, array{exists: bool, mtime: int, size: int, hash: string}>
     */
    private static array $fingerprint = [];

    /**
     * The merged settings as last observed through this watch.
     *
     * @var array<string, mixed>
     */
    private static array $mergedSnapshot = [];

    /**
     * The settings keys whose merged value changed since the last observation.
     *
     * With `$force` the stat and hash gates are skipped and the merge is
     * always recomputed — the `/settings reload` command's shape of the same
     * question. An unchanged merge still answers `[]` under force: the force
     * buys "look now", never "report noise".
     *
     * @return list<string>
     */
    public static function changedKeys(bool $force = false): array
    {
        $files = self::watchedFiles();
        \clearstatcache();

        $seen = \array_keys($files);
        \sort($seen);
        $known = \array_keys(self::$fingerprint);
        \sort($known);
        if ($seen !== $known) {
            self::seed($files);

            return [];
        }

        $statMoved = $force;
        foreach ($files as $path) {
            if (!self::statMatches($path)) {
                $statMoved = true;
                break;
            }
        }
        if (!$statMoved) {
            return [];
        }

        $hashMoved = false;
        foreach ($files as $path) {
            $fingerprint = self::$fingerprint[$path];
            $hash = self::hashOf($path);
            if ($hash !== $fingerprint['hash']) {
                $hashMoved = true;
            }
            self::$fingerprint[$path] = self::statOf($path, $hash);
        }
        if (!$hashMoved) {
            return [];
        }

        $merged = Bootstrap::readUserConfig();
        $changed = [];
        foreach ($merged as $key => $value) {
            $key = (string) $key;
            if (!\array_key_exists($key, self::$mergedSnapshot) || self::$mergedSnapshot[$key] != $value) {
                $changed[] = $key;
            }
        }
        foreach (\array_keys(self::$mergedSnapshot) as $key) {
            if (!\array_key_exists($key, $merged)) {
                $changed[] = (string) $key;
            }
        }

        self::$mergedSnapshot = $merged;

        return $changed;
    }

    /**
     * The paths whose bytes the merged settings are read from, in tier order.
     *
     * `config.json`, the user `settings.json`, and — only for a trusted
     * project root — the two project files, taken from
     * {@see LayeredSettings::projectFiles()} so the trust and containment
     * gates decide, not a re-derivation here. The paths are deterministic per
     * (home, root, trust): a file missing today and present tomorrow keeps
     * the SAME set, so the re-seed rule above fires only on a real tier-tree
     * change, never on an appearance or disappearance a reload must catch.
     *
     * @return array<string, null> path => placeholder (keys are the contract)
     */
    public static function watchedFiles(): array
    {
        $paths = [Bootstrap::userConfigPath()];

        $userDir = Bootstrap::userSettingsDirOrNull();
        if ($userDir !== null) {
            $paths[] = \rtrim($userDir, '/') . '/' . LayeredSettings::USER_FILE;
        }

        $root = LaunchContext::projectRootForSettings();
        if ($root !== null && \trim($root) !== '') {
            foreach (\array_keys(LayeredSettings::projectFiles($root, Bootstrap::projectSettingsTrusted($root))) as $path) {
                $paths[] = (string) $path;
            }
        }

        $files = [];
        foreach ($paths as $path) {
            $files[$path] = null;
        }

        return $files;
    }

    /** Discard the fingerprint — the test seam for a fresh process. */
    public static function resetForTesting(): void
    {
        self::$fingerprint = [];
        self::$mergedSnapshot = [];
    }

    /**
     * Record the current fingerprint of every watched path and take the merge
     * as the new baseline.
     *
     * @param array<string, null> $files
     */
    private static function seed(array $files): void
    {
        \clearstatcache();
        $fingerprint = [];
        foreach (\array_keys($files) as $path) {
            $fingerprint[$path] = self::statOf($path, self::hashOf($path));
        }
        self::$fingerprint = $fingerprint;
        self::$mergedSnapshot = Bootstrap::readUserConfig();
    }

    /** Whether a path's current stat still matches its recorded fingerprint. */
    private static function statMatches(string $path): bool
    {
        $recorded = self::$fingerprint[$path];
        $mtime = @\filemtime($path);
        $size = $mtime === false ? false : @\filesize($path);

        if ($recorded['exists'] !== ($mtime !== false)) {
            return false;
        }
        if (!$recorded['exists']) {
            return true;
        }

        return $recorded['mtime'] === $mtime && $recorded['size'] === $size;
    }

    /**
     * The current stat triple for a path, paired with a content hash already
     * taken ({@see self::hashOf()} reads through the gazed sink vocabulary,
     * the census row for this file names the shape).
     *
     * @return array{exists: bool, mtime: int, size: int, hash: string}
     */
    private static function statOf(string $path, string $hash): array
    {
        $mtime = @\filemtime($path);

        return [
            'exists' => $mtime !== false,
            'mtime' => $mtime === false ? -1 : (int) $mtime,
            'size' => $mtime === false ? -1 : (int) @\filesize($path),
            'hash' => $hash,
        ];
    }

    /** The content fingerprint of a path: '' for a file that is not there. */
    private static function hashOf(string $path): string
    {
        $contents = @\file_get_contents($path);

        return $contents === false ? '' : \hash('sha256', $contents);
    }
}
