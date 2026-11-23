<?php

declare(strict_types=1);

namespace SugarCraft\Code\Config;

/**
 * Per-process memo of the FILE tiers behind
 * {@see \SugarCraft\Code\Cli\Bootstrap::readUserConfig()} (AUD §7 rank 5).
 *
 * That read runs on well over a hundred call sites, several per turn
 * ({@see \SugarCraft\Code\Backend\EngineBackend}'s dispatch settings, the
 * HTTP client defaults), and each one decoded every settings file and
 * re-ran the layering. The memo keeps the decoded, layered answer and
 * hands it back while nothing it was derived from has moved.
 *
 * KEYED BY THE BYTES, NOT BY A STAT. The key is every input the layering
 * reads: the config path, the user settings file, the project files the
 * trust gate and containment pair admit THIS call (re-walked on every
 * read, so a swapped symlink or a revoked root moves the key), the
 * managed path, and the exact bytes of each file. A stat gate was the
 * cheaper candidate and is refused for the reason
 * {@see SettingsFileWatch} gives: a same-second, same-size rewrite
 * (`4` edited to `5`) leaves mtime and size untouched, and a memo blind to
 * it would serve the old value for the life of the process. Comparing the
 * bytes costs the small reads the merge would make anyway and saves the
 * decode and the merge; it also means an edit made outside the app is seen
 * at the very next read, exactly as before the memo.
 *
 * INVALIDATED EXPLICITLY AS WELL, which the byte key makes redundant for
 * correctness and is kept for the cost of nothing: every config write
 * through a writer ({@see \SugarCraft\Code\Cli\Bootstrap::mutateUserConfig()},
 * {@see \SugarCraft\Code\Config\Settings\SettingsWriter}) and every moved
 * fingerprint {@see SettingsFileWatch::changedKeys()} sees drop the memo,
 * so the read after a write or a detected edit is a full re-derivation and
 * never depends on the key alone.
 *
 * A DELIBERATE DEVIATION FROM THE STEP'S changedKeys() ROUTE (AUD §7 rank 5).
 * The step proposed letting {@see SettingsFileWatch::changedKeys()} be the
 * only invalidation, so a hit would do no I/O. That is refused: changedKeys()
 * runs only at a chat turn's settle and on `/settings reload`, so a read
 * mid-turn would serve a pre-edit value until the settle, and the headless
 * doors (`-p`, `serve`, the subcommands) never call it at all and would serve
 * the launch-time value for the life of the process. What this memo saves is
 * the decode and the merge; each read still pays the small `file_get_contents`
 * of up to five settings files, the same reads it made before the memo, which
 * is what keeps "an edit outside the app is seen at the next read" true.
 *
 * The session tier ({@see \SugarCraft\Code\Config\Settings\SessionSettings})
 * is process state, not a file, and stays OUT of the memo: the caller lays
 * it on each read, so a session save needs no invalidation at all.
 */
final class MergedConfigMemo
{
    /**
     * One slot per read shape (with and without the project tier).
     *
     * @var array<string, array{key: array<int, mixed>, value: array{0: array<string, mixed>, 1: array<string, mixed>}}>
     */
    private static array $slots = [];

    /** How many times a slot was (re)computed: the test seam for "it did not re-derive". */
    private static int $computations = 0;

    private function __construct()
    {
    }

    /**
     * The layered file tiers and the managed layer for these inputs, from the
     * memo when the paths and every file's bytes match the last derivation.
     *
     * @param string $configPath the CLI-written `config.json` (layer 4)
     * @param ?string $configBytes its bytes, read by the caller with its own tolerance
     * @param \Closure(?string): array<string, mixed> $decodeConfig layer 4's decode rule
     * @param ?string $userSettingsFile `settings.json` in the home-owned dir, or null
     * @param list<string> $projectPaths {@see LayeredSettings::projectLayerPaths()} for this read
     * @param string $systemPath the managed file
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [layers 1-4 merged, managed layer]
     */
    public static function fileTiers(
        bool $withProjectTier,
        string $configPath,
        ?string $configBytes,
        \Closure $decodeConfig,
        ?string $userSettingsFile,
        array $projectPaths,
        string $systemPath,
    ): array {
        $userBytes = $userSettingsFile === null ? null : LayeredSettings::fileBytes($userSettingsFile);
        $projectBytes = array_map(LayeredSettings::fileBytes(...), $projectPaths);
        $systemBytes = LayeredSettings::fileBytes($systemPath);

        $key = [$configPath, $configBytes, $userSettingsFile, $userBytes, $projectPaths, $projectBytes, $systemPath, $systemBytes];
        $slot = $withProjectTier ? 'project' : 'user';
        $hit = self::$slots[$slot] ?? null;
        if ($hit !== null && $hit['key'] === $key) {
            return $hit['value'];
        }

        self::$computations++;
        $value = [
            LayeredSettings::merge(
                $decodeConfig($configBytes),
                LayeredSettings::layeredFromBytes($userBytes),
                LayeredSettings::projectLayerFromBytes($projectBytes),
            ),
            LayeredSettings::layeredFromBytes($systemBytes),
        ];
        self::$slots[$slot] = ['key' => $key, 'value' => $value];

        return $value;
    }

    /** Drop every memoised derivation; the next read re-derives from disk. */
    public static function invalidate(): void
    {
        self::$slots = [];
    }

    /** Derivations since process start (or the last {@see resetForTesting()}). */
    public static function computations(): int
    {
        return self::$computations;
    }

    /** A fresh process's memo — the test seam. */
    public static function resetForTesting(): void
    {
        self::$slots = [];
        self::$computations = 0;
    }
}
