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
 * THE RECORD. At every launch that reads a policy we fingerprint the four
 * sources it consulted — the user config file, the user hooks file, the
 * user's own `~/.claude/settings.json` (C-EXT-1.b: it feeds the same
 * hook chain as `hooks.yaml`, so a silent edit to it was exactly the gap
 * INT-4-P2 closed for the other two), and each trusted project's hooks file
 * as the exact entry identities (name|event|
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
    /**
     * Schema of the state file itself; bumped only with a shape change.
     * 1→2 adds the `userClaudeSettings` fingerprint (C-EXT-1.b); a record
     * still on 1 reads as null, which is the intended one-time baseline
     * reset — a first launch after upgrade has nothing to falsely accuse.
     */
    public const VERSION = 2;

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
     * A present-but-malformed file is NOT an absent one: this is a
     * tamper-evidence cache, and answering "no snapshot" to corrupt bytes
     * would skip exactly one tamper-compare on the very file the guard exists
     * to accuse. A read that cannot be trusted therefore throws at the seam
     * ({@see self::readOrAccuse()} folds the accusation into the launch
     * posture — notice, quarantine-all-new, heal — instead of a silent pass).
     * True absence stays quiet: a first launch has nothing to falsely accuse.
     *
     * `trustedRoots` is read defensively ({@see \SugarCraft\Code\Cli\Bootstrap}
     * treats a missing list as "no root-level quarantine, entry-level only")
     * rather than refused, so a record written by a sibling build that has not
     * grown the field yet stays loadable instead of silently resetting the
     * baseline. `userClaudeSettings` is NOT given that courtesy: it rides the
     * VERSION 2 bump, and the version gate above is the deliberate one-time
     * reset for every pre-bump record.
     *
     * @return array{version: int, userConfig: ?string, userHooks: ?string, userClaudeSettings: ?string, trustedRoots?: list<string>, projectHooks: array<string, list<string>>}|null
     */
    public static function read(callable $configDir): ?array
    {
        $path = self::path($configDir);
        if (!is_file($path)) {
            return null;
        }

        // Gate on is_file() BEFORE the atomic reader: a present-but-empty file
        // decodes to [] — indistinguishable from the reader's missing-file
        // answer — so it must reach the shape gate below and be accused, never
        // slip through as a quiet "no snapshot".
        try {
            $data = AtomicJsonFile::new($path)->read();
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                self::FILE_NAME . ' at ' . $path . ' is unreadable: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if (($data['version'] ?? null) === 1) {
            return null;
        }

        if (($data['version'] ?? null) !== self::VERSION
            || !array_key_exists('userConfig', $data)
            || !array_key_exists('userHooks', $data)
            || !array_key_exists('userClaudeSettings', $data)
            || !is_array($data['projectHooks'] ?? null)) {
            throw new \RuntimeException(
                self::FILE_NAME . ' at ' . $path
                    . ' is not a record this build could have written (corrupt or foreign).',
            );
        }

        /** @var array{version: int, userConfig: ?string, userHooks: ?string, userClaudeSettings: ?string, projectHooks: array<string, list<string>>} $data */
        return $data;
    }

    /**
     * The launch-seam reader: previous record plus a "be suspicious" bit.
     *
     * [null, false] — the file is genuinely absent: day one, compare nothing,
     * accuse nothing. [null, true] — the file is present but cannot be
     * trusted: the guard treats an unreadable evidence cache as a CHANGED
     * policy (announce + quarantine every project-hook entry the launch
     * cannot vouch for) and rewrites the file this launch, so the corrupt
     * record heals instead of lurking. A silent skip here would re-open the
     * exact one-launch tamper window the finding names.
     *
     * Only the RuntimeException this class raises at the read seam is folded;
     * anything wider would swallow a real bug as policy suspicion.
     *
     * @return array{0: array{version: int, userConfig: ?string, userHooks: ?string, userClaudeSettings: ?string, trustedRoots?: list<string>, projectHooks: array<string, list<string>>}|null, 1: bool}
     */
    public static function readOrAccuse(callable $configDir): array
    {
        try {
            return [self::read($configDir), false];
        } catch (\RuntimeException) {
            return [null, true];
        }
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
     * @param array{version: int, userConfig: ?string, userHooks: ?string, userClaudeSettings: ?string, projectHooks: array<string, list<string>>} $record
     */
    public static function write(callable $configDir, array $record): void
    {
        AtomicJsonFile::new(self::path($configDir))
            ->withPermissions(0600)
            ->write($record);
    }
}
