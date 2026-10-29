<?php

declare(strict_types=1);

namespace SugarCraft\Code\Context;

use InvalidArgumentException;

/**
 * The rule packs this SESSION has turned off, and the one place that decides
 * whether a rule is in the prompt.
 *
 * DESIGN SOURCE: prompt_plan.md Phase 6 P6.S3 (the rulebook ruling block) and
 * the step brief's requirement 5. This is a SugarCraft architecture type, not a
 * port - charmbracelet/crush has no `RulesState` symbol, so the repo's
 * "Mirrors charmbracelet/..." convention does not apply.
 *
 * WHAT A PACK IS NAMED BY. `~/.candy-code/rulebooks/*.md` is one file per pack
 * and the pack's identity is its filename minus the extension - the same string
 * {@see RuleLoader::ruleKeyFor()} derives for the tier walk and which
 * {@see Rule::$key} now carries. `/rules terse` therefore means the same thing
 * in the command, in this set, and in the loader's ordering, with no second
 * identity function anywhere to fall out of step with the first.
 *
 * MUTABLE, ON PURPOSE, AND HERE IS THE COST. Every value object in this
 * namespace is immutable and fluent ({@see Rule}), so a set that changes in
 * place is a deliberate exception and it needs a reason stronger than
 * convenience. The reason is that exactly one instance of this object has to be
 * reachable from two owners that are not in a call chain with each other:
 * {@see \SugarCraft\Code\Chat} writes it when `/rules` runs, and
 * {@see \SugarCraft\Code\Backend\EngineBackend} reads it when it builds the
 * per-turn {@see \SugarCraft\Code\App\App} that
 * {@see \SugarCraft\Code\Runtime::buildSystemPrompt()} assembles off. A
 * `withDisabled()`-returns-a-copy setter would work only if every toggle were
 * pushed back into the backend as well, which means one command updating two
 * fields that can then disagree - and a disagreement here is silent, because the
 * symptom is a prompt that carries a pack the user just turned off. This is the
 * same shape the tree already accepts for one-instance-per-launch collaborators:
 * {@see \SugarCraft\Code\Agents\AgentManager} is mutated through
 * `register()`, {@see \SugarCraft\Code\Skills\SkillRegistry} through
 * `disableMultiple()`, and `Chat`'s live-event inbox is a shared
 * `\ArrayObject` for the identical two-owners reason. What is NOT mutable is the
 * {@see Rule} this object judges: the toggle never edits a rule, it edits a set
 * of names and then asks for a new Rule through
 * {@see Rule::withEnabled()} ({@see effectiveRule()}), so the parsed file stays
 * the immutable record of what is ON DISK while session intent lives here.
 *
 * SESSION-SCOPED, AND THAT IS THE PINNED ANSWER, NOT AN OMISSION. Nothing in
 * this class reads or writes `~/.candy-code/config.json`; there is no
 * `onConfigChange` call anywhere on the `/rules` path. The plan's done-when asked
 * for persistence "or explicitly does not, and a test pins which", and the test
 * that pins it asserts the config file is byte-identical across a toggle
 * (`RulesCommandTest::testTogglingAPackLeavesTheConfigFileByteIdentical()`).
 * P6.S4 has since landed, on the READ side and not in this class:
 * {@see \SugarCraft\Code\Cli\Bootstrap::chat()} seeds `new()` from the operator's
 * own `disabledRules`, so a pack named there does come back off after a restart —
 * by design, and from the user tier of the layered settings — `config.json` and
 * `settings.json` both — rather than from anything here. The sentence below
 * is therefore scoped to the TOGGLE path, which is the half this class owns: a
 * `/rules` switch stays session-only, and that is what keeps the two states a
 * restart can end in distinguishable — a pack that came back off on its own would
 * otherwise be indistinguishable from one the operator never re-enabled.
 *
 * THE INTERACTION RULE LIVES HERE AND NOWHERE ELSE. A pack is in the prompt when
 * its frontmatter says enabled AND this session has not turned it off; the
 * frontmatter wins, so an `enabled: false` pack stays off under a toggle that
 * would otherwise switch it on. That conjunction is computed in exactly one
 * method, {@see effectiveRule()}, because a second spelling of it - one in the
 * splice, one in the command listing - is how a listing starts promising bytes
 * the prompt does not deliver.
 *
 * SCOPE OF A TOGGLE. Only the user tier is toggleable
 * ({@see TOGGLEABLE_TIER}). `~/.candy-code/rules` and
 * `~/.candy-code/rulebooks` are both directories the operator of this machine
 * chose, so `/rules` may silence either; `<repoRoot>/.candy-code/rules` and
 * `<repoRoot>/RULES.md` are the repository's voice, and a session-scoped set of
 * names is not a place where a user grants or withholds a repository's authority.
 * The cost is symmetric and stated: a project rule whose filename collides with
 * a pack name is NOT silenced by `/rules <that name>`.
 *
 * THE SECOND SWITCH, AND THE ONE-OF-ONE LAW (C-CTX-4.e). Output styles are
 * rulebook-kind files walked from the two styles directories; a style is
 * addressed not by the disabled set but by {@see $activeStyle}, a single
 * nullable slot: AT MOST ONE style rides the prompt per session, selecting a
 * second replaces the first, and `off` clears the slot. The slot lives in this
 * class rather than in a directory scan because the splice and the listing must
 * read one stored fact, not re-derive it - the same reason the disabled set is
 * a field here. A style file that disables itself in frontmatter stays out
 * whichever name is selected: the frontmatter wins over session intent for every
 * file in this family, no exceptions. Styles deliberately bypass the disabled
 * set in both directions: `/rules` neither lists nor silences them (its listing
 * reads the four pack-family walk methods, and the styles walk is not one of
 * them), and a config-seeded `disabledRules` entry naming a style file cannot
 * substitute for `/output-style off` - the two
 * switches stay orthogonal so their answers cannot disagree.
 */
final class RulesState
{
    /**
     * The one tier a session may silence. Named rather than inlined in the
     * predicate so {@see effectiveRule()}'s guard reads as the decision it is.
     */
    private const TOGGLEABLE_TIER = 'user';

    /**
     * Pack identities turned off for this session, in the order they were
     * toggled (a listing reads them, so insertion order is the display order).
     *
     * @var list<string>
     */
    private array $disabled = [];

    /**
     * The ONE output style selected for this session by `/output-style`
     * (C-CTX-4.e), as the exact file-stem key {@see Rule::$key}; null when no
     * style is active. A field rather than derived state because the splice and
     * the listing must answer from one stored fact.
     */
    private ?string $activeStyle = null;

    /**
     * @param list<string> $disabled pack identities to start off; every entry is
     *        checked here so the set can be trusted after construction
     *        (parse-don't-validate at the boundary)
     *
     * @throws InvalidArgumentException on a blank or non-string entry
     */
    public static function new(array $disabled = []): self
    {
        $state = new self();

        foreach ($disabled as $pack) {
            $state->disable(self::parsePack($pack, 'RulesState::new()'));
        }

        return $state;
    }

    /**
     * Whether $pack is currently OFF for this session.
     */
    public function isDisabled(string $pack): bool
    {
        return in_array($pack, $this->disabled, true);
    }

    /**
     * Flip $pack and report the state it landed in: true means the pack is
     * ENABLED after the call, false means it is OFF.
     *
     * The return value is the whole point of the shape. `/rules <name>` is a
     * toggle, so the message the command prints has to say which way it went,
     * and a caller that read {@see isDisabled()} before and after would be
     * reconstructing the answer out of two calls that a future edit could
     * separate.
     */
    public function toggle(string $pack): bool
    {
        $pack = self::parsePack($pack, 'RulesState::toggle()');

        if ($this->isDisabled($pack)) {
            $this->disabled = array_values(array_filter(
                $this->disabled,
                static fn(string $known): bool => $known !== $pack,
            ));

            return true;
        }

        $this->disabled[] = $pack;

        return false;
    }

    /**
     * Turn $pack off without flipping anything that is already off - the
     * idempotent half of the API, present because {@see new()} seeds the set.
     */
    public function disable(string $pack): void
    {
        if (!$this->isDisabled($pack)) {
            $this->disabled[] = $pack;
        }
    }

    /**
     * Every pack off for this session, in the order they were turned off.
     *
     * @return list<string>
     */
    public function disabled(): array
    {
        return $this->disabled;
    }

    /**
     * The style selected for this session, or null when none is - the exact
     * key the splice compares against ({@see effectiveRule()}).
     */
    public function activeStyle(): ?string
    {
        return $this->activeStyle;
    }

    /**
     * Select one output style by key for this session (C-CTX-4.e). Calling
     * this again REPLACES the previous selection - the one-of-one law is a
     * property of the field shape, not of any check: there is a single slot, so
     * no second style can ride alongside the first, and clearing is
     * {@see clearStyle()} rather than a magic name.
     *
     * The command layer only ever selects a key it found on disk; this
     * validation is the boundary the headless callers share - parse-don't-validate,
     * same discipline as {@see disable()}.
     *
     * @throws InvalidArgumentException on a blank or non-string name
     */
    public function setStyle(string $style): void
    {
        $this->activeStyle = self::parseStyle($style, 'RulesState::setStyle()');
    }

    /**
     * Drop the session's style selection (`/output-style off`); selecting
     * another style replaces the slot in one act and never passes through here.
     */
    public function clearStyle(): void
    {
        $this->activeStyle = null;
    }

    /**
     * The rule as the prompt should see it.
     *
     * A style rule ({@see Rule::$style}, C-CTX-4.e) takes the branch this class
     * exists to centralise: it rides iff the file's own frontmatter is enabled
     * AND its key is the single active style - the frontmatter wins over
     * selection, an unselected style returns as its own disabled clone so both
     * switches spell the same answer, and the disabled set is never consulted
     * for a style.
     *
     * Everything else: unchanged when it is already off or outside the
     * toggleable tier, a disabled CLONE when this session turned its pack off.
     *
     * Returns a new instance through {@see Rule::withEnabled()} rather than
     * editing anything, so {@see RuleLoader::load()}'s dedup ledger and the
     * parsed record of the file on disk both stay untouched by session intent.
     */
    public function effectiveRule(Rule $rule): Rule
    {
        if ($rule->style) {
            if ($this->activeStyle !== null && $rule->key === $this->activeStyle) {
                return $rule;
            }

            return $rule->enabled ? $rule->withEnabled(false) : $rule;
        }

        if (!$rule->enabled || $rule->tier !== self::TOGGLEABLE_TIER || !$this->isDisabled($rule->key)) {
            return $rule;
        }

        return $rule->withEnabled(false);
    }

    /**
     * Accept only a usable pack name, and say which caller supplied the junk.
     *
     * @throws InvalidArgumentException
     */
    private static function parsePack(mixed $pack, string $cameFrom): string
    {
        if (!is_string($pack) || trim($pack) === '') {
            throw new InvalidArgumentException(sprintf(
                'A rule pack identity must be a non-blank string, %s given (%s).',
                get_debug_type($pack),
                $cameFrom,
            ));
        }

        return $pack;
    }

    /**
     * Accept only a usable style name, and say which caller supplied the junk.
     *
     * @throws InvalidArgumentException
     */
    private static function parseStyle(mixed $style, string $cameFrom): string
    {
        if (!is_string($style) || trim($style) === '') {
            throw new InvalidArgumentException(sprintf(
                'An output style name must be a non-blank string, %s given (%s).',
                get_debug_type($style),
                $cameFrom,
            ));
        }

        return $style;
    }
}
