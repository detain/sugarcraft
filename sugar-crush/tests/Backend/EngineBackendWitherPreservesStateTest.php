<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Context\RulesState;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Support\SiblingSpendLedger;

/**
 * Audit B5: two withers rebuilt the backend with a hand-written POSITIONAL
 * argument list that stopped two parameters short, so `$spendCapUsd` and
 * `$sessionSpendAtStartUsd` fell back to their defaults — any
 * `withMemoryStore()` / `withPermissionApprover()` called after
 * `withSpendCap()` silently took the mid-turn spend cap off.
 *
 * The first test pins that exact chain. The sweep below pins the CLASS of
 * bug: every public wither must carry every constructor field it does not
 * own, measured against a backend whose every field holds a distinctive
 * non-default value (a default-valued baseline would let a dropped argument
 * read as "preserved"). The roster test makes a new wither that nobody adds
 * to the sweep go red rather than slip past it.
 */
final class EngineBackendWitherPreservesStateTest extends TestCase
{
    public function testSpendCapSurvivesMemoryStoreAndPermissionApproverWithers(): void
    {
        $backend = EngineBackend::new($this->provider(), 'm')
            ->withSpendCap(1.0, 0.25)
            ->withMemoryStore(null)
            ->withPermissionApprover(static fn(): bool => true);

        $state = $this->state($backend);
        $this->assertSame(1.0, $state['spendCapUsd']);
        $this->assertSame(0.25, $state['sessionSpendAtStartUsd']);
    }

    /**
     * @return array<string, array{\Closure(EngineBackend): EngineBackend, list<string>}>
     */
    public static function withers(): array
    {
        return [
            'withTools' => [static fn(EngineBackend $b): EngineBackend => $b->withTools([]), ['tools']],
            'withSkills' => [static fn(EngineBackend $b): EngineBackend => $b->withSkills([]), ['skills']],
            'withSkillRegistry' => [
                static fn(EngineBackend $b): EngineBackend => $b->withSkillRegistry(self::blank(SkillRegistry::class)),
                ['skillRegistry'],
            ],
            'withInstructionLoader' => [
                static fn(EngineBackend $b): EngineBackend => $b->withInstructionLoader(self::blank(InstructionFileLoader::class)),
                ['instructionLoader'],
            ],
            'withHooks' => [
                static fn(EngineBackend $b): EngineBackend => $b->withHooks(new HookManager(new HookRegistry())),
                ['hookManager', 'hooksDisabled'],
            ],
            'withPermissionGate' => [
                static fn(EngineBackend $b): EngineBackend => $b->withPermissionGate(new PermissionGate(PermissionMode::Default)),
                ['permissionGate'],
            ],
            'withPermissionApprover' => [
                static fn(EngineBackend $b): EngineBackend => $b->withPermissionApprover(static fn(): bool => false),
                ['permissionApprover'],
            ],
            'withoutHooks' => [
                static fn(EngineBackend $b): EngineBackend => $b->withoutHooks(),
                ['hookManager', 'hooksDisabled'],
            ],
            'withRoot' => [static fn(EngineBackend $b): EngineBackend => $b->withRoot('/other'), ['root']],
            'withMemoryStore' => [
                static fn(EngineBackend $b): EngineBackend => $b->withMemoryStore(null),
                ['memoryStore'],
            ],
            'withRulesState' => [
                static fn(EngineBackend $b): EngineBackend => $b->withRulesState(null),
                ['rulesState'],
            ],
            'withMaxSteps' => [static fn(EngineBackend $b): EngineBackend => $b->withMaxSteps(3), ['maxSteps']],
            'withSpendCap' => [
                static fn(EngineBackend $b): EngineBackend => $b->withSpendCap(9.0, 4.0),
                ['spendCapUsd', 'sessionSpendAtStartUsd'],
            ],
            // Audit B4-rem: the forked group member's shared spend file.
            'withSiblingSpend' => [
                static fn(EngineBackend $b): EngineBackend => $b->withSiblingSpend(self::blank(SiblingSpendLedger::class)),
                ['siblingSpend'],
            ],
            // Registers onto a CLONE of the manager (audit F-J5), so it owns
            // hookManager; it owns hooksDisabled too (re-asserted false), and
            // tools, whose path-resolving members it re-jails to the worktree.
            'withWorktreeRoot' => [
                static fn(EngineBackend $b): EngineBackend => $b->withWorktreeRoot('/wt'),
                ['hookManager', 'hooksDisabled', 'tools'],
            ],
        ];
    }

    /**
     * @param \Closure(EngineBackend): EngineBackend $wither
     * @param list<string>                         $owned
     */
    #[DataProvider('withers')]
    public function testWitherPreservesEveryFieldItDoesNotOwn(\Closure $wither, array $owned): void
    {
        $before = $this->populated();
        $after = $wither($before);

        $this->assertNotSame($before, $after, 'a wither must return a new instance');

        $expected = $this->state($before);
        $actual = $this->state($after);
        $this->assertSame(array_keys($expected), array_keys($actual));

        foreach ($expected as $field => $value) {
            if (in_array($field, $owned, true)) {
                continue;
            }
            $this->assertSame($value, $actual[$field], "wither dropped or altered `\${$field}`");
        }
    }

    /** The specific values each wither is contracted to write. */
    public function testWitherOwnedFieldSemantics(): void
    {
        $base = $this->populated();

        $hooks = new HookManager(new HookRegistry());
        $s = $this->state($base->withoutHooks()->withHooks($hooks));
        $this->assertSame($hooks, $s['hookManager']);
        $this->assertFalse($s['hooksDisabled']);

        $s = $this->state($base->withoutHooks());
        $this->assertNull($s['hookManager']);
        $this->assertTrue($s['hooksDisabled']);

        $this->assertSame(1, $this->state($base->withMaxSteps(0))['maxSteps']);
        $this->assertSame(1, $this->state($base->withMaxSteps(-5))['maxSteps']);
        $this->assertSame(3, $this->state($base->withMaxSteps(3))['maxSteps']);

        $s = $this->state($base->withSpendCap(null));
        $this->assertNull($s['spendCapUsd']);
        $this->assertSame(0.0, $s['sessionSpendAtStartUsd']);

        // With hooks off, the worktree still re-jails the tools (audit F-J5)
        // but registers no hook: the opt-out is kept, not re-armed.
        $disabled = $base->withoutHooks();
        $s = $this->state($disabled->withWorktreeRoot('/wt'));
        $this->assertNull($s['hookManager']);
        $this->assertTrue($s['hooksDisabled']);

        $s = $this->state(EngineBackend::new($this->provider(), 'm')->withWorktreeRoot('/wt'));
        $this->assertInstanceOf(HookManager::class, $s['hookManager']);
        $this->assertFalse($s['hooksDisabled']);
    }

    /**
     * Audit F-J5: `withWorktreeRoot()` registered its worktree-scoped Bash
     * guard on the receiver's own manager, so the PARENT backend's chain also
     * started denying paths outside the sub-agent's worktree.
     */
    public function testWithWorktreeRootLeavesTheReceiversHookChainAlone(): void
    {
        $shared = new HookManager(new HookRegistry());
        $parent = EngineBackend::new($this->provider(), 'm')->withHooks($shared);

        $child = $parent->withWorktreeRoot('/wt');

        $this->assertNull(
            $shared->hook('PreToolUse', 'bash-escape-deny'),
            'the parent backend\'s manager gained the worktree guard',
        );
        $this->assertNull($shared->hook('PreToolUse', 'protect-files'));
        $this->assertSame($shared, $this->state($parent)['hookManager']);

        $childManager = $this->state($child)['hookManager'];
        $this->assertInstanceOf(HookManager::class, $childManager);
        $this->assertNotSame($shared, $childManager);
        $this->assertNotNull($childManager->hook('PreToolUse', 'bash-escape-deny'));
    }

    public function testSweepCoversEveryPublicWither(): void
    {
        $declared = [];
        foreach ((new \ReflectionClass(EngineBackend::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if (!$m->isStatic() && preg_match('/^with(out)?[A-Z]/', $m->getName()) === 1) {
                $declared[] = $m->getName();
            }
        }
        sort($declared);
        $covered = array_keys(self::withers());
        sort($covered);

        $this->assertSame($declared, $covered, 'add every new EngineBackend wither to withers()');
    }

    /** Every constructor field set to a distinctive, non-default value. */
    private function populated(): EngineBackend
    {
        return new EngineBackend(
            provider: $this->provider(),
            model: 'populated-model',
            tools: ['tool-sentinel'],
            skills: ['skill-sentinel'],
            hookManager: new HookManager(new HookRegistry()),
            maxSteps: 5,
            hooksDisabled: false,
            skillRegistry: self::blank(SkillRegistry::class),
            instructionLoader: self::blank(InstructionFileLoader::class),
            root: '/populated',
            permissionGate: new PermissionGate(PermissionMode::BypassPermissions),
            permissionApprover: static fn(): bool => true,
            memoryStore: self::blank(MemoryStore::class),
            rulesState: RulesState::new(),
            spendCapUsd: 2.5,
            sessionSpendAtStartUsd: 0.75,
            siblingSpend: self::blank(SiblingSpendLedger::class),
        );
    }

    /**
     * Every constructor-promoted field, keyed by name — the constructor's own
     * parameter list is the roster, so a new parameter joins the sweep
     * without anyone editing this test.
     *
     * @return array<string, mixed>
     */
    private function state(EngineBackend $backend): array
    {
        $out = [];
        $ctor = (new \ReflectionClass(EngineBackend::class))->getConstructor();
        self::assertNotNull($ctor);
        foreach ($ctor->getParameters() as $param) {
            $out[$param->getName()] = (new \ReflectionProperty(EngineBackend::class, $param->getName()))->getValue($backend);
        }

        return $out;
    }

    /**
     * Identity is all the sweep compares, so collaborators whose constructors
     * touch the filesystem are built without running them.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private static function blank(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private function provider(): ProviderInterface
    {
        return new class implements ProviderInterface {
            public function name(): string { return 'stub'; }
            public function supportsStreaming(): bool { return false; }
            public function supportsFunctionCalling(): bool { return false; }
            public function supportsVision(): bool { return false; }
            public function supportsJsonSchema(): bool { return false; }
            public function contextWindow(): int { return 1000; }
            public function costPer1kTokens(string $model, string $direction): ?float { return null; }
            public function complete(CompleteRequest $request): CompleteResponse
            {
                throw new \LogicException('not called');
            }
            public function completeStream(CompleteRequest $request): \Generator
            {
                yield from [];
            }
            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                throw new \LogicException('not called');
            }
        };
    }
}
