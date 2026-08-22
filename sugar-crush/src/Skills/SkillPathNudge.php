<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Skills;

/**
 * Session-lifetime tracker that turns a skill's `paths:` frontmatter into a
 * live auto-scoping signal (crush_feat.md section 7 E4).
 *
 * Before this, `paths:` was static metadata: {@see SkillRegistry::getForPaths()}
 * was correct and unit-tested but had no production caller, so a skill scoped
 * to `**\/*.php` never announced itself when the agent actually opened a PHP
 * file. Read/Edit/Glob now hand every path they resolve to this tracker, and it
 * emits a system-reminder-style nudge on the tool result — mirroring Claude
 * Code's "skills load on first read/edit inside that subdirectory".
 *
 * Deliberately kept OFF the hot path: matching is pure in-memory `fnmatch()`
 * against frontmatter already loaded at bootstrap — no filesystem scan of the
 * skills tree and no per-skill syscall on a tool call — and once every
 * path-scoped skill THE MODEL MAY INVOKE has been announced the tracker
 * short-circuits to null before matching at all, so a long session pays
 * nothing per tool call.
 *
 * WHAT THAT CLAUSE SAID: "once every path-scoped skill has been announced".
 * WHAT IS TRUE NOW: it is qualified, because unqualified it was false of any
 * tree holding a path-scoped skill with `disable-model-invocation: true`
 * (E72). {@see forPaths()} filters such a skill out and so can never announce
 * it, while {@see hasPending()} counted it as pending — so the guard stayed
 * true for the whole session and every tool call walked the registry and ran
 * `fnmatch()` per pattern per path, which for a {@see
 * \SugarCraft\Crush\Tools\BuiltIn\Glob} handing over a whole match list is
 * paths x patterns per call, forever. DRIVEN at ae30fee5: two consecutive
 * `forPath()` calls both returned null, `announced()` stayed empty, and
 * `hasPending()` was still true after both.
 * WHY THIS STILL EARNS ITS PLACE: the short-circuit is the only thing keeping
 * the steady state of a long session at one array walk instead of a glob match
 * per skill per tool call, so the claim is worth making — it just has to be
 * made over the set forPaths() can actually retire. The two predicates have to
 * agree about what "pending" means or the guard guards nothing.
 */
final class SkillPathNudge
{
    /**
     * Names already surfaced this session. The nudge fires on the FIRST touch
     * of a matching path only; repeating it on every subsequent Read would
     * burn context re-telling the model something it was already told.
     *
     * @var array<string, true>
     */
    private array $announced = [];

    /**
     * Opens every nudge. Named once so {@see maxBytes()} can price it rather
     * than restate it.
     */
    private const HEADER = "<system-reminder>\n"
        . "These skills are scoped to paths you just touched. Invoke one with the Skill tool if it applies:\n";

    /** Closes every nudge. */
    private const FOOTER = "\n</system-reminder>";

    /**
     * The most bytes ONE `- name: description` entry may occupy.
     *
     * A `description` is frontmatter written by whoever shipped the skill —
     * arbitrary repository text, not a name — and nothing upstream clips it:
     * {@see Skill::fromFile()} reads it whole. MEASURED on this class before
     * the bound, with every skill `paths:`-scoped and auto-invocable, one call
     * to {@see forPaths()} on a matching path:
     *
     *     1 skill  x    200-byte description  ->        345 bytes
     *    10 skills x  2,000-byte descriptions ->     20,253 bytes
     *    50 skills x 20,000-byte descriptions ->  1,000,773 bytes
     *   200 skills x 50,000-byte descriptions -> 10,002,823 bytes
     *
     * Linear in (matching skills x description length), and appended to a tool
     * result that had already been clipped to its own cap — so the cap was not
     * one. MEASURED end to end at the same commit, through
     * {@see \SugarCraft\Crush\Tools\BuiltIn\Grep} over a 30-file fixture:
     * cap 1,000 with 1 skill x 200 returned 1,334 bytes (1.3x), with
     * 5 x 5,000 returned 26,182 (26.2x) and with 20 x 20,000 returned 401,372
     * (401.4x). {@see \SugarCraft\Crush\Tools\BuiltIn\Read} at maxBytes 200
     * returned 400,406 bytes on the last of those — 2,002x.
     *
     * 300 bytes is about two sentences, which is what a `description` is FOR:
     * it is a trigger phrase the model matches a task against, not the skill's
     * body.
     */
    private const MAX_ENTRY_BYTES = 300;

    /**
     * The most entries ONE nudge may carry.
     *
     * TWO BOUNDS, for the reason
     * {@see \SugarCraft\Crush\Tools\Concerns\TruncatesOutput::instructionSection()}
     * needs two: a per-entry byte clip alone still grows LINEARLY in the number
     * of matching skills, and a `paths: ['**\/*.php']` skill in each of a
     * hundred installed trees all match the same Read.
     *
     * The overflow is DEFERRED, not dropped: entries past this count — or past
     * what the caller's budget can hold — are left unannounced, so
     * {@see hasPending()} still reports them and the next tool call in the
     * session surfaces the next batch. Marking them announced here would
     * retire a skill for the whole session without ever having named it — the
     * same failure `instructionSection()` bounds its count BEFORE loading a
     * body to avoid.
     *
     * RAISING THIS MOVES {@see maxBytes()}, AND {@see maxBytes()} IS WHAT THE
     * SHIPPED TOOL BUDGETS ARE GUARDED AGAINST — so this constant is one of
     * the two dials that can red
     * {@see \SugarCraft\Crush\Tests\Integration\SkillPathScopingWiringTest::testEveryShippedNudgeBudgetClearsTheTrackerCeiling()}
     * without anyone touching a tool. MEASURED on this tree, PHP 8.3.6: at 8
     * the ceiling is 2,636 bytes against a Grep/Glob budget of 8,192, a 3.1x
     * margin; {@see largestEntryCountWithin()} answers the tipping point
     * exactly, and it is 26 — the first value that reds the guard is 27, where
     * the ceiling reaches 8,355. (The round-43 brief for E87 asserted that 20
     * would red it. It does not: 20 prices the ceiling at 6,248, comfortably
     * under 8,192. VERIFIED by mutation at 26bcdb42 — 20 and 26 green, 27 and
     * 40 red.)
     *
     * IF YOU REDDED THAT GUARD, here is the decided order to work through.
     * The guard firing is correct; what it is telling you is that the nudge
     * can now be CLIPPED inside a shipped tool's result.
     *
     *  1. Come back under the ceiling. `largestEntryCountWithin()` over the
     *     tightest shipped budget prints the largest count that fits. This is
     *     the default answer: the nudge is a pointer at a skill, not the
     *     skill, and a nudge that needs more than 26 entries is one the model
     *     will not read anyway.
     *  2. Raise the tool's DEFAULT output cap. That is a model-facing change —
     *     it moves what every Grep returns, not just a nudge — so it is its
     *     own item with its own measurement, never a side effect of this one.
     *  3. Lower {@see CALLER_BUDGET_DIVISOR}, spending a bigger share on the
     *     nudge. Also model-facing, and it takes the room out of the ANSWER,
     *     which is the trade E66 was about. Last resort.
     */
    private const MAX_ENTRIES = 8;

    /**
     * Ends an entry whose line did not fit {@see MAX_ENTRY_BYTES}.
     *
     * A clipped description that says nothing about being clipped reads as the
     * skill's whole trigger phrase, and the model then decides the skill does
     * not apply on half a sentence.
     */
    private const ENTRY_CLIP_MARKER = ' ... [clipped]';

    /**
     * Counts the entries {@see MAX_ENTRIES} held back on THIS call.
     *
     * Worded "on a later call" and not "dropped" because that is what happens:
     * they are still pending, and the next matching tool call announces them.
     */
    private const DEFERRED_NOTE = '... [%d further path-scoped skill(s) matched; they announce on a later call.]';

    public function __construct(private readonly SkillRegistry $registry) {}

    /**
     * Build a tracker over the session's one populated {@see SkillRegistry}.
     */
    public static function new(SkillRegistry $registry): self
    {
        return new self($registry);
    }

    /**
     * Nudge text for a single touched file, or null when nothing new matches.
     *
     * $budget as in {@see forPaths()}.
     */
    public function forPath(string $path, ?int $budget = null): ?string
    {
        return $this->forPaths([$path], $budget);
    }

    /**
     * Nudge text for a batch of touched files (Glob resolves many at once), or
     * null when no path-scoped skill newly matches.
     *
     * Never longer than {@see maxBytes()}, whatever the registry holds, and
     * never longer than $budget when one is given.
     *
     * $budget is how a caller with a byte cap of its own spends this INSIDE
     * that cap rather than beside it. Null means "no caller cap" — the class
     * ceiling still applies — and is what {@see \SugarCraft\Crush\Tools\BuiltIn\Edit}
     * and {@see \SugarCraft\Crush\Tools\BuiltIn\Write} pass, because their
     * result is a one-line success message with no cap to spend.
     *
     * A budget too small for even one entry returns null and MARKS NOTHING, so
     * the skill is not retired for the session by a call that never named it —
     * the same rule
     * {@see \SugarCraft\Crush\Tools\Concerns\TruncatesOutput::instructionSection()}
     * follows when its reserve cannot hold one body.
     *
     * @param list<string> $paths
     */
    public function forPaths(array $paths, ?int $budget = null): ?string
    {
        if ($paths === [] || !$this->hasPending()) {
            return null;
        }

        // Collected before anything is marked, because the COUNT of what did
        // not fit is part of the result and cannot be known mid-loop.
        $pending = [];
        foreach ($this->registry->getForPaths($paths) as $skill) {
            if (isset($this->announced[$skill->name]) || isset($pending[$skill->name])) {
                continue;
            }

            // A disable-model-invocation skill is user-invocable only; nudging
            // the model about a skill it is forbidden to call would just invite
            // a failed Skill tool call.
            if (!$this->registry->isAutoInvocable($skill->name)) {
                continue;
            }

            $pending[$skill->name] = $skill;
        }

        if ($pending === []) {
            return null;
        }

        $room = $budget === null ? self::maxBytes() : min($budget, self::maxBytes());
        // Priced at its worst case (PHP_INT_MAX digits) rather than at the
        // count, which is not yet known: where the loop stops decides the
        // count, and the count decides the note's length. +1 for the newline
        // implode() puts before it.
        $noteReserve = strlen(sprintf(self::DEFERRED_NOTE, PHP_INT_MAX)) + 1;

        $used = strlen(self::HEADER) + strlen(self::FOOTER);
        $total = count($pending);
        $seen = 0;
        $lines = [];
        $emitted = [];

        foreach ($pending as $name => $skill) {
            ++$seen;
            if (count($lines) >= self::MAX_ENTRIES) {
                break;
            }

            $entry = self::entry($skill);
            $cost = strlen($entry) + ($lines === [] ? 0 : 1);
            // The last pending skill cannot leave a remainder, so it is the one
            // entry that does not have to pay for the note it would introduce.
            $reserve = $seen === $total ? 0 : $noteReserve;
            if ($used + $cost + $reserve > $room) {
                break;
            }

            $used += $cost;
            $lines[] = $entry;
            $emitted[] = $name;
        }

        if ($lines === []) {
            return null;
        }

        foreach ($emitted as $name) {
            // Marked HERE and not during collection: only an entry the model
            // actually receives may spend the one-shot mark.
            $this->announced[$name] = true;
        }

        $deferred = $total - count($lines);
        if ($deferred > 0) {
            $lines[] = sprintf(self::DEFERRED_NOTE, $deferred);
        }

        return self::HEADER . implode("\n", $lines) . self::FOOTER;
    }

    /**
     * One `- name: description` line, held to {@see MAX_ENTRY_BYTES}.
     */
    private static function entry(Skill $skill): string
    {
        $line = "- {$skill->name}: {$skill->description}";
        if (strlen($line) <= self::MAX_ENTRY_BYTES) {
            return $line;
        }

        // mb_strcut and not substr, for the reason
        // {@see \SugarCraft\Crush\Tools\Concerns\TruncatesOutput::clipInstructions()}
        // gives: a description is arbitrary repository text, so a plain byte
        // cut lands inside a UTF-8 sequence and puts invalid bytes into a tool
        // result the model reads. There is no line-boundary fallback behind
        // this clip — an entry IS one line — so the byte cut is the only cut.
        return mb_strcut($line, 0, self::MAX_ENTRY_BYTES - strlen(self::ENTRY_CLIP_MARKER), 'UTF-8')
            . self::ENTRY_CLIP_MARKER;
    }

    /**
     * The share of its own output cap a tool spends on the nudge: one eighth.
     *
     * ONE CONSTANT AND NOT THREE LITERALS. {@see \SugarCraft\Crush\Tools\BuiltIn\Grep},
     * {@see \SugarCraft\Crush\Tools\BuiltIn\Glob} and
     * {@see \SugarCraft\Crush\Tools\BuiltIn\Read} each wrote `intdiv($cap, 8)`
     * and each carried a comment saying the others must agree with it. Three
     * copies of a number plus three notes asking them to stay equal is a
     * convention, not a relationship; a fourth caller would have inherited the
     * note and not the number.
     *
     * A SHARE, DELIBERATELY, AND NOT A MULTIPLE OF {@see maxBytes()}. Tying
     * the budget to the tracker's ceiling would make the relationship
     * structural — and would also hand a Grep constructed with
     * `maxOutputBytes: 1_000` a 2,636-byte nudge budget inside a 1,000-byte
     * result, which is E66 inverted. The nudge is spent INSIDE the caller's
     * cap; a caller that asks for a small result has to get a small nudge. So
     * the margin over the ceiling can only be a property of the DEFAULT caps,
     * and it is asserted rather than imposed — see
     * {@see smallestUnclippedCallerCap()}.
     */
    public const CALLER_BUDGET_DIVISOR = 8;

    /**
     * The smallest tool output cap whose {@see CALLER_BUDGET_DIVISOR} share
     * can still hold a whole worst-case nudge — margin exactly 1.0x.
     *
     * MEASURED on this tree, PHP 8.3.6: 21,088, against shipped caps of 65,536
     * (Grep, Glob) and 1,048,576 (Read) — margins of 3.1x and 49.7x. Both
     * figures are re-derived by
     * {@see \SugarCraft\Crush\Tests\Integration\SkillPathScopingWiringTest}
     * rather than written into it, so neither can rot in place.
     */
    public static function smallestUnclippedCallerCap(): int
    {
        return self::maxBytes() * self::CALLER_BUDGET_DIVISOR;
    }

    /**
     * How many entries a nudge of at most $budget bytes can carry.
     *
     * The inverse of {@see maxBytes()}, and the actionable half of the ceiling
     * guard: when a shipped budget stops clearing the ceiling, this is the
     * number {@see MAX_ENTRIES} has to come back to. Clamped at zero because a
     * budget below the fixed parts buys nothing rather than a negative count.
     */
    public static function largestEntryCountWithin(int $budget): int
    {
        $fixed = strlen(self::HEADER)
            + strlen(sprintf(self::DEFERRED_NOTE, PHP_INT_MAX))
            + strlen(self::FOOTER);

        return max(0, intdiv($budget - $fixed, self::MAX_ENTRY_BYTES + 1));
    }

    /**
     * The most bytes {@see forPaths()} can ever return.
     *
     * Priced from the parts rather than hardcoded, so a change to any of them
     * moves this with it. Callers that reserve room for a nudge before they
     * know whether one fires size the reserve with this; callers that compute
     * the nudge first use its actual length, which is what Grep and Glob do.
     */
    public static function maxBytes(): int
    {
        return strlen(self::HEADER)
            // +1 per entry for the "\n" implode() puts between them; the
            // deferred note is the (MAX_ENTRIES + 1)th line, and the newline
            // budgeted for the entry it replaces pays for its own separator.
            + self::MAX_ENTRIES * (self::MAX_ENTRY_BYTES + 1)
            + strlen(sprintf(self::DEFERRED_NOTE, PHP_INT_MAX))
            + strlen(self::FOOTER);
    }

    /**
     * Skill names already nudged this session, in announcement order.
     *
     * `strval` over the raw keys because PHP coerces a decimal-integer string
     * array key to `int` on insertion: a skill named `123` was stored as
     * `"123"` and would come back out of `array_keys()` as `int(123)`. See
     * {@see markAnnounced()} for what that costs on the far side.
     *
     * @return list<string>
     */
    public function announced(): array
    {
        return array_map(strval(...), array_keys($this->announced));
    }

    /**
     * Union $names into the announced set, so the "announce once" rule
     * survives a fork.
     *
     * A tool run inside one of {@see \SugarCraft\Crush\Runtime}'s forked tool
     * children announces into the CHILD's copy of this tracker; without this
     * the mark dies with the child and the same skill is re-announced on the
     * next call. A union, never a replacement: concurrent children report
     * overlapping sets in no defined order, each starting from a copy of this
     * tracker's own state. See {@see \SugarCraft\Crush\Tools\CarriesSessionState}.
     *
     * CAST, not a type filter: {@see announced()} is `array_keys()` over a
     * string-keyed array, and PHP coerces a decimal-integer string key to
     * `int` on the way IN — so a skill literally named `123` comes back as
     * `int(123)`, which an `is_string()` filter here would silently drop, and
     * that skill would then re-announce on every forked Read/Glob for the rest
     * of the session. Casting round-trips it to the same key it went in as.
     *
     * @param list<string|int> $names
     */
    public function markAnnounced(array $names): void
    {
        foreach ($names as $name) {
            if (is_string($name) || is_int($name)) {
                $name = (string) $name;
                if ($name !== '') {
                    $this->announced[$name] = true;
                }
            }
        }
    }

    /**
     * True while at least one enabled, auto-invocable path-scoped skill has yet
     * to be announced. Guards the match loop so the steady state of a long
     * session (everything announceable already announced) costs one array
     * walk, not a glob match per skill per tool call.
     *
     * "Auto-invocable" is load-bearing and not a refinement: see the class
     * doc-block for what it cost to leave it out.
     */
    private function hasPending(): bool
    {
        foreach ($this->registry->all() as $skill) {
            if ($skill->paths === [] || isset($this->announced[$skill->name])) {
                continue;
            }

            // The SAME filter {@see forPaths()} applies before it builds an
            // entry, and it has to be the same one: a skill this predicate
            // calls pending but forPaths() refuses to announce is a skill that
            // can never stop being pending, and the guard below it then never
            // fires again for the rest of the session (E72). Routed through
            // isAutoInvocable() rather than re-reading
            // $skill->disableModelInvocation here for the reason
            // {@see SkillRegistry::findForPrompt()} gives: one definition of
            // "the model may invoke this", so the two stay in lockstep.
            if (!$this->registry->isAutoInvocable($skill->name)) {
                continue;
            }

            return true;
        }

        return false;
    }
}
