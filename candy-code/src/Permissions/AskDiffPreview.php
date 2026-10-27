<?php

declare(strict_types=1);

namespace SugarCraft\Code\Permissions;

use SugarCraft\Code\Tools\BuiltIn\ApplyPatch;
use SugarCraft\Code\Tools\BuiltIn\Edit;
use SugarCraft\Code\Tools\BuiltIn\Write;
use SugarCraft\Code\Tools\Edit\PatchParser;
use SugarCraft\Code\Tools\PathJail;

/**
 * The unified diff of a PROPOSED write-tool call, computed by the process that
 * holds the real files — the `diff` field of an ask frame and of the
 * `permission.requested` wire shape (C-SEC-2.b).
 *
 * Why server-side: the web card's preview is minted client-side from the call
 * ARGUMENTS alone, so its `@@` headers are snippet-relative — for a batched
 * `Edit` they describe none of the hunks in the call, and an approver clicking
 * Allow is consenting to a rendering the tool itself never made. The tool's
 * own `previewDiff()` answers run against the file on disk and carry its real
 * line numbers.
 *
 * Security laws, in order of how they fail:
 *
 *  - CONFINED. Every path the preview would touch must pass
 *    {@see PathJail::confine()} against the session root — the same algorithm
 *    the writers themselves jail to. A call whose preview would read (or
 *    claim to write) outside the root gets NO diff at all, never a partial
 *    one: the whole preview is the consent surface.
 *  - CAPPED. The ask frame rides an un-validated serialized channel and the
 *    wire carries every pending ask in a snapshot, so the preview is bounded
 *    at {@see MAX_DIFF_BYTES} AFTER generation. Beyond it the raw text is
 *    REPLACED by an omitted note with the change's line counts, in the house
 *    note genre (`BuildsUnifiedDiff::omittedDiffNote`) — silence would read as
 *    "no change", which is the dangerous wrong answer.
 *  - FAIL-CLOSED. No root, no preview. An unparseable `ApplyPatch` argument
 *    falls back to the raw patch text — honest to the same confinement law and
 *    to the same cap; any other throw loses only the preview. A field missing
 *    is the same answer as a tool with no previewable shape: absent, and the
 *    card keeps its raw-arguments dump.
 *
 * The preview is advisory: it is what the tool SAYS it will do, and the
 * approver still sees the arguments beside it. The TUI renderer keeps its own
 * pre-existing sibling path to these tools' previews; this class is the wire
 * side and must never grow a rendering dependency.
 */
final class AskDiffPreview
{
    /**
     * Hard bound on one diff's bytes on the wire — generous against real
     * edit hunks, small enough that a pending-asks snapshot cannot be
     * inflated past it by one hostile call ({@see \SugarCraft\Code\Backend\PendingAsk::fromFrame()}
     * drops an oversize frame value rather than trusting it).
     */
    public const MAX_DIFF_BYTES = 32768;

    /**
     * The preview for a proposed call, or null when it has no diff.
     *
     * @param array<string, mixed> $args the call's rewritten arguments
     * @param ?string              $root the session root the writers are
     *                                   jailed to; null refuses everything
     */
    public static function for(string $tool, array $args, ?string $root): ?string
    {
        if ($root === null || $root === '') {
            return null;
        }

        $preview = match ($tool) {
            // Edit and Write carry their names only in the #[BuiltInTool]
            // attribute; ApplyPatch has the const.
            'Edit' => self::confinedEditPreview($root, $args),
            'Write' => self::confinedWritePreview($root, $args),
            ApplyPatch::NAME => self::confinedPatchPreview($root, $args),
            default => null,
        };

        return $preview === null ? null : self::cap($preview);
    }

    /**
     * Whether every path an $args set would touch is confined under $root.
     * Shared by the Edit and Write gates; a missing or non-string
     * `file_path` is not confined — it is nothing.
     */
    private static function pathConfined(string $root, array $args): bool
    {
        $path = $args['file_path'] ?? null;

        return is_string($path) && PathJail::confine($root, $path) !== null;
    }

    private static function confinedEditPreview(string $root, array $args): ?string
    {
        return self::pathConfined($root, $args) ? Edit::previewDiff($root, $args) : null;
    }

    private static function confinedWritePreview(string $root, array $args): ?string
    {
        return self::pathConfined($root, $args) ? Write::previewDiff($root, $args) : null;
    }

    /**
     * ApplyPatch's own preview refuses the whole patch when ANY operation
     * path fails the writer jail, which is also a legitimate "no diff" answer
     * — but a refusal would then hide the patch text the raw-arguments dump
     * still shows. So confinement is checked here first: all paths in → the
     * tool's preview (falling back to the raw patch text when the tool itself
     * answers null); any path out → no diff at all. A patch that will not
     * even parse has no path census to make: its raw text rides the same cap.
     *
     * @param array<string, mixed> $args
     */
    private static function confinedPatchPreview(string $root, array $args): ?string
    {
        $patch = $args['patch'] ?? null;
        if (!is_string($patch) || $patch === '') {
            return null;
        }

        try {
            $operations = PatchParser::new()->parse($patch);
        } catch (\InvalidArgumentException) {
            return $patch;
        }

        foreach ($operations as $operation) {
            if (PathJail::confine($root, $operation->path) === null) {
                return null;
            }
        }

        return ApplyPatch::previewDiff($root, $args) ?? $patch;
    }

    /**
     * Bound one preview for the wire: past {@see MAX_DIFF_BYTES} the text is
     * replaced by an omitted note that names the change's size, never by
     * nothing.
     */
    private static function cap(string $preview): string
    {
        if (strlen($preview) <= self::MAX_DIFF_BYTES) {
            return $preview;
        }

        $added = 0;
        $removed = 0;
        foreach (explode("\n", $preview) as $line) {
            if (str_starts_with($line, '+++') || str_starts_with($line, '---')) {
                continue;
            }
            if (str_starts_with($line, '+')) {
                ++$added;
            } elseif (str_starts_with($line, '-')) {
                ++$removed;
            }
        }

        return \sprintf(
            'Diff preview omitted: %d bytes over the %d-byte wire bound (changed region: +%d -%d lines).',
            strlen($preview),
            self::MAX_DIFF_BYTES,
            $added,
            $removed,
        );
    }
}
