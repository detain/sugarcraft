<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Agents\Live\AgentInbox;
use SugarCraft\Code\Agents\Live\MessageMode;
use SugarCraft\Code\Events\SubAgentActivity;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Server\Support\WireClient;
use SugarCraft\Code\Theme;
use SugarCraft\Code\Tui\Window\AgentColor;

/**
 * P-AGENT.c: the two halves of the agent story a browser's roster and Agent
 * View were missing — every `agents.list` item carrying its preset's colour,
 * resolved through the same {@see AgentColor} law the TUI strip follows (a
 * hex the browser can paint, or no key at all rather than a guess), and
 * `agents.message` taking the two mid-run wire modes the TUI's cycle leads
 * with — the {@see MessageMode::Steer} default and `interrupt`, which marks
 * the mailbox line so the run skips its unstarted calls before reading it.
 */
final class AgentsColorAndInterruptTest extends TestCase
{
    private ProtocolFixture $fixture;

    private string $presetDir = '';

    protected function setUp(): void
    {
        // C-AGT-4.c: two of the roster's presets declare their presenting
        // tier in frontmatter, so the mode read needs real files — written
        // under a private per-process dir the law asks of fixtures.
        $this->presetDir = \sys_get_temp_dir() . '/cagt4c-modes-' . \bin2hex(\random_bytes(6));
        \mkdir($this->presetDir, 0o700);
        $this->fixture = ProtocolFixture::new(agentManager: $this->roster());
    }

    protected function tearDown(): void
    {
        $this->fixture->tearDown();
        foreach (scandir($this->presetDir) ?: [] as $left) {
            if ($left !== '.' && $left !== '..') {
                @\unlink($this->presetDir . '/' . $left);
            }
        }
        @\rmdir($this->presetDir);
    }

    public function testTheRosterCarriesThePresetColorResolvedAndNothingElseInvented(): void
    {
        $client = $this->fixture->client();
        $items = $client->call('agents.list')['items'];
        /** @var array<string, array<string, mixed>> $byName */
        $byName = [];
        foreach ($items as $item) {
            $byName[$item['name']] = $item;
        }

        // A vocabulary word lands as its theme token's current hex — the
        // same round-trip the strip paints — never the raw word.
        $theme = Theme::default();
        self::assertSame($theme->shellError->toHex(), $byName['crimson']['color'] ?? null);

        // A literal hex lands lower-cased, and only because it clears the
        // shell's own contrast floor; deriving the expectation through the
        // same law keeps this pin honest if the default theme is retuned.
        $literal = AgentColor::resolve('#003D99', $theme);
        self::assertNotNull($literal, 'the fixture literal must stay legible on the default theme for this pin to mean anything');
        self::assertSame($literal->toHex(), $byName['hexed']['color'] ?? null, 'the literal rides the resolution, lower-cased');
        self::assertNotSame('#003D99', $byName['hexed']['color'] ?? null, 'not the raw preset spelling');

        // No colour named, and a word outside the eight: no key, so the
        // browser keeps its own default rather than a carried null.
        self::assertArrayNotHasKey('color', $byName['plain'], 'a preset naming no colour carries no key');
        self::assertArrayNotHasKey('color', $byName['magenta'], 'a word outside the vocabulary resolves to nothing, and nothing is omitted');
    }

    // C-AGT-4.c: the same items also carry the declaring `mode:` — the web's
    // primary-agent picker reads it to offer what the TUI's /agent cycle
    // would, so the leniency law must ride the wire: only a preset that
    // actually says `subagent` answers subagent; built-ins (no sourcePath),
    // missing files and any other spelling all answer `all`.
    public function testTheRosterCarriesTheDeclaredPresentingMode(): void
    {
        $items = $this->fixture->client()->call('agents.list')['items'];
        /** @var array<string, array<string, mixed>> $byName */
        $byName = [];
        foreach ($items as $item) {
            $byName[$item['name']] = $item;
        }

        self::assertSame('primary', $byName['boss']['mode'] ?? null, 'a preset declaring primary rides');
        self::assertSame('subagent', $byName['scout']['mode'] ?? null, 'and the picker must not offer this one');
        self::assertSame('all', $byName['dangling']['mode'] ?? null, 'an unreadable preset answers all, the presentsAsPrimary leniency');
        foreach (['crimson', 'hexed', 'plain', 'magenta'] as $builtin) {
            self::assertSame('all', $byName[$builtin]['mode'] ?? null, "a built-in names no mode and answers all");
        }
    }

    public function testAnInterruptMessageLandsInTheMailboxMarkedInterrupt(): void
    {
        [$client, $sessionId] = $this->runningRun('run-i1');

        $sent = $client->call('agents.message', [
            'sessionId' => $sessionId,
            'agentId' => 'run-i1',
            'text' => 'stop, check the schema first',
            'mode' => 'interrupt',
        ]);
        self::assertSame('queued', $sent['status']);

        $delivered = $this->drain($sessionId, 'run-i1');
        self::assertCount(1, $delivered);
        self::assertSame('stop, check the schema first', $delivered[0]->text);
        self::assertTrue($delivered[0]->isFromUser(), 'signed as the user with the launch key, like every steer');
        self::assertSame(MessageMode::Interrupt, $delivered[0]->mode, 'the run reads it before starting more of this step');
        self::assertTrue($delivered[0]->mode->skipsUnstartedCalls());
    }

    public function testOmittingTheModeSteersAsBefore(): void
    {
        [$client, $sessionId] = $this->runningRun('run-i2');

        $client->call('agents.message', ['sessionId' => $sessionId, 'agentId' => 'run-i2', 'text' => 'also check the tests']);

        $delivered = $this->drain($sessionId, 'run-i2');
        self::assertCount(1, $delivered);
        self::assertSame(MessageMode::Steer, $delivered[0]->mode, 'the default is the old one-argument behavior');
    }

    public function testAModeOffTheWireListIsRefused(): void
    {
        [$client, $sessionId] = $this->runningRun('run-i3');

        $bogus = $client->request('agents.message', ['sessionId' => $sessionId, 'agentId' => 'run-i3', 'text' => 'x', 'mode' => 'explode']);
        self::assertSame(ErrorCode::InvalidParams->value, $bogus['error']['code']);

        // `followup` is a real MessageMode, but a finished run's follow-up is
        // what agents.message does on its own when the run has ended — the
        // wire never takes it.
        $internal = $client->request('agents.message', ['sessionId' => $sessionId, 'agentId' => 'run-i3', 'text' => 'x', 'mode' => 'followup']);
        self::assertSame(ErrorCode::InvalidParams->value, $internal['error']['code']);
    }

    // ── harness ─────────────────────────────────────────────────────────

    /** @return array{0: WireClient, 1: string} a client and a session holding one running run */
    private function runningRun(string $runId): array
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $client->call('session.subscribe', ['sessionId' => $sessionId]);
        $this->fixture->run();
        $client->call('session.send', ['sessionId' => $sessionId, 'text' => 'delegate it']);
        $this->fixture->backend->emit(new SubAgentActivity(
            op: SubAgentActivity::OP_STARTED,
            id: $runId,
            name: 'reviewer',
            task: 'fix it',
            seq: 1,
            tail: '',
            parentCallId: 'c1',
            description: '',
            outcome: '',
        ));
        $this->fixture->run(0.08);

        return [$client, $sessionId];
    }

    /** @return list<\SugarCraft\Code\Agents\Live\AgentMessage> the running run's whole mailbox */
    private function drain(string $sessionId, string $runId): array
    {
        $inbox = $this->fixture->hub->workspace()->agentInbox($sessionId);
        self::assertInstanceOf(AgentInbox::class, $inbox);

        return $inbox->drain($runId);
    }

    private function roster(): AgentManager
    {
        $manager = new AgentManager($this->createStub(ProviderInterface::class), new SkillRegistry());
        $make = fn (string $name, ?string $color, ?string $sourcePath = null): Agent => new Agent(
            name: $name,
            description: $name . ' description',
            prompt: 'Test prompt',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
            color: $color,
            sourcePath: $sourcePath,
        );
        $manager->register($make('crimson', 'red'));
        $manager->register($make('hexed', '#003D99'));
        $manager->register($make('plain', null));
        $manager->register($make('magenta', 'magenta'));
        // C-AGT-4.c: one preset per declared tier, plus a sourcePath whose
        // file is gone — the leniency door the TUI's cycle already walks.
        $manager->register($make('boss', null, $this->presetFile('boss', 'primary')));
        $manager->register($make('scout', null, $this->presetFile('scout', 'subagent')));
        $manager->register($make('dangling', null, $this->presetDir . '/no-such-preset.md'));

        return $manager;
    }

    /** A launch-trusted preset file whose frontmatter declares $mode. */
    private function presetFile(string $name, string $mode): string
    {
        $path = $this->presetDir . '/' . $name . '.md';
        \file_put_contents($path, "---\nmode: $mode\ndescription: preset\n---\nbody\n");

        return $path;
    }
}
