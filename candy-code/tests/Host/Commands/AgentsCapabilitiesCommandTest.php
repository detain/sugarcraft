<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Agents\CapabilityCard;
use SugarCraft\Code\Agents\CapabilityCardStore;
use SugarCraft\Code\Agents\Live\AgentRunCards;
use SugarCraft\Code\Agents\Live\SubAgentTranscriptLog;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Host\Commands\AgentsHostCommand;
use SugarCraft\Code\Host\Commands\AgentStatusCommands;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\CommandResult;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\ScriptedProvider;

use function array_column;
use function count;
use function escapeshellarg;
use function file_put_contents;
use function is_dir;
use function json_decode;
use function mkdir;
use function preg_replace;
use function substr_count;
use function sys_get_temp_dir;

/**
 * `agents capabilities` is the discover verb of the a2a-style card slice
 * (RS-AGENT-C4 v1): it publishes this session's roster as capability
 * manifests, then prints everything discoverable under the home store.
 *
 * Three behaviors are load-bearing and each gets its own side of a pair.
 * PUBLISH IS SCOPED AND CONSENTED: manifests are written only with a session
 * id and only off a non-readonly context — the read-only surface must leave
 * the store byte-absent, and a null scope discovers without impersonating a
 * publisher. STALENESS IS OURS ALONE: a manifest in this session's bucket
 * for a name the live roster dropped is surfaced STALE (knowledge of a
 * vanished capability beats silent deletion), while foreign-session rows are
 * never accused of staleness. AND CONTENT IS DISPLAY-ONLY: every printed
 * field crosses the one-line transcript filter, so a forged manifest cannot
 * mint a row or color the screen — this test prints a hostile name through
 * the same path and reads back inert text.
 *
 * The routing arm is additive on a3's pre-manager gate (same posture as
 * `tree`): argument misuse costs the shared usage line as a notice row, and
 * a null AgentManager still discovers — the roster only drives publishing.
 */
final class AgentsCapabilitiesCommandTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';
    private string $home = '';
    private string $project = '';
    private string $root = '';
    private string $session = '';
    private ?string $priorTranscriptRoot = null;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-agents-caps-' . bin2hex(random_bytes(6));
        $this->home = $this->useHomeSandbox($this->sandbox . '/home');
        $this->project = $this->sandbox . '/project';
        mkdir($this->project, 0700, true);
        $this->root = $this->sandbox . '/caps';
        $this->session = 'sess-' . bin2hex(random_bytes(4));
        // The store derives its default root as the sibling of the transcript
        // root, and tests/bootstrap.php pins that suite-wide — so the routed
        // verbs (which pass no root override) would otherwise publish into a
        // directory shared by every test in the suite, and every later one.
        // Re-pin it into this test's sandbox home, restoring the prior pin in
        // tearDown; the store test file carries the same capture/restore law.
        $this->priorTranscriptRoot = SubAgentTranscriptLog::defaultRoot();
        SubAgentTranscriptLog::pinDefaultRoot($this->home . '/.candy-code/subagents');
    }

    protected function tearDown(): void
    {
        SubAgentTranscriptLog::pinDefaultRoot($this->priorTranscriptRoot);
        $this->restoreHomeSandbox();
        if ($this->sandbox !== '' && is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox) . ' 2>&1');
        }
    }

    // ------------------------------------------------------------- render core

    public function testPublishThenTextRosterShowsCurrentRows(): void
    {
        $out = AgentStatusCommands::capabilities(
            $this->session,
            [self::capsAgent('alpha'), self::capsAgent('beta')],
            null,
            false,
            true,
            $this->root,
        );

        self::assertStringStartsWith('Discoverable agent capabilities (a manifest is data, not permission):', $out);
        self::assertStringContainsString('- alpha · model:', $out);
        self::assertStringContainsString('- beta · model:', $out);
        self::assertStringNotContainsString('from session', $out, 'own-bucket rows need no origin tag');
        self::assertStringNotContainsString('STALE', $out);
        self::assertFileExists($this->root . '/' . AgentRunCards::segment($this->session) . '/beta.json');
    }

    public function testJsonCarriesThePinnedEnvelopeAndKeyOrder(): void
    {
        $out = AgentStatusCommands::capabilities(
            $this->session,
            [self::capsAgent('beta'), self::capsAgent('alpha')],
            null,
            true,
            true,
            $this->root,
        );

        $decoded = json_decode($out, true);
        self::assertIsArray($decoded);
        self::assertSame([1, $this->session], [$decoded['version'], $decoded['scope']]);
        self::assertSame(['alpha', 'beta'], array_column(array_column($decoded['agents'], 'card'), 'name'), 'sorted, not insertion-ordered');

        $first = $decoded['agents'][0];
        self::assertSame(['session', 'current', 'stale', 'card'], array_keys($first));
        self::assertSame([
            'schema', 'name', 'description', 'model', 'inheritsModel',
            'tools', 'disallowedTools', 'mcpServers', 'skills',
            'background', 'isolation', 'permissionMode', 'memory', 'effort',
            'maxTurns', 'source', 'derivedAt',
        ], array_keys($first['card']));
        self::assertSame(CapabilityCard::SCHEMA_VERSION, $first['card']['schema']);
        self::assertTrue($first['current']);
        self::assertFalse($first['stale']);
    }

    public function testNullScopeDiscoversWithoutPublishing(): void
    {
        $out = AgentStatusCommands::capabilities(null, [self::capsAgent('alpha')], null, true, true, $this->root);

        self::assertSame('{"version":1,"scope":null,"agents":[]}', preg_replace('/\s+/', '', $out), 'a null scope never impersonates a publisher');
        self::assertDirectoryDoesNotExist($this->root);
    }

    public function testNameFilterNarrowsRows(): void
    {
        $out = AgentStatusCommands::capabilities(
            $this->session,
            [self::capsAgent('alpha'), self::capsAgent('beta')],
            'beta',
            false,
            true,
            $this->root,
        );

        self::assertStringContainsString('- beta', $out);
        self::assertStringNotContainsString('- alpha', $out);
    }

    public function testVanishedOwnRosterNameIsSurfacedStale(): void
    {
        CapabilityCardStore::publish($this->session, [self::capsManifest('ghost')], $this->root);

        $out = AgentStatusCommands::capabilities($this->session, [self::capsAgent('alpha')], null, false, true, $this->root);

        self::assertStringContainsString("- alpha \u{B7}", $out, 'the live roster row publishes and shows');
        self::assertStringContainsString("- ghost \u{B7}", $out, 'the dropped name stays visible…');
        self::assertStringContainsString('STALE', $out, '…labeled as gone, because a lost capability is knowledge');
    }

    public function testForeignRowsAreAttributedNeverAccused(): void
    {
        CapabilityCardStore::publish('sess-other', [self::capsManifest('gamma')], $this->root);

        $out = AgentStatusCommands::capabilities($this->session, [self::capsAgent('alpha')], null, false, true, $this->root);

        self::assertStringContainsString('- gamma ', $out);
        self::assertStringContainsString(' · from session sess-other', $out);
        self::assertStringNotContainsString('STALE', $out, 'we are never anyone else\'s staleness judge');
    }

    public function testEmptyStoreAnswersRatherThanFails(): void
    {
        $out = AgentStatusCommands::capabilities(null, [], null, false, false, $this->root);

        self::assertStringContainsString('No capability manifests are on record yet.', $out);
    }

    public function testReadOnlySurfaceLeavesTheStoreByteAbsent(): void
    {
        $out = AgentStatusCommands::capabilities($this->session, [self::capsAgent('alpha')], null, false, false, $this->root);

        self::assertStringContainsString('No capability manifests are on record yet.', $out);
        self::assertDirectoryDoesNotExist($this->root, 'the read-only context never publishes');
    }

    public function testForgedManifestContentStaysDisplayInert(): void
    {
        // A process that can already write inside the home store can name a
        // bucket directory with raw control bytes — publish() itself cannot
        // (AgentRunCards::segment flattens them) — and a manifest can carry a
        // description no text row ever prints. The discover walk takes the
        // directory name from disk verbatim; the transcript filter is the
        // last line of defense, so the hostile bytes must reach the screen
        // flattened and the description must never reach it at all.
        $hostileDir = "/evil\nsess\x1b[31m";
        mkdir($this->root . $hostileDir, 0700, true);
        file_put_contents(
            $this->root . $hostileDir . '/worker.json',
            self::capsManifest('worker', "two\nlines\x1b[31m")->serialize(),
        );

        $out = AgentStatusCommands::capabilities($this->session, [], null, false, false, $this->root);

        self::assertStringContainsString('- worker', $out, 'the valid manifest is still discoverable');
        self::assertStringNotContainsString("\x1b", $out, 'no escape sequence reaches the screen');
        self::assertSame(1, substr_count($out, "\n"), 'header plus one row: a hostile directory cannot mint a line');
        self::assertStringNotContainsString('two', $out, 'descriptions never ride text rows');

        $decoded = json_decode(AgentStatusCommands::capabilities($this->session, [], null, true, false, $this->root), true);
        self::assertSame("two\nlines\x1b[31m", $decoded['agents'][0]['card']['description'], 'machine output carries bytes json_encode escapes; text stays inert');
    }

    // ------------------------------------------------------------------ routing

    /** @return array<string,array{0:string}> */
    public static function argumentMisuse(): array
    {
        return [
            'unknown flag' => ['/agents capabilities --nope'],
            'second positional' => ['/agents capabilities alpha beta'],
            'repeated json' => ['/agents capabilities --json --json'],
        ];
    }

    #[DataProvider('argumentMisuse')]
    public function testArgumentMisuseAnswersWithTheUsageNoticeLine(string $text): void
    {
        $result = $this->driveCaps($text);

        // CommandResult::failure carries the refusal in the row shape (a
        // notice row, not an assistant reply), not in ->errorCode — the same
        // shape a3's `tree` usage refusal has; the usage line is the answer.
        $rows = $result->rows;
        self::assertCount(2, $rows, 'the echo plus one notice row');
        self::assertSame($text, (string) $rows[0]->content);
        self::assertSame(
            Lang::t('host.agents.usage'),
            (string) $rows[count($rows) - 1]->content,
            'argument misuse costs the shared usage line, never a partial manifest',
        );
    }

    public function testManagerRosterFlowsThroughTheRoutingArm(): void
    {
        $manager = new AgentManager(new ScriptedProvider([]), new SkillRegistry());
        $manager->register(self::capsAgent('wired'));

        $result = (new AgentsHostCommand())->run(
            CommandContext::new(root: $this->project, sessionId: $this->session, agentManager: $manager),
            '/agents capabilities --json',
        );

        self::assertNull($result->errorCode);
        $decoded = json_decode(self::lastContent($result), true);
        self::assertSame(1, $decoded['version']);
        self::assertSame($this->session, $decoded['scope']);
        self::assertSame(['wired'], array_column(array_column($decoded['agents'], 'card'), 'name'));
    }

    public function testNullManagerStillDiscovers(): void
    {
        $result = $this->driveCaps('/agents capabilities');

        self::assertNull($result->errorCode, 'the verb sits on the pre-manager arm: no roster is a state, not an error');
        self::assertStringContainsString('No capability manifests are on record yet.', self::lastContent($result));
    }

    public function testPositionalFilterAndJsonRideTheSameArm(): void
    {
        $manager = new AgentManager(new ScriptedProvider([]), new SkillRegistry());
        $manager->register(self::capsAgent('alpha'));

        $hit = (new AgentsHostCommand())->run(
            CommandContext::new(root: $this->project, sessionId: $this->session, agentManager: $manager),
            '/agents capabilities alpha --json',
        );
        self::assertNull($hit->errorCode);
        $decoded = json_decode(self::lastContent($hit), true);
        self::assertSame(['alpha'], array_column(array_column($decoded['agents'], 'card'), 'name'));

        $miss = (new AgentsHostCommand())->run(
            CommandContext::new(root: $this->project, sessionId: $this->session, agentManager: $manager),
            '/agents capabilities nobody --json',
        );
        $missDecoded = json_decode(self::lastContent($miss), true);
        self::assertSame([], $missDecoded['agents']);
    }

    public function testReadOnlyContextSuppressesPublishThroughRouting(): void
    {
        $manager = new AgentManager(new ScriptedProvider([]), new SkillRegistry());
        $manager->register(self::capsAgent('alpha'));

        $result = (new AgentsHostCommand())->run(
            CommandContext::new(root: $this->project, sessionId: $this->session, agentManager: $manager, readOnly: true),
            '/agents capabilities',
        );

        self::assertStringContainsString('No capability manifests are on record yet.', self::lastContent($result));
        $store = CapabilityCardStore::defaultRoot();
        self::assertNotNull($store, 'the sandboxed home gives the store a root to stay away from');
        self::assertDirectoryDoesNotExist($store, 'the home store stays byte-absent under a read-only surface');
    }

    // ------------------------------------------------------------------- helpers

    private function driveCaps(string $text): CommandResult
    {
        return (new AgentsHostCommand())->run(
            CommandContext::new(root: $this->project, sessionId: $this->session),
            $text,
        );
    }

    /** @return list<string> */
    private static function contentsOf(CommandResult $result): array
    {
        $lines = [];
        foreach ($result->rows as $row) {
            $lines[] = (string) $row->content;
        }

        return $lines;
    }

    private static function lastContent(CommandResult $result): string
    {
        $lines = self::contentsOf($result);

        return (string) $lines[count($lines) - 1];
    }

    private static function capsAgent(string $name): Agent
    {
        return new Agent(
            name: $name,
            description: 'helper for the capability roster',
            prompt: 'private task text that must never ride a manifest',
            model: 'gemini-pro',
            provider: 'test',
            tools: ['Read'],
            skillNames: [],
            hooks: [],
            isActive: true,
        );
    }

    private static function capsManifest(string $name, string $description = 'd'): CapabilityCard
    {
        return new CapabilityCard(
            name: $name,
            description: $description,
            model: 'm',
            inheritsModel: false,
            tools: [],
            disallowedTools: [],
            mcpServers: [],
            skills: [],
            background: false,
            isolation: null,
            permissionMode: 'default',
            memory: null,
            effort: null,
            maxTurns: null,
            source: 'native',
            derivedAt: 1_700_000_000,
        );
    }
}
