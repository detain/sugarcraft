<?php

declare(strict_types=1);

namespace SugarCraft\Code\Context;

use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Code\Lang;
use SugarCraft\Diff\Diff;

/**
 * Fingerprints the root instruction files — and the `@import` children their
 * expansions inlined, stat-only — at session freeze and reports, each turn,
 * what has changed on disk since then (plan_comparison CTX-3; the child
 * census is C-CTX-1.c).
 *
 * WHY THIS EXISTS. The standing instruction slab is a PerSession layer of
 * {@see SessionPromptMemo}: CLAUDE.md / AGENTS.md / personal notes are read
 * ONCE per session and their bytes ride the frozen prefix until a refresh
 * point (rewritten history). That is the cache-stability law and this class
 * does not bend it — the prefix never moves. What it adds is the OTHER half of
 * the freshness contract: when the user edits a tracked file mid-session, the
 * model learns of it on the next turn as a bounded unified diff delivered in
 * the `<turn-context>` row ({@see TurnContextBlock::withInstructionDiff()}),
 * exactly where every other volatile notice already lives.
 *
 * THE RECHECK IS STAT-FIRST. Per turn this walks a handful of instruction
 * paths and asks only `stat()` whether mtime and size moved; a file is read
 * and hashed again ONLY when the stat did move, and even then the new content
 * hash is compared against both baselines before a diff is computed, so a
 * touch-without-edit costs one stat, not a read. A measured change is
 * RECORDED (reason, current size, and for an edited file the current body)
 * and replayed byte-identically on every later step, so an unmoved disk pays
 * nothing beyond the stat walk. The message-0 assembly path never touches this
 * class at all — rechecks run from {@see \SugarCraft\Code\Runtime::turnContext()},
 * which the wire tail alone consults.
 *
 * THE BASELINE IS IMMUTABLE. Frozen bytes are captured once (the tracker
 * itself rides a memo slot, so a refresh point forgets it and the rebuilt slab
 * re-captures together). Every turn's diff is therefore frozen-vs-current: the
 * same edit renders the SAME bytes every turn until it is reverted, extended,
 * or a refresh point absorbs it into the slab — which is what lets
 * {@see TurnContextBlock::changedSince()} dedup the row for free. The one
 * accepted blind spot is the stat gate's own: a rewrite that keeps the byte
 * size identical within the same second as the last measurement is invisible
 * until mtime granularity or size moves it — a narrower miss than the
 * whole-session blindness this class exists to close.
 *
 * BOUNDED AND DEGRADED, NEVER SILENT. A changed file renders at most
 * {@see FILE_DIFF_MAX_BYTES} of diff, and the section at most
 * {@see TOTAL_DIFF_MAX_BYTES} — the EnvironmentBlock::DIFF_MAX_BYTES idiom
 * (a diff that overflows costs more cache than it informs). Anything that
 * cannot be shown faithfully within the budget — an edit too large to
 * preview, a file with no diffable frozen baseline, a vanished or
 * now-unreadable file, a path whose symlink resolution moved — degrades to a
 * name+size line carrying a `Read <path>` pointer, so the model always learns
 * THAT something moved even when it cannot be shown WHAT.
 *
 * ESCAPED AS DATA. Bodies and paths arrive from repository-controlled files;
 * every payload byte goes through {@see PromptFence::escape()} before it is
 * rendered, and the row's own fence is neutralised at
 * {@see TurnContextBlock::render()} like every other payload here.
 *
 * DECLARED LIMITS (each accepted, not overlooked):
 * - Nested `@import:` children of a root document are tracked stat-only,
 *   capped at {@see MAX_TRACKED_CHILDREN} per root in expansion order. The
 *   child list rides the loader's already-expanded document rows, so the
 *   capture pays no re-expansion; the per-step cost is one stat per tracked
 *   child, exactly like a root's. A moved child renders the `Read <path>`
 *   pointer line — its new bytes are never re-inlined per turn (that is the
 *   per-step re-read this design exists to avoid); the next refresh point
 *   absorbs them. Imports past the cap stay invisible until a refresh point,
 *   and a child cannot cancel its line by reverting (no content baseline
 *   exists to cancel against) — both accepted.
 * - `loadForPath()` on-touch delivery and `Skill` bodies keep their own
 *   channels and are not session instructions.
 * - The capture rides the parent's pre-fork prime of this slot
 *   ({@see \SugarCraft\Code\Runtime::primeInstructionDiff()}, called by
 *   EngineBackend in beginSessionTurn and beside the summary prime), not the
 *   slab closure itself, so an edit landing in the window between the two is
 *   measured against the post-edit bytes — worst case the diff arrives one
 *   refresh point late. The frozen slab is unaffected either way. What the
 *   prime fixes (C-CTX-1) is the fork case: a capture left to the child's
 *   first turn-context build froze "frozen = current disk" once per child,
 *   the tracker died with each child, and a mid-session edit surfaced in no
 *   turn at all.
 * - The C-CTX-2.f transcript row rides that same fork topology, and its
 *   said-state is session-scoped where the tracker object is not: the ledger
 *   lives on the loader (registered at {@see capture()}, written through at
 *   each announce, cleared on revert) and crosses a forked child's boundary
 *   as the {@see InstructionFileLoader::ANNOUNCED_UPDATES_KEY} key of the
 *   tool session-state envelope — {@see self::$announcedUpdates} for the
 *   fingerprint semantics.
 *
 * Mutable by design — a snapshot that recorded no pending state could not
 * replay a delta without re-reading every step, defeating the stat gate. Its
 * mutations are idempotent (re-running a recheck from the same disk state
 * re-renders byte-identical text and writes the seen tuples back to the values
 * they already hold), so a forked turn whose child dies without its mutations
 * propagating simply redoes the same work in the next child.
 */
final class InstructionDiffTracker
{
    /**
     * Opens the section in the rendered row. Pinned verbatim by the
     * byte-stability family; changing it changes every turn-context row that
     * carries a diff, which is fine at a refresh point and cheap as a line.
     */
    public const HEADER = 'Instruction files edited since this session\'s frozen copies — the system prompt above still carries the frozen text; these diffs are the delta:';

    /**
     * Per-file ceiling on rendered diff bytes. Half of
     * {@see TOTAL_DIFF_MAX_BYTES} so two files can share the section at full
     * per-file size before the total forces degradation.
     */
    public const FILE_DIFF_MAX_BYTES = 4096;

    /**
     * Ceiling on the diff bodies of the whole section, degradation lines
     * excluded (they are short and path-bounded). Rides the
     * {@see EnvironmentBlock::DIFF_MAX_BYTES} figure and its reasoning: the
     * row is a cache tail, and a diff larger than this is worth a re-read
     * pointer, not a re-prefill.
     */
    public const TOTAL_DIFF_MAX_BYTES = 8192;

    /**
     * A file larger than this is not diffable — the slab renders it as a
     * {@see InstructionFileLoader::pointer()} line rather than a body, and
     * holding a second copy here to diff would out-price the pointer it
     * replaces. Changes to it still surface as a degradation line.
     */
    public const MAX_DIFFABLE_BYTES = InstructionFileLoader::MAX_DOCUMENT_BYTES;

    /**
     * Cap on the `@import` children tracked stat-only per root document.
     *
     * A document's import graph is repository-controlled and a deep one is
     * cheap to state but expensive to walk every step; the cap bounds the
     * child half of the stat sweep at this many CONSIDERED per document, in
     * expansion order — a child already tracked via another document
     * (realpath dedup) or one whose stat failed consumes budget without
     * freezing, so a later root may track fewer new children than this.
     * Children past the cap keep the pre-C-CTX-1.c behaviour: an edit
     * surfaces only through the parent's own bytes at a refresh point.
     */
    public const MAX_TRACKED_CHILDREN = 32;

    /**
     * Cap on a path's rendered length inside a line — mirrors the loader's
     * own pointer budget without reaching into its private constant.
     */
    private const MAX_PATH_BYTES = 1024;

    /**
     * A frozen file that has since vanished (its stat fails) — the sentinel
     * no real mtime collides with.
     */
    private const GONE = -1;

    /**
     * @param list<array{path: string, real: string, body: ?string, sha: ?string, mtime: int, size: int, statOnly?: bool}> $frozen
     *        Frozen entries in tracked order (the loader's own precedence
     *        order), each holding the disk stat — and, for diffable files, the
     *        raw body and its hash — as measured at capture. Never mutated.
     * @param array<string, array{real: string, sha: ?string, mtime: int, size: int, pending: ?array{reason: string, body: ?string, size: int}}> $seen
     *        Last measured disk state per path plus the recorded change (if
     *        any); advanced only when the stat moved. Seeded from $frozen, so
     *        the first recheck is pure stat.
     * @param InstructionFileLoader $loader
     *        The loader whose session-state envelope carries this tracker's
     *        announce ledger (C-CTX-2.f round 1, the lead-accepted in-step
     *        carry): {@see capture()} seeds {@see self::$announcedUpdates}
     *        from it, every announce and every revert-clear writes back
     *        through it, and the guard reads it live so a merge landing after
     *        this capture still silences this tracker.
     */
    private function __construct(
        private readonly array $frozen,
        private array $seen,
        private readonly InstructionFileLoader $loader,
    ) {
        // C-CTX-2.f round 1: this capture starts where the SESSION's rows
        // stand — the loader's ledger is the carry across a forked child's
        // boundary, and every later write here goes through to it.
        $this->announcedUpdates = $loader->instructionUpdateLedger();
    }

    /**
     * C-CTX-2.f: the transcript rows already spoken, path => fingerprint.
     *
     * The fingerprint is the new content's sha1 where this method knows it
     * (the real-change leg, and a revert to the frozen bytes, which clears
     * the ledger so an edit away and back counts as the new change it is),
     * and the reason/size pair where the change was deliberately not read
     * (gone, moved, unreadable, over-cap, no-baseline) — a re-measured state
     * announces once; a different one announces again.
     *
     * This map is the capture-time view; the SESSION's view lives on the
     * loader (C-CTX-2.f round 1, the lead-accepted in-step carry). Capture
     * seeds from it, every write here goes through to it, and the guard
     * consults it live — so on the TUI's per-turn forks the child that
     * announces a change writes the row into its loader copy, the parent
     * merges the {@see InstructionFileLoader::ANNOUNCED_UPDATES_KEY} key back
     * from the turn child's envelope, and the next child that re-detects the
     * same change (by C-CTX-1's law the parent never advances $seen, so
     * every child re-detects) stays silent: one row per path per session,
     * the block law, holds on fork hosts as it always held in-process. The
     * model-facing half is untouched by the carry — the ledger rides, never
     * $seen — so the diff section still replays byte-identically every turn
     * ({@see TurnContextBlock::changedSince()} collapses its wire repeat;
     * the envelope collapses the transcript row). The per-turn E199 budget
     * bounds a burst of changed files; it never hides a file's own row
     * inside its turn.
     *
     * @var array<string, string>
     */
    private array $announcedUpdates = [];

    /**
     * Freeze the fingerprints of every tracked instruction path.
     *
     * The path list comes from the loader's memoised document lists — reads
     * nothing from disk beyond one stat and (for diffable files) one read per
     * path. The expanded rows also carry their inlined `@import` children,
     * frozen in a second pass stat-only (one stat each, never read, never
     * hashed) up to {@see MAX_TRACKED_CHILDREN} per root. A path that no
     * longer resolves is skipped: there is no baseline to hold.
     *
     * Roots freeze before children so a file that is both a root and another
     * document's child keeps its full body/sha tracking — the realpath dedup
     * below can only ever skip the child copy, never the root.
     *
     * Dedup is by REALPATH so a per-library file symlinked to the monorepo's
     * shared one fingerprints once — the same rule the loader applies to its
     * own emission — and a document the loader marked over-cap (body null) is
     * tracked stat-only: never hashed, never diffed, always tellable.
     */
    public static function capture(InstructionFileLoader $loader): self
    {
        $frozen = [];
        /** @var array<string, true> $byReal */
        $byReal = [];
        /** @var list<list<string>> $childLists */
        $childLists = [];

        foreach ([...$loader->loadDocuments(), ...$loader->loadPersonal()] as $document) {
            $path = (string) ($document['path'] ?? '');
            if ($path === '') {
                continue;
            }
            $stat = @stat($path);
            if ($stat === false) {
                continue;
            }
            $real = realpath($path) ?: '';
            if ($real === '' || isset($byReal[$real])) {
                continue;
            }
            $byReal[$real] = true;

            $diffable = $document['body'] !== null && (int) $stat['size'] <= self::MAX_DIFFABLE_BYTES;
            $body = $diffable ? @file_get_contents($path) : false;
            if ($diffable && $body === false) {
                // Unreadable at capture: no baseline exists to diff against,
                // and a stat-only entry would render pointer lines for files
                // that never claimed to be pointer-sized. Skip it.
                continue;
            }

            $entry = [
                'path' => $path,
                'real' => $real,
                'body' => $diffable ? (string) $body : null,
                'sha' => $diffable ? sha1((string) $body) : null,
                'mtime' => (int) $stat['mtime'],
                'size' => (int) $stat['size'],
            ];
            $frozen[] = $entry;
            $seen[$path] = [
                'real' => $real,
                'sha' => $entry['sha'],
                'mtime' => $entry['mtime'],
                'size' => $entry['size'],
                'pending' => null,
            ];

            $imports = $document['imports'] ?? [];
            if (is_array($imports) && $imports !== []) {
                $childLists[] = $imports;
            }
        }

        // Pass two, the children — roots are all frozen first, so a later
        // root's realpath can never lose its body/sha tracking to an earlier
        // document's child entry at the $byReal dedup.
        foreach ($childLists as $imports) {
            $budget = self::MAX_TRACKED_CHILDREN;
            foreach ($imports as $child) {
                if ($budget-- <= 0) {
                    break;
                }
                $child = (string) $child;
                if ($child === '') {
                    continue;
                }
                $stat = @stat($child);
                if ($stat === false) {
                    continue;
                }
                $real = realpath($child) ?: '';
                if ($real === '' || isset($byReal[$real])) {
                    continue;
                }
                $byReal[$real] = true;

                $entry = [
                    'path' => $child,
                    'real' => $real,
                    'body' => null,
                    'sha' => null,
                    'mtime' => (int) $stat['mtime'],
                    'size' => (int) $stat['size'],
                    'statOnly' => true,
                ];
                $frozen[] = $entry;
                $seen[$child] = [
                    'real' => $real,
                    'sha' => null,
                    'mtime' => $entry['mtime'],
                    'size' => $entry['size'],
                    'pending' => null,
                ];
            }
        }

        return new self($frozen, $seen, $loader);
    }

    /**
     * The rendered instruction-diff section, or '' when no tracked file has
     * moved. Byte-stable for an unmoved disk, and identical every turn for a
     * still-unchanged change — which is what lets the row-level
     * {@see TurnContextBlock::changedSince()} dedup swallow the repeat.
     *
     * @return string the section body, headers included, ready for
     *                {@see TurnContextBlock::withInstructionDiff()}
     */
    public function recheck(): string
    {
        /** @var list<array{entry: array{path: string, real: string, body: ?string, sha: ?string, mtime: int, size: int, statOnly?: bool}, size: int, body: ?string, reason: string}> $changed */
        $changed = [];

        foreach ($this->frozen as $entry) {
            $path = $entry['path'];
            $current = $this->seen[$path];
            $stat = @stat($path);

            if ($stat === false) {
                if ($current['mtime'] !== self::GONE) {
                    // Vanished since it was last measured: record and report.
                    $current = [
                        'real' => '', 'sha' => null, 'mtime' => self::GONE, 'size' => self::GONE,
                        'pending' => ['reason' => 'gone', 'body' => null, 'size' => self::GONE],
                    ];
                    $this->seen[$path] = $current;
                    $this->announceUpdate($path, null, 'gone', self::GONE);
                }
                if ($current['pending'] !== null) {
                    $changed[] = self::carried($entry, $current['pending']);
                }
                continue;
            }

            $mtime = (int) $stat['mtime'];
            $size = (int) $stat['size'];
            if ($mtime === $current['mtime'] && $size === $current['size']) {
                // The stat gate: the file has not moved since it was last
                // measured, whatever it now holds is already accounted for —
                // a recorded change replays from the record, no read.
                if ($current['pending'] !== null) {
                    $changed[] = self::carried($entry, $current['pending']);
                }
                continue;
            }

            $real = realpath($path) ?: '';
            if ($real !== $current['real']) {
                // The resolved target moved (a symlink swap at any component):
                // reading here would follow the new inode through the old
                // containment verdict, so the re-read is refused and the
                // change surfaces as a degradation line.
                $current = [
                    'real' => $real, 'sha' => null, 'mtime' => $mtime, 'size' => $size,
                    'pending' => ['reason' => 'moved', 'body' => null, 'size' => $size],
                ];
                $this->seen[$path] = $current;
                $this->announceUpdate($path, null, 'moved', $size);
                $changed[] = self::carried($entry, $current['pending']);
                continue;
            }

            if (($entry['statOnly'] ?? false) === true) {
                // A tracked @import child: its bytes ride the parent's frozen
                // expansion, so there is no baseline to diff against and none
                // is bought here — the moved stat surfaces as the `Read <path>`
                // pointer line (the 'no-baseline' degradation) and the next
                // refresh point absorbs the new bytes into the slab.
                $current = [
                    'real' => $real, 'sha' => null, 'mtime' => $mtime, 'size' => $size,
                    'pending' => ['reason' => 'no-baseline', 'body' => null, 'size' => $size],
                ];
                $this->seen[$path] = $current;
                $this->announceUpdate($path, null, 'no-baseline', $size);
                $changed[] = self::carried($entry, $current['pending']);
                continue;
            }

            if ($size > self::MAX_DIFFABLE_BYTES) {
                // Too big to diff this turn (either always was, or a growth
                // just made it so): no read is paid, the change still tells.
                $current = [
                    'real' => $real, 'sha' => null, 'mtime' => $mtime, 'size' => $size,
                    'pending' => ['reason' => 'no-baseline', 'body' => null, 'size' => $size],
                ];
                $this->seen[$path] = $current;
                $this->announceUpdate($path, null, 'no-baseline', $size);
                $changed[] = self::carried($entry, $current['pending']);
                continue;
            }

            $body = @file_get_contents($path);
            $sha = $body === false ? null : sha1((string) $body);

            if ($sha !== null && $sha === $entry['sha']) {
                // Back to the frozen content: the pending notice is cancelled
                // and cleared, matching the slab the model still holds. The
                // C-CTX-2.f ledger clears with it — the next edit away from
                // the frozen bytes is a newly detected change and is said.
                $this->seen[$path] = ['real' => $real, 'sha' => $sha, 'mtime' => $mtime, 'size' => $size, 'pending' => null];
                unset($this->announcedUpdates[$path]);
                $this->loader->forgetInstructionUpdate($path);
                continue;
            }

            if ($sha !== null && $sha === $current['sha']) {
                // Content came back to what was last measured (a touch, or a
                // rewrite with identical bytes): advance the stat — the one
                // hash this turn already paid for — and replay the record.
                $current['mtime'] = $mtime;
                $current['size'] = $size;
                $this->seen[$path] = $current;
                if ($current['pending'] !== null) {
                    $changed[] = self::carried($entry, $current['pending']);
                }
                continue;
            }

            if ($body === false) {
                $current = [
                    'real' => $real, 'sha' => null, 'mtime' => $mtime, 'size' => $size,
                    'pending' => ['reason' => 'unreadable', 'body' => null, 'size' => $size],
                ];
                $this->seen[$path] = $current;
                $this->announceUpdate($path, null, 'unreadable', $size);
                $changed[] = self::carried($entry, $current['pending']);
                continue;
            }

            // A real change: record it (body included when the baseline is
            // diffable) so every later step replays the same bytes without
            // touching this file again.
            $diffableNow = $entry['body'] !== null;
            $pending = [
                'reason' => $diffableNow ? 'edited' : 'no-baseline',
                'body' => $diffableNow ? (string) $body : null,
                'size' => $size,
            ];
            $this->seen[$path] = ['real' => $real, 'sha' => $sha, 'mtime' => $mtime, 'size' => $size, 'pending' => $pending];
            $this->announceUpdate($path, $sha, $pending['reason'], $size);
            $changed[] = self::carried($entry, $pending);
        }

        if ($changed === []) {
            return '';
        }

        return $this->render($changed);
    }

    /**
     * Shape one change item out of the frozen entry plus a recorded (or just
     * measured) pending change.
     *
     * @param array{path: string, real: string, body: ?string, sha: ?string, mtime: int, size: int, statOnly?: bool} $entry
     * @param array{reason: string, body: ?string, size: int} $pending
     *
     * @return array{entry: array{path: string, real: string, body: ?string, sha: ?string, mtime: int, size: int, statOnly?: bool}, size: int, body: ?string, reason: string}
     */
    private static function carried(array $entry, array $pending): array
    {
        return ['entry' => $entry, 'size' => $pending['size'], 'body' => $pending['body'], 'reason' => $pending['reason']];
    }

    /**
     * Render changed entries: unified diffs while the budget holds, name+size
     * degradation lines the moment anything exceeds it. Diff bodies ride in
     * from the measurement pass — this renders the bytes that were hashed, so
     * one disk state renders one answer however many turns see it.
     *
     * @param list<array{entry: array{path: string, real: string, body: ?string, sha: ?string, mtime: int, size: int, statOnly?: bool}, size: int, body: ?string, reason: string}> $changed
     */
    private function render(array $changed): string
    {
        $parts = [];
        $spent = 0;

        foreach ($changed as $change) {
            $entry = $change['entry'];
            $label = self::label($entry['path']);

            $diff = '';
            $degrade = $change['reason'];
            if ($degrade === 'edited' && $entry['body'] !== null && $change['body'] !== null) {
                $hunks = Diff::compute($entry['body'], $change['body'])->hunkText();
                if (strlen($hunks) <= self::FILE_DIFF_MAX_BYTES) {
                    $diff = $hunks;
                } else {
                    $degrade = 'over-budget';
                }
            }

            if ($diff === '') {
                $parts[] = $label . ': ' . self::degradation($change['size'], $degrade, $entry['path']);
                continue;
            }

            if ($spent + strlen($diff) > self::TOTAL_DIFF_MAX_BYTES) {
                // This file's delta is real but the section has spent its
                // budget; degrade it rather than clip a hunk mid-line.
                $parts[] = $label . ': ' . self::degradation($change['size'], 'over-budget', $entry['path']);
                continue;
            }
            $spent += strlen($diff);

            $parts[] = PromptFence::escape(
                $label . ' (' . number_format($entry['size']) . ' -> '
                . number_format($change['size']) . ' bytes):' . "\n" . $diff,
            );
        }

        return self::HEADER . "\n\n" . implode("\n\n", $parts);
    }

    /**
     * The name+size re-read pointer shown whenever a faithful diff cannot be
     * rendered — the same "Read <path>" shape the loader's own budget pointer
     * uses, worded for a change rather than a deferral.
     */
    private static function degradation(int $size, string $reason, string $path): string
    {
        if ($reason === 'gone') {
            return 'it no longer exists';
        }

        $tail = match ($reason) {
            'moved' => ' — it changed and its resolved path moved, so the new target is not read. Read ' . self::clip($path),
            'unreadable' => ' — the new content could not be read. Read ' . self::clip($path),
            'no-baseline' => ' — the file has no diffable frozen baseline. Read ' . self::clip($path),
            'over-budget' => ' — the delta exceeds the diff budget. Read ' . self::clip($path),
            default => ' — changed without a renderable diff. Read ' . self::clip($path),
        };

        return PromptFence::escape('now ' . number_format($size) . ' bytes' . $tail);
    }

    /**
     * C-CTX-2.f: say a newly detected change to the human — one row per
     * fingerprint, checked against this capture's view AND the loader's
     * session ledger (see {@see self::$announcedUpdates} for what a
     * fingerprint is, and for how the said-state crosses a fork).
     */
    private function announceUpdate(string $path, ?string $sha, string $reason, int $size): void
    {
        $fingerprint = $sha ?? ($reason . ':' . $size);
        if (($this->announcedUpdates[$path] ?? '') === $fingerprint
            || $this->loader->instructionUpdateFingerprint($path) === $fingerprint
        ) {
            return;
        }

        $this->announcedUpdates[$path] = $fingerprint;
        $this->loader->recordInstructionUpdate($path, $fingerprint);
        RuntimeNoticeSink::record(Lang::t('chat.instructions.updated', ['file' => self::rowLabel($path)]));
    }

    /**
     * The row's name for the file: the bare name. {@see label()} is the
     * model-facing form — clipped-path tail, fence-escaped — for a row the
     * transcript clips again at MAX_CHARS and the user reads at a glance.
     */
    private static function rowLabel(string $path): string
    {
        return basename($path);
    }

    /**
     * The display label: basename plus the clipped full path, so two
     * ancestors' same-named files stay tellable apart.
     */
    private static function label(string $path): string
    {
        return PromptFence::escape(basename($path) . ' (' . self::clip($path) . ')');
    }

    private static function clip(string $path): string
    {
        if (strlen($path) <= self::MAX_PATH_BYTES) {
            return $path;
        }

        return mb_strcut($path, 0, self::MAX_PATH_BYTES - strlen(' [clipped]'), 'UTF-8') . ' [clipped]';
    }
}
