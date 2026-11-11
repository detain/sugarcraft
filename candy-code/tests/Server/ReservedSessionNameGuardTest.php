<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Channels\ChannelToolPolicyService;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Server\Support\WireClient;

/**
 * M-4 (cc-review-1111 TEAM-A): the `agent:` session-name grammar that D6
 * channel tool policy is bound through was unreserved at the wire edge — a
 * free-text title could collide with it, and a colliding rename silently
 * produced a refusingAll scope (the m54 silent-refusal issue). The server
 * now REFUSES the reserved prefix at entry, so refusingAll can never be
 * reached by accident from a user title. Bridge bundles minting real policy
 * names are exempted by their hello identity (the same trust axis D6's
 * autoloader door uses).
 *
 * These pins prove both polarities: plain clients are refused LOUDLY on
 * create/rename/import with no store row written, near-miss names still
 * land, and the two registered minters keep their grammar path. A final
 * cross-leg feeds the minted shapes through the REAL ChannelToolPolicyService
 * so the grammar this guard protects is proven resolvable end-to-end —
 * including the colon-encoded Teams group form the M-5 fix stamps.
 */
final class ReservedSessionNameGuardTest extends TestCase
{
    private ProtocolFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ProtocolFixture::new();
    }

    protected function tearDown(): void
    {
        $this->fixture->tearDown();
    }

    private function client(?string $identity = null): WireClient
    {
        if ($identity === null) {
            return $this->fixture->client();
        }

        $client = WireClient::open($this->fixture->dispatcher, 'bundle');
        $client->hello(['client' => ['name' => $identity, 'version' => '0']]);

        return $client;
    }

    /** @return array<int, array<string, mixed>> */
    private function sessionRows(): array
    {
        return $this->fixture->store->listSessions(20);
    }

    private function nameOfRow(string $sessionId): ?string
    {
        foreach ($this->sessionRows() as $row) {
            if ((string) ($row['id'] ?? '') === $sessionId) {
                return isset($row['name']) ? (string) $row['name'] : null;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function refused(string $method, array $params, string $label): array
    {
        $response = $this->client()->request($method, $params);

        self::assertArrayHasKey('error', $response, $label . ' must be refused');
        self::assertSame(ErrorCode::InvalidParams->value, $response['error']['code'], $label);
        self::assertSame('reserved_session_name', $response['error']['data']['kind'], $label);
        self::assertStringContainsString('reserved', (string) $response['error']['message'], $label);

        return $response;
    }

    public function testCreateRefusesAStolenChannelPolicyName(): void
    {
        $before = count($this->sessionRows());
        $this->refused('session.create', ['name' => 'agent:main:discord:group:stolen'], 'create');
        self::assertCount($before, $this->sessionRows(), 'a refused create must never reach the store');
    }

    public function testRenameRefusesAndLeavesTheStoredNameUntouched(): void
    {
        $client = $this->client();
        $sessionId = $this->fixture->session($client, ['name' => 'quarterly review']);

        $this->refused('session.rename', ['sessionId' => $sessionId, 'name' => 'agent:main:msteams:dm:victim'], 'rename');
        self::assertSame('quarterly review', $this->nameOfRow($sessionId), 'the refusal must not rewrite the row');
    }

    public function testRenameIsRefusedEvenForAnUnknownSession(): void
    {
        // The grammar check runs before the store lookup: the refusal reason
        // is the reserved name, never a misspelled session id hiding it.
        $this->refused('session.rename', ['sessionId' => 'nope-not-real', 'name' => 'agent:main:discord:dm:ghost'], 'unknown rename');
    }

    public function testImportRefusesAStolenChannelPolicyName(): void
    {
        $this->refused('session.import', ['content' => 'hello transcript', 'name' => 'agent:x'], 'import');
    }

    public function testNearMissNamesStillLand(): void
    {
        foreach (['agency: notes', 'agent-less', 'work'] as $name) {
            $sessionId = $this->fixture->session($this->client(), ['name' => $name]);
            self::assertSame($name, $this->nameOfRow($sessionId), $name . ' is not the reserved prefix');
        }
    }

    public function testAnUnnamedCreateStillLands(): void
    {
        $sessionId = $this->fixture->session($this->client());
        self::assertNotSame('', $sessionId);
        self::assertNull($this->nameOfRow($sessionId));
    }

    public function testBothBridgeMintersKeepTheirGrammar(): void
    {
        $ids = [];
        $names = [
            'candy-code-discord-bundle' => 'agent:main:discord:group:ok-1',
            'candy-code-msteams-bundle' => 'agent:main:msteams:dm:peer-9',
        ];

        foreach ($names as $identity => $name) {
            $ids[$identity] = $this->fixture->session($this->client($identity), ['name' => $name]);
        }

        foreach ($names as $identity => $name) {
            self::assertSame($name, $this->nameOfRow($ids[$identity]), $identity . ' mints the grammar');
        }
    }

    public function testMintedShapesResolveRealChannelPolicyScopes(): void
    {
        // Cross-leg: the grammar this guard reserves is the grammar D6 binds
        // through. The shapes the two bridge routers stamp — the plain DM
        // form and the colon-encoded Teams group form from the M-5 fix —
        // must parse against the real policy service, not fall into the
        // refusingAll bucket.
        $policy = new ChannelToolPolicyService(
            static fn (): array => ['channels' => [
                'candy-discord|discord' => ['tools' => ['allow' => ['Read']]],
                'candy-msteams|msteams' => ['tools' => ['allow' => ['Read']]],
            ]],
            ['candy-discord', 'candy-msteams'],
        );

        $discord = $policy->scopeFor('agent:main:discord:dm:peer-1');
        self::assertNotNull($discord, 'the minted discord DM shape must resolve a scope');
        self::assertFalse($discord->blocksEverything());
        self::assertNotNull($discord->verdict('Bash'));
        self::assertNull($discord->verdict('Read'));

        // The msteams router stamps Teams groups with the id's own colons
        // percent-encoded (ConversationRouter::grammarName): the raw
        // '19:team-42@thread.v2' tail would explode into six segments and
        // land in the fail-closed refusingAll bucket — the M-5 defect the
        // encode prevents. The minted encoded form must resolve a live scope.
        $teams = $policy->scopeFor('agent:main:msteams:group:19%3Ateam-42@thread.v2');
        self::assertNotNull($teams, 'the minted colon-encoded Teams group shape must resolve a scope');
        self::assertFalse($teams->blocksEverything(), 'the encoded group id must not fall into refusingAll');

        // A plain user title resolves NO channel scope at all (it is simply
        // not a policy-bound session) — and that is exactly the silent hole
        // the entry guard keeps a rename from ever turning into refusingAll.
        self::assertNull($policy->scopeFor('quarterly review'));
    }
}
