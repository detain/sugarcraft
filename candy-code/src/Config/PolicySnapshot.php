<?php

declare(strict_types=1);

namespace SugarCraft\Code\Config;

use SugarCraft\Core\Util\AtomicJsonFile;

/**
 * One-launch freshness record for the operator's permission policy (rv-sec-3).
 *
 * THE GAP THIS CLOSES. `~/.candy-code/config.json` and `hooks.yaml` are the
 * policy the last launch agreed to; nothing at startup compared them to what
 * THIS launch is about to load, so a mid-session edit — yours, a dotfiles
 * sync's, or a tool's — was live the moment the next process read it, with no
 * signal in between. A checked-out project's `hooks.yaml` has the same shape
 * one level worse: entering a directory is the edit.
 *
 * THE RECORD. At every launch that reads a policy we fingerprint the three
 * sources it consulted — the user config file, the user hooks file, and each
 * trusted project's hooks file as the exact entry identities (name|event|
 * command) that loaded — into a 0600 JSON state file next to the config. At
 * the NEXT launch, a hash that differs from the snapshot means the policy
 * changed while no candy-code process was watching, and the answer is not to
 * trust it silently: the operator gets a loud launch notice, and this launch
 * quarantines the NEW part — project-hook entries absent from the previous
 * snapshot are skipped for this launch only. The launch after, with the
 * change seen and stated, honours them. One-launch quarantine is deliberate:
 * permanent silence-on-diff would brick legitimate edits, and no quarantine
 * at all is today's behavior the review flagged.
 *
 * WHY ENTRY IDENTITIES, NOT JUST HASHES, FOR PROJECTS. A hash says "something
 * changed"; quarantine needs to say WHICH hook lines are the new ones, and
 * the only stable identity a hook entry has is its own name/event/command
 * triple. User-tier sources are never entry-skipped — they are the operator's
 * own files and the notice is the consent mechanism there; the quarantine
 * exists for the tier where a repository can speak.
 *
 * Mirrors no upstream; this is a SugarCraft safety property.
 */
final class PolicySnapshot
{
    /** Schema of the state file itself; bumped only with a shape change. */
    public const VERSION = 1;

    public const FILE_NAME = 'policy-snapshot.json';

    private function __construct()
    {
    }

    /**
     * @param callable():string $configDir directory the state file lives in
     *                                     (injectable for tests; production
     *                                     passes `Bootstrap::trustedConfigDirPath()`)
     */
    public static function path(callable $configDir): string
    {
        return $configDir() . '/' . self::FILE_NAME;
    }

    /**
     * The previous record, or null when this box has never launched with the
     * guard armed (first launch after upgrade: nothing to compare, nothing to
     * quarantine — the snapshot written this launch is the baseline).
     *
     * A present-but-malformed file is treated exactly like an absent one:
     * freshness is a safety notice, and a corrupt record must not become a
     * crash path or a permanent quarantine.
     *
     * `trustedRoots` is read defensively ({@see \SugarCraft\Code\Cli\Bootstrap}
     * treats a missing list as "no root-level quarantine, entry-level only")
     * rather than refused, so a record written by a sibling build that has not
     * grown the field yet stays loadable instead of silently resetting the
     * baseline.
     *
     * @return array{version: int, userConfig: ?string, userHooks: ?string, trustedRoots?: list<string>, projectHooks: array<string, list<string>>}|null
     */
    public static function read(callable $configDir): ?array
    {
        $raw = @file_get_contents(self::path($configDir));
        if (!is_string($raw)) {
            return null;
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data)
            || ($data['version'] ?? null) !== self::VERSION
            || !array_key_exists('userConfig', $data)
            || !array_key_exists('userHooks', $data)
            || !is_array($data['projectHooks'] ?? null)) {
            return null;
        }

        /** @var array{version: int, userConfig: ?string, userHooks: ?string, projectHooks: array<string, list<string>>} $data */
        return $data;
    }

    /**
     * sha256 of a policy file's exact bytes, or null when it does not exist.
     * Read failure counts as "absent": a file that cannot be read cannot be
     * loaded either, so both snapshots agree on nothing-to-hash.
     */
    public static function fingerprint(?string $path): ?string
    {
        if ($path === null || !is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);

        return is_string($raw) ? hash('sha256', $raw) : null;
    }

    /**
     * Persist this launch's record: 0600 via the house atomic writer, so the
     * policy text itself never lands world-readable even though only hashes
     * and entry identities live here.
     *
     * @param array{version: int, userConfig: ?string, userHooks: ?string, projectHooks: array<string, list<string>>} $record
     */
    public static function write(callable $configDir, array $record): void
    {
        AtomicJsonFile::new(self::path($configDir))
            ->withPermissions(0600)
            ->write($record);
    }
}
