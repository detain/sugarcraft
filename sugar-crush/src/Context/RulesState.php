<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context;

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
 * WHAT A PACK IS NAMED BY. `~/.sugar-crush/rulebooks/*.md` is one file per pack
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
 * {@see \SugarCraft\Crush\Chat} writes it when `/rules` runs, and
 * {@see \SugarCraft\Crush\Backend\EngineBackend} reads it when it builds the
 * per-turn {@see \SugarCraft\Crush\App\App} that
 * {@see \SugarCraft\Crush\Runtime::buildSystemPrompt()} assembles off. A
 * `withDisabled()`-returns-a-copy setter would work only if every toggle were
 * pushed back into the backend as well, which means one command updating two
 * fields that can then disagree - and a disagreement here is silent, because the
 * symptom is a prompt that carries a pack the user just turned off. This is the
 * same shape the tree already accepts for one-instance-per-launch collaborators:
 * {@see \SugarCraft\Crush\Agents\AgentManager} is mutated through
 * `register()`, {@see \SugarCraft\Crush\Skills\SkillRegistry} through
 * `disableMultiple()`, and `Chat`'s live-event inbox is a shared
 * `\ArrayObject` for the identical two-owners reason. What is NOT mutable is the
 * {@see Rule} this object judges: the toggle never edits a rule, it edits a set
 * of names and then asks for a new Rule through
 * {@see Rule::withEnabled()} ({@see effectiveRule()}), so the parsed file stays
 * the immutable record of what is ON DISK while session intent lives here.
 *
 * SESSION-SCOPED, AND THAT IS THE PINNED ANSWER, NOT AN OMISSION. Nothing in
 * this class reads or writes `~/.sugar-crush/config.json`; there is no
 * `onConfigChange` call anywhere on the `/rules` path. The plan's done-when asked
 * for persistence "or explicitly does not, and a test pins which", and the test
 * that pins it asserts the config file is byte-identical across a toggle
 * (`RulesCommandTest::testTogglingAPackLeavesTheConfigFileByteIdentical()`).
 * Persistence keys belong to P6.S4, and a pack that came back off after a
 * restart would otherwise be indistinguishable from one the operator never
 * re-enabled.
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
 * ({@see TOGGLEABLE_TIER}). `~/.sugar-crush/rules` and
 * `~/.sugar-crush/rulebooks` are both directories the operator of this machine
 * chose, so `/rules` may silence either; `<repoRoot>/.sugar-crush/rules` and
 * `<repoRoot>/RULES.md` are the repository's voice, and a session-scoped set of
 * names is not a place where a user grants or withholds a repository's authority.
 * The cost is symmetric and stated: a project rule whose filename collides with
 * a pack name is NOT silenced by `/rules <that name>`.
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
     * The rule as the prompt should see it: unchanged when it is already off or
     * outside the toggleable tier, a disabled CLONE when this session turned its
     * pack off.
     *
     * Returns a new instance through {@see Rule::withEnabled()} rather than
     * editing anything, so {@see RuleLoader::load()}'s dedup ledger and the
     * parsed record of the file on disk both stay untouched by session intent.
     */
    public function effectiveRule(Rule $rule): Rule
    {
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
}
