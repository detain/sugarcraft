<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Permissions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Permissions\ArmedPrompt;
use SugarCraft\Code\Permissions\PermissionFlow;
use SugarCraft\Code\Permissions\PermissionPrompt;
use SugarCraft\Code\Tests\Support\SlicesDeclaredMethodsTrait;

/**
 * W5-A extraction-parity pins: the permission FSM moved out of Chat into
 * {@see PermissionFlow} (transient state), {@see PermissionPrompt} (consult,
 * arm and resolve computations) and {@see ArmedPrompt} (the raised question).
 *
 * These are RELOCATION anchors, not behaviour tests — the behaviour is pinned
 * by the pre-existing Permission/Approval/Denial/Refusal families. Each pin
 * here fails loudly if a delegate is re-inlined, a moved body re-grows logic
 * on the Chat side, or the flow value object silently loses a slot the
 * mutate() carry still routes through it (the pattern W4-A shipped for
 * LaunchNotices/McpDecision).
 */
final class PermissionFsmExtractionParityTest extends TestCase
{
    use SlicesDeclaredMethodsTrait;

    /**
     * The five Chat-era transient slots, in mutate()-key spelling. The name
     * before the arrow is what callers of Chat::mutate() pass; the value is
     * the PermissionFlow property that now holds it.
     *
     * @return array<string,string>
     */
    private static function flowSlots(): array
    {
        return PermissionFlow::OVERRIDE_KEYS;
    }

    /**
     * Slice a declared method's source, asserting it is declared on the
     * expected class so a move or a shadow cannot silently redirect the pin.
     *
     * @param class-string $class
     */
    private static function bodyOf(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        self::assertSame(
            $class,
            $reflection->getDeclaringClass()->getName(),
            $method . '() must stay declared on ' . $class . '; a re-inline or a move elsewhere '
            . 'redirects every delegate-arity pin below without changing behaviour, which is exactly the drift this file exists to catch.',
        );
        $filename = $reflection->getFileName();
        self::assertNotFalse($filename);
        $lines = file($filename);
        self::assertNotFalse($lines);

        return self::declaredSlice($lines, $method, (int) $reflection->getStartLine(), (int) $reflection->getEndLine());
    }

    public function testTheFlowValueObjectOwnsExactlyTheTransientSlots(): void
    {
        $properties = [];
        foreach ((new ReflectionClass(PermissionFlow::class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            self::assertTrue($property->isReadOnly(), $property->getName() . ' must stay public readonly (PaletteState law).');
            $properties[] = $property->getName();
        }
        sort($properties);
        self::assertSame(
            ['deferred', 'jobs', 'pendingPermission', 'scopeEdit', 'stage'],
            $properties,
            'PermissionFlow owns precisely the five transient prompt slots extracted from Chat; a sixth is a redesign and a fifth-name loss strands a mutate() key.',
        );
    }

    public function testOverrideKeysIsABijectionOntoTheValueObject(): void
    {
        $keys = array_keys(self::flowSlots());
        $values = array_values(self::flowSlots());
        sort($keys);
        sort($values);
        self::assertCount(5, $values);
        self::assertSame($values, array_unique($values), 'two mutate() keys may not fold onto one PropertyFlow slot — the later write would silently win.');
        self::assertSame(['deferred', 'jobs', 'pendingPermission', 'scopeEdit', 'stage'], $values);
        self::assertSame(
            ['pendingPermission', 'pendingPermissionJobs', 'permissionDeferred', 'permissionScopeEdit', 'permissionStage'],
            $keys,
            'the Chat-era mutate() key spellings are a public vocabulary (Renderer/tests drive them); renaming one is a behaviour change, not a cleanup.',
        );
    }

    public function testChatCarriesTheFlowAndNoLongerTheFiveFields(): void
    {
        $params = array_map(
            static fn (object $p): string => $p->getName(),
            (new ReflectionClass(Chat::class))->getConstructor()?->getParameters() ?? [],
        );
        self::assertContains('flow', $params, 'Chat must carry the single flow slot C1 collapsed the five into.');
        self::assertSame(
            [],
            array_intersect($params, array_keys(self::flowSlots())),
            'a resurrected five-field Chat constructor re-introduces the double-carry the VO fold removed.',
        );
        foreach ((new ReflectionClass(Chat::class))->getProperties() as $property) {
            if (in_array($property->getName(), array_keys(self::flowSlots()), true)) {
                self::fail('Chat still declares ' . $property->getName() . '; the flow VO owns it.');
            }
        }
    }

    public function testMutateFoldsFlowKeysThroughTheValueObjectOnce(): void
    {
        $body = self::bodyOf(Chat::class, 'mutate');
        self::assertSame(1, substr_count($body, "'flow' => \$this->flow,"));
        self::assertSame(1, substr_count($body, "\$constructorProps['flow'] = \$this->flow->with(\$changes);"));
        self::assertSame(1, substr_count($body, 'foreach (array_keys(PermissionFlow::OVERRIDE_KEYS) as $flowKey)'));
        foreach (array_keys(self::flowSlots()) as $key) {
            self::assertSame(
                0,
                substr_count($body, "'$key' => \$this->"),
                "mutate() must not carry '$key' directly again: two carriers for one slot is the drift the fold deleted.",
            );
        }
    }

    /**
     * @return array<array{0:string,1:string}>
     */
    public static function consultDelegates(): array
    {
        return [
            ['permissionAlwaysScope', 'alwaysScope'],
            ['permissionPersistPatterns', 'persistPatterns'],
            ['permissionPersistScope', 'persistScope'],
            ['permissionEditableScope', 'editableScope'],
            ['permissionScopeError', 'scopeError'],
            ['permissionScopeRefusal', 'scopeRefusal'],
            ['permissionAlwaysAsks', 'alwaysAsks'],
        ];
    }

    #[DataProvider('consultDelegates')]
    public function testTheConsultFamilyDelegatesThinly(string $chatMethod, string $promptMethod): void
    {
        $body = self::bodyOf(Chat::class, $chatMethod);
        self::assertSame(
            1,
            substr_count($body, "permissionPrompt()->$promptMethod("),
            "$chatMethod() must be a one-line delegate to PermissionPrompt::$promptMethod().",
        );
        self::assertStringNotContainsString('foreach (', $body);
        self::assertStringNotContainsString('match (', $body);
        self::assertTrue((new ReflectionMethod(PermissionPrompt::class, $promptMethod))->isPublic());
    }

    public function testTheStaticHelpersDelegateToThePromptStatics(): void
    {
        self::assertSame(1, substr_count(self::bodyOf(Chat::class, 'grantCovers'), 'PermissionPrompt::grantCovers('));
        self::assertSame(1, substr_count(self::bodyOf(Chat::class, 'rememberNative'), 'PermissionPrompt::rememberNative('));
        self::assertSame(1, substr_count(self::bodyOf(Chat::class, 'persistPermissionRules'), 'permissionPrompt()->persistRules('));
        // The real bodies must live on the far side, or "delegates" is a word game.
        self::assertStringContainsString('new Deferred(', self::bodyOf(PermissionPrompt::class, 'armAsk'));
        self::assertStringContainsString('SessionPermissionMemo::fromGrants(', self::bodyOf(PermissionPrompt::class, 'grantCovers'));
    }

    public function testArmAskHasTheExtractedSignatureAndArmedPromptShape(): void
    {
        $armAsk = new ReflectionMethod(PermissionPrompt::class, 'armAsk');
        $returnType = $armAsk->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertTrue($returnType->allowsNull(), 'a stale-generation arm returns no prompt — the null half is the bail contract.');
        self::assertSame('SugarCraft\Code\Permissions\ArmedPrompt', $returnType->getName());
        $types = array_map(
            static fn (object $p): string => (string) $p->getType(),
            $armAsk->getParameters(),
        );
        self::assertSame(
            ['SugarCraft\Code\PermissionRequestMsg', 'int', 'array', 'SugarCraft\Code\Agents\Live\AgentLiveRegistry'],
            $types,
            'armAsk takes the prompt message, the generation guard value, the history for AskOrigin labels and the live-registry handle — the four reads the original body needed.',
        );
        $names = [];
        foreach ((new ReflectionClass(ArmedPrompt::class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            self::assertTrue($property->isReadOnly());
            $names[] = $property->getName();
        }
        sort($names);
        self::assertSame(['ask', 'deferred', 'notifyTitle'], $names);
    }

    public function testRequestPermissionAsksArmAskOnceAndKeepsTheArmingMutate(): void
    {
        $body = self::bodyOf(Chat::class, 'requestPermission');
        self::assertSame(1, substr_count($body, '$this->permissionPrompt()->armAsk($msg, $this->generation, $this->history, $this->agentLive())'));
        self::assertSame(1, substr_count($body, 'return [$this, null];'), 'the stale-generation bail must stay a single arm.');
        self::assertSame(1, substr_count($body, "'permissionStage' => PermissionPromptStage::Armed"));
        self::assertSame(1, substr_count($body, 'Cmd::promise(static fn(): PromiseInterface => $armed->deferred->promise())'));
        self::assertStringNotContainsString('new Deferred(', $body, 'the Deferred is armed inside armAsk(); a second one here would orphan the first promise.');
    }

    public function testAnswerPermissionUsesTheSixMovedResolvers(): void
    {
        $body = self::bodyOf(Chat::class, 'answerPermission');
        foreach ([
            'rememberedLabel',
            'enginePathGrants',
            'answeredLabel',
            'refusalPair',
            'askFor',
            'releasedJobs',
        ] as $moved) {
            self::assertSame(
                1,
                substr_count($body, $moved . '('),
                "answerPermission() must route exactly one call to the moved $moved() — a second copy means logic re-inlined onto the Chat side.",
            );
        }
        self::assertStringNotContainsString('SessionPermissionMemo::', $body, 'grant-memo computation belongs to PermissionPrompt.');
        self::assertStringNotContainsString('ApprovalVerdict::', $body, 'refusal wording belongs to PermissionPrompt::refusalPair().');
        // The pieces that must STAY on Chat: the recursion, the dispatch, the queue release.
        self::assertSame(1, substr_count($body, '->requestPermission('));
        self::assertSame(1, substr_count($body, '$this->mutate($cleared)->dispatchToolCalls('));
        self::assertSame(1, substr_count($body, 'self::releaseQueuedPrompts('));
    }
}
