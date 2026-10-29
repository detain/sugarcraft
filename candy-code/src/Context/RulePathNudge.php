<?php

declare(strict_types=1);

namespace SugarCraft\Code\Context;

use Closure;
use InvalidArgumentException;
use SugarCraft\Code\Context\Triggers\KeywordTrigger;
use SugarCraft\Code\Context\Triggers\PathTrigger;
use SugarCraft\Code\Memory\MemoryEntry;

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
 * tail", and named {@see \SugarCraft\Code\Support\HookContextFiles} as the
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
 * (the agent can write `.candy-code/rules/`) is in neither channel until a
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
 * {@see \SugarCraft\Code\Skills\SkillPathNudge}, which it deliberately does NOT
 * share a class with: one tracker per subject keeps a skill announcement and a
 * rule delivery from inheriting each other's budget.
 *
 * STANDING INTENTS (C-CTX-3.f). A second subject rides the same seam: memory
 * notes of type `intent`, each carrying a normalised `when:` trigger —
 * `path:<glob>` (matched with {@see PathTrigger}) or `keyword:<word>[,...]`
 * (matched with {@see KeywordTrigger}, whole-word, against the same touched
 * paths the rules are matched against). The tracker never opens a store: it
 * holds a caller-supplied source closure — bound once at catalog build in
 * {@see \SugarCraft\Code\Tools\BuiltIn\MemoryTool::fromCatalog()}, the single
 * place the session's {@see \SugarCraft\Code\Memory\MemoryWriter} and this
 * tracker are both in hand — and re-walks it per consult exactly like the rule
 * loader, so an intent saved, edited or deleted mid-session is judged by the
 * current note. Intents announce in their OWN envelope under their own header,
 * after the rule envelope, spending the LEFTOVER of the same budget: rules
 * first, and the class ceiling stays a true statement about the whole return.
 * The one-shot ledger is shared but key-namespaced (`intent:` prefix) so an
 * intent mark can never collide with a repository path, an emitted intent is
 * never re-delivered on a later touch (once per intent per turn at most —
 * the session-lifetime rule mirrored), and the fork union in
 * {@see markAnnouncedPaths()} carries intent marks across children for free.
 * A digest of trigger + body means an edited intent re-announces its new text,
 * exactly like an edited rule. Tool-name triggers are refused at save
 * ({@see \SugarCraft\Code\Memory\MemoryEntry::normalizeWhen()}) rather than
 * accepted and silently inert: this seam sees paths, not calling tool names.
 * Because an intent body is model-written steering text, every byte of it
 * rides through {@see PromptFence::escape()} like a rule body, and the listing
 * in {@see \SugarCraft\Code\Tools\BuiltIn\MemoryTool} names each intent's
 * trigger so what steers the model stays visible and deletable by id.
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
     * Re-produces the session's intent notes on demand, or null while the
     * channel is unbound.
     *
     * Bound once at catalog build ({@see \SugarCraft\Code\Tools\BuiltIn\MemoryTool::fromCatalog()}),
     * never by the constructor: every other construction site — tests, forks,
     * trackers built before a memory writer exists — stays byte-identical, and
     * the closure keeps the walk on the consult-time clock the loader law (audit
     * 15d-20) demands: a note saved, edited or deleted mid-session is judged by
     * its current text on the next matching call.
     *
     * @var (Closure(): list<MemoryEntry>)|null
     */
    private ?Closure $intentSource = null;

    /**
     * The intents this channel can announce, keyed by note id (first-seen wins).
     *
     * Rebuilt by {@see reloadIntents()} from the bound source, filtered to
     * `intent`-typed notes with a non-blank normalised `when:` — the same shape
     * {@see hasPendingIntents()} and {@see collectIntents()} judge, per the E72
     * filter-equality law the candidate list follows.
     *
     * @var array<string, MemoryEntry>
     */
    private array $intents = [];

    /**
     * {@see intentDigest()} of each candidate intent, under the same key.
     *
     * @var array<string, string>
     */
    private array $intentDigests = [];

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
     * {@see \SugarCraft\Code\Tests\Context\RulePathNudgeTest}.
     */
    private const MAX_POINTER_BYTES = 1024;

    /**
     * The most lines — bodies and pointers TOGETHER — ONE nudge may carry.
     *
     * A count bound is required in addition to the byte bound for the reason
     * {@see \SugarCraft\Code\Skills\SkillPathNudge} gives for its own: a single
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
     * Namespace prefix for an intent's mark in the shared {@see $announced}
     * ledger. Repository paths are absolute (they start with `/`), so a marked
     * intent can never collide with a rule path, and the fork union in
     * {@see markAnnouncedPaths()} carries both kinds of mark in one array.
     */
    private const INTENT_MARK_PREFIX = 'intent:';

    /**
     * Opens an intent envelope. Its own header rather than {@see HEADER}
     * because the provenance differs — a rule is a rulebook file the operator
     * ships, an intent is a note the model saved earlier — and the model must
     * be able to tell them apart from the frame alone.
     */
    private const INTENT_HEADER = "<system-reminder>\n"
        . "These standing intents match paths you just touched. Follow them where they apply:\n";

    /**
     * Counts the matched intents this call left unannounced — the intent shape
     * of {@see DEFERRED_NOTE}, which it mirrors in wording and pricing.
     */
    private const INTENT_DEFERRED_NOTE = '... [%d further standing intent(s) matched; they announce on a later call.]';

    /**
     * The share of its own output cap a capped tool spends on the rule channel:
     * one eighth, the same share the skills channel takes.
     *
     * A separate constant rather than
     * {@see \SugarCraft\Code\Skills\SkillPathNudge::CALLER_BUDGET_DIVISOR} on
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
     * Bind (or unbind with null) the session's intent-note source.
     *
     * The one place the memory router and this tracker meet is the catalog
     * build — {@see \SugarCraft\Code\Tools\BuiltIn\MemoryTool::fromCatalog()}
     * holds both — so the bind is a launch-time one-shot there; every other
     * construction site ships the pre-C-CTX-3.f shape unchanged. Re-binding
     * clears the rebuilt lists (marks are kept: a note already delivered by the
     * previous source is not re-delivered just because the source changed),
     * and the next consult re-walks whatever the new source answers.
     *
     * @param (Closure(): list<MemoryEntry>)|null $source
     */
    public function bindIntentSource(?Closure $source): void
    {
        $this->intentSource = $source;
        $this->intents = [];
        $this->intentDigests = [];
    }

    /**
     * Whether standing intents have been bound on this tracker
     * (C-CTX-3.f). Introspection only — the launch wiring pin in
     * tests/Context/StandingIntentTest.php names the one place the bind must
     * happen (`MemoryTool::fromCatalog`); a tracker that never got a source
     * silently answers without intents, so "bound" must be observable.
     */
    public function intentSourceBound(): bool
    {
        return $this->intentSource !== null;
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
        // Intents first: the true-bind below consults both digest maps, and a
        // source that threw must not leave the rule walk half-applied behind a
        // re-bound ledger — reloadIntents() mutates only its own two arrays.
        $this->reloadIntents();

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

        foreach ($this->announced as $key => $mark) {
            if ($mark !== true) {
                continue;
            }

            // Rule marks are keyed by absolute path, intent marks by
            // INTENT_MARK_PREFIX + the note id — while intentDigests is keyed
            // by the RAW id. Without the strip, an intent mark could never
            // bind, and a child-reported intent would stay `true` (announced)
            // forever — silently disabling the re-announce-on-edit promise the
            // markAnnouncedPaths doc-block makes (C-CTX-3.f review MINOR-1).
            $digest = str_starts_with($key, self::INTENT_MARK_PREFIX)
                ? ($this->intentDigests[substr($key, strlen(self::INTENT_MARK_PREFIX))] ?? null)
                : ($this->digests[$key] ?? null);
            if ($digest !== null) {
                $this->announced[$key] = $digest;
            }
        }
    }

    /**
     * Re-walk the bound intent source and rebuild the candidate list.
     *
     * The same consult-time clock {@see reload()} gives the rules (audit
     * 15d-20): an intent saved mid-session is deliverable on the next matching
     * call, an edited one re-announces its new text, a deleted one simply stops
     * being a candidate. Notes failing the shape test — wrong type, blank
     * trigger, duplicate id — are refused at the rebuild so the three filters
     * ({@see hasPendingIntents()}, {@see collectIntents()} and this one) stay
     * byte-identical in what they admit, per the E72 lesson
     * {@see adopt()} records.
     */
    private function reloadIntents(): void
    {
        if ($this->intentSource === null) {
            $this->intents = [];
            $this->intentDigests = [];

            return;
        }

        $notes = ($this->intentSource)();
        if (!is_array($notes)) {
            throw new InvalidArgumentException(sprintf(
                'RulePathNudge intent source must return a list of MemoryEntry instances, %s given.',
                get_debug_type($notes),
            ));
        }

        $intents = [];
        $digests = [];
        foreach ($notes as $note) {
            if (!$note instanceof MemoryEntry
                || $note->type() !== 'intent'
                || trim($note->when()) === ''
                || isset($intents[$note->id()])
            ) {
                continue;
            }

            $intents[$note->id()] = $note;
            $digests[$note->id()] = self::intentDigest($note);
        }

        $this->intents = $intents;
        $this->intentDigests = $digests;
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
     * already carries an {@see \SugarCraft\Code\Context\Triggers\IntentTrigger}
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

        $rulesNote = $this->hasPending() ? $this->collectRules($paths, $budget) : null;

        // The intent envelope spends the LEFTOVER of the same budget, rules
        // first, so the ceiling above stays a true statement about the whole
        // return: an intent never displaces a rule the budget had already
        // priced, and a call with no rules matched spends its whole share on
        // intents.
        $room = ($budget === null ? self::maxBytes() : min($budget, self::maxBytes()))
            - ($rulesNote === null ? 0 : strlen($rulesNote));
        $intentNote = $room > 0 && $this->hasPendingIntents()
            ? $this->collectIntents($paths, $room)
            : null;

        if ($rulesNote === null && $intentNote === null) {
            return null;
        }

        return ($rulesNote ?? '') . ($intentNote ?? '');
    }

    /**
     * The rule half of {@see forPaths()} — collect, price, emit, mark.
     *
     * Moved verbatim from that method's body when standing intents joined the
     * seam, so a session with no bound intent source returns byte-identical
     * rule envelopes; the frozen budget and pointer tests are the proof.
     *
     * @param list<string> $paths
     */
    private function collectRules(array $paths, ?int $budget): ?string
    {
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
     * {@see \SugarCraft\Code\Skills\SkillPathNudge::smallestUnclippedCallerCap()}
     * is for the skills channel.
     */
    public static function smallestUnclippedCallerCap(): int
    {
        return self::maxBytes() * self::CALLER_BUDGET_DIVISOR;
    }

    /**
     * Keys of the announce ledger — rule paths already surfaced this session,
     * plus `intent:<note-id>` marks (C-CTX-3.f), in announcement order.
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
     * A tool run inside one of {@see \SugarCraft\Code\Runtime}'s forked tool
     * children announces into the CHILD's copy of this tracker; without this the
     * mark dies with the child and the same rule is delivered twice. A union,
     * never a replacement: concurrent children report overlapping sets in no
     * defined order. See {@see \SugarCraft\Code\Tools\CarriesSessionState}.
     *
     * A path already marked keeps its mark: a child reports the whole set it
     * inherited, so overwriting would let a stale inherited path erase the record
     * of which text the parent delivered. A new path is marked `true` (text
     * unknown) and bound on the next reload — see {@see $announced}.
     *
     * `intent:<note-id>` keys ride the same union: the child reports them, the
     * parent stores them `true`, and {@see reload()} binds them to the intent's
     * current digest, so an intent announced in a forked child is not announced
     * twice and an intent edited after the fork still re-announces.
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
     * True while at least one announceable intent is still unannounced.
     *
     * The intent shape of {@see hasPending()} and the same E72 contract: the
     * filter here must equal the one {@see collectIntents()} collects with,
     * modulo the path-match the guard exists to avoid. There is no enable gate
     * to mirror — a note the user deleted stops being a candidate at the
     * rebuild, and an intent whose trigger cannot even be constructed is
     * refused here and there alike.
     */
    private function hasPendingIntents(): bool
    {
        foreach ($this->intents as $id => $note) {
            if ($this->intentIsAnnounced($id) || self::intentTrigger($note) === null) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Has the model already received THIS intent's current text?
     *
     * The mark lives in the same {@see $announced} ledger as the rule marks,
     * under {@see INTENT_MARK_PREFIX} + note id; a `true` mark (reported by a
     * forked child, text unknown) counts as announced and is bound to the
     * current digest on the next reload, exactly like a rule's.
     */
    private function intentIsAnnounced(string $id): bool
    {
        $mark = $this->announced[self::INTENT_MARK_PREFIX . $id] ?? null;

        return $mark === true || ($mark !== null && $mark === ($this->intentDigests[$id] ?? null));
    }

    /**
     * The intent half of a consult: collect, price, emit, mark.
     *
     * Mirrors {@see collectRules()} with two differences by design. There is no
     * pointer branch: the rule pointer works because a rule file has an absolute
     * path the model can go and Read, and an intent note has no path worth
     * naming — a deferred intent simply waits for a later call, counted in the
     * trailing note. And entries are priced against $room, the leftover of the
     * same budget the rules already spent, so the pair never overflows the
     * ceiling {@see forPaths()} promises.
     *
     * @param list<string> $paths
     */
    private function collectIntents(array $paths, ?int $budget): ?string
    {
        $pending = [];
        foreach ($this->intents as $id => $note) {
            if ($this->intentIsAnnounced($id)) {
                continue;
            }

            if (self::intentTrigger($note) === null || !self::intentMatches($note, $paths)) {
                continue;
            }

            $pending[$id] = $note;
        }

        if ($pending === []) {
            return null;
        }

        $room = $budget === null ? self::maxBytes() : min($budget, self::maxBytes());
        $noteReserve = strlen(sprintf(self::INTENT_DEFERRED_NOTE, PHP_INT_MAX)) + 1;

        $used = strlen(self::INTENT_HEADER) + strlen(self::FOOTER);
        $total = count($pending);
        $seen = 0;
        $lines = [];
        $emitted = [];

        foreach ($pending as $id => $note) {
            ++$seen;
            if (count($lines) >= self::MAX_ENTRIES) {
                break;
            }

            $entry = self::intentEntry($note);
            $cost = strlen($entry) + ($lines === [] ? 0 : 1);
            $reserve = $seen === $total ? 0 : $noteReserve;
            // Same indivisibility as the rule channel — a half-delivered intent
            // is a half-steered model — and no pointer fallback, so an entry
            // that does not fit simply defers.
            if ($used + $cost + $reserve > $room) {
                break;
            }

            $used += $cost;
            $lines[] = $entry;
            $emitted[] = $id;
        }

        if ($lines === []) {
            return null;
        }

        foreach ($emitted as $id) {
            $this->announced[self::INTENT_MARK_PREFIX . $id] = $this->intentDigests[$id];
        }

        $deferred = $total - count($emitted);
        if ($deferred > 0) {
            $lines[] = sprintf(self::INTENT_DEFERRED_NOTE, $deferred);
        }

        return self::INTENT_HEADER . implode("\n", $lines) . self::FOOTER;
    }

    /**
     * Build the trigger a note's normalised `when:` names, or null when it
     * cannot be built.
     *
     * Saved intents are normalised at write ({@see MemoryEntry::normalizeWhen()})
     * and always well-formed, but the home store is plain YAML files the user
     * may hand-edit, so a `when: path:` with nothing after the prefix must not
     * be able to turn a tool call into an exception. The trigger is rebuilt per
     * consult along with the candidate — no state, no cross-note leak.
     */
    private static function intentTrigger(MemoryEntry $note): PathTrigger|KeywordTrigger|null
    {
        $glob = $note->whenPathGlob();
        if ($glob !== null) {
            try {
                return PathTrigger::new([$glob]);
            } catch (\Throwable) {
                return null;
            }
        }

        $words = $note->whenKeywords();
        if ($words === null) {
            return null;
        }

        try {
            return KeywordTrigger::new($words);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Does this intent's trigger admit any of the touched paths?
     *
     * Matched against exactly what the tools resolved — absolute paths, the
     * same operand {@see matchesAny()} uses, for the same anchoring reason. A
     * keyword intent whole-word-matches its words against those paths; that is
     * the honest reading of a keyword trigger at a seam that only ever sees
     * paths, and it is why a `tool:` trigger is refused at save rather than
     * accepted and silently inert here.
     *
     * @param list<string> $paths
     */
    private static function intentMatches(MemoryEntry $note, array $paths): bool
    {
        $trigger = self::intentTrigger($note);
        if ($trigger === null) {
            return false;
        }

        foreach ($paths as $path) {
            if ($trigger->matches($path)) {
                return true;
            }
        }

        return false;
    }
    /**
     * Identity of the text {@see intentEntry()} would deliver: trigger and
     * body, so editing either re-announces and merely matching a different path
     * does not.
     */
    private static function intentDigest(MemoryEntry $note): string
    {
        return hash('xxh128', $note->when() . "\0" . $note->content());
    }

    /**
     * One delivered intent: a label line naming the note id and its trigger,
     * and the WHOLE escaped body.
     *
     * The id rides because the steering must be traceable to the note that
     * carries it — the model (or the user) who wonders where an instruction
     * came from runs `Memory view <id>`, and the trigger rides because an
     * intent that fires for a reason the model can see is auditable in-context.
     * Every byte is repository text, so every byte is escaped, exactly as in
     * {@see entry()}: an intent is model-written, and a body that could open
     * its own reminder would forge the platform's voice out of a note file.
     */
    private static function intentEntry(MemoryEntry $note): string
    {
        return '- ' . PromptFence::escape('intent ' . $note->id() . ' (when: ' . $note->when() . ')')
            . "\n" . PromptFence::escape($note->content());
    }

    /**
     * Does this rule's `paths:` trigger admit any of the touched paths?
     *
     * Matched against exactly what the tool resolved — an absolute path — the
     * same operand {@see \SugarCraft\Code\Skills\SkillPathNudge} is fed, because
     * {@see PathTrigger} is anchored and root-blind and a repo-relative glob
     * would otherwise answer about a string nobody wrote. A rule author writing a
     * directory-prefixed glob means the leading-`**`-tolerant dialect
     * {@see \SugarCraft\Code\Util\PathGlob} documents; the P6.S5a ruling fixed
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
