<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Skills;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Context\TurnContextBlock;
use SugarCraft\Code\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Messages\AssistantMessage;
use SugarCraft\Code\Messages\ToolResultMessage;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Skills\ActiveSkillEffects;
use SugarCraft\Code\Skills\Skill;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tools\ToolCall as ProviderToolCall;

/**
 * C-EXT-1/C-EXT-2.b (R-EXT-1): what a skill declares for the turn is derived
 * purely from the turn's own messages — no process-wide state, because a
 * forked child re-derives from the transcript it was handed.
 *
 * The derivation is the whole product here: the gate and the step loop only
 * consume what {@see ActiveSkillEffects::derive()} lists. So these pins are
 * about WHICH skills are in the turn (slice boundary, failed calls, asset
 * reads, the re-injected roster), how their tool lists become rules (as
 * written, Claude Code's own grammar, prefix dialect included), and which
 * model/effort wins when several skills spoke.
 *
 * @see ActiveSkillEffects
 */
final class ActiveSkillEffectsTest extends TestCase
{
    /**
     * A skill that declares everything: both tool lists, a model and an effort.
     */
    private static function wizard(string $name = 'php-wizard'): Skill
    {
        return new Skill(
            name: $name,
            description: 'guides PHP work',
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: 'Read, Grep',
            disallowedTools: 'WebFetch',
            model: 'opus-x',
            effort: 'high',
            context: 'thread',
            paths: [],
            content: 'body',
            sourcePath: '/nowhere/SKILL.md',
        );
    }

    /** A skill that declares nothing — invoking it must cost the turn nothing. */
    private static function plain(string $name = 'quiet'): Skill
    {
        return new Skill(
            name: $name,
            description: 'pure guidance',
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: null,
            disallowedTools: null,
            model: null,
            effort: null,
            context: 'thread',
            paths: [],
            content: 'body',
            sourcePath: '/nowhere/SKILL.md',
        );
    }

    private static function registry(Skill ...$skills): SkillRegistry
    {
        $registry = new SkillRegistry();
        $registry->register(array_values($skills));

        return $registry;
    }

    /**
     * @param list<array{name: string, args?: string, asset?: string}> $calls
     *
     * @return list<object>
     */
    private static function invocation(array $calls, bool $failLast = false): array
    {
        $messages = [new UserMessage('do the thing')];
        foreach ($calls as $i => $call) {
            $arguments = ['name' => $call['name']];
            if (isset($call['args'])) {
                $arguments['args'] = $call['args'];
            }
            if (isset($call['asset'])) {
                $arguments['asset'] = $call['asset'];
            }
            $id = 'call-' . $i;
            $failed = $failLast && $i === count($calls) - 1;
            $messages[] = new AssistantMessage('', [new ProviderToolCall($id, 'Skill', $arguments)]);
            $messages[] = new ToolResultMessage($id, $failed ? 'boom' : 'loaded', $failed);
        }

        return $messages;
    }

    public function testAnInvokedSkillFeedsItsToolListsModelAndEffort(): void
    {
        $effects = ActiveSkillEffects::derive(
            self::invocation([['name' => 'php-wizard', 'args' => 'audit this']]),
            self::registry(self::wizard()),
        );

        $this->assertFalse($effects->isEmpty());
        $this->assertSame('opus-x', $effects->model);
        $this->assertSame('high', $effects->effort);

        $allow = array_map(static fn ($rule) => $rule->pattern, $effects->allowRules);
        $deny = array_map(static fn ($rule) => $rule->pattern, $effects->denyRules);
        $this->assertSame(['Read', 'Grep'], $allow);
        $this->assertSame(['WebFetch'], $deny);
    }

    public function testASkillThatDeclaresNothingContributesNothing(): void
    {
        $effects = ActiveSkillEffects::derive(
            self::invocation([['name' => 'quiet']]),
            self::registry(self::plain()),
        );

        $this->assertTrue($effects->isEmpty());
        $this->assertNull($effects->model);
        $this->assertNull($effects->effort);
    }

    public function testTheTurnEndsAtTheNewestRealPrompt(): void
    {
        // The wizard ran, then the user spoke again: its grants died with the
        // turn. This is the clearing the ruling asks for — stateless by design.
        $messages = self::invocation([['name' => 'php-wizard']]);
        $messages[] = new UserMessage('now do something else');

        $this->assertTrue(ActiveSkillEffects::derive($messages, self::registry(self::wizard()))->isEmpty());
    }

    public function testATurnContextRowIsNotABoundary(): void
    {
        // Turn-context rows ride the wire as UserMessages but they are not the
        // user speaking; cutting the turn on one would erase the grants the
        // row itself is describing.
        $messages = self::invocation([['name' => 'php-wizard']]);
        $messages[] = TurnContextBlock::new()->withInvokedSkills(['php-wizard'])->message();

        $effects = ActiveSkillEffects::derive($messages, self::registry(self::wizard()));
        $this->assertSame('opus-x', $effects->model);
    }

    public function testAFailedSkillCallGrantsNothing(): void
    {
        // invokedIn() drops calls whose result errored — nothing was loaded,
        // so nothing was promised.
        $messages = self::invocation([['name' => 'php-wizard']], failLast: true);

        $this->assertTrue(ActiveSkillEffects::derive($messages, self::registry(self::wizard()))->isEmpty());
    }

    public function testAnAssetReadIsNotASkillInvocation(): void
    {
        $messages = self::invocation([['name' => 'php-wizard', 'asset' => 'scripts/one.sh']]);

        $this->assertTrue(ActiveSkillEffects::derive($messages, self::registry(self::wizard()))->isEmpty());
    }

    public function testTheReinjectedRosterCountsAsInvoked(): void
    {
        // After compaction the transcript's Skill calls may be gone; the
        // re-injection roster names survive, and the effects must too.
        $messages = [
            new UserMessage('continue'),
            TurnContextBlock::new()->withInvokedSkills(['php-wizard'])->message(),
        ];

        $effects = ActiveSkillEffects::derive($messages, self::registry(self::wizard()));
        $this->assertSame(['Read', 'Grep'], array_map(static fn ($r) => $r->pattern, $effects->allowRules));
        $this->assertSame('opus-x', $effects->model);
    }

    public function testAnUnknownNameIsSkippedAndTheKnownOnesStillFeed(): void
    {
        $messages = self::invocation([
            ['name' => 'ghost'],
            ['name' => 'php-wizard'],
        ]);

        $effects = ActiveSkillEffects::derive($messages, self::registry(self::wizard()));
        $this->assertSame(['Read', 'Grep'], array_map(static fn ($r) => $r->pattern, $effects->allowRules));
    }

    public function testANameGivenByItsDisplayLeafResolvesThroughTheRegistry(): void
    {
        // A bundle skill is keyed 'acme/php-wizard' but the model writes the
        // leaf; SkillTool accepts the leaf, so the effects must too or the
        // grant the model was promised never lands.
        $messages = self::invocation([['name' => 'php-wizard']]);

        $effects = ActiveSkillEffects::derive($messages, self::registry(self::wizard('acme/php-wizard')));
        $this->assertSame('opus-x', $effects->model);
        $this->assertSame(['Read', 'Grep'], array_map(static fn ($r) => $r->pattern, $effects->allowRules));
    }

    public function testThePrefixDialectRidesTheRulesAsTheGateDefinesIt(): void
    {
        $composer = new Skill(
            name: 'composer-wizard',
            description: 'drives composer',
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: 'Read, Grep, Bash(composer:*)',
            disallowedTools: null,
            model: null,
            effort: null,
            context: 'thread',
            paths: [],
            content: 'body',
            sourcePath: '/nowhere/SKILL.md',
        );

        $effects = ActiveSkillEffects::derive(
            self::invocation([['name' => 'composer-wizard']]),
            self::registry($composer),
        );

        // R-EXT-1 honours the list AS WRITTEN, and 'as written' includes
        // Claude Code's own dialect: `(prefix:*)` means the native
        // `(prefix *)` — the exact rewrite the configured-rule loader applies.
        $this->assertSame(
            ['Read', 'Grep', 'Bash(composer *)'],
            array_map(static fn ($r) => $r->pattern, $effects->allowRules),
        );
    }

    public function testAMalformedEntryIsSkippedAndTheRestOfTheListSurvives(): void
    {
        $broken = new Skill(
            name: 'broken',
            description: 'd',
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: 'Read, Write(',
            disallowedTools: null,
            model: null,
            effort: null,
            context: 'thread',
            paths: [],
            content: 'body',
            sourcePath: '/nowhere/SKILL.md',
        );

        $effects = ActiveSkillEffects::derive(
            self::invocation([['name' => 'broken']]),
            self::registry($broken),
        );

        $this->assertSame(['Read'], array_map(static fn ($r) => $r->pattern, $effects->allowRules));
    }

    public function testTheLatestDeclaringSkillWinsFieldByField(): void
    {
        $early = new Skill(
            name: 'early', description: 'd', userInvocable: true, disableModelInvocation: false,
            allowedTools: 'Read', disallowedTools: null, model: 'early-model', effort: 'low',
            context: 'thread', paths: [], content: 'b', sourcePath: '/nowhere/SKILL.md',
        );
        $late = new Skill(
            name: 'late', description: 'd', userInvocable: true, disableModelInvocation: false,
            allowedTools: 'Grep', disallowedTools: null, model: 'late-model', effort: null,
            context: 'thread', paths: [], content: 'b', sourcePath: '/nowhere/SKILL.md',
        );

        $effects = ActiveSkillEffects::derive(
            self::invocation([['name' => 'early'], ['name' => 'late']]),
            self::registry($early, $late),
        );

        // 'late' invoked after 'early': its model wins, but it declared no
        // effort, so 'early's low stands — each field takes the latest skill
        // that actually spoke about it. Both allows ride; grants accumulate.
        $this->assertSame('late-model', $effects->model);
        $this->assertSame('low', $effects->effort);
        $this->assertSame(['Grep', 'Read'], array_map(static fn ($r) => $r->pattern, $effects->allowRules));
    }

    public function testDeriveWithoutARegistryDerivesNothing(): void
    {
        // Defensive: the App seam always carries a registry, but a null one
        // must answer none() rather than reach for names it cannot resolve.
        $this->assertTrue(ActiveSkillEffects::derive(self::invocation([['name' => 'php-wizard']]), null)->isEmpty());
    }

    // ------------------------------------------------------------------
    // apply(): the swap discipline the gate seam shares with mode switches.
    // ------------------------------------------------------------------

    private function chain(HookManager $hooks): PermissionGate
    {
        $hook = $hooks->hook(HookEvent::PreToolUse->value, PermissionGateHook::NAME);
        $this->assertInstanceOf(PermissionGateHook::class, $hook);

        return $hook->gate();
    }

    public function testApplyCarriesTheEffectsOntoTheChainGateAndClearsThemAgain(): void
    {
        $hooks = new HookManager(new HookRegistry());
        $base = new PermissionGate(PermissionMode::Default);
        $hooks->register(new PermissionGateHook($base));

        $effects = ActiveSkillEffects::apply(
            $hooks,
            self::invocation([['name' => 'php-wizard']]),
            self::registry(self::wizard()),
        );
        $this->assertSame('opus-x', $effects->model);

        $carried = $this->chain($hooks);
        $this->assertNotSame($base, $carried, 'the swap must replace the chain hook, exactly like a mode switch');
        $this->assertCount(2, $carried->turnAllowRules());
        $this->assertCount(1, $carried->turnDenyRules());
        $this->assertTrue($carried->hasTurnGrants());

        // A later step whose turn has no skill calls must CLEAR the grants —
        // stale grants surviving into a fresh turn is the whole leak this
        // class exists to prevent.
        ActiveSkillEffects::apply($hooks, [new UserMessage('fresh turn')], self::registry(self::wizard()));
        $cleared = $this->chain($hooks);
        $this->assertTrue($cleared->hasTurnGrants() === false);
        $this->assertSame([], $cleared->turnAllowRules());
        $this->assertSame([], $cleared->turnDenyRules());
    }

    public function testApplyLeavesTheChainUntouchedWhenThereIsNothingToCarry(): void
    {
        $hooks = new HookManager(new HookRegistry());
        $base = new PermissionGate(PermissionMode::Default);
        $hooks->register(new PermissionGateHook($base));

        $effects = ActiveSkillEffects::apply($hooks, [new UserMessage('just talking')], self::registry());

        $this->assertTrue($effects->isEmpty());
        $this->assertSame($base, $this->chain($hooks), 'an empty turn must not churn the chain');
    }

    public function testApplySurvivesAChainWithoutAGateHook(): void
    {
        // The gate is optional (a bare HookManager runs the no-permissions
        // shape); derivation must still answer, because the caller reads the
        // model/effort off the return value regardless of the gate.
        $hooks = new HookManager(new HookRegistry());

        $effects = ActiveSkillEffects::apply(
            $hooks,
            self::invocation([['name' => 'php-wizard']]),
            self::registry(self::wizard()),
        );

        $this->assertSame('opus-x', $effects->model);
        $this->assertSame('high', $effects->effort);
        $this->assertNull($hooks->hook(HookEvent::PreToolUse->value, PermissionGateHook::NAME));
    }
}
