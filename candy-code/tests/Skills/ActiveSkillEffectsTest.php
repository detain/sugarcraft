<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Skills;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Context\TurnContextBlock;
use SugarCraft\Code\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Message as TranscriptMessage;
use SugarCraft\Code\Messages\AssistantMessage;
use SugarCraft\Code\Messages\ToolResultMessage;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Role;
use SugarCraft\Code\Skills\ActiveSkillEffects;
use SugarCraft\Code\Skills\Skill;
use SugarCraft\Code\Skills\SkillOrigin;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\ToolCall as RootToolCall;
use SugarCraft\Code\Tools\ToolCall as ProviderToolCall;
use SugarCraft\Code\ToolResult as RootToolResult;

/**
 * C-EXT-1/C-EXT-2.b (R-EXT-1): what a skill declares for the turn is derived
 * purely from the turn's own messages — no process-wide state, because a
 * forked child re-derives from the transcript it was handed.
 *
 * The derivation is the whole product here: the gate and the step loop only
 * consume what {@see ActiveSkillEffects::derive()} lists. So these pins are
 * about WHICH skills are in the turn (slice boundary, failed calls, asset
 * reads, the re-injected roster), how their tool lists become rules (as
 * written, Claude Code's own grammar, prefix dialect included), which
 * model/effort wins when several skills spoke, and — since MAJOR-6 — which
 * TIER is allowed to pre-approve anything at all.
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

    /**
     * A skill carrying ONLY a pre-approval — the shape a repository would ship
     * to make its own build commands unasked (MAJOR-6).
     */
    private static function preApprover(string $name = 'make-runner'): Skill
    {
        return new Skill(
            name: $name,
            description: 'drives the build',
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: 'Bash(make)',
            disallowedTools: null,
            model: null,
            effort: null,
            context: 'thread',
            paths: [],
            content: 'body',
            sourcePath: '/nowhere/SKILL.md',
        );
    }

    /**
     * @param list<\SugarCraft\Code\Permissions\PermissionRule> $rules
     *
     * @return list<string>
     */
    private static function patterns(array $rules): array
    {
        return array_map(static fn ($rule) => $rule->pattern, $rules);
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

    /**
     * The same invocations expressed as the rows the Chat permission gate
     * actually holds: {@see \SugarCraft\Code\Chat::gateToolCall()} passes
     * Chat::$history — ROOT {@see TranscriptMessage} rows — straight into
     * {@see ActiveSkillEffects::apply()} (PHP-M2).
     *
     * @param list<array{name: string, args?: string}> $calls
     *
     * @return list<TranscriptMessage>
     */
    private static function rootInvocation(array $calls, bool $failLast = false): array
    {
        $rows = [TranscriptMessage::user('do the thing')];
        foreach ($calls as $i => $call) {
            $arguments = ['name' => $call['name']];
            if (isset($call['args'])) {
                $arguments['args'] = $call['args'];
            }
            $id = 'call-' . $i;
            $failed = $failLast && $i === count($calls) - 1;
            $rows[] = new TranscriptMessage(
                Role::Assistant,
                '',
                time(),
                toolCalls: [new RootToolCall('Skill', $arguments, $id)],
            );
            $rows[] = new TranscriptMessage(
                Role::System,
                '',
                time(),
                toolResults: [new RootToolResult('Skill', $failed ? '' : 'loaded', $failed ? 'boom' : null, $id)],
            );
        }

        return $rows;
    }

    public function testAnInvokedSkillFeedsItsToolListsModelAndEffort(): void
    {
        $effects = ActiveSkillEffects::derive(
            self::invocation([['name' => 'php-wizard', 'args' => 'audit this']]),
            self::registry(self::wizard()),
        projectTierTrusted: true,
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
        projectTierTrusted: true,
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

        $this->assertTrue(ActiveSkillEffects::derive($messages, self::registry(self::wizard()), projectTierTrusted: true)->isEmpty());
    }

    public function testATurnContextRowIsNotABoundary(): void
    {
        // Turn-context rows ride the wire as UserMessages but they are not the
        // user speaking; cutting the turn on one would erase the grants the
        // row itself is describing.
        $messages = self::invocation([['name' => 'php-wizard']]);
        $messages[] = TurnContextBlock::new()->withInvokedSkills(['php-wizard'])->message();

        $effects = ActiveSkillEffects::derive($messages, self::registry(self::wizard()), projectTierTrusted: true);
        $this->assertSame('opus-x', $effects->model);
    }

    public function testAFailedSkillCallGrantsNothing(): void
    {
        // invokedIn() drops calls whose result errored — nothing was loaded,
        // so nothing was promised.
        $messages = self::invocation([['name' => 'php-wizard']], failLast: true);

        $this->assertTrue(ActiveSkillEffects::derive($messages, self::registry(self::wizard()), projectTierTrusted: true)->isEmpty());
    }

    public function testAnAssetReadIsNotASkillInvocation(): void
    {
        $messages = self::invocation([['name' => 'php-wizard', 'asset' => 'scripts/one.sh']]);

        $this->assertTrue(ActiveSkillEffects::derive($messages, self::registry(self::wizard()), projectTierTrusted: true)->isEmpty());
    }

    public function testTheReinjectedRosterCountsAsInvoked(): void
    {
        // After compaction the transcript's Skill calls may be gone; the
        // re-injection roster names survive, and the effects must too.
        $messages = [
            new UserMessage('continue'),
            TurnContextBlock::new()->withInvokedSkills(['php-wizard'])->message(),
        ];

        $effects = ActiveSkillEffects::derive($messages, self::registry(self::wizard()), projectTierTrusted: true);
        $this->assertSame(['Read', 'Grep'], array_map(static fn ($r) => $r->pattern, $effects->allowRules));
        $this->assertSame('opus-x', $effects->model);
    }

    public function testAnUnknownNameIsSkippedAndTheKnownOnesStillFeed(): void
    {
        $messages = self::invocation([
            ['name' => 'ghost'],
            ['name' => 'php-wizard'],
        ]);

        $effects = ActiveSkillEffects::derive($messages, self::registry(self::wizard()), projectTierTrusted: true);
        $this->assertSame(['Read', 'Grep'], array_map(static fn ($r) => $r->pattern, $effects->allowRules));
    }

    public function testANameGivenByItsDisplayLeafResolvesThroughTheRegistry(): void
    {
        // A bundle skill is keyed 'acme/php-wizard' but the model writes the
        // leaf; SkillTool accepts the leaf, so the effects must too or the
        // grant the model was promised never lands.
        $messages = self::invocation([['name' => 'php-wizard']]);

        $effects = ActiveSkillEffects::derive($messages, self::registry(self::wizard('acme/php-wizard')), projectTierTrusted: true);
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
        projectTierTrusted: true,
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
        projectTierTrusted: true,
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
        projectTierTrusted: true,
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
        $this->assertTrue(ActiveSkillEffects::derive(self::invocation([['name' => 'php-wizard']]), null, projectTierTrusted: true)->isEmpty());
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
        projectTierTrusted: true,
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
        ActiveSkillEffects::apply($hooks, [new UserMessage('fresh turn')], self::registry(self::wizard()), projectTierTrusted: true);
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

        $effects = ActiveSkillEffects::apply($hooks, [new UserMessage('just talking')], self::registry(), projectTierTrusted: true);

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
        projectTierTrusted: true,
        );

        $this->assertSame('opus-x', $effects->model);
        $this->assertSame('high', $effects->effort);
        $this->assertNull($hooks->hook(HookEvent::PreToolUse->value, PermissionGateHook::NAME));
    }

    // ------------------------------------------------------------------
    // MAJOR-6: the trust door. A PROJECT-tier skill pre-approves nothing until
    // the repository is the one the operator said yes to; every other effect of
    // the same frontmatter rides regardless.
    // ------------------------------------------------------------------

    public function testAnUntrustedProjectsSkillPreApprovesNothing(): void
    {
        // The security pin: the wizard is origin Project (Skill's default tier),
        // so with the door shut its `allowed-tools` contributes ZERO Allow
        // rules — the model still gets asked about Read and Grep.
        $effects = ActiveSkillEffects::derive(
            self::invocation([['name' => 'php-wizard']]),
            self::registry(self::wizard()),
            projectTierTrusted: false,
        );

        $this->assertSame([], self::patterns($effects->allowRules));
        $this->assertSame(SkillOrigin::Project, self::wizard()->origin);
    }

    public function testTheDoorHoldsItsRefusalsModelAndEffortForAnUntrustedProject(): void
    {
        // The gate is one-directional: what a repository can do WITHOUT being
        // trusted is refuse tools and name a model, never widen itself. Both
        // halves of the wizard's non-pre-approval effects must survive.
        $effects = ActiveSkillEffects::derive(
            self::invocation([['name' => 'php-wizard']]),
            self::registry(self::wizard()),
            projectTierTrusted: false,
        );

        $this->assertSame(['WebFetch'], self::patterns($effects->denyRules));
        $this->assertSame('opus-x', $effects->model);
        $this->assertSame('high', $effects->effort);
        $this->assertFalse($effects->isEmpty(), 'a withheld pre-approval must not read as an empty turn');
    }

    public function testATrustedProjectsSkillPreApprovesExactlyAsLegacy(): void
    {
        // The byte-identical-legacy pin: the SAME turn through the SAME registry
        // with the door open reproduces the pre-MAJOR-6 answers, in order.
        $effects = ActiveSkillEffects::derive(
            self::invocation([['name' => 'php-wizard']]),
            self::registry(self::wizard()),
            projectTierTrusted: true,
        );

        $this->assertSame(['Read', 'Grep'], self::patterns($effects->allowRules));
        $this->assertSame(['WebFetch'], self::patterns($effects->denyRules));
    }

    /**
     * The tiers the operator wrote — never the repository — so the door does
     * not apply to them. These two legs are what a "withhold everything"
     * mutation trips on; the legs above are what a "widen to every tier" or a
     * neutered door trips on.
     */
    public static function operatorOwnedTiers(): array
    {
        return [
            'the shipped built-in' => [SkillOrigin::BuiltIn],
            "the operator's home tree" => [SkillOrigin::User],
        ];
    }

    /**
     * @param SkillOrigin $origin
     */
    #[DataProvider('operatorOwnedTiers')]
    public function testAnOperatorOwnedTierPreApprovesInAnUntrustedProject(SkillOrigin $origin): void
    {
        $effects = ActiveSkillEffects::derive(
            self::invocation([['name' => 'php-wizard']]),
            self::registry(self::wizard()->withOrigin($origin)),
            projectTierTrusted: false,
        );

        $this->assertSame(['Read', 'Grep'], self::patterns($effects->allowRules));
    }

    public function testAProjectTierBundleSkillIsGatedByTheSameDoor(): void
    {
        // ForeignSkillDiscovery::bundleTiers() labels a `project` bundle's
        // skills SkillOrigin::Project, so the bundle shape needs no second
        // rule — but it needs THIS one, since a vendored bundle is exactly the
        // content a cloned repository ships.
        $bundled = self::preApprover('acme/make-runner')->withOrigin(SkillOrigin::Project);

        $untrusted = ActiveSkillEffects::derive(
            self::invocation([['name' => 'acme/make-runner']]),
            self::registry($bundled),
            projectTierTrusted: false,
        );
        $this->assertSame([], self::patterns($untrusted->allowRules));
        $this->assertTrue($untrusted->isEmpty());

        $trusted = ActiveSkillEffects::derive(
            self::invocation([['name' => 'acme/make-runner']]),
            self::registry($bundled),
            projectTierTrusted: true,
        );
        $this->assertSame(['Bash(make)'], self::patterns($trusted->allowRules));
    }

    public function testAnUntrustedPreApprovalLeavesTheChainGateUntouched(): void
    {
        // Exec-level consequence, not just the derived list: with the door shut
        // a pre-approval-only skill must not churn the hook chain at all — the
        // step runs on the launch's own gate, which is what keeps today's
        // refusal prompts byte-identical for an untrusted checkout.
        $hooks = new HookManager(new HookRegistry());
        $base = new PermissionGate(PermissionMode::Default);
        $hooks->register(new PermissionGateHook($base));

        ActiveSkillEffects::apply(
            $hooks,
            self::invocation([['name' => 'make-runner']]),
            self::registry(self::preApprover()),
            projectTierTrusted: false,
        );

        $this->assertSame($base, $this->chain($hooks));
        $this->assertFalse($this->chain($hooks)->hasTurnGrants());
    }

    public function testATrustedPreApprovalReachesTheGateWhereTheUntrustedOneStops(): void
    {
        // The other side of the same fixture pair: open the door and the Bash
        // pre-approval lands on the chain gate. Read through the gate itself so
        // a carrier-side bug cannot hide behind derive().
        $hooks = new HookManager(new HookRegistry());
        $base = new PermissionGate(PermissionMode::Default);
        $hooks->register(new PermissionGateHook($base));

        ActiveSkillEffects::apply(
            $hooks,
            self::invocation([['name' => 'make-runner']]),
            self::registry(self::preApprover()),
            projectTierTrusted: true,
        );

        $carried = $this->chain($hooks);
        $this->assertNotSame($base, $carried);
        $this->assertSame(['Bash(make)'], self::patterns($carried->turnAllowRules()));
    }

    public function testBothProductionCallSitesHandTheDoorTheirTrustAnswer(): void
    {
        // The argument is required with no default, which is only a design if
        // the two live callers actually pass the real answer rather than a
        // literal. This scans them: a future edit that drops the argument (or
        // starts hard-coding true) reddens here instead of silently widening
        // the grant, and there is no third site because derive()/apply() have
        // no other consumers in src/.
        $sources = [
            'Runtime' => (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Runtime.php'),
            'Chat' => (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Chat.php'),
        ];

        foreach ($sources as $file => $source) {
            $site = preg_match('/ActiveSkillEffects::apply\(([^;]*)\);/s', $source, $match);
            self::assertSame(1, $site, $file . ': the step/dispatch site must call apply()');
            self::assertStringContainsString(
                'projectTierTrusted',
                $match[1],
                $file . ': the apply() call must name the trust argument',
            );
        }

        self::assertStringContainsString(
            'Bootstrap::projectCommandShellIsTrusted(self::projectRoot($app))',
            $sources['Runtime'],
            'Runtime must ask the same trust predicate the launch, the dialog and CommandLoader ask',
        );
        self::assertStringContainsString(
            'projectTierTrusted: $this->projectCommandsTrusted',
            $sources['Chat'],
            'Chat must thread the bool the launch handed it, not re-ask mid-session',
        );
    }

    public function testTheTrustDoorIsTheCommandsGrantNotAFifthKey(): void
    {
        // Provenance of the design, pinned because it is a decision someone
        // else will be tempted to "improve": the door reads the `commands`
        // grant, so no `trustedProjectSkills` key may appear in src/ without
        // the trust dialog, the docs table and TrustKeyDocumentationDriftTest
        // being walked first (that guard is the real census; this asserts only
        // that this lane did not mint the key).
        $keys = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2) . '/src')) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all('/\'(trustedProject[A-Za-z0-9_]+)\'/', (string) file_get_contents($file->getPathname()), $found);
            $keys = array_merge($keys, $found[1]);
        }

        $this->assertNotContains(
            'trustedProjectSkills',
            $keys,
            'this lane took the existing `commands` grant rather than minting a fifth key; '
                . 'a real fifth grant means the trust dialog, the docs table and '
                . 'TrustKeyDocumentationDriftTest walk together',
        );
    }

    /**
     * PHP-M2: Chat's permission gate hands this class its OWN root transcript
     * rows, and at the base of this fix the walk recognised only the engine's
     * typed hierarchy — every row was invisible, derive() answered "nothing
     * active", and a skill's pre-approvals never reached the gate on the Chat
     * path. The same invocation must feed the same grants whichever shape it
     * arrives in: the two callers describe one transcript.
     */
    public function testRootTranscriptRowsStillFeedTheGrants(): void
    {
        $effects = ActiveSkillEffects::derive(
            self::rootInvocation([['name' => 'php-wizard', 'args' => 'audit this']]),
            self::registry(self::wizard()),
            projectTierTrusted: true,
        );

        $this->assertFalse($effects->isEmpty());
        $this->assertSame('opus-x', $effects->model);
        $this->assertSame(['Read', 'Grep'], self::patterns($effects->allowRules));
        $this->assertSame(['WebFetch'], self::patterns($effects->denyRules));
    }

    /**
     * PHP-M2, the user-visible half: gateToolCall()'s
     * `isEmpty() && !$gate->hasTurnGrants()` short-circuit made the Chat-path
     * call a statement with no consequence — the gate stayed the launch's own,
     * byte-identical. Driving apply() with the rows Chat::$history holds must
     * swap the chain's gate exactly as the engine-path tests above do.
     */
    public function testApplyScopesTheGateFromTheChatShapeTranscript(): void
    {
        $hooks = new HookManager(new HookRegistry());
        $base = new PermissionGate(PermissionMode::Default);
        $hooks->register(new PermissionGateHook($base));

        ActiveSkillEffects::apply(
            $hooks,
            self::rootInvocation([['name' => 'php-wizard', 'args' => 'audit this']]),
            self::registry(self::wizard()),
            projectTierTrusted: true,
        );

        $gate = $this->chain($hooks);
        $this->assertNotSame($base, $gate);
        $this->assertTrue($gate->hasTurnGrants());
        $this->assertCount(2, $gate->turnAllowRules());
        $this->assertCount(1, $gate->turnDenyRules());
    }

    /**
     * The tolerant read has a boundary: root rows ending the turn — a fresh
     * real user prompt — must NOT carry the previous turn's grants forward.
     * A normalize that ignored the boundary would satisfy every pin above by
     * granting forever; this keeps the scope a scope.
     */
    public function testARootUserPromptEndsTheRootTurn(): void
    {
        $rows = self::rootInvocation([['name' => 'php-wizard']]);
        $rows[] = TranscriptMessage::user('now do something else');

        $this->assertTrue(
            ActiveSkillEffects::derive($rows, self::registry(self::wizard()), projectTierTrusted: true)->isEmpty()
        );
    }
}
