<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Memory\MemoryStore;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\RpcError;
use SugarCraft\Code\Protocol\TrustAsks;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\McpLaunchEnabledTrait;

/**
 * WEB-3b: the server-scope trust prompt. A `context.sources` measurement that
 * finds an untrusted project `.mcp.json` puts a `kind:"trust"` ask over the
 * existing `permission.asked` channel — once per root — and `context.trust`
 * answers it: the user-tier grant persists through the one writer the CLI
 * already owns, the session grant settles without writing, and a
 * project-tier remember is refused exactly as `permission.respond` refuses
 * one. `permission.respond` never sees these ids.
 */
final class ContextTrustPromptTest extends TestCase
{
    use HomeSandboxTrait;
    use McpLaunchEnabledTrait;

    private ProtocolFixture $fixture;

    private string $homeRoot = '';

    protected function setUp(): void
    {
        TrustAsks::resetForTesting();
        // Shards launch with CANDY_CODE_MCP_DISABLE=1 (E737 gate); the
        // context-trust verdicts this file proves are only produced on the
        // MCP-enabled launch path, so arm it per-test exactly like the MCP
        // suites do.
        $this->armMcpLaunchEnabled();
        $this->homeRoot = \sys_get_temp_dir() . '/w5e-home-' . \uniqid('', true);
        $this->useHomeSandbox($this->homeRoot);
        \mkdir($this->homeRoot . '/.candy-code', 0o700, true);
        \mkdir($this->homeRoot . '/memory', 0o700, true);
        $this->fixture = ProtocolFixture::new(memoryStore: new MemoryStore($this->homeRoot . '/memory'));
    }

    protected function tearDown(): void
    {
        TrustAsks::resetForTesting();
        $this->restoreMcpLaunchEnabled();
        $this->fixture->tearDown();
        $this->restoreHomeSandbox();
        $this->purgeTree($this->homeRoot);
    }

    public function testAnUntrustedProjectMcpJsonMintsOnePromptAndAnnouncesIt(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $this->declareMcpServers();

        $asked = $this->sources($client, $sessionId)['trust'];
        self::assertSame('trust', $asked['kind']);
        self::assertSame('mcp', $asked['target']);
        self::assertSame('asked', $asked['status']);
        self::assertFalse($asked['persisted']);
        self::assertSame(realpath($this->fixture->root), $asked['root']);

        // The once law: a reload re-reads the same record and puts no second ask.
        $again = $this->sources($client, $sessionId)['trust'];
        self::assertSame($asked['askId'], $again['askId']);

        $announced = \array_values(\array_filter(
            $client->events('permission.asked'),
            static fn (array $envelope): bool => ($envelope['data']['kind'] ?? null) === 'trust',
        ));
        self::assertCount(1, $announced, 'the prompt rides permission.asked exactly once');
        $data = $announced[0]['data'];
        self::assertSame($asked['askId'], $data['askId']);
        self::assertSame($sessionId, $data['sessionId']);
        self::assertStringContainsString('.mcp.json', $data['reason']);
    }

    public function testTrustedAndAbsentVerdictsPutNoPrompt(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);

        self::assertNull($this->sources($client, $sessionId)['trust'], 'no .mcp.json, nothing to ask');

        file_put_contents($this->fixture->root . '/.mcp.json', "{\"mcpServers\":{\"local\":{\"command\":\"true\",\"args\":[]}}}\n");
        self::assertSame('untrusted', $this->sources($client, $sessionId)['mcp']['status']);
        self::assertSame('asked', $this->sources($client, $sessionId)['trust']['status']);
    }

    public function testAProjectTierRememberIsRefusedTheWayPermissionRulesAre(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $this->declareMcpServers();
        $askId = $this->sources($client, $sessionId)['trust']['askId'];

        $reply = $client->request('context.trust', ['sessionId' => $sessionId, 'askId' => $askId, 'decision' => 'grant', 'remember' => 'project']);

        self::assertSame(-32003, $reply['error']['code'], 'Forbidden, the same refusal the respond arm gives');
        self::assertSame('remember_refused', $reply['error']['data']['kind']);
        self::assertSame('asked', $this->sources($client, $sessionId)['trust']['status'], 'a refused write leaves the question open');
        self::assertFileDoesNotExist($this->homeRoot . '/.candy-code/config.json');
    }

    public function testTheUserGrantPersistsThroughTheOneUserTierWriter(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $this->declareMcpServers();
        $askId = $this->sources($client, $sessionId)['trust']['askId'];

        $result = $client->call('context.trust', ['sessionId' => $sessionId, 'askId' => $askId, 'decision' => 'grant', 'remember' => 'user']);

        self::assertSame(['askId' => $askId, 'status' => 'granted', 'persisted' => true], $result);
        $config = \json_decode((string) \file_get_contents($this->homeRoot . '/.candy-code/config.json'), true);
        self::assertContains(realpath($this->fixture->root), $config['trustedProjectMcp'] ?? []);

        // Settled for the process: the panel keeps the truth, and answering
        // twice is the same `already_resolved` a settled question gives.
        $settled = $this->sources($client, $sessionId)['trust'];
        self::assertSame('granted', $settled['status']);
        self::assertTrue($settled['persisted']);
        $again = $client->request('context.trust', ['sessionId' => $sessionId, 'askId' => $askId, 'decision' => 'grant', 'remember' => 'user']);
        self::assertSame(-32009, $again['error']['code']);
        self::assertSame('already_resolved', $again['error']['data']['kind']);
    }

    public function testTheSessionGrantSettlesWithoutWriting(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $this->declareMcpServers();
        $askId = $this->sources($client, $sessionId)['trust']['askId'];

        $result = $client->call('context.trust', ['sessionId' => $sessionId, 'askId' => $askId, 'decision' => 'grant']);

        self::assertSame(['askId' => $askId, 'status' => 'granted', 'persisted' => false], $result);
        self::assertFileDoesNotExist($this->homeRoot . '/.candy-code/config.json', 'a session grant writes nothing');
        self::assertSame($askId, $this->sources($client, $sessionId)['trust']['askId'], 'one prompt per root, not per reload');
    }

    public function testARejectionIsADecisionNotAQuestionToNag(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $this->declareMcpServers();
        $askId = $this->sources($client, $sessionId)['trust']['askId'];

        $client->call('context.trust', ['sessionId' => $sessionId, 'askId' => $askId, 'decision' => 'reject']);

        $relaunched = $this->fixture->client();
        $other = $this->fixture->session($relaunched);
        self::assertSame('rejected', $this->sources($relaunched, $other)['trust']['status']);
        self::assertCount(0, \array_filter(
            $relaunched->events('permission.asked'),
            static fn (array $envelope): bool => ($envelope['data']['kind'] ?? null) === 'trust',
        ), 'a second client measuring the same root is not asked again');
    }

    public function testTheToolAnswerChannelNeverSeesTrustIds(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $this->declareMcpServers();
        $askId = $this->sources($client, $sessionId)['trust']['askId'];

        // The trust prompt is NOT in the host's turn-question registry — no
        // ask timeout, no turn-teardown sweep — so the gate's own answer
        // channel honestly reports it never held that id.
        $reply = $client->request('permission.respond', ['sessionId' => $sessionId, 'askId' => $askId, 'reply' => 'reject']);
        self::assertSame('ask_not_found', $reply['error']['data']['kind']);
        self::assertSame('asked', $this->sources($client, $sessionId)['trust']['status']);

        $unknown = $client->request('context.trust', ['sessionId' => $sessionId, 'askId' => 'trust-000000000', 'decision' => 'reject']);
        self::assertSame('ask_not_found', $unknown['error']['data']['kind']);
    }

    public function testTheSettledTruthRidesEveryLaterMeasurementOfTheRoot(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $this->declareMcpServers();
        $asked = $this->sources($client, $sessionId)['trust'];
        self::assertSame('asked', $asked['status']);

        // The record is the single truth between the answer and the next
        // measurement: a second client opening a panel reads the settled
        // state straight from context.sources rather than a re-broadcast.
        $client->call('context.trust', ['sessionId' => $sessionId, 'askId' => $asked['askId'], 'decision' => 'reject']);
        $second = $this->fixture->client();
        $secondSession = $this->fixture->session($second);
        self::assertSame('rejected', $this->sources($second, $secondSession)['trust']['status']);
    }

    /**
     * C-SEC-4: a user-tier grant whose write does not land is REFUSED, not
     * silently reported. `writeUserConfig` fails silently on an unwritable
     * config dir, which the old settle arm would have papered over with
     * `granted, persisted:false` — the panel would have said "settled" while
     * the project stayed untrusted. The answer now raises `trust_write_failed`
     * and leaves the record `asked`, so the next answer can succeed.
     */
    public function testAnUnwritableUserConfigRefusesTheGrantAndKeepsThePromptOpen(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root writes into a 0500 directory regardless of its mode.');
        }
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $this->declareMcpServers();
        $askId = $this->sources($client, $sessionId)['trust']['askId'];

        chmod($this->homeRoot . '/.candy-code', 0o500);
        try {
            $reply = $client->request('context.trust', ['sessionId' => $sessionId, 'askId' => $askId, 'decision' => 'grant', 'remember' => 'user']);
        } finally {
            chmod($this->homeRoot . '/.candy-code', 0o700);
        }

        self::assertSame(-32009, $reply['error']['code'], 'Conflict: the state and the filesystem disagree, and the answer may be repeated');
        self::assertSame('trust_write_failed', $reply['error']['data']['kind']);
        self::assertIsString($reply['error']['data']['error'] ?? null);
        self::assertNotSame('', $reply['error']['data']['error'], 'the write failure is admitted, never silent');
        self::assertSame('asked', $this->sources($client, $sessionId)['trust']['status'], 'the refusal leaves the prompt open');

        // The same answer against a writable config now lands.
        $result = $client->call('context.trust', ['sessionId' => $sessionId, 'askId' => $askId, 'decision' => 'grant', 'remember' => 'user']);
        self::assertSame(['askId' => $askId, 'status' => 'granted', 'persisted' => true], $result);
    }

    /**
     * C-SEC-4: the prompt is minted on `session.subscribe` too, so a watcher
     * that never mounts the Context panel still gets it — exactly once, with
     * the same record `context.sources` will later read.
     */
    public function testSubscribingWithoutThePanelStillPutsThePrompt(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $this->declareMcpServers();

        $reply = $client->request('session.subscribe', ['sessionId' => $sessionId]);
        self::assertArrayHasKey('result', $reply);
        $this->fixture->run(0.05);

        $announced = array_values(array_filter(
            $client->events('permission.asked'),
            static fn (array $envelope): bool => ($envelope['data']['kind'] ?? null) === 'trust',
        ));
        self::assertCount(1, $announced, 'subscribe puts the prompt with no panel in sight');
        self::assertSame($sessionId, $announced[0]['data']['sessionId']);

        // The measurement reads the SAME record — the once-per-root law holds
        // across both minting paths, so the panel and the badge never diverge.
        $asked = $this->sources($client, $sessionId)['trust'];
        self::assertSame($announced[0]['data']['askId'], $asked['askId']);
        self::assertSame('asked', $asked['status']);
    }

    /**
     * C-SEC-4: the record lives in the process that minted it; a settle call
     * carrying a session whose root is NOT the prompt's root is refused the
     * same way an unknown id is — one root's answer never settles another's.
     */
    public function testAPromptOfAnotherRootIsNotTheOneOnThisSession(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $this->declareMcpServers();
        $askId = $this->sources($client, $sessionId)['trust']['askId'];

        try {
            TrustAsks::settle($askId, 'grant', null, '/definitely-not-the-asked-root');
            self::fail('a foreign root must not settle the prompt');
        } catch (RpcError $failure) {
            self::assertSame(ErrorCode::NotFound, $failure->errorCode);
            self::assertSame('ask_not_found', $failure->kind);
        }

        self::assertSame('asked', $this->sources($client, $sessionId)['trust']['status'], 'the refusal leaves the record untouched');
    }

    /**
     * C-SEC-4: `sessionId` is required on `context.trust` — it is what routes
     * the answer to the workspace host that minted the prompt. A pre-fix client
     * that sends none is refused at the schema, loudly, instead of having its
     * answer misrouted to the primary process and lost as `ask_not_found`.
     */
    public function testAnAnswerWithoutASessionIsRefusedAtTheSchema(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $this->declareMcpServers();
        $askId = $this->sources($client, $sessionId)['trust']['askId'];

        $reply = $client->request('context.trust', ['askId' => $askId, 'decision' => 'grant']);

        self::assertSame(-32602, $reply['error']['code']);
        self::assertSame('invalid_params', $reply['error']['data']['kind']);
        self::assertSame('asked', $this->sources($client, $sessionId)['trust']['status'], 'a schema refusal changes nothing server-side');
    }

    /** @return array<string, mixed> */
    private function sources(\SugarCraft\Code\Tests\Server\Support\WireClient $client, string $sessionId): array
    {
        $answer = $client->call('context.sources', ['sessionId' => $sessionId]);
        self::assertArrayHasKey('trust', $answer, 'the answer carries the trust prompt state');

        return $answer;
    }

    private function declareMcpServers(): void
    {
        \file_put_contents(
            $this->fixture->root . '/.mcp.json',
            "{\"mcpServers\":{\"local\":{\"command\":\"true\",\"args\":[]}}}\n",
        );
    }

    private function purgeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path) || (is_dir($path) && !is_link($path))) {
                $this->purgeTree($path);
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
