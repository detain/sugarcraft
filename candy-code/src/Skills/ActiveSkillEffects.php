<?php

declare(strict_types=1);

namespace SugarCraft\Code\Skills;

use SugarCraft\Code\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Permissions\PermissionAction;
use SugarCraft\Code\Permissions\PermissionRule;
use SugarCraft\Code\Context\TurnContextBlock;
use SugarCraft\Code\Tools\BuiltIn\SkillTool;

/**
 * What the skills invoked THIS turn ask for: tool grants, a model and a
 * thinking tier — R-EXT-1 (C-EXT-2.b), honouring them as written.
 *
 * A SKILL'S `allowed-tools` was always parsed and carried; what it never had
 * was a reader. This is the reader, and the ruling the reader obeys is the
 * owner's: the list is honoured AS WRITTEN — a tool named by a skill that is
 * actually running this turn is pre-approved while the turn runs, exactly the
 * Claude Code behaviour the imported skills were written against. That is why
 * the built-ins were trimmed (R-EXT-1's second half): nothing gets a Bash
 * pre-approval it does not actually run commands with, and a name is no longer
 * decoration a skill ships to look scoped.
 *
 * "AS WRITTEN" answers WHAT a skill may ask for. It never answered WHO ASKED,
 * and that gap was MAJOR-6: a PROJECT-tier skill arrived with the repository
 * that was cloned, so honouring its pre-approvals unconditionally lets an
 * untrusted repository pre-approve its own commands — commit
 * `allowed-tools: Bash(make:*)`, invoke the skill, and every `make` call of
 * the turn stops being a question. Pre-approval IS one of the trust grants,
 * so the gate is that grant and not a new one: {@see derive()} takes
 * `bool $projectTierTrusted`, the same answer
 * {@see \SugarCraft\Code\Cli\Bootstrap::projectCommandShellIsTrusted()} gives
 * the launch, the trust dialog and {@see \SugarCraft\Code\Commands\CommandLoader}
 * for `.candy-code/commands`, and a project-tier skill's `allowed-tools`
 * contributes rules only while it is true. Project-tier BUNDLES are covered by
 * the same test — {@see ForeignSkillDiscovery} labels them `Project` origin —
 * and {@see Skill::$origin} defaults to `Project`, so a skill whose provenance
 * was never stamped is gated with the repository rather than excused by it.
 * There is no new prompt and no new notice for the refusal to pre-approve: the
 * untrusted case is exactly the question that would have been asked anyway.
 *
 * WHAT THE DOOR LEAVES OPEN, on purpose: skill DISCOVERY and the prompt
 * LISTING are untouched, so an untrusted project's skills stay visible and
 * invocable (that is R-EXT-1 as shipped, and what the re-check confirmed — the
 * plan's §6.1 row described a load-time gate that never existed). Nor does the
 * door soften anything: `disallowed-tools`, `model` and `effort` ride whatever
 * the tier, because a refusal cannot widen what a repository gets and a model
 * name is not a permission.
 *
 * THE SCOPE IS THE TURN. "Active" means invoked since the last real user
 * prompt: the slice this class walks ends at the newest non-turn-context
 * {@see UserMessage}, because the next human message is where the previous
 * turn's permissions die. A grant therefore never outlives the turn that
 * earned it, and a forked child needs no plumbing around it — re-derive from
 * the messages and the same answer falls out. THIS CLASS IS DELIBERATELY
 * STATELESS for that reason: no memo, no per-session store, nothing to leak
 * across turns or reset on compaction. {@see \SugarCraft\Code\Runtime::run()}
 * and {@see \SugarCraft\Code\Chat} each call {@see apply()} per step.
 *
 * HOW THE ANSWERS RIDE:
 *  - `allowed-tools` becomes `Allow` rules carried to
 *    {@see \SugarCraft\Code\Permissions\PermissionGate::withTurnGrants()},
 *    where they answer an `Ask` and nothing else — a turn grant cannot
 *    outrank a configured deny, Plan or the `rm -rf /` breaker any more than
 *    a session grant can. A PROJECT-tier skill's list contributes nothing at
 *    all until the project is trusted (see above).
 *  - `disallowed-tools` becomes `Deny` rules on the same carrier, refused
 *    BEFORE the mode speaks: a skill that says "no Bash this turn" means it
 *    even in a mode that would otherwise allow the call.
 *  - `model` and `effort` are read by the step loop and applied to the NEXT
 *    request, so the skill's first tool round still runs on the session's
 *    model and the round the body actually steers runs on the declared one.
 *    Both take the LATEST-invoked declaring skill: invokedIn() answers
 *    most-recent-first, and the first non-null wins, which is how the
 *    roadmap-4.1 preset ladder reads the same roster.
 *
 * WHAT DOES NOT RIDE: the grant is a PRE-APPROVAL of the listed tools, not a
 * capability set — a skill that lists `Read` does not hide `Edit`, it only
 * stops the question on `Read`. That is what the field's shape can say; a
 * skill that wants a tool refused says so through `disallowed-tools`.
 * (`context: fork` used to ride here too: it left the INERT map with
 * C-EXT-2.c, when {@see \SugarCraft\Code\Tools\BuiltIn\SkillTool} gained the
 * forked-run path.)
 *
 * A malformed entry is skipped exactly where {@see
 * \SugarCraft\Code\Cli\Bootstrap::permissionRules()} skips one: a pattern
 * the matcher cannot parse pre-approves nothing, and refuses nothing.
 */
final readonly class ActiveSkillEffects
{
    /**
     * @param list<PermissionRule> $allowRules `Allow`s from `allowed-tools`, as written
     * @param list<PermissionRule> $denyRules  `Deny`s from `disallowed-tools`, as written
     * @param string|null $model  the latest-invoked skill that declares one
     * @param string|null $effort the latest-invoked skill that declares one —
     *        null means NOTHING declared it, which is not the same as the old
     *        parsed default of `medium` (that trap is why `Skill::$effort` is
     *        nullable since this step).
     */
    private function __construct(
        public array $allowRules,
        public array $denyRules,
        public ?string $model,
        public ?string $effort,
    ) {
    }

    /**
     * Everything the skills active in $messages ask for, derived fresh.
     *
     * $projectTierTrusted is the caller's answer to "does this repository get
     * to make commands run unasked?" — {@see
     * \SugarCraft\Code\Cli\Bootstrap::projectCommandShellIsTrusted()} in
     * production, the launch's own bool in {@see \SugarCraft\Code\Chat}. It is
     * REQUIRED with no default so that a new call site cannot silently widen
     * the grant: the argument is the audit trail.
     *
     * @param iterable<\SugarCraft\Code\Messages\Message> $messages the turn's messages, oldest first
     */
    public static function derive(iterable $messages, ?SkillRegistry $registry, bool $projectTierTrusted): self
    {
        if ($registry === null) {
            return self::none();
        }

        $turn = self::turnMessages($messages);

        // The two channels the tree already has for "which skills ran",
        // merged in the order ReinjectionPlan merges them: the tool-call
        // trail first (most-recent-first, results proven not to have
        // failed), then the roster names carried by the newest turn-context
        // row that the trail does not already know — the compaction case,
        // where the calls themselves have been summarised away.
        $invoked = SkillTool::invokedIn($turn);
        $seen = [];
        foreach ($invoked as $entry) {
            $seen[$entry['name']] = true;
        }
        foreach (TurnContextBlock::invokedSkillsIn($turn) as $name) {
            if (!isset($seen[$name])) {
                $invoked[] = ['name' => $name, 'args' => ''];
                $seen[$name] = true;
            }
        }

        $allow = [];
        $deny = [];
        $model = null;
        $effort = null;
        foreach ($invoked as $entry) {
            $skill = self::resolve($registry, (string) $entry['name']);
            if ($skill === null) {
                continue; // a name the registry does not know pre-approves nothing
            }
            if (self::preApprovalsHonoured($skill, $projectTierTrusted)) {
                foreach (self::rulesFrom($skill->allowedTools, PermissionAction::Allow) as $rule) {
                    $allow[] = $rule;
                }
            }
            // Ungated on purpose: a refusal cannot widen what the repository
            // gets, and a model name is not a permission (class docblock).
            foreach (self::rulesFrom($skill->disallowedTools, PermissionAction::Deny) as $rule) {
                $deny[] = $rule;
            }
            $model ??= $skill->model;
            $effort ??= $skill->effort;
        }

        return new self($allow, $deny, $model, $effort);
    }

    /**
     * Derive, and carry the tool grants onto the hook chain's gate — the
     * SAME swap the permission-mode toggle performs (replace the
     * {@see PermissionGateHook} under its stable name so both live tool
     * paths, {@see \SugarCraft\Code\Runtime::gate()} and
     * {@see \SugarCraft\Code\Chat::gateToolCall()}, consult one gate).
     *
     * When nothing is active and the chain gate carries no grants, no swap
     * happens at all: the step runs on the launch's own gate, byte-identical
     * to before this class existed. When something WAS active and no longer
     * is, the grants are cleared rather than left behind.
     *
     * @param iterable<\SugarCraft\Code\Messages\Message> $messages
     */
    public static function apply(HookManager $hooks, iterable $messages, ?SkillRegistry $registry, bool $projectTierTrusted): self
    {
        $effects = self::derive($messages, $registry, $projectTierTrusted);

        $hook = $hooks->hook(HookEvent::PreToolUse->value, PermissionGateHook::NAME);
        if (!$hook instanceof PermissionGateHook) {
            return $effects; // no gate on the chain: nothing to carry onto
        }
        $gate = $hook->gate();
        if ($effects->isEmpty() && !$gate->hasTurnGrants()) {
            return $effects;
        }

        $hooks->register(new PermissionGateHook($gate->withTurnGrants($effects->allowRules, $effects->denyRules)));

        return $effects;
    }

    /**
     * The answer when no skill ran: no grants, no overrides.
     */
    public static function none(): self
    {
        return new self([], [], null, null);
    }

    public function isEmpty(): bool
    {
        return $this->allowRules === [] && $this->denyRules === []
            && $this->model === null && $this->effort === null;
    }

    /**
     * The turn's messages only: from just after the newest real user prompt
     * to the end. A turn-context row is not that boundary — it is injected
     * beside the prompt, not instead of it.
     *
     * @param iterable<mixed> $messages
     *
     * @return list<mixed>
     */
    private static function turnMessages(iterable $messages): array
    {
        $all = is_array($messages) ? array_values($messages) : iterator_to_array($messages, false);
        $turn = [];
        for ($i = count($all) - 1; $i >= 0; --$i) {
            $message = $all[$i];
            if ($message instanceof UserMessage && !TurnContextBlock::isTurnContext($message)) {
                break;
            }
            $turn[] = $message;
        }

        return array_reverse($turn);
    }

    /**
     * Registry key first, display-name leaf second — the same ladder
     * {@see SkillTool} walks when the model writes `composer-wizard` for a
     * skill registered under a bundle key.
     */
    private static function resolve(SkillRegistry $registry, string $name): ?Skill
    {
        $skill = $registry->get($name);
        if ($skill !== null) {
            return $skill;
        }
        foreach ($registry->all() as $candidate) {
            if ($candidate->displayName() === $name) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Whether an invoked skill's `allowed-tools` may pre-approve anything.
     *
     * Built-in and user tiers are files the operator wrote, so their lists are
     * honoured however the skill was invoked. The project tier is the
     * repository's own — gated on the same grant that lets a committed command
     * file shell out, because a pre-approval is that authority in frontmatter
     * form. The test is a WHITELIST of the operator's own tiers rather than
     * `!== Project` so that a future {@see SkillOrigin} case starts GATED: an
     * added tier must be admitted to the grant deliberately, which is the only
     * direction that cannot leak authority.
     */
    private static function preApprovalsHonoured(Skill $skill, bool $projectTierTrusted): bool
    {
        return $projectTierTrusted
            || $skill->origin === SkillOrigin::BuiltIn
            || $skill->origin === SkillOrigin::User;
    }

    /**
     * A skill's comma-folded tool list into matcher-ready rules, exactly as
     * written: each entry through the prefix dialect (`Bash(composer:*)`
     * becomes the native `Bash(composer *)` prefix form the gate already
     * speaks) and dropped when malformed, per the class docblock.
     *
     * @return list<PermissionRule>
     */
    private static function rulesFrom(?string $list, PermissionAction $action): array
    {
        if ($list === null || trim($list) === '') {
            return [];
        }

        $rules = [];
        foreach (explode(',', $list) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            $pattern = PermissionRule::fromClaudePrefixDialect($entry);
            if (PermissionRule::patternRejectionReason($pattern) !== null) {
                continue;
            }
            $rules[] = new PermissionRule($pattern, $action);
        }

        return $rules;
    }
}
