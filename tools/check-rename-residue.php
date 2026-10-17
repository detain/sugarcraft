<?php

declare(strict_types=1);

/**
 * Hunt old-slug vocabulary left behind by an EXECUTED rename (plan_rename.md Step 0.4).
 *
 * WHY THIS EXISTS. tools/rename-lib.php rewrites the mechanical cases in one
 * pass, but a rename campaign leaks vocabulary by hand: prose a rule skipped,
 * a changelog line, a bin name quoted in a tutorial. The residue checker is
 * the pin that says "this entry is finished" with an exit code instead of an
 * opinion, so Procedure R6 and the Phase 12 close run it and trust the rc.
 *
 * WHAT COUNTS AS RESIDUE — Part C's notion, by construction, not by copy.
 * The scan vocabulary is the SAME derived-name algebra (Part B.4) and the SAME
 * rule regexes the renamer applies, reached through RenameLib::derive(),
 * RenameLib::r3Pattern() and RenameLib::r4Pattern(). A token is residue for
 * an entry iff the renamer would have rewritten it: R1 (old namespace, only
 * when ns_old != ns_new), R2 (old PascalCase brand), R3 (old slug at word
 * boundaries, PROTECT lookaheads included), R4 (old compact identifier),
 * R5 (old env prefixes), R7 (entry extra_literals), plus the bare old bin
 * name from each `bins` key when it appears in backticks or after `$ ` /
 * `php ` — the shapes C.2 explicitly leaves to manual fixing. A line
 * carrying `@rename-keep` is law and is skipped, exactly as in the renamer.
 *
 * WHICH ENTRIES. `--old=<slug>` checks that one entry whatever its status
 * (Procedure R6 runs it right after the rename; step 1.x runs it on merge
 * entries). With no `--old`, the checker defaults to the `--all-done` set:
 * every entry whose status is NOT `pending` — a pending entry legitimately
 * still carries the old vocabulary everywhere, so flagging it would make the
 * tool wrong on the day it ships. An entry with no `old` name (kind `create`)
 * has no vocabulary to hunt and is skipped.
 *
 * WHICH FILES. The same file set as the renamer: `git ls-files` minus the
 * Part C.3 exclusions, reached through RenameLib::isExcludedPath() and
 * RenameLib::isContentExcluded() — including this tool and its own test, so
 * the fixture vocabularies living here can never indict the repo.
 *
 * OUTPUT / EXIT.
 *   `file:line: [rule] matched-text` per finding (deduplicated, sorted by
 *   path, then line, then rule order R1 R2 R3 R4 R5 R7 BIN), then one
 *   summary line `check-rename-residue: N entries checked, M findings`.
 *   Exit 0 clean, 1 residue found, 2 refusal (bad flag, unreadable map,
 *   unknown slug, missing root). Read-only: this tool never writes.
 */

namespace SugarCraft\Tools;

require_once __DIR__ . '/rename-lib.php';

final class CheckRenameResidue
{
    /** The status at (or before) which old vocabulary is legitimate, not residue. */
    private const PENDING_STATUS = 'pending';

    /** Report order of the rule tags; anything unlisted sorts last by name. */
    private const TAG_ORDER = ['R1', 'R2', 'R3', 'R4', 'R5', 'R7', 'BIN'];

    /**
     * `--help` text. The `Environment:` block is asserted in both directions
     * by tools/tests/ToolsEnvRosterTest.php — keep it in sync with getenv().
     */
    private const HELP = <<<'TXT'
        Usage: php tools/check-rename-residue.php [--old=<slug>] [--all-done] [--map=<path>]

        Options:
          --old=<slug>   check only the map entry whose "old" is <slug>, whatever its status
          --all-done     check every entry whose status is not "pending" (the default mode)
          --map=<path>   alternate renames.json instead of the sibling of this script

        Environment:
          SUGARCRAFT_RENAME_ROOT  target repository root; defaults to the monorepo
                containing tools/. The same documented hook tools/rename-lib.php
                uses, so one throwaway fixture repo can drive both tools.

        TXT;

    /** @param list<string> $argv */
    public static function main(array $argv): int
    {
        $old = null;
        $mapPath = null;
        foreach (array_slice($argv, 1) as $arg) {
            // --all-done is accepted as an explicit spelling of the default
            // mode (the plan's Procedure R and Phase 12 invoke it by name);
            // there is no separate branch because the semantics are identical.
            if (preg_match('#^--old=(.+)$#', $arg, $m) === 1) {
                $old = $m[1];
            } elseif (preg_match('#^--map=(.+)$#', $arg, $m) === 1) {
                $mapPath = $m[1];
            } elseif ($arg === '--all-done') {
                continue;
            } elseif ($arg === '--help' || $arg === '-h') {
                fwrite(STDOUT, self::HELP);

                return 0;
            } else {
                fwrite(STDERR, "check-rename-residue: unrecognised argument: $arg\n");

                return 2;
            }
        }

        $root = getenv('SUGARCRAFT_RENAME_ROOT');
        if (!is_string($root) || $root === '') {
            $root = dirname(__DIR__);
        }
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved)) {
            fwrite(STDERR, "check-rename-residue: root is not a directory: $root\n");

            return 2;
        }

        return self::run($resolved, $old, $mapPath ?? __DIR__ . '/renames.json');
    }

    public static function run(string $root, ?string $old, string $mapPath): int
    {
        $entries = RenameLib::loadEntries($mapPath);
        if (!is_array($entries)) {
            fwrite(STDERR, "check-rename-residue: $entries\n");

            return 2;
        }

        $selected = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $entryOld = $entry['old'] ?? null;
            if (!is_string($entryOld) || $entryOld === '') {
                // kind "create" (and any future no-old shape): nothing to hunt.
                continue;
            }
            if ($old !== null) {
                if ($entryOld === $old) {
                    $selected[] = $entry;
                    break;
                }

                continue;
            }
            if ((string) ($entry['status'] ?? self::PENDING_STATUS) !== self::PENDING_STATUS) {
                $selected[] = $entry;
            }
        }
        if ($old !== null && $selected === []) {
            fwrite(STDERR, "check-rename-residue: no map entry with old=\"$old\" in $mapPath\n");

            return 2;
        }

        $findings = $selected === [] ? [] : self::scan($root, $entries, $selected);

        foreach ($findings as $finding) {
            fwrite(STDOUT, sprintf("%s:%d: [%s] %s\n", $finding['path'], $finding['line'], $finding['tag'], $finding['text']));
        }
        fwrite(STDOUT, sprintf("check-rename-residue: %d entries checked, %d findings\n", count($selected), count($findings)));

        return $findings === [] ? 0 : 1;
    }

    /**
     * The per-entry residue rules for one derived name set: the renamer's own
     * detection patterns, minus the rewrites. R6 (Lang domain literal) is
     * deliberately absent — the lowercase domain word ('crush', 'shine') is a
     * bare English-ish token the plan itself forbids hunting (C.4 "do not
     * rewrite bare words"), and R6's scope is a single generated constant the
     * renamer owns.
     *
     * @param array<string, mixed> $d
     *
     * @return list<array{tag: string, pattern: string}>
     */
    public static function residueRules(array $d): array
    {
        $rules = [];

        if ($d['ns_old'] !== '' && $d['ns_old'] !== $d['ns_new']) {
            $rules[] = ['tag' => 'R1', 'pattern' => '#SugarCraft(\\\\+)' . preg_quote((string) $d['ns_old'], '#') . '(?![A-Za-z0-9_])#'];
        }
        $rules[] = ['tag' => 'R2', 'pattern' => RenameLib::r2Pattern($d)];
        $rules[] = ['tag' => 'R3', 'pattern' => RenameLib::r3Pattern($d)];
        $rules[] = ['tag' => 'R4', 'pattern' => RenameLib::r4Pattern($d)];
        foreach ($d['env_old_prefixes'] as $prefix) {
            $rules[] = ['tag' => 'R5', 'pattern' => RenameLib::r5Pattern($d, (string) $prefix)];
        }
        foreach (array_keys((array) $d['extra_literals']) as $literal) {
            if (is_string($literal) && $literal !== '') {
                $rules[] = ['tag' => 'R7', 'pattern' => '#' . preg_quote($literal, '#') . '#'];
            }
        }
        foreach (array_keys((array) $d['bins']) as $binPath) {
            $bare = is_string($binPath) ? basename($binPath) : '';
            if ($bare !== '') {
                // C.2: short bin names survive content rules, so the checker
                // hunts them only in the command shapes prose uses.
                $rules[] = ['tag' => 'BIN', 'pattern' => '#(?:`|\$ |php )' . preg_quote($bare, '#') . '(?![A-Za-z0-9_\-])#'];
            }
        }

        return $rules;
    }

    /**
     * Scan the C.3 file set for every selected entry.
     *
     * Files are read once and asked of each entry (renamer-fast-path
     * RenameLib::mightMatch plus the bin-bare extension below — mightMatch
     * knows nothing about short bin names like `pop`, and a file whose only
     * residue is `$ pop` would otherwise be prefilted away). Findings are
     * deduplicated per (file, line, rule, text) and sorted so reruns on the
     * same tree are byte-identical.
     *
     * @param list<array<string, mixed>> $entries
     * @param list<array<string, mixed>> $selected
     *
     * @return list<array{path: string, line: int, tag: string, text: string}>
     */
    public static function scan(string $root, array $entries, array $selected): array
    {
        $scans = [];
        foreach ($selected as $entry) {
            $d = RenameLib::derive($entry, $entries);
            $scans[] = ['d' => $d, 'rules' => self::residueRules($d), 'bins' => self::binBareNames($d)];
        }

        $seen = [];
        foreach (RenameLib::trackedPaths($root) as $path) {
            if (RenameLib::isExcludedPath($path)) {
                continue;
            }
            $file = "$root/$path";
            if (!is_file($file)) {
                continue;
            }
            $contents = (string) file_get_contents($file);
            if (RenameLib::isContentExcluded($path, $contents)) {
                continue;
            }
            $lines = explode("\n", $contents);
            foreach ($scans as $scan) {
                if (!RenameLib::mightMatch($contents, $scan['d']) && !self::containsAny($contents, $scan['bins'])) {
                    continue;
                }
                foreach ($lines as $index => $line) {
                    if (str_contains($line, RenameLib::KEEP_SENTINEL)) {
                        continue;
                    }
                    foreach ($scan['rules'] as $rule) {
                        if (preg_match_all($rule['pattern'], $line, $matches) === false || $matches[0] === []) {
                            continue;
                        }
                        foreach (array_unique($matches[0]) as $text) {
                            $seen[$path . "\0" . $index . "\0" . $rule['tag'] . "\0" . $text] = [
                                'path' => $path,
                                'line' => $index + 1,
                                'tag' => $rule['tag'],
                                'text' => $text,
                            ];
                        }
                    }
                }
            }
        }

        $findings = array_values($seen);
        usort($findings, static function (array $a, array $b): int {
            return [$a['path'], $a['line'], array_search($a['tag'], self::TAG_ORDER, true)]
                <=> [$b['path'], $b['line'], array_search($b['tag'], self::TAG_ORDER, true)];
        });

        return $findings;
    }

    /**
     * @param array<string, mixed> $d
     *
     * @return list<string>
     */
    private static function binBareNames(array $d): array
    {
        $bares = [];
        foreach (array_keys((array) $d['bins']) as $binPath) {
            $bare = is_string($binPath) ? basename($binPath) : '';
            if ($bare !== '') {
                $bares[] = $bare;
            }
        }

        return $bares;
    }

    /** @param list<string> $needles */
    private static function containsAny(string $contents, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($contents, $needle)) {
                return true;
            }
        }

        return false;
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($argv[0] ?? '')) === realpath(__FILE__)) {
    exit(CheckRenameResidue::main($argv));
}
