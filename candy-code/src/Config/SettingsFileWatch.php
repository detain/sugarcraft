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
 * The check is a ladder, and the cheap rung is the file's CONTENT hash: every
 * watched path is small JSON that the launch machinery reads every turn
 * anyway, so hashing them at a settle costs what a next-turn read already
 * costs, and no stat shortcut is honest enough to keep. A stat (mtime + size)
 * gate can sail straight over a same-second, same-size rewrite — `4` edited
 * to `5` within the recorded second leaves both fields untouched, and the
 * fingerprint would stay blind to that edit forever, not just until the next
 * touch. Existence is a hash too: a file that is not there hashes as ''.
 * Only a moved hash triggers the expensive rung: re-derive the merged view
 * exactly as the launch does ({@see Bootstrap::readUserConfig()}) and diff it
 * key by key against the previously observed merge. Churn that does not move
 * the merge — an editor that touches a file without changing it, a `git
 * checkout` that rewrites identical bytes, a non-layered key edited, a
 * project file the trust gate refuses — is caught at the hash rung and still
 * answers none.
 *
 * Two deliberate behaviours protect the running session:
 *
 * - The FIRST observation of a watch-set seeds the fingerprint and reports
 *   nothing. The launch makes that observation itself —
 *   {@see seedAtLaunch()} runs once {@see \SugarCraft\Code\Cli\Bootstrap::chat()}
 *   has built the session — so the seed snapshot IS the merge the session
 *   started from, and an edit made while the session sits idle before its
 *   first settle is a MOVE against that baseline, not a change folded into it.
 *   A watch that never seeded (a `Chat` built outside the launch, as the suite
 *   does) still baselines on its own first look: with no earlier merge there
 *   is nothing to be stale against.
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
     * Content fingerprint per watched path ('' for a file that is not there).
     *
     * @var array<string, string>
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
     * With `$force` the hash gate is skipped and the merge is always
     * recomputed — the `/settings reload` command's shape of the same
     * question. An unchanged merge still answers `[]` under force: the force
     * buys "look now", never "report noise".
     *
     * @return list<string>
     */
    public static function changedKeys(bool $force = false): array
    {
        $files = self::watchedFiles();

        $seen = \array_keys($files);
        \sort($seen);
        $known = \array_keys(self::$fingerprint);
        \sort($known);
        if ($seen !== $known) {
            self::seed($files);

            return [];
        }

        $hashMoved = $force;
        foreach ($seen as $path) {
            $hash = self::hashOf($path);
            if ($hash !== self::$fingerprint[$path]) {
                $hashMoved = true;
            }
            self::$fingerprint[$path] = $hash;
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
     * Take the watch's first observation at boot: record the fingerprint of
     * every watched path and the merge the launch read, reporting nothing.
     *
     * Called once by {@see \SugarCraft\Code\Cli\Bootstrap::chat()} after the
     * session is built. Without it the first observation waits for the first
     * settle, so an edit that landed while a freshly-launched session sat idle
     * is folded into the baseline it should have been measured against — the
     * swallow the watch exists to close, wearing the "hot reload" clothes.
     * After the build (not before) because the build is what settles the
     * project root and its trust the watched set is derived from.
     */
    public static function seedAtLaunch(): void
    {
        self::seed(self::watchedFiles());
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
        $fingerprint = [];
        foreach (\array_keys($files) as $path) {
            $fingerprint[$path] = self::hashOf($path);
        }
        self::$fingerprint = $fingerprint;
        self::$mergedSnapshot = Bootstrap::readUserConfig();
    }

    /**
     * The content fingerprint of a path: '' for a file that is not there.
     * Reads through the gazed sink vocabulary (file_get_contents), the census
     * row for this file names the shape.
     */
    private static function hashOf(string $path): string
    {
        $contents = @\file_get_contents($path);

        return $contents === false ? '' : \hash('sha256', $contents);
    }
}
