<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context;

use Closure;
use InvalidArgumentException;
use SugarCraft\Crush\Context\Triggers\PathTrigger;

/**
 * Session-lifetime tracker that turns a rule's `paths:` frontmatter into a live
 * auto-scoping signal (prompt_plan.md §1.10 escalation item (4), P6.S5b).
 *
 * Until now `paths:` was parsed into a {@see PathTrigger} by
 * {@see Rule::buildTriggers()} and read by nobody: {@see Runtime}'s splice
 * rendered every rule into every session, so a rule scoped to `src/**`
 * instructed a session that never touched `src/`. This class is the half that
 * decides WHEN a scoped rule reaches the model, and `Runtime`'s splice now
 * defers exactly the rules this class claims, through the one shared predicate
 * {@see isPathScoped()} — one named gate, so the two can never disagree about
 * which rules are path-scoped and a rule is either spliced or nudged, never
 * both, never neither.
 *
 * SHAPE: the user ruled "byte-bounded whole bodies with deferral plus a pointer
 * tail", and named {@see \SugarCraft\Crush\Support\HookContextFiles} as the
 * ancestor — bounded model-visible bytes with a pointer that says where the
 * whole lives. The transplant keeps the two halves of that shape and drops the
 * one thing that makes a rule different from a log line: a truncated rule is a
 * half-instructed model, which {@see RuleLoader}'s own doc-block refuses whole,
 * so an entry here is INDIVISIBLE. A rule that fits the remaining budget is
 * delivered complete; one that does not is delivered as exactly one pointer
 * line, `Rule 'x' deferred: budget. Read /abs/path` — and never as the first
 * N bytes of its body. Nothing silently vanishes: every matched rule either
 * arrives whole, arrives pointed at, or is counted in the trailing
 * "announce on a later call" note.
 *
 * BUDGETS ARE PRICED HONESTLY AGAINST THE SHIPPED CALL SITES, and the honest
 * answer is that deferral is the COMMON case, not the edge. Grep and Glob spend
 * `intdiv(65_536, 8)` = 8,192 bytes on a nudge; Read spends
 * `intdiv(1_048_576, 8)` = 131,072; Edit and Write pass no budget at all and so
 * get this class's own ceiling. A rule file may hold
 * {@see RuleLoader::MAX_FILE_BYTES} = 65,536 raw bytes, which after
 * `PromptFence::escape()` is more still, so a large rule can NEVER fit a Grep or
 * Glob call at any entry count. That is by design: the budget protects the
 * common case (a short rule the model should simply have) and the pointer serves
 * the long tail (a rule it can go and Read).
 *
 * WHY THIS CEILING BOUNDS EVEN THE POINTER. The envelope arithmetic in
 * {@see maxBytes()} has to be a true statement, and a pointer line carries two
 * unbounded pieces of repository text — the rule's `name:` and its absolute
 * path — so {@see pointer()} holds that line to {@see MAX_POINTER_BYTES} by
 * construction: the path survives whole in every realistic case, the name is a
 * label and clips first, and a path so long it cannot fit alone clips the line
 * with a visible marker rather than breaking the ceiling. A clipped pointer is
 * degraded prose; a clipped BODY would be a different rule.
 *
 * The tracker holds no filesystem handle and walks nothing itself: the rules
 * arrive either as a fixed list or from a loader closure the caller owns, and
 * matching is pure string work against compiled globs. It is deliberately
 * invisible to the read-sink census over `src/` for the reason
 * {@see PathTrigger} gives.
 *
 * WHY THE SHIPPED TRACKER RE-READS THE RULES (audit 15d-20). The splice walks
 * {@see RuleLoader::load()} afresh on EVERY prompt build and skips every rule
 * {@see isPathScoped()} claims, on the promise that this channel delivers it. A
 * tracker handed one boot-time walk breaks that promise three ways the moment a
 * rule file changes under a running session: a scoped rule written mid-session
 * (the agent can write `.sugar-crush/rules/`) is in neither channel until a
 * restart; an edited scoped rule keeps announcing the text read at launch; and a
 * rule whose `paths:` was removed is spliced as standing AND still nudged. So the
 * boot path builds this class through {@see fromLoader()}, and every
 * {@see forPaths()} consult rebuilds the candidate list from the same entry point
 * the splice calls — both channels then judge the same walk, whatever order the
 * prompt build and the tool call happen in. The walk is the price: one `load()`
 * per path-resolving tool call, which re-reads the rule tiers (a handful of small
 * files in practice, capped by the loader's own file and byte bounds) and is
 * small beside the Read, Grep or Glob it rides on. A cheaper stat fingerprint
 * would need this class to know the loader's directory list, which would be a
 * second copy of the tier layout free to drift from the first.
 *
 * WHAT THE LEDGER REMEMBERS ACROSS A RELOAD. Marks stay keyed by
 * {@see Rule::$path}, but a mark now records WHICH text was delivered (a digest
 * of the name line and body {@see entry()} renders). A re-walk that returns the
 * same bytes is not an edit and stays silent; a rule whose body or name changed
 * since its announcement is delivered again, with its current text, on the next
 * matching touch — the model was told the old instructions and the splice will
 * never tell it the new ones. A change to `paths:` alone re-announces nothing: the
 * instructions the model holds are still the current ones.
 *
 * THE DISABLE GATE (follow-up (a) to P6.S5b). A rulebook pack the operator turned
 * off — in the `disabledRules` config key or with the session-scoped `/rules`
 * toggle — is subtracted from the splice by {@see RulesState::effectiveRule()}
 * inside {@see RuleLoader::load()}. This channel was originally handed nothing but
 * that `load()` output, so a pack the user had just silenced kept speaking through
 * its `paths:` rules at tool time: the toggle reached the standing prompt, the
 * `/rules` listing, and nowhere else. It now holds the SAME {@see RulesState} and
 * asks the SAME question of every rule, through {@see enabledForThisSession()},
 * which delegates to `effectiveRule()` rather than re-spelling the conjunction — so
 * there is still exactly one place in the tree that decides whether a rule is on.
 * Two consequences are worth naming because both are the point: a disabled pack is
 * as invisible on this channel as on the splice, and a PROJECT-tier rule whose
 * filename collides with a silenced pack name is still delivered here, because the
 * tier guard lives inside `effectiveRule()` and is inherited rather than copied.
 *
 * WHY THE ANSWER IS TAKEN AT CONSULT TIME AND NOT AT CONSTRUCTION. `tools()` builds
 * this tracker once, at launch, and holds it for the life of the process, while a
 * `/rules` keystroke can land in any later turn. Filtering the candidate list in the
 * constructor would therefore freeze the boot-time answer and silence exactly the
 * case the toggle is for; the splice gets fresh arithmetic every turn because
 * `Runtime::systemPromptSections()` rebuilds its loader per prompt, and consulting
 * the set per rule here is what makes the two channels agree MID-session as well as
 * at launch. The cost is one `in_array` per still-pending candidate per tool call,
 * which the {@see hasPending()} short-circuit already bounds. The re-enable half
 * comes free with the same choice: a pack switched back on is deliverable again on
 * the next matching call, because it was never removed from the list — only refused.
 *
 * A TOGGLE NEVER REVISES THE ANNOUNCED SET. A mark is the record that the model
 * already received those bytes, either whole or as a pointer, and neither flipping a
 * pack off nor flipping it back rewrites what was said: a rule announced before it
 * was disabled stays silent after it is re-enabled. That is the same one-shot
 * semantics the budget rules give (a pointer counts as delivery), and it keeps the
 * fork round trip in {@see markAnnouncedPaths()} a pure union.
 *
 * This is a SugarCraft architecture type, not a port — charmbracelet/crush has
 * no rule-nudge symbol, so the repo's "Mirrors charmbracelet/…" convention does
 * not apply. Its shape is borrowed from
 * {@see \SugarCraft\Crush\Skills\SkillPathNudge}, which it deliberately does NOT
 * share a class with: one tracker per subject keeps a skill announcement and a
 * rule delivery from inheriting each other's budget.
 */
final class RulePathNudge
{
    /**
     * Rule paths already surfaced this session, keyed for O(1) re-touch checks.
     *
     * Keyed by {@see Rule::$path} and not by {@see Rule::$name}: `name:` is
     * display text taken from frontmatter, so two files in two tiers can carry
     * the same one, and `RuleLoader::load()` de-duplicates by `realpath()`. The
     * path is the identity that is provably unique in the list this tracker is
     * handed, and an identity collision here would retire a rule for the whole
     * session without ever naming it.
     *
     * The value is the {@see digest()} of the text the model received, so an
     * edited rule reads as unannounced again (audit 15d-20). `true` is a mark
     * whose text is unknown — what {@see markAnnouncedPaths()} receives from a
     * forked child, which reports paths only — and is bound to the current
     * digest on the next reload, so a later edit still re-announces.
     *
     * @var array<string, string|true>
     */
    private array $announced = [];

    /**
     * The rules this channel can announce: path-scoped, non-blank-bodied, keyed
     * by {@see Rule::$path}, first-seen wins (the loader's own de-dup order).
     *
     * Resolved by {@see adopt()} — once in the constructor for a fixed list, and
     * again on every consult for a tracker built {@see fromLoader()} — and
     * filtered by exactly the same predicate {@see hasPending()} and
     * {@see forPaths()} consult — the E72 lesson from the sibling skills tracker
     * is that a candidate one filter admits and the other refuses leaves
     * `hasPending()` true forever, so the steady state of a long session stops
     * short-circuiting.
     *
     * @var array<string, Rule>
     */
    private array $candidates = [];

    /**
     * {@see digest()} of each candidate, under the same key as {@see $candidates}.
     *
     * Computed when the list is adopted rather than per check, so the pending
     * guard of a long session stays one array walk.
     *
     * @var array<string, string>
     */
    private array $digests = [];

    /**
     * Re-produces the session's rule list on demand, or null for a fixed list.
     *
     * @var (Closure(): list<Rule>)|null
     */
    private readonly ?Closure $loader;

    /**
     * The session's rulebook toggle set, or null when nothing can be turned off.
     *
     * Held, not unwrapped into a name list, for the reason {@see RulesState} gives
     * for being mutable: one instance has two owners that are not in a call chain
     * with each other, and a copy taken here would be a snapshot of the operator's
     * intent at launch rather than the intent of the current turn. Null is the
     * pre-P6.S3 shape of this class — every tracker built without a set delivers
     * exactly what it delivered before the gate existed.
     */
    private readonly ?RulesState $rulesState;

    /**
     * Opens every nudge. Named once so {@see maxBytes()} prices it.
     */
    private const HEADER = "<system-reminder>\n"
        . "These rules are scoped to paths you just touched. Follow them:\n";

    /** Closes every nudge. */
    private const FOOTER = "\n</system-reminder>";

    /**
     * The per-entry pricing unit of {@see maxBytes()}: the bytes that formula
     * bills for one line slot — a rule's name line plus its whole escaped body.
     *
     * It is NOT a gate on delivery: {@see forPaths()} prices each entry against
     * the REMAINING room — the caller budget capped at the ceiling below — so a
     * body priced over this constant ships whole while room lasts (~4,097 bytes
     * for a first entry), and the pointer branch triggers on room exhaustion,
     * never on this number. The gate rather than clip holds — the gate is the
     * room the loop checks, not this constant.
     */
    private const MAX_ENTRY_BYTES = 2048;

    /**
     * The most bytes ONE pointer line may occupy.
     *
     * Must stay at or under {@see MAX_ENTRY_BYTES}, because {@see maxBytes()}
     * prices every line of a nudge at the larger of the two. Asserted rather
     * than assumed in
     * {@see \SugarCraft\Crush\Tests\Context\RulePathNudgeTest}.
     */
    private const MAX_POINTER_BYTES = 1024;

    /**
     * The most lines — bodies and pointers TOGETHER — ONE nudge may carry.
     *
     * A count bound is required in addition to the byte bound for the reason
     * {@see \SugarCraft\Crush\Skills\SkillPathNudge} gives for its own: a single
     * `paths: ['**\/*.php']` rule matches every Read in a PHP tree, so without
     * a count ceiling the total grows linearly with the number of rules that
     * happen to claim the area.
     */
    private const MAX_ENTRIES = 2;

    /**
     * The two literal halves of a pointer line, split so {@see pointer()} can
     * budget the name and the path against each other instead of guessing at a
     * `sprintf()` width.
     */
    private const POINTER_HEAD = "Rule '";

    private const POINTER_TAIL = "' deferred: budget. Read ";

    /**
     * Ends a name — or, only for a path too long to fit alone, a whole pointer
     * line — that did not fit {@see MAX_POINTER_BYTES}.
     */
    private const CLIP_MARKER = ' [clipped]';

    /**
     * Counts the matched rules this call left unannounced.
     *
     * Worded "on a later call" because that is what happens: an unemitted rule
     * spends no mark, so the next matching tool call delivers it — unless the
     * rule body is simply too big for this channel, in which case the pointer
     * line is what arrives, on whichever call has room for a pointer.
     */
    private const DEFERRED_NOTE = '... [%d further path-scoped rule(s) matched; they announce on a later call.]';

    /**
     * The share of its own output cap a capped tool spends on the rule channel:
     * one eighth, the same share the skills channel takes.
     *
     * A separate constant rather than
     * {@see \SugarCraft\Crush\Skills\SkillPathNudge::CALLER_BUDGET_DIVISOR} on
     * purpose: the two channels have different ceilings, so the dial each of
     * them can afford is a different decision, and importing the skills dial
     * would move rule budgets whenever somebody retunes skill budgets. The
     * guard test prices each channel against the shipped caps independently.
     */
    public const CALLER_BUDGET_DIVISOR = 8;

    /**
     * @param list<Rule>      $rules       The session's loaded rule list, in loader order.
     * @param RulesState|null $rulesState  The session's rulebook toggle set, consulted
     *        per rule at match time through {@see enabledForThisSession()}. Null —
     *        what every caller predating this argument passes — means no pack is off.
     * @param (Closure(): list<Rule>)|null $loader Re-produces the rule list; when
     *        given, every {@see forPaths()} consult replaces $rules with its answer.
     *        Null keeps $rules fixed for the tracker's life.
     *
     * @throws InvalidArgumentException if the list holds a non-Rule element.
     */
    public function __construct(array $rules, ?RulesState $rulesState = null, ?Closure $loader = null)
    {
        $this->rulesState = $rulesState;
        $this->loader = $loader;
        $this->adopt($rules);
    }

    /**
     * Build a tracker over the rules the session already loaded.
     *
     * @param list<Rule>      $rules
     * @param RulesState|null $rulesState See the constructor.
     */
    public static function new(array $rules, ?RulesState $rulesState = null): self
    {
        return new self($rules, $rulesState);
    }

    /**
     * Build a tracker that re-reads the rule list on every consult — the shape the
     * boot path ships, so a rule added, edited or demoted mid-session is judged by
     * the same walk the splice makes (audit 15d-20; see the class doc-block).
     *
     * Nothing is loaded here: the first {@see forPaths()} call walks, and so does
     * every later one, which is what makes a construction-time walk redundant.
     *
     * @param Closure(): list<Rule> $loader     Typically `fn () => (new RuleLoader($root))->load()`.
     * @param RulesState|null       $rulesState See the constructor.
     */
    public static function fromLoader(Closure $loader, ?RulesState $rulesState = null): self
    {
        return new self([], $rulesState, $loader);
    }

    /**
     * Replace the candidate list with the announceable subset of $rules.
     *
     * @param array<mixed> $rules
     *
     * @throws InvalidArgumentException if the list holds a non-Rule element.
     */
    private function adopt(array $rules): void
    {
        $candidates = [];
        $digests = [];
        foreach ($rules as $rule) {
            if (!$rule instanceof Rule) {
                throw new InvalidArgumentException(sprintf(
                    'RulePathNudge expects a list of Rule instances, %s given.',
                    get_debug_type($rule),
                ));
            }

            // First-seen wins, as in the loader's own de-dup: a second rule under
            // a path already held could never be emitted (the ledger is keyed by
            // path), and two digests under one key would make the edit check
            // oscillate between them forever.
            if (!self::isPathScoped($rule) || trim($rule->body) === '' || isset($candidates[$rule->path])) {
                continue;
            }

            $candidates[$rule->path] = $rule;
            $digests[$rule->path] = self::digest($rule);
        }

        $this->candidates = $candidates;
        $this->digests = $digests;
    }

    /**
     * Re-run the loader, if there is one, and adopt what it returns.
     *
     * A `true` mark — announced by a forked child, text unknown — is bound to the
     * digest the walk finds now, which is the text the child almost certainly
     * read; leaving it `true` would exempt that rule from every later edit.
     */
    private function reload(): void
    {
        if ($this->loader === null) {
            return;
        }

        $rules = ($this->loader)();
        if (!is_array($rules)) {
            throw new InvalidArgumentException(sprintf(
                'RulePathNudge loader must return a list of Rule instances, %s given.',
                get_debug_type($rules),
            ));
        }

        $this->adopt($rules);

        foreach ($this->announced as $path => $mark) {
            if ($mark === true && isset($this->digests[$path])) {
                $this->announced[$path] = $this->digests[$path];
            }
        }
    }

    /**
     * Has the model already received THIS rule's current text?
     */
    private function isAnnounced(Rule $rule): bool
    {
        $mark = $this->announced[$rule->path] ?? null;

        return $mark === true || ($mark !== null && $mark === ($this->digests[$rule->path] ?? null));
    }

    /**
     * Identity of the text {@see entry()} would deliver: name and body, never the
     * triggers, so narrowing a rule's `paths:` does not repeat instructions the
     * model already holds.
     */
    private static function digest(Rule $rule): string
    {
        return hash('xxh128', $rule->name . "\0" . $rule->body);
    }

    /**
     * Is this rule still ON for this session, by the splice's own arithmetic?
     *
     * One line on purpose: the conjunction "frontmatter says enabled AND this
     * session has not turned the pack off AND only the user tier can be turned off"
     * is {@see RulesState::effectiveRule()}'s to know, and spelling it a second time
     * here is how a listing starts promising bytes the prompt does not deliver. The
     * identity it keys on is {@see Rule::$key} — the loader's pack name, nested
     * spellings included — so `/rules style/terse` and a config entry naming the
     * same pack gate this channel exactly as they gate `<user-rules>`.
     *
     * A rule whose own frontmatter says `enabled: false` is refused here once a set
     * is in scope, because that is what `effectiveRule()` reports; {@see
     * RuleLoader::load()} never emits one, so no production call site can observe
     * the difference from the null case.
     */
    private function enabledForThisSession(Rule $rule): bool
    {
        return $this->rulesState === null || $this->rulesState->effectiveRule($rule)->enabled;
    }

    /**
     * THE ONE GATE: does this rule carry a `paths:` trigger?
     *
     * `Runtime::systemPromptSections()` asks it to decide what to SKIP, and this
     * class asks it to decide what to announce, so the two halves of P6.S5b read
     * one predicate. The `instanceof` is load-bearing in the direction the
     * golden law pins: the question is "does this rule carry a PATH trigger",
     * never "does this rule carry a trigger". A rule with a `description:`
     * already carries an {@see \SugarCraft\Crush\Context\Triggers\IntentTrigger}
     * — including the fixture rule behind the frozen 8,278-byte golden system
     * prompt — so keying on any trigger would drop a standing rule out of
     * `<user-rules>` and move those bytes.
     */
    public static function isPathScoped(Rule $rule): bool
    {
        foreach ($rule->triggers as $trigger) {
            if ($trigger instanceof PathTrigger) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nudge text for a single touched path, or null when nothing new matches.
     *
     * $budget as in {@see forPaths()}.
     */
    public function forPath(string $path, ?int $budget = null): ?string
    {
        return $this->forPaths([$path], $budget);
    }

    /**
     * Nudge text for a batch of touched paths (Glob and Grep resolve many at
     * once), or null when no path-scoped rule newly matches.
     *
     * Never longer than {@see maxBytes()}, whatever the rule set holds, and
     * never longer than $budget when one is given. Null for $budget means "the
     * caller has no output cap to spend inside" — which is what Edit and Write
     * pass, mirroring the skills channel — and the class ceiling still applies.
     *
     * A budget too small for even one entry returns null and MARKS NOTHING, so a
     * rule is never retired for the session by a call that never named it.
     *
     * A rule whose pack this session turned off is not collected at all, so it
     * buys neither an entry nor a pointer nor a mark — which is what lets a pack
     * switched back on mid-session still be delivered on a later call.
     *
     * @param list<string> $paths
     */
    public function forPaths(array $paths, ?int $budget = null): ?string
    {
        if ($paths === []) {
            return null;
        }

        // Before the pending guard, not after: a rule written since the last
        // call is exactly what can turn a settled guard back into a pending one.
        $this->reload();

        if (!$this->hasPending()) {
            return null;
        }

        // Collected before anything is marked, because how many matched rules
        // did not fit is part of the result and cannot be known mid-loop.
        // Candidates are unique by path, so no rule can be collected twice.
        $pending = [];
        foreach ($this->candidates as $rule) {
            if ($this->isAnnounced($rule)) {
                continue;
            }

            if (!$this->enabledForThisSession($rule) || !self::matchesAny($rule, $paths)) {
                continue;
            }

            $pending[$rule->path] = $rule;
        }

        if ($pending === []) {
            return null;
        }

        $room = $budget === null ? self::maxBytes() : min($budget, self::maxBytes());
        // Priced at its worst case (PHP_INT_MAX digits), for the reason the
        // skills tracker prices it there: the count that fills `%d` is decided
        // by where this loop stops. +1 for the newline implode() puts before it.
        $noteReserve = strlen(sprintf(self::DEFERRED_NOTE, PHP_INT_MAX)) + 1;

        $used = strlen(self::HEADER) + strlen(self::FOOTER);
        $total = count($pending);
        $seen = 0;
        $lines = [];
        $emitted = [];

        foreach ($pending as $path => $rule) {
            ++$seen;
            if (count($lines) >= self::MAX_ENTRIES) {
                break;
            }

            $entry = self::entry($rule);
            $cost = strlen($entry) + ($lines === [] ? 0 : 1);
            // The last pending rule cannot leave a remainder, so it is the one
            // entry that does not have to pay for the note it would introduce.
            $reserve = $seen === $total ? 0 : $noteReserve;
            if ($used + $cost + $reserve <= $room) {
                $used += $cost;
                $lines[] = $entry;
                $emitted[] = $rule;

                continue;
            }

            // The body does not fit, so the body is NOT delivered — the ruling's
            // indivisibility, and the whole reason this branch exists. What
            // arrives instead is the pointer, and it pays for itself the same
            // way the body would have.
            $pointer = self::pointer($rule);
            $cost = strlen($pointer) + ($lines === [] ? 0 : 1);
            if ($used + $cost + $reserve > $room) {
                break;
            }

            $used += $cost;
            $lines[] = $pointer;
            $emitted[] = $rule;
        }

        if ($lines === []) {
            return null;
        }

        foreach ($emitted as $rule) {
            // Marked HERE and not during collection: only a line the model
            // actually receives may spend the one-shot mark. A DEFERRED rule is
            // marked too, because the ruling counts a pointer as delivery — the
            // model was told the rule exists and where to read it.
            $this->announced[$rule->path] = $this->digests[$rule->path];
        }

        $deferred = $total - count($lines);
        if ($deferred > 0) {
            $lines[] = sprintf(self::DEFERRED_NOTE, $deferred);
        }

        return self::HEADER . implode("\n", $lines) . self::FOOTER;
    }

    /**
     * The most bytes {@see forPaths()} can ever return.
     *
     * Priced from the parts rather than hardcoded, so changing any of them moves
     * this with it. Each of the {@see MAX_ENTRIES} line slots is priced at
     * {@see MAX_ENTRY_BYTES} — pointers tighter, at {@see MAX_POINTER_BYTES} —
     * and it is the room test on the running total that holds the ceiling.
     */
    public static function maxBytes(): int
    {
        return strlen(self::HEADER)
            + self::MAX_ENTRIES * (self::MAX_ENTRY_BYTES + 1)
            + strlen(sprintf(self::DEFERRED_NOTE, PHP_INT_MAX))
            + strlen(self::FOOTER);
    }

    /**
     * The smallest tool output cap whose {@see CALLER_BUDGET_DIVISOR} share can
     * still hold a whole worst-case nudge — margin exactly 1.0x.
     *
     * The number the shipped-cap guard is read against, derived rather than
     * remembered, exactly as
     * {@see \SugarCraft\Crush\Skills\SkillPathNudge::smallestUnclippedCallerCap()}
     * is for the skills channel.
     */
    public static function smallestUnclippedCallerCap(): int
    {
        return self::maxBytes() * self::CALLER_BUDGET_DIVISOR;
    }

    /**
     * Rule paths already surfaced this session, in announcement order.
     *
     * `strval` over the raw keys because PHP coerces a decimal-integer string
     * key to `int` on insertion, and a path is arbitrary repository text: a
     * rule file literally named `123.md` under a directory called `0` could
     * otherwise come back out of the merge on the far side of a fork as an int.
     *
     * @return list<string>
     */
    public function announcedPaths(): array
    {
        return array_map(strval(...), array_keys($this->announced));
    }

    /**
     * Union $paths into the announced set, so the announce-once rule survives a
     * fork.
     *
     * A tool run inside one of {@see \SugarCraft\Crush\Runtime}'s forked tool
     * children announces into the CHILD's copy of this tracker; without this the
     * mark dies with the child and the same rule is delivered twice. A union,
     * never a replacement: concurrent children report overlapping sets in no
     * defined order. See {@see \SugarCraft\Crush\Tools\CarriesSessionState}.
     *
     * A path already marked keeps its mark: a child reports the whole set it
     * inherited, so overwriting would let a stale inherited path erase the record
     * of which text the parent delivered. A new path is marked `true` (text
     * unknown) and bound on the next reload — see {@see $announced}.
     *
     * @param list<string|int> $paths
     */
    public function markAnnouncedPaths(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_string($path) || is_int($path)) {
                $path = (string) $path;
                if ($path !== '' && !isset($this->announced[$path])) {
                    $this->announced[$path] = true;
                }
            }
        }
    }

    /**
     * True while at least one announceable path-scoped rule is still unannounced.
     *
     * Guards the match loop so the steady state of a long session costs one array
     * walk instead of a glob match per rule per touched path — which for a Glob
     * handing over a whole match list is paths x patterns per call, forever.
     * The filter here MUST stay identical to the one {@see forPaths()} collects
     * with, or the guard never fires again (E72) — which now means BOTH of its
     * operands, the announce-once mark and {@see enabledForThisSession()}: a
     * disabled rule that this method counted as pending while the collection loop
     * refused it would hold the guard open for the rest of the session.
     */
    private function hasPending(): bool
    {
        foreach ($this->candidates as $rule) {
            if (!$this->isAnnounced($rule) && $this->enabledForThisSession($rule)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does this rule's `paths:` trigger admit any of the touched paths?
     *
     * Matched against exactly what the tool resolved — an absolute path — the
     * same operand {@see \SugarCraft\Crush\Skills\SkillPathNudge} is fed, because
     * {@see PathTrigger} is anchored and root-blind and a repo-relative glob
     * would otherwise answer about a string nobody wrote. A rule author writing a
     * directory-prefixed glob means the leading-`**`-tolerant dialect
     * {@see \SugarCraft\Crush\Util\PathGlob} documents; the P6.S5a ruling fixed
     * the dialect, not the spelling of any shipped rule.
     */
    private static function matchesAny(Rule $rule, array $paths): bool
    {
        foreach ($rule->triggers as $trigger) {
            if (!$trigger instanceof PathTrigger) {
                continue;
            }

            foreach ($paths as $path) {
                if ($trigger->matches($path)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * One delivered entry: the rule's name line and its WHOLE escaped body.
     *
     * There is no clip here and there must never be one; the caller checks this
     * fits before emitting it, and checks the pointer otherwise. Every byte of
     * it comes from the repository, so every byte of it goes through
     * {@see PromptFence::escape()} — the roster includes `system-reminder`, and
     * a rule body that could open its own reminder would be the platform's own
     * voice forged out of a cloned checkout.
     */
    private static function entry(Rule $rule): string
    {
        return '- ' . PromptFence::escape($rule->name) . "\n" . PromptFence::escape($rule->body);
    }

    /**
     * One pointer line, held to {@see MAX_POINTER_BYTES} by construction.
     *
     * The path is the payload — a pointer whose path is cut cannot be followed —
     * so the name takes the clip when the two together overflow, and the whole
     * line is clipped only in the case where the path alone cannot fit, which
     * needs an absolute path over a kilobyte.
     *
     * PUBLIC SINCE THE FU5 STANDING SPLICE BECAME THE CHANNEL'S SECOND CUSTOMER:
     * `Runtime::systemPromptSections()` prices every standing rule against a
     * per-build byte budget and calls THIS method for the rules that do not fit,
     * so `Rule '...' deferred: budget. Read ...` is spelled in exactly one place
     * and byte-identical prose across the two channels is structural rather than
     * a copy somebody must remember to keep copies of.
     */
    public static function pointer(Rule $rule): string
    {
        $name = PromptFence::escape($rule->name);
        $path = PromptFence::escape($rule->path);
        $line = self::POINTER_HEAD . $name . self::POINTER_TAIL . $path;

        if (strlen($line) <= self::MAX_POINTER_BYTES) {
            return $line;
        }

        $pathRoom = self::MAX_POINTER_BYTES
            - strlen(self::POINTER_HEAD)
            - strlen(self::POINTER_TAIL)
            - strlen($path);

        if ($pathRoom <= 0) {
            return mb_strcut($line, 0, self::MAX_POINTER_BYTES - strlen(self::CLIP_MARKER), 'UTF-8')
                . self::CLIP_MARKER;
        }

        if (strlen($name) > $pathRoom) {
            $name = mb_strcut($name, 0, max(0, $pathRoom - strlen(self::CLIP_MARKER)), 'UTF-8')
                . self::CLIP_MARKER;
        }

        return self::POINTER_HEAD . $name . self::POINTER_TAIL . $path;
    }

    /**
     * The most bytes {@see pointer()} can ever return — the ceiling the method
     * enforces by construction, exposed so the splice's standing-rule budget can
     * price a deferral fence against the real number instead of a copy of it.
     */
    public static function maxPointerBytes(): int
    {
        return self::MAX_POINTER_BYTES;
    }
}
