<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\ClientTools;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Host\ClientTools\ClientToolProxy;
use SugarCraft\Code\Host\ClientTools\ClientToolRegistry;
use SugarCraft\Code\Host\ClientTools\ClientToolSpec;

/**
 * C-TOOL-6.h: the descriptor door and the per-session registration book.
 *
 * The design's matrix in one file: what the spec constructor refuses (shape
 * of name, length of description, schema type and size, timeout bounds),
 * what `refusal()` says for the wire parser, and how the registry replaces a
 * client's set, refuses cross-client name conflicts, forgets a client, caps
 * an announcement, and hands out `client__`-named proxies.
 */
final class ClientToolRegistryTest extends TestCase
{
    // ---------------------------------------------------------------- spec

    public function testAWellFormedSpecKeepsEveryFieldExactly(): void
    {
        $spec = new ClientToolSpec(
            name: 'browser_context',
            description: 'Read the currently open browser tab.',
            inputSchema: ['type' => 'object', 'properties' => ['tab' => ['type' => 'string']]],
            timeoutSeconds: 30,
        );

        self::assertSame('browser_context', $spec->name);
        self::assertSame('Read the currently open browser tab.', $spec->description);
        self::assertSame(['type' => 'object', 'properties' => ['tab' => ['type' => 'string']]], $spec->inputSchema);
        self::assertSame(30, $spec->timeoutSeconds);
    }

    public function testAnOmittedTimeoutDefaultsToTwoMinutes(): void
    {
        $spec = new ClientToolSpec(
            name: 'ctx',
            description: 'Anything the client can describe.',
            inputSchema: ['type' => 'object'],
        );

        self::assertSame(ClientToolSpec::DEFAULT_TIMEOUT_SECONDS, $spec->timeoutSeconds);
        self::assertSame(120, $spec->timeoutSeconds);
    }

    /**
     * The design's name grammar: leading lowercase letter, then lowercase
     * letters, digits and underscores, up to 48 characters total. Hyphens are
     * deliberately NOT accepted here even though `user__` names take them —
     * these names go to provider tool-list grammars, and the design chose the
     * narrower alphabet for exactly that reason.
     *
     * @return array<string, array{string, bool}>
     */
    public static function nameShapeCases(): array
    {
        return [
            'plain letter' => ['a', true],
            'underscore inside' => ['browser_context', true],
            'digits inside' => ['tab2', true],
            'forty-eight chars' => [str_repeat('a', 48), true],
            'forty-nine chars' => [str_repeat('a', 49), false],
            'leading digit' => ['2tab', false],
            'leading underscore' => ['_tab', false],
            'uppercase' => ['Browser', false],
            'hyphen' => ['browser-context', false],
            'empty' => ['', false],
            'space' => ['a b', false],
        ];
    }

    /**
     * @dataProvider nameShapeCases
     */
    public function testNamePatternEnforcesTheDesignGrammar(string $name, bool $accepted): void
    {
        $build = static fn () => new ClientToolSpec(
            name: $name,
            description: 'shape',
            inputSchema: ['type' => 'object'],
        );

        if ($accepted) {
            self::assertSame($name, $build()->name);

            return;
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bad_name');
        $build();
    }

    public function testDescriptionIsBoundedInCharactersNotBytes(): void
    {
        // 1024 multibyte characters: 2048 bytes for UTF-8, still legal — the
        // model reads characters, and a multilingual client sends them.
        $legal = new ClientToolSpec(
            name: 'ctx',
            description: str_repeat('é', ClientToolSpec::MAX_DESCRIPTION_CHARS),
            inputSchema: ['type' => 'object'],
        );
        self::assertSame(ClientToolSpec::MAX_DESCRIPTION_CHARS, mb_strlen($legal->description));

        try {
            new ClientToolSpec(
                name: 'ctx',
                description: str_repeat('é', ClientToolSpec::MAX_DESCRIPTION_CHARS + 1),
                inputSchema: ['type' => 'object'],
            );
            self::fail('one character over the bound was accepted');
        } catch (InvalidArgumentException $why) {
            self::assertSame('bad_description', $why->getMessage());
        }

        try {
            new ClientToolSpec(name: 'ctx', description: '', inputSchema: ['type' => 'object']);
            self::fail('an empty description was accepted');
        } catch (InvalidArgumentException $why) {
            self::assertSame('bad_description', $why->getMessage());
        }
    }

    public function testSchemaMustDeclareObjectAndFitTheEncodingBound(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bad_schema');
        new ClientToolSpec(name: 'ctx', description: 'shape', inputSchema: ['type' => 'string']);
    }

    public function testASchemaMissingItsTypeIsBadSchemaToo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bad_schema');
        new ClientToolSpec(name: 'ctx', description: 'shape', inputSchema: ['properties' => []]);
    }

    public function testAnOverSixteenKibibyteSchemaIsRefusedByEncodedSize(): void
    {
        // Pad until the JSON encoding crosses the cap: the bound is what the
        // model pays for per turn, not the PHP entry count.
        $blob = str_repeat('x', ClientToolSpec::MAX_SCHEMA_BYTES);
        self::assertGreaterThan(
            ClientToolSpec::MAX_SCHEMA_BYTES,
            strlen((string) json_encode(['type' => 'object', 'properties' => ['k' => ['type' => 'string', 'enum' => [$blob]]]])),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bad_schema');
        new ClientToolSpec(
            name: 'ctx',
            description: 'shape',
            inputSchema: ['type' => 'object', 'properties' => ['k' => ['type' => 'string', 'enum' => [$blob]]]],
        );
    }

    /** @return array<string, array{int|NULL, bool}> */
    public static function timeoutCases(): array
    {
        return [
            'one second' => [1, true],
            'six hundred seconds' => [600, true],
            'zero seconds' => [0, false],
            'six oh one seconds' => [601, false],
            'negative seconds' => [-5, false],
            'omitted (default)' => [null, true],
        ];
    }

    /**
     * @dataProvider timeoutCases
     */
    public function testTimeoutKeepsTheDeclaredOneOperationBounds(?int $timeout, bool $accepted): void
    {
        $build = static fn () => new ClientToolSpec(
            name: 'ctx',
            description: 'shape',
            inputSchema: ['type' => 'object'],
            timeoutSeconds: $timeout ?? ClientToolSpec::DEFAULT_TIMEOUT_SECONDS,
        );

        if ($accepted) {
            self::assertInstanceOf(ClientToolSpec::class, $build());

            return;
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bad_timeout');
        $build();
    }

    // ------------------------------------------------------------- wire shape

    public function testRefusalAnswersTheVerdictWordsForTheWireDoor(): void
    {
        self::assertSame('bad_shape', ClientToolSpec::refusal([]));
        self::assertSame('bad_name', ClientToolSpec::refusal(['name' => 'Bad', 'description' => 'x', 'inputSchema' => ['type' => 'object']]));
        self::assertSame('bad_description', ClientToolSpec::refusal(['name' => 'ctx', 'description' => '', 'inputSchema' => ['type' => 'object']]));
        self::assertSame('bad_schema', ClientToolSpec::refusal(['name' => 'ctx', 'description' => 'x', 'inputSchema' => 'not-an-array']));
        self::assertSame('bad_timeout', ClientToolSpec::refusal(['name' => 'ctx', 'description' => 'x', 'inputSchema' => ['type' => 'object'], 'timeoutSeconds' => 700]));
        self::assertSame('bad_timeout', ClientToolSpec::refusal(['name' => 'ctx', 'description' => 'x', 'inputSchema' => ['type' => 'object'], 'timeoutSeconds' => '120']));
        self::assertNull(ClientToolSpec::refusal(['name' => 'ctx', 'description' => 'x', 'inputSchema' => ['type' => 'object']]));
    }

    public function testUnknownTopLevelKeysAreTolerated(): void
    {
        $decoded = [
            'name' => 'ctx',
            'description' => 'x',
            'inputSchema' => ['type' => 'object'],
            'futureField' => ['whatever' => true],
        ];

        self::assertNull(ClientToolSpec::refusal($decoded));
        $spec = ClientToolSpec::fromDecoded($decoded);
        self::assertSame('ctx', $spec->name);
        self::assertSame(ClientToolSpec::DEFAULT_TIMEOUT_SECONDS, $spec->timeoutSeconds);
    }

    // ------------------------------------------------------------ registry

    public function testRegistrationIsAHexSixteenHandleAndReplacesTheWholeSet(): void
    {
        $registry = new ClientToolRegistry();
        $first = $registry->register('c1', [ClientToolRegistryTest::registrySpec('alpha')]);
        self::assertSame(1, preg_match('/\A[0-9a-f]{16}\z/', $first));
        self::assertSame($first, $registry->currentRegistrationId('c1'));

        // Re-announcement REPLACES: the old name is freed for its own owner
        // check and the id moves, retiring answers aimed at the previous set.
        $second = $registry->register('c1', [ClientToolRegistryTest::registrySpec('beta')]);
        self::assertNotSame($first, $second);
        self::assertNull($registry->owner('client__alpha'));
        self::assertSame('c1', $registry->owner('client__beta'));
        self::assertSame($second, $registry->currentRegistrationId('c1'));
    }

    public function testReannouncingTheSameSetStillMintsAFreshId(): void
    {
        // The reconnect case: identical content, but the id MUST move so the
        // pendings of the connection that died cannot be answered into the
        // new one. A content hash alone would repeat here.
        $registry = new ClientToolRegistry();
        $specs = [ClientToolRegistryTest::registrySpec('alpha')];
        $first = $registry->register('c1', $specs);
        $second = $registry->register('c1', $specs);

        self::assertNotSame($first, $second);
    }

    public function testANameAnotherClientHoldsIsRefused(): void
    {
        $registry = new ClientToolRegistry();
        $registry->register('c1', [ClientToolRegistryTest::registrySpec('shared')]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('name_conflict');
        $registry->register('c2', [
            ClientToolRegistryTest::registrySpec('own'),
            ClientToolRegistryTest::registrySpec('shared'),
        ]);
    }

    public function testTheConflictRefusalLeavesTheLosingClientWithNothing(): void
    {
        // Partial registration would strand half an announcement with no id
        // to answer it — the refusal must be all-or-nothing.
        $registry = new ClientToolRegistry();
        $registry->register('c1', [ClientToolRegistryTest::registrySpec('shared')]);

        try {
            $registry->register('c2', [
                ClientToolRegistryTest::registrySpec('own'),
                ClientToolRegistryTest::registrySpec('shared'),
            ]);
            self::fail('the conflicting announcement was accepted');
        } catch (InvalidArgumentException) {
            self::assertNull($registry->owner('client__own'));
            self::assertNull($registry->currentRegistrationId('c2'));
            self::assertSame(1, $registry->clientCount());
        }
    }

    public function testADuplicateInsideOneSubmissionIsRefusedAsDuplicateName(): void
    {
        $registry = new ClientToolRegistry();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicate_name');
        $registry->register('c1', [
            ClientToolRegistryTest::registrySpec('twice'),
            ClientToolRegistryTest::registrySpec('twice'),
        ]);
    }

    public function testReannouncingOwnNamesIsNotAConflict(): void
    {
        $registry = new ClientToolRegistry();
        $registry->register('c1', [ClientToolRegistryTest::registrySpec('alpha')]);
        $again = $registry->register('c1', [ClientToolRegistryTest::registrySpec('alpha')]);

        self::assertSame('c1', $registry->owner('client__alpha'));
        self::assertNotSame('', $again);
    }

    public function testForgetDropsTheClientsWholeSetAtOnce(): void
    {
        $registry = new ClientToolRegistry();
        $registry->register('c1', [
            ClientToolRegistryTest::registrySpec('alpha'),
            ClientToolRegistryTest::registrySpec('beta'),
        ]);
        $registry->register('c2', [ClientToolRegistryTest::registrySpec('gamma')]);

        $registry->forget('c1');

        self::assertNull($registry->owner('client__alpha'));
        self::assertNull($registry->owner('client__beta'));
        self::assertNull($registry->currentRegistrationId('c1'));
        self::assertSame('c2', $registry->owner('client__gamma'));
        self::assertSame(1, $registry->clientCount());

        // And the freed names are immediately available to another client.
        $registry->register('c3', [ClientToolRegistryTest::registrySpec('alpha')]);
        self::assertSame('c3', $registry->owner('client__alpha'));
    }

    public function testForgettingAnUnknownClientIsANoOp(): void
    {
        $registry = new ClientToolRegistry();
        $registry->forget('never-registered');
        self::assertSame(0, $registry->clientCount());
    }

    public function testSixteenToolsIsTheCapAndSeventeenRefuses(): void
    {
        $registry = new ClientToolRegistry();
        $sixteen = [];
        for ($i = 0; $i < ClientToolRegistry::MAX_TOOLS_PER_CLIENT; $i++) {
            $sixteen[] = ClientToolRegistryTest::registrySpec('tool_' . $i);
        }
        $registry->register('c1', $sixteen);
        self::assertCount(16, $registry->proxies());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('too_many_tools');
        $seventeen = $sixteen;
        $seventeen[] = ClientToolRegistryTest::registrySpec('tool_16');
        $registry->register('c2', $seventeen);
    }

    public function testAnEmptyClientKeyIsRefused(): void
    {
        $registry = new ClientToolRegistry();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bad_client_key');
        $registry->register('', [ClientToolRegistryTest::registrySpec('alpha')]);
    }

    public function testProxiesCarryTheClientPrefixAndSortByNameNotArrivalOrder(): void
    {
        $registry = new ClientToolRegistry();
        // zeta registers FIRST; the roster order must not depend on that.
        $registry->register('c1', [ClientToolRegistryTest::registrySpec('zeta')]);
        $registry->register('c2', [
            ClientToolRegistryTest::registrySpec('mid'),
            ClientToolRegistryTest::registrySpec('alpha'),
        ]);

        $names = array_map(static fn (ClientToolProxy $proxy): string => $proxy->name(), $registry->proxies());

        self::assertSame(['client__alpha', 'client__mid', 'client__zeta'], $names);
    }

    public function testProxiesAreFreshInstancesEachCall(): void
    {
        $registry = new ClientToolRegistry();
        $registry->register('c1', [ClientToolRegistryTest::registrySpec('alpha')]);

        $a = $registry->proxies()[0];
        $b = $registry->proxies()[0];

        self::assertNotSame($a, $b);
        self::assertSame($a->name(), $b->name());
        self::assertSame('alpha', $a->spec()->name);
    }

    public function testOwnerReadsFullProxyNamesOnly(): void
    {
        $registry = new ClientToolRegistry();
        $registry->register('c1', [ClientToolRegistryTest::registrySpec('probe')]);

        // The frames and the permission layer both carry the prefixed name;
        // a bare spec name must NOT resolve — that mismatch would let a
        // half-spelled lookup read as "nobody holds this".
        self::assertSame('c1', $registry->owner('client__probe'));
        self::assertNull($registry->owner('probe'));
        self::assertNull($registry->owner('not_client__probe'));
    }

    public function testCurrentRegistrationIdIsStaleForAForgottenClient(): void
    {
        $registry = new ClientToolRegistry();
        $id = $registry->register('c1', [ClientToolRegistryTest::registrySpec('alpha')]);
        self::assertSame($id, $registry->currentRegistrationId('c1'));

        $registry->forget('c1');
        self::assertNull($registry->currentRegistrationId('c1'));

        // And an id from a previous registration of the same client reads as
        // stale after a replace — the single-comparison leg 6.i depends on.
        $registry->register('c1', [ClientToolRegistryTest::registrySpec('beta')]);
        self::assertNotSame($id, $registry->currentRegistrationId('c1'));
    }

    /** One well-formed descriptor with a caller-supplied name. */
    public static function registrySpec(string $name): ClientToolSpec
    {
        return new ClientToolSpec(
            name: $name,
            description: 'registry probe descriptor for ' . $name,
            inputSchema: ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]],
        );
    }
}
