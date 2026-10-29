<?php

declare(strict_types=1);

namespace SugarCraft\Code\Skills;

use SugarCraft\Code\Context\PromptFence;

/**
 * Fingerprints the live skill roster at session freeze and reports, each
 * turn, what `/skills reload` (or an in-place registry edit) has moved since
 * then (C-EXT-3.a; the roster sibling of
 * {@see \SugarCraft\Code\Context\InstructionDiffTracker()}).
 *
 * WHY THIS EXISTS. `/skills reload` swaps the discovered roster inside the
 * ONE registry object the engine, the workspace and every App share
 * ({@see SkillRegistry::replaceAll()}), so the change is live from the next
 * read — but the re-rendered listing says nothing about the move: the model
 * simply finds a different roster at the top of the prompt with no word of
 * what left or arrived, and after a compaction scrolls away even that trace.
 * This class is the announce channel: the roster as of the session
 * generation's first assembly is frozen here, and every turn a bounded delta
 * of it over the current registry rides the `<turn-context>` row
 * ({@see \SugarCraft\Code\TurnContextBlock::withSkillRosterDiff()}), exactly
 * where the instruction diff, the git state and every other volatile notice
 * already lives. The tracker adds nothing to the prompt prefix; the PerTurn
 * listing it annotates stays the roster's own live channel.
 *
 * THE BASELINE RIDES A MEMO SLOT, THE CAPTURE IS PARENT-SIDE. The tracker is
 * captured once per session-generation through
 * {@see \SugarCraft\Code\Runtime::skillRosterDiff()}'s memo slot, keyed by
 * the registry's identity, warmed during the parent's pre-fork assembly
 * ({@see \SugarCraft\Code\Runtime::primeSessionPrompt()} runs the whole
 * section walk in the parent, and the stamp sits beside the listing line it
 * mirrors). A refresh point forgets every slot together — the rebuilt
 * baseline then names the roster the rebuilt prompt actually carries. Because
 * {@see recheck()} is PURE (no pending state, no recorded measurement — a
 * name→hash map needs no stat walk, the deviation from the instruction
 * tracker's disk-facing design, disclosed in the lane report), a forked
 * child's recheck can never corrupt the parent's slot.
 *
 * DETERMINISTIC BY CONSTRUCTION. The same registry state renders the same
 * bytes on every call until it moves again — so an unchanged roster costs
 * one fingerprint walk and returns the empty string, and a delta neither
 * grows nor re-flows across steps of the same turn.
 *
 * BOUNDED, NEVER SILENT. At most {@see MAX_ROWS_PER_CLASS} rows per class
 * (adds, removals, changes) — the overflow names its count — and at most
 * {@see MAX_TOTAL_BYTES} of body under the header. A roster too large to show
 * fully says so instead of quietly dropping rows.
 *
 * ESCAPED AS DATA. Names and descriptions are repository-controlled text;
 * every payload goes through {@see PromptFence::escape()} before it is
 * rendered, and the row's own fence is neutralised at
 * {@see \SugarCraft\Code\TurnContextBlock::render()} like every other
 * payload there.
 *
 * POPULATION. The fingerprint covers exactly the roster the prompt listing
 * is built from: enabled ({@see SkillRegistry::all()}) AND auto-invocable
 * ({@see SkillRegistry::isAutoInvocable()}). A skill the operator disables,
 * or one whose `disable-model-invocation` the reload newly set (or cleared),
 * therefore appears as a removal (or an addition) — the model learns of every
 * move that changes what the listing would have said, and of nothing else.
 * Enabled-with-body skills keep their body channel and are NOT excluded here
 * the way `listForPrompt()` excludes them from the line roster: the body
 * bytes already moved the moment the toggle moved, and a roster delta that
 * hid a body-carrying skill would hide a reload's whole point.
 */
final class SkillRosterDiffTracker
{
    /**
     * First line of every non-empty section — pinned verbatim by the byte
     * stability family, {@see InstructionDiffTracker::HEADER}'s law for this
     * class.
     */
    public const HEADER = 'Skills moved during this session — the roster the launch discovered has changed; these rows name the adds, removals and edits:';

    /**
     * Per-class ceiling on rendered rows. An overflow class still gets one
     * line naming how many it hid, so the section can never shrink a real
     * delta into silence.
     */
    public const MAX_ROWS_PER_CLASS = 8;

    /**
     * Ceiling on the body bytes below {@see HEADER}. Row bodies are
     * single-line roster rows (bounded per line by
     * {@see SkillPromptLine::LISTING_MAX_BYTES}); the total keeps a swapped
     * giant bundle from spending the whole turn-context budget on itself.
     */
    public const MAX_TOTAL_BYTES = 4096;

    /**
     * The frozen generation: name => description hash, auto-invocable enabled
     * roster only, insertion order kept so rendering is stable.
     *
     * @var array<string, string>
     */
    private function __construct(
        private readonly SkillRegistry $registry,
        private readonly array $frozen,
    ) {}

    /**
     * Freeze the registry's current roster as the baseline.
     */
    public static function capture(SkillRegistry $registry): self
    {
        return new self($registry, self::fingerprint($registry));
    }

    /**
     * Frozen-vs-current delta, or '' when nothing has moved.
     *
     * Every recheck compares against the SAME frozen map, so a move shows up
     * on every turn from then until a refresh point re-freezes the baseline —
     * identical bytes each time, exactly like the instruction tracker's
     * replay law, without needing a recorded `pending` state to do it.
     */
    public function recheck(): string
    {
        $current = self::fingerprint($this->registry);

        $added = [];
        $changed = [];
        foreach ($current as $name => $hash) {
            if (!isset($this->frozen[$name])) {
                $added[] = $name;
            } elseif ($this->frozen[$name] !== $hash) {
                $changed[] = $name;
            }
        }
        $removed = [];
        foreach (array_keys($this->frozen) as $name) {
            if (!isset($current[$name])) {
                $removed[] = (string) $name;
            }
        }

        if ($added === [] && $removed === [] && $changed === []) {
            return '';
        }

        $rows = [];
        $rows[] = self::HEADER;
        foreach ([['added', $added], ['removed', $removed], ['changed', $changed]] as [$class, $names]) {
            foreach (array_slice($names, 0, self::MAX_ROWS_PER_CLASS) as $name) {
                $rows[] = $class === 'removed'
                    ? '- removed: ' . PromptFence::escape($name)
                    : self::rosterRow($class, $name);
            }
            $hidden = count($names) - self::MAX_ROWS_PER_CLASS;
            if ($hidden > 0) {
                $rows[] = '- ' . $class . ': +' . $hidden . ' more (not shown)';
            }
        }

        $body = '';
        foreach ($rows as $index => $row) {
            $candidate = $index === 0 ? $row : $body . "\n" . $row;
            if ($index > 0 && strlen($candidate) > self::MAX_TOTAL_BYTES) {
                $body .= "\n- ... (remainder omitted: " . (count($rows) - $index) . ' rows)';
                break;
            }
            $body = $candidate;
        }

        return $body;
    }

    /**
     * One add/change row: the class tag ahead of the same single-line roster
     * text the listing would print for that skill now — badge, display name
     * and (for `changed`) its NEW description, so the model can act on the
     * row without a re-read.
     */
    private function rosterRow(string $class, string $name): string
    {
        $skill = $this->registry->get($name);
        if (!$skill instanceof Skill) {
            // Fingerprinted above yet gone at render time — the registry is
            // mutable and a concurrent disable can land between the walks.
            // Say that instead of fabricating a row.
            return '- ' . $class . ': ' . PromptFence::escape($name) . ' (no live body to describe)';
        }

        $line = SkillPromptLine::render($skill, SkillPromptLine::LISTING_MAX_BYTES, withOrigin: true);

        return '- ' . $class . ': ' . (str_starts_with($line, '- ') ? substr($line, 2) : $line);
    }

    /**
     * name => description hash over the listing population (enabled AND
     * auto-invocable), insertion order preserved.
     *
     * @return array<string, string>
     */
    private static function fingerprint(SkillRegistry $registry): array
    {
        $map = [];
        foreach ($registry->all() as $skill) {
            if (!$registry->isAutoInvocable($skill->name)) {
                continue;
            }
            $map[$skill->name] = hash('xxh128', $skill->description);
        }

        return $map;
    }
}
