<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SugarCraft\Code\Agents\AgentPreset;
use SugarCraft\Code\Agents\CapabilityCard;
use SugarCraft\Code\Agents\Effort;
use SugarCraft\Code\Agents\Isolation;
use SugarCraft\Code\Agents\MemoryScope;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Skills\SkillSource;

use function array_keys;
use function array_map;
use function json_decode;
use function strlen;
use function str_repeat;

/**
 * The capability card is a pure derivation with a fail-closed reader, and
 * both halves are load-bearing in opposite directions (RS-AGENT-C4).
 *
 * The derivation half must be BYTE-DETERMINISTIC: two cards drawn from the
 * same preset at the same stamp are the same bytes, because the published
 * store is diffed and audited, and a wobbling serializer would make every
 * re-publish look like a capability change. The clock lives in the caller
 * (derivedAt is a parameter, never a read), so nothing here is ambient.
 *
 * The reader half must REFUSE: a foreign manifest is display data from an
 * untrusted machine-local file, and every shape deviation — a bumped schema,
 * a traversal name, an invented enum spelling, a coerced integer — costs the
 * whole manifest its parse, never just the one field. There is no repair
 * path, because a repaired card would let a forged file dictate what the
 * roster believes an agent can do. The permission-mode mirror is the sharpest
 * edge: Agent::fromPreset() never lets a foreign-sourced preset carry a
 * raised mode, and the card repeats that gate so a manifest cannot overstate
 * a foreign agent's floor either.
 */
final class CapabilityCardTest extends TestCase
{
    /** Fixed epoch: derivation takes the clock as input, never reads one. */
    private const STAMP = 1_700_000_000;

    // ---------------------------------------------------------------- derivation

    public function testEqualInputsSerializeToIdenticalBytes(): void
    {
        $first = CapabilityCard::fromPreset(self::fullPreset(), self::STAMP);
        $second = CapabilityCard::fromPreset(self::fullPreset(), self::STAMP);

        self::assertSame($first->serialize(), $second->serialize(), 'determinism is the publish-diff contract');
        self::assertSame(array_keys($first->toArray()), array_keys($second->toArray()));
    }

    public function testCardNeverReadsAClockItWasNotGiven(): void
    {
        $card = CapabilityCard::fromPreset(self::fullPreset(), self::STAMP);

        self::assertSame(self::STAMP, $card->derivedAt, 'derivedAt rides the caller-stamp only');
    }

    public function testForeignSourcedPresetReportsTheDefaultFloor(): void
    {
        $foreign = new AgentPreset(
            name: 'claude-import',
            description: 'read from a foreign tier',
            permissionMode: PermissionMode::BypassPermissions,
            source: SkillSource::Claude,
        );

        self::assertSame(
            PermissionMode::Default->value,
            CapabilityCard::fromPreset($foreign, self::STAMP)->permissionMode,
            'the card mirrors fromPreset(): raised modes never travel for foreign sources',
        );

        $native = new AgentPreset(
            name: 'native-raised',
            description: 'authored for candy-code',
            permissionMode: PermissionMode::BypassPermissions,
            source: SkillSource::Native,
        );

        self::assertSame(
            PermissionMode::BypassPermissions->value,
            CapabilityCard::fromPreset($native, self::STAMP)->permissionMode,
            'a native preset keeps the floor it actually declares',
        );
    }

    public function testInheritModelSpellingIsCarriedAndFlagged(): void
    {
        $card = CapabilityCard::fromPreset(
            new AgentPreset(name: 'worker', description: 'x', model: 'inherit'),
            self::STAMP,
        );

        self::assertTrue($card->inheritsModel);
        self::assertSame('inherit', $card->model);
    }

    public function testLongDescriptionIsClippedAndStillRoundTrips(): void
    {
        $card = CapabilityCard::fromPreset(
            new AgentPreset(name: 'worker', description: str_repeat('d', CapabilityCard::MAX_DESCRIPTION_BYTES + 900)),
            self::STAMP,
        );

        self::assertSame(CapabilityCard::MAX_DESCRIPTION_BYTES, strlen($card->description));

        $back = CapabilityCard::fromJson($card->serialize());
        self::assertNotNull($back);
        self::assertSame($card->serialize(), $back->serialize());
    }

    // ---------------------------------------------------------------- round trip

    public function testFullCardRoundTripsThroughJsonByteIdentically(): void
    {
        $card = CapabilityCard::fromPreset(self::fullPreset(), self::STAMP);
        $back = CapabilityCard::fromJson($card->serialize());

        self::assertNotNull($back);
        self::assertSame($card->serialize(), $back->serialize());
        self::assertSame($card->toArray(), $back->toArray());
    }

    public function testMinimalCardRoundTrips(): void
    {
        $card = CapabilityCard::fromPreset(
            new AgentPreset(name: 'bare', description: ''),
            self::STAMP,
        );
        $back = CapabilityCard::fromJson($card->serialize());

        self::assertNotNull($back);
        self::assertSame([], $back->tools);
        self::assertNull($back->isolation);
        self::assertNull($back->memory);
        self::assertNull($back->effort);
        self::assertNull($back->maxTurns);
        self::assertFalse($back->background);
    }

    public function testToArrayCarriesTheFixedKeyOrder(): void
    {
        $keys = array_keys(CapabilityCard::fromPreset(self::fullPreset(), self::STAMP)->toArray());

        self::assertSame([
            'schema', 'name', 'description', 'model', 'inheritsModel',
            'tools', 'disallowedTools', 'mcpServers', 'skills',
            'background', 'isolation', 'permissionMode', 'memory', 'effort',
            'maxTurns', 'source', 'derivedAt',
        ], $keys, 'key order is the byte-stability contract');
    }

    // ---------------------------------------------------------------- fail closed

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function hostileManifests(): array
    {
        $good = CapabilityCard::fromPreset(self::fullPreset(), self::STAMP)->toArray();

        $reboarded = $good;
        $reboarded['schema'] = '1';

        $wideList = $good;
        $wideList['tools'] = [];
        for ($i = 0; $i < CapabilityCard::MAX_LIST_ENTRIES + 1; $i++) {
            $wideList['tools'][] = 'tool' . $i;
        }

        $cases = [
            'future schema' => [['schema' => $good['schema'] + 1] + $good],
            'stringified schema' => [$reboarded],
            'missing schema' => [array_slice($good, 1, null, true) + ['schema' => null]],
            'traversal name' => [['name' => '../evil'] + $good],
            'empty name' => [['name' => ''] + $good],
            'oversized description' => [['description' => str_repeat('d', CapabilityCard::MAX_DESCRIPTION_BYTES + 1)] + $good],
            'oversized model' => [['model' => str_repeat('m', 129)] + $good],
            'assoc tools list' => [['tools' => ['a' => 'Read']] + $good],
            'non-string tool entry' => [['tools' => [42]] + $good],
            'empty-string tool entry' => [['tools' => ['']] + $good],
            'over-long tool entry' => [['tools' => [str_repeat('t', 129)]] + $good],
            'list beyond cap' => [$wideList],
            'stringified background' => [['background' => 'yes'] + $good],
            'invented permission mode' => [['permissionMode' => 'godmode'] + $good],
            'invented source' => [['source' => 'alien'] + $good],
            'invented isolation' => [['isolation' => 'floating'] + $good],
            'integer memory' => [['memory' => 42] + $good],
            'invented effort' => [['effort' => 'ultra'] + $good],
            'negative max turns' => [['maxTurns' => -1] + $good],
            'stringified max turns' => [['maxTurns' => '3'] + $good],
            'float stamp' => [['derivedAt' => 1.5] + $good],
            'stringified stamp' => [['derivedAt' => '1700000000'] + $good],
            'missing name key' => [['name' => null] + $good],
        ];

        // 'missing schema' above must actually LACK the key, not carry null:
        // array + union keeps both spellings honest by deleting it outright.
        $absent = $good;
        unset($absent['schema']);
        $cases['absent schema'] = [$absent];
        unset($cases['missing schema']);

        return $cases;
    }

    #[DataProvider('hostileManifests')]
    public function testEveryShapeDeviationIsRefusedWholesale(array $manifest): void
    {
        self::assertNull(
            CapabilityCard::fromArray($manifest),
            'a foreign manifest is all-or-nothing — no field-level repair path',
        );
    }

    public function testNonObjectJsonIsRefused(): void
    {
        self::assertNull(CapabilityCard::fromJson('not json at all'));
        self::assertNull(CapabilityCard::fromJson('[]'));
        self::assertNull(CapabilityCard::fromJson('"a string"'));
    }

    public function testConstructorRefusesAnUnsafeManifestName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CapabilityCard(
            name: '../evil',
            description: '',
            model: 'inherit',
            inheritsModel: true,
            tools: [],
            disallowedTools: [],
            mcpServers: [],
            skills: [],
            background: false,
            isolation: null,
            permissionMode: PermissionMode::Default->value,
            memory: null,
            effort: null,
            maxTurns: null,
            source: SkillSource::Native->value,
            derivedAt: self::STAMP,
        );
    }

    // ---------------------------------------------------------------- summary

    public function testSummaryCarriesCapabilityVocabularyOnly(): void
    {
        $summary = CapabilityCard::fromPreset(self::fullPreset(), self::STAMP)->summary();

        self::assertStringContainsString('model:gemini-pro', $summary);
        self::assertStringContainsString('3 tools', $summary);
        self::assertStringContainsString('1 denied', $summary);
        self::assertStringContainsString('1 mcp', $summary);
        self::assertStringContainsString('1 skills', $summary);
        self::assertStringContainsString('background', $summary);
        self::assertStringContainsString('isolation:worktree', $summary);
        self::assertStringContainsString('mode:auto', $summary);
        self::assertStringContainsString('effort:high', $summary);
        self::assertStringContainsString('cap:12', $summary);
        self::assertStringNotContainsString('General worker', $summary, 'prose is not a capability digest');
    }

    public function testSummaryFallsBackAndSkipsQuietly(): void
    {
        $summary = CapabilityCard::fromPreset(
            new AgentPreset(name: 'bare', description: ''),
            self::STAMP,
        )->summary();

        self::assertStringContainsString('model:inherit', $summary);
        self::assertStringContainsString('all tools', $summary, 'an unrestricted toolset is itself a capability');
        self::assertStringContainsString('mode:default', $summary);
        self::assertStringNotContainsString('denied', $summary);
        self::assertStringNotContainsString('isolation:', $summary);
    }

    public function testIsolationNoneStaysOutOfTheSummary(): void
    {
        $summary = CapabilityCard::fromPreset(
            new AgentPreset(name: 'bare', description: '', isolation: Isolation::None),
            self::STAMP,
        )->summary();

        self::assertStringNotContainsString('isolation:', $summary, 'the spelled default says nothing worth paying for');
    }

    // ---------------------------------------------------------------- exclusions

    public function testTaskContentAndDecorationNeverRideAManifest(): void
    {
        $names = array_map(
            static fn (mixed $property): string => $property->getName(),
            (new ReflectionClass(CapabilityCard::class))->getProperties(),
        );

        foreach (['prompt', 'initialPrompt', 'sourcePath', 'color', 'mode', 'provider', 'hooks', 'isActive'] as $forbidden) {
            self::assertNotContains($forbidden, $names, "{$forbidden} is task content, decoration, or selection vocabulary — never a capability");
        }
    }

    public function testJsonEncodingIsPlainReadableForDrivers(): void
    {
        $decoded = json_decode(CapabilityCard::fromPreset(self::fullPreset(), self::STAMP)->serialize(), true);

        self::assertIsArray($decoded);
        self::assertSame(CapabilityCard::SCHEMA_VERSION, $decoded['schema']);
        self::assertStringNotContainsString('\\/', $decoded['description'] . '', 'unescaped slashes keep names readable');
    }

    // ------------------------------------------------------------------ fixtures

    private static function fullPreset(): AgentPreset
    {
        return new AgentPreset(
            name: 'researcher',
            description: 'General worker for long reads',
            tools: ['Read', 'Grep', 'WebSearch'],
            disallowedTools: ['Bash'],
            model: 'gemini-pro',
            permissionMode: PermissionMode::Auto,
            maxTurns: 12,
            skills: ['pdf'],
            mcpServers: ['docs'],
            memory: MemoryScope::Project,
            background: true,
            effort: Effort::High,
            isolation: Isolation::Worktree,
            color: '#ff0000',
            initialPrompt: 'quietly',
            source: SkillSource::Native,
            sourcePath: '/home/elsewhere/.claude/agents/researcher.md',
        );
    }
}
