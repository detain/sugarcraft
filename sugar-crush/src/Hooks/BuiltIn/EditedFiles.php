<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks\BuiltIn;

use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Tools\Edit\PatchParser;

/**
 * The files a finished file-changing call names, for the post-edit hooks
 * (lint, LSP diagnostics, auto-commit, auto-test).
 *
 * `Write` and `Edit` name one file in `file_path`; `ApplyPatch` (roadmap
 * 3.I-3) names every file its patch adds, updates, moves (both ends) or
 * deletes. Each hook decides what to do with a path that no longer exists —
 * all of them skip it today, so a deleted or moved-away file is neither
 * linted nor committed.
 */
final class EditedFiles
{
    /** The PostToolUse matcher every post-edit hook shares. */
    public const MATCHER = '^(Write|Edit|ApplyPatch)$';

    private function __construct()
    {
    }

    /**
     * The paths $context's call names, as the model spelled them, each once
     * and in patch order — [] when it names none (or its patch does not
     * parse, which the tool refused).
     *
     * @return list<string>
     */
    public static function of(HookContext $context): array
    {
        $candidates = $context->toolName === 'ApplyPatch'
            ? (PatchParser::paths($context->toolArgs['patch'] ?? null) ?? [])
            : [$context->toolArgs['file_path'] ?? null];

        $paths = [];
        foreach ($candidates as $path) {
            if (\is_string($path) && trim($path) !== '' && !\in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }
}
