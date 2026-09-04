<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context;

use SugarCraft\Crush\Agents\MemoryScope;
use SugarCraft\Crush\Memory\MemoryEntry;
use SugarCraft\Crush\Memory\MemoryStore;

/**
 * Renders the project's accreted memory entries as a fenced block for the
 * system prompt (crush_code.md Phase 5 item 9).
 *
 * Shaped after {@see EnvironmentBlock} — same directory, same
 * capture-then-render split — so
 * {@see \SugarCraft\Crush\Runtime::buildSystemPrompt()} folds it in the same
 * way it folds the environment and the instruction documents.
 *
 * Unlike `EnvironmentBlock`, whose capture freezes only cwd/model/timestamp
 * while its git section is polled per render, EVERYTHING here is frozen at
 * {@see capture()}: `render()` reads no filesystem at all. A note written
 * mid-turn therefore reaches the prompt on the next `Runtime`, not the next
 * step — asserted by
 * `tests/Integration/MemoryPromptWiringTest::testAFreshRuntimeSeesTheNewNote()`.
 *
 * WHY SCOPE, NOT SEARCH
 * --------------------
 * Phase 5 item 9 says to "run `MemoryStore::search()` against the current turn
 * and/or project-scope entries". Only the second half of that is implemented,
 * and the first half is not a simplification — it does not work.
 * {@see MemoryStore::search()} is a case-insensitive SUBSTRING match: it keeps
 * an entry when the query appears literally inside the entry's content, type or
 * one of its tags. Passing a whole user turn as the query therefore asks
 * "does this entire sentence appear verbatim inside a memory entry", which is
 * essentially never true. Recall built that way would be permanently and
 * silently empty — a wired feature that never fires, which is worse than an
 * unwired one because nothing looks broken.
 *
 * The alternative considered and rejected was tokenising the turn and searching
 * per term, ranking by how many terms hit. It was rejected on three grounds,
 * in increasing order of importance:
 *
 *   - Cost. `search()` globs `{memoryPath}/&#42;/&#42;.md` across EVERY scope and
 *     YAML-parses each file, per call. `buildSystemPrompt()` runs once per step
 *     of the agentic loop (up to `maxSteps`, default 8), so a per-term search
 *     would be terms x 8 full-store scans per turn.
 *   - Prompt caching, stated carefully because the obvious version of this
 *     argument is wrong here. Turn-varying content in the system prompt voids
 *     the cacheable prefix — but this prefix is ALREADY unstable in any session
 *     where the agent writes files: {@see EnvironmentBlock::render()} shells out
 *     to `git status --porcelain` on every call and sits AHEAD of this block, so
 *     the first edit of a session voids everything downstream of it anyway. That
 *     hazard is pinned, deliberately, by
 *     `tests/Providers/PromptStabilityTest::testEnvironmentBlockGitSnapshotIsLivePolledNotFrozenAtCapture()`.
 *     So the accurate claim is narrower: a query-dependent memory block would
 *     newly void the prefix in the read-only sessions where it is currently
 *     stable, and would add nothing further in the write-heavy ones. That makes
 *     caching a real but SECONDARY reason, not the decisive one.
 *   - Placement. This is the decisive one. The system prompt is where STANDING
 *     instructions live: it is what already carries root `AGENTS.md`/`CLAUDE.md`.
 *     Turn-dependent retrieval belongs in the turn, not in the preamble.
 *     Project-scope memory entries genuinely ARE standing project convention,
 *     which is what makes them belong here and makes a query unnecessary.
 *
 * So recall is {@see MemoryStore::list()} at {@see MemoryScope::Project}:
 * query-independent, one directory read instead of a whole-store glob, and
 * scope-authoritative — `list()` reads only that scope's directory AND re-checks
 * each entry's own `scope()` field, so nothing from another scope can leak in.
 *
 * WHAT IS DELIBERATELY NOT HERE
 * -----------------------------
 * User-scope and agent-scope entries. `MemoryScope::Project` is the only scope
 * folded in, because it is the only one whose meaning matches a block sitting
 * next to the project's instruction files. A user-scope block would be a
 * different decision with a different consequence — user memory follows the
 * operator across every project, so leaking it into a work repository's prompt
 * is a choice to make deliberately, not a scope this class should widen into by
 * accident. `/memory add --scope user` therefore still does not reach the
 * prompt; it remains a stored note reachable through `/memory list`.
 *
 * A NOTE ON THE FENCE NAME
 * ------------------------
 * `<project-memory>`, not `<project-instructions>`. The plan says "the same
 * `<project-instructions>`-STYLE block" and the style is what is copied, not
 * the tag: `<project-instructions>` means "a document the project's authors
 * checked in", while these are notes accreted at runtime by the user and the
 * agent. Filing them under the same tag would remove the model's ability to
 * weigh a curated convention differently from an accreted note.
 *
 * AS A PROMPT SECTION (P5.S2)
 * ---------------------------
 * This class implements {@see PromptSection} directly: `Runtime`'s memoized
 * `memorySnapshot()` IS the `<project-memory>` section, and the empty string
 * {@see render()} returns for a store with nothing to say is now the ONE place
 * the absence is expressed — the assembler's documented rule ("an absent layer
 * adds nothing") folds it away, replacing the `!== ''` guard this used to sit
 * behind. Same bytes, one fewer voice.
 */
final readonly class MemoryBlock implements PromptSection
{
    /**
     * Most notes rendered, newest first. Anything past this is dropped and the
     * block says so.
     *
     * Bounded because the fold happens on EVERY turn: the block is part of the
     * system prompt, so an unbounded one grows the prompt for the rest of the
     * session, and the context window and the compaction tiers are now real
     * (crush_code.md Phase 5 items 4/5) — an inflating prompt pushes a session
     * into automatic compaction sooner. Twelve is enough to carry a project's
     * standing conventions and small enough that the block cannot become the
     * largest thing in the prompt.
     */
    public const MAX_ENTRIES = 12;

    /**
     * Total budget for the rendered note lines, in BYTES.
     *
     * Bytes, not characters and emphatically not tokens: what this bound exists
     * to control is how much of the prompt the block occupies, and the only
     * figure this class can know exactly is its own byte length. The token cost
     * is whatever the provider counts it as — see {@see ContextWindow} for why
     * this codebase keeps estimated and provider-counted figures apart.
     *
     * DOMAIN, stated exactly because the first version of this docblock got it
     * wrong: the budget covers the summed RENDERED NOTE LINES — `- `, the
     * `[type]`, the content and the `(tags: …)` suffix all inside it, because
     * that is what {@see render()} measures with `strlen($line)`. Outside it:
     * the `<project-memory>` fence, the header sentence, and the newlines that
     * join the lines. Those three are fixed overhead a note cannot inflate,
     * which is why they are the ones left out.
     */
    public const MAX_BYTES = 4096;

    /**
     * Per-note ceiling for the WHOLE rendered line, in bytes, so one runaway
     * note cannot consume the {@see MAX_BYTES} budget and crowd out every
     * other note.
     *
     * Applied to the assembled line rather than to `content()` alone, which is
     * the fix for a hole the first version of this class shipped: it clipped
     * only the content, so an entry carrying a long `type` or many `tags`
     * rendered unbounded. Measured on one project entry with 400 tags: an
     * 11119-byte block against a 4096-byte budget, going out on every turn of
     * the session. Clipping the line bounds every field at once, whichever one
     * carries the bytes.
     *
     * `MAX_ENTRY_BYTES <= MAX_BYTES` is what makes {@see MAX_BYTES} a real
     * ceiling with no first-entry exemption — see {@see render()}. The relation
     * is asserted, not assumed.
     *
     * A note over this is TRUNCATED with a visible marker rather than dropped.
     * Dropping it would be tidier, but a note is dropped silently while a
     * truncation is something the model can see and discount — and a note
     * silently cut in half mid-sentence is the worst of the three, because
     * half an instruction can read as a whole one.
     */
    public const MAX_ENTRY_BYTES = 512;

    /**
     * Appended to a line cut at {@see MAX_ENTRY_BYTES}, and paid for out of
     * that same budget rather than added on top of it — otherwise the ceiling
     * would be `MAX_ENTRY_BYTES + strlen(marker)`, which is the kind of
     * off-by-a-constant that makes a documented bound false.
     */
    private const TRUNCATION_MARKER = ' […truncated]';

    /**
     * @param list<MemoryEntry> $entries already ordered newest-first and
     *                                   filtered to project scope by
     *                                   {@see capture()}
     */
    private function __construct(
        private array $entries,
    ) {}

    /**
     * Read the project-scope entries out of a store.
     *
     * A snapshot, exactly like {@see EnvironmentBlock::capture()}: the caller
     * takes one and reuses it, rather than this class re-reading the directory
     * once per step of the agentic loop.
     *
     * Newest-first by {@see MemoryEntry::modifiedAt()} because when the cap
     * bites, the note most recently written is the one most likely to still be
     * true — memory entries are edited in place, so modification time tracks
     * relevance better than creation time.
     *
     * Ties break on the entry id, and the credit that clause deserves is
     * narrower than the obvious one. Determinism WITHIN a machine comes for
     * free: `MemoryStore::list()` globs, `glob()` returns paths sorted, and PHP
     * 8's `usort` is stable, so equal timestamps already keep discovery order.
     * What the id tie-break adds is that the order follows the entry's own
     * identity rather than the FILENAME it was discovered under — normally the
     * same thing, since a file is named for its id, but not for a store whose
     * files were renamed or written by hand, which this store's markdown format
     * invites. Pinned by
     * `tests/Context/MemoryBlockTest::testTheIdTieBreakOutranksTheOnDiskDiscoveryOrder()`,
     * which is the only place the clause can be observed at all.
     */
    public static function capture(MemoryStore $store): self
    {
        $entries = $store->list(MemoryScope::Project);

        usort($entries, static function (MemoryEntry $a, MemoryEntry $b): int {
            return [$b->modifiedAt()->getTimestamp(), $a->id()]
                <=> [$a->modifiedAt()->getTimestamp(), $b->id()];
        });

        return new self(array_values($entries));
    }

    /** An explicitly empty block, for a session with no memory store at all. */
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * The project-scope notes this block was captured with, newest first and
     * before any cap is applied.
     *
     * @return list<MemoryEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * The fenced block, or the empty string when there is nothing to say.
     *
     * Empty string rather than an empty fence: a session with no project memory
     * must add nothing at all to the prompt, not an empty container the model
     * has to interpret.
     */
    public function render(): string
    {
        $rendered = [];
        $bytes = 0;

        foreach ($this->entries as $entry) {
            if (count($rendered) >= self::MAX_ENTRIES) {
                break;
            }

            $line = $this->renderEntry($entry);
            $lineBytes = strlen($line);

            // Checked before appending and with NO exemption for the first
            // note, which is what makes MAX_BYTES a ceiling rather than a
            // ceiling-plus-one-entry. The exemption used to be here to keep a
            // single oversized note from zeroing the block out; that job now
            // belongs to renderEntry()'s per-line clip, and because
            // MAX_ENTRY_BYTES <= MAX_BYTES the first note always fits, so the
            // exemption protected nothing and admitted an unbounded line.
            //
            // A note that does not fit ends the list: continuing to look for a
            // smaller one further down would reorder the newest-first guarantee
            // the header states.
            if ($bytes + $lineBytes > self::MAX_BYTES) {
                break;
            }

            $rendered[] = $line;
            $bytes += $lineBytes;
        }

        if ($rendered === []) {
            return '';
        }

        $omitted = count($this->entries) - count($rendered);

        // Every figure interpolated from the constant that enforces it, so the
        // sentence cannot go on claiming a limit the code stopped applying.
        // This is a promise made to the model INSIDE the prompt, so its domain
        // has to be the one render() actually bounds: whole listed lines, not
        // "note text" — an earlier wording said the latter while the code
        // measured the former, and an entry with many tags then exceeded the
        // stated total by 2.7x.
        $header = sprintf(
            'Notes recorded for this project across earlier sessions, most recently updated first. '
            . 'At most %d notes and %d bytes of listed notes are included, and any single note '
            . 'longer than %d bytes is shown truncated, so this list may be incomplete. These '
            . 'are notes the user or a previous session wrote down, not verified fact — treat '
            . 'them as project convention, and prefer what you can confirm in the repository '
            . 'itself.',
            self::MAX_ENTRIES,
            self::MAX_BYTES,
            self::MAX_ENTRY_BYTES,
        );

        if ($omitted > 0) {
            $header .= sprintf(' %d further note(s) were omitted by those limits.', $omitted);
        }

        return "<project-memory>\n" . $header . "\n\n" . implode("\n", $rendered) . "\n</project-memory>";
    }

    /**
     * The opening fence this block's body sits inside.
     *
     * {@see render()} emits BOTH ends itself (and returns the empty string for
     * an absent layer, fence and all), so this names the layer for metadata
     * only — the answer P5.S3's fence-escaping step reads when it asks
     * "escape against which fence?". The A NOTE ON THE FENCE NAME section
     * above is why the value is this tag and not `<project-instructions>`.
     */
    public function fence(): string
    {
        return '<project-memory>';
    }

    /**
     * Session-stable: {@see capture()} reads the project-scope store once and
     * `Runtime::memorySnapshot()` memoizes the block per Runtime, so a note
     * added mid-turn does not retroactively join a prompt already in flight —
     * the snapshot contract stated on that accessor.
     *
     * This is the tier P5.S1's inline wrapper already declared for this layer;
     * the migration restates it, it does not invent it.
     */
    public function stability(): Stability
    {
        return Stability::PerSession;
    }

    /**
     * Advisory ceiling; see {@see PromptSection::byteBudget()}.
     *
     * PHP_INT_MAX because no ceiling is enforced at the assembler, and this
     * block's real bounds are the per-entry caps {@see render()} applies
     * ({@see MAX_ENTRIES}, {@see MAX_BYTES}, {@see MAX_ENTRY_BYTES}) — the
     * {@see MAX_BYTES} docblock is explicit that the fence, header and joining
     * newlines sit OUTSIDE that budget, so no single constant here is a
     * whole-section ceiling to promote. Wiring one would pre-empt the
     * compaction tiers' decision; every production section reports this same
     * value until then (pinned by
     * {@see \SugarCraft\Crush\Tests\Context\PromptSectionTest::testTheProductionSectionListOrdersBaseFirstAndEnvLast()}).
     */
    public function byteBudget(): int
    {
        return \PHP_INT_MAX;
    }

    /**
     * One note as a single line: `- [type] content (tags: a, b)`.
     *
     * Collapsed to one line because the block is a list and a multi-line note
     * would make the next "- " ambiguous about whether it starts a new note.
     *
     * Clipped ONCE, at the end, over the assembled line. Clipping `content()`
     * on its way in would bound only the field the notes usually carry their
     * bytes in and leave `type()` and `tags()` free — and `tags()` is a list, so
     * it is the field that scales without limit. Entries are hand-editable
     * markdown by design, and `ForeignMemoryImporter` writes tags from another
     * tool's files, so "no writer sets many tags today" is not a bound.
     */
    private function renderEntry(MemoryEntry $entry): string
    {
        $line = '- [' . $this->oneLine($entry->type()) . '] ' . $this->oneLine($entry->content());

        $tags = array_values(array_filter(array_map(
            fn(string $tag): string => $this->oneLine($tag),
            $entry->tags(),
        ), static fn(string $tag): bool => $tag !== ''));

        if ($tags !== []) {
            $line .= ' (tags: ' . implode(', ', $tags) . ')';
        }

        return $this->clip($line);
    }

    /** Every run of whitespace — newlines included — collapsed to one space. */
    private function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Cut to {@see MAX_ENTRY_BYTES} INCLUDING the marker, without splitting a
     * UTF-8 character.
     *
     * `mb_strcut()` rather than `substr()` because the budget is in bytes but
     * the cut must land on a character boundary: a bare `substr()` can leave a
     * partial multi-byte sequence, and invalid UTF-8 in the system prompt does
     * not degrade gracefully — `json_encode()` refuses it, which would fail the
     * whole provider request rather than mangling one note. `mb_strcut()` is
     * the one function that takes a byte budget and respects the boundary; it
     * is already used elsewhere in this library for the same reason.
     */
    private function clip(string $text): string
    {
        if (strlen($text) <= self::MAX_ENTRY_BYTES) {
            return $text;
        }

        // The marker comes out of the budget, not on top of it: a cut line is
        // at most MAX_ENTRY_BYTES bytes, marker included.
        $room = self::MAX_ENTRY_BYTES - strlen(self::TRUNCATION_MARKER);

        return rtrim(mb_strcut($text, 0, $room, 'UTF-8')) . self::TRUNCATION_MARKER;
    }
}
