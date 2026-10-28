<?php

declare(strict_types=1);

namespace SugarCraft\Code\Format;

use SugarCraft\Code\Tools\Concerns\DetectsCapabilities;

/**
 * The built-in extension => formatter table behind `formatAuto` (C-TOOL-5.b).
 *
 * THE TABLE, the shape {@see \SugarCraft\Code\LSP\LspLauncher}'s auto-detect
 * table teaches, applied to formatting: each extension lists CANDIDATES in
 * preference order, and the runner takes the first one the host actually has.
 * A formatter that is not installed is simply not a candidate, so the table
 * stays silent on every host where none of its entries exist — which is what
 * lets it ship as a default the explicit `formatCommands` map overrides
 * rather than fights.
 *
 *     {"formatAuto": true}
 *
 * WHY THE TRUST GATE TRAVELS WITH IT. An explicit `formatCommands` entry is
 * the operator naming their own formatter; a table entry runs a binary the
 * OPERATOR never typed, chosen by the file's extension. The riskiest entry is
 * deliberately project-local: `node_modules/.bin/prettier` ships inside the
 * clone, and prettier loads plugins that are arbitrary JavaScript from that
 * same node_modules. That is the C-TOOL-7.c reasoning verbatim — a project's
 * own files must not decide which binaries run over the model's edits — so
 * the auto arm reaches {@see FormatRunner::commandFor()} only when
 * `formatAuto` is on AND the project is in `trustedProjectHooks`. A global
 * prettier is never a candidate: the project-local copy is the one prettier
 * resolves with the project's config and plugins, and preferring a host-wide
 * binary over it would format a repository against settings it never agreed
 * to. Every entry also just REWRITES the edited file, the same overwrite
 * power that makes `formatCommands` user-tier-only; {@see FormatRunner}'s
 * mutate guard is what keeps a wrong formatter recoverable.
 *
 * Resolving candidates costs at most one `PATH` walk per distinct name —
 * {@see DetectsCapabilities::capabilityPresent()} memoizes host lookups;
 * project-local entries are checked against $root without the memo, because
 * the answer is a question about THAT project directory and must not be
 * poisoned by a probe made for another root.
 */
final class FormatAutoTable
{
    use DetectsCapabilities;

    /**
     * Extension (lower case, no dot) => candidates in preference order, each
     * one a bare `PATH` name or a project-relative path, plus its fixed
     * argument list. Every extension the table knows is here; the runner
     * never invents one.
     *
     * @var array<string, list<array{binary: string, args: list<string>}>>
     */
    private const TABLE = [
        'go' => [
            ['binary' => 'gofmt', 'args' => ['-w']],
        ],
        'rs' => [
            ['binary' => 'rustfmt', 'args' => []],
        ],
        'py' => [
            ['binary' => 'ruff', 'args' => ['format']],
            ['binary' => 'black', 'args' => []],
        ],
        'sh' => [
            ['binary' => 'shfmt', 'args' => ['-w']],
        ],
        'bash' => [
            ['binary' => 'shfmt', 'args' => ['-w']],
        ],
        'tf' => [
            ['binary' => 'terraform', 'args' => ['fmt']],
        ],
        'js' => [
            ['binary' => 'node_modules/.bin/prettier', 'args' => ['--write']],
        ],
        'jsx' => [
            ['binary' => 'node_modules/.bin/prettier', 'args' => ['--write']],
        ],
        'mjs' => [
            ['binary' => 'node_modules/.bin/prettier', 'args' => ['--write']],
        ],
        'cjs' => [
            ['binary' => 'node_modules/.bin/prettier', 'args' => ['--write']],
        ],
        'ts' => [
            ['binary' => 'node_modules/.bin/prettier', 'args' => ['--write']],
        ],
        'tsx' => [
            ['binary' => 'node_modules/.bin/prettier', 'args' => ['--write']],
        ],
        'json' => [
            ['binary' => 'node_modules/.bin/prettier', 'args' => ['--write']],
        ],
        'jsonc' => [
            ['binary' => 'node_modules/.bin/prettier', 'args' => ['--write']],
        ],
        'css' => [
            ['binary' => 'node_modules/.bin/prettier', 'args' => ['--write']],
        ],
        'scss' => [
            ['binary' => 'node_modules/.bin/prettier', 'args' => ['--write']],
        ],
        'less' => [
            ['binary' => 'node_modules/.bin/prettier', 'args' => ['--write']],
        ],
        'md' => [
            ['binary' => 'node_modules/.bin/prettier', 'args' => ['--write']],
        ],
        'markdown' => [
            ['binary' => 'node_modules/.bin/prettier', 'args' => ['--write']],
        ],
        'yaml' => [
            ['binary' => 'node_modules/.bin/prettier', 'args' => ['--write']],
        ],
        'yml' => [
            ['binary' => 'node_modules/.bin/prettier', 'args' => ['--write']],
        ],
    ];

    /**
     * The candidates for one extension, in preference order — [] for an
     * extension the table does not know.
     *
     * @return list<array{binary: string, args: list<string>}>
     */
    public static function candidatesFor(string $extension): array
    {
        return self::TABLE[strtolower($extension)] ?? [];
    }

    /** Every extension the table carries, in table order. */
    public static function extensions(): array
    {
        return array_keys(self::TABLE);
    }

    /**
     * The command line one candidate runs as: the file path rides the end of
     * it exactly as an explicit command without `{file}` does, which is where
     * {@see FormatRunner::format()} adds it, shell-quoted.
     *
     * @param array{binary: string, args: list<string>} $candidate
     */
    public static function commandString(array $candidate): string
    {
        return $candidate['args'] === []
            ? $candidate['binary']
            : $candidate['binary'] . ' ' . implode(' ', $candidate['args']);
    }

    /**
     * Whether $binary is here to run: a name with a separator is a project
     * path, answered against $root; anything else is a host `PATH` lookup.
     */
    public static function isPresent(string $binary, string $root): bool
    {
        if (str_contains($binary, '/')) {
            return $root !== '' && is_executable($root . '/' . $binary);
        }

        return self::capabilityPresent($binary);
    }
}
