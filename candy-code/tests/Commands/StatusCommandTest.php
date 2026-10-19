<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\StatusCommand;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;

/**
 * `/status` — OBS-2: the session's facts, one screen, every row owned by the
 * surface that measures it.
 *
 * The page's whole claim is COMPLETENESS: every section and every row prints
 * always — value, count, or a stated `not measured` — so the screen has a
 * FIXED line budget, and the forgery guard below counts lines rather than
 * scanning for residue (the `/notices` template, carried over because it is
 * the right shape: one hostile directory name must not be able to fabricate
 * an `Ecosystem:` header the way a raw newline through an unescaped field
 * would).
 *
 * Everything the routing test drives goes through a real submitted draft via
 * `Chat::update(new KeyMsg(KeyType::Enter))`, never the private handler —
 * the dispatch is half of what is under test (the discipline
 * `NoticesCommandTest` and `SlashDispatchTest` share; the latter covers the
 * registry row automatically).
 */
final class StatusCommandTest extends TestCase
{
    /**
     * 2 title lines + 4 blanks + 5 section headers + 16 fact rows = 28. The
     * fact count is FIXED by the page's contract: a row that goes absent
     * rather than sentinel-ing is exactly the defect this constant catches.
     */
    private const PAGE_LINES = 28;

    // ── the command surface ──────────────────────────────────────────────

    public function testStatusIsAnsweredLocallyAsAnAppendOnlyTranscriptPair(): void
    {
        $next = $this->submit($this->statusChat(draft: '/status'));

        self::assertFalse($next->inFlight, 'every row is read off live state; nothing may be in flight');
        self::assertSame('', self::readInputBuf($next));

        $added = array_slice($next->history, 2);
        self::assertCount(2, $added, '/status must echo the command and answer it');
        self::assertSame(Role::User, $added[0]->role);
        self::assertSame('/status', $added[0]->content);
        self::assertSame(Role::Assistant, $added[1]->role);
        self::assertStringStartsWith('/status — where this session stands', $added[1]->content);
    }

    /**
     * The report is total, so every spelling gets the whole page rather than
     * a "no such subcommand" — `/notices`' stated reason for the same door.
     */
    public function testEverySpellingWithArgumentsGetsTheSameLocalAnswer(): void
    {
        foreach (['/status all', '/status --help', '/status what am I looking at'] as $draft) {
            $next = $this->submit($this->statusChat(draft: $draft));

            self::assertFalse($next->inFlight, $draft . ' must not hand the question to the model');
            $added = array_slice($next->history, 2);
            self::assertCount(2, $added, $draft . ' must echo the command and answer it locally');
            self::assertStringStartsWith('/status — where this session stands', $added[1]->content);
        }
    }

    /**
     * The page the EchoBackend session CAN measure still prints every header
     * it can answer, and says `not measured` rather than skipping what it
     * cannot: the completeness law pinned on the live host path, not just on
     * the pure composer.
     */
    public function testTheLivePageCarriesEverySectionHeaderEvenWhenMostRowsAreSentinels(): void
    {
        $page = array_slice($this->submit($this->statusChat(draft: '/status'))->history, 2)[1]->content;

        foreach (['App:', 'Session:', 'Model:', 'Spend:', 'Ecosystem:'] as $header) {
            self::assertStringContainsString($header, $page, "section {$header} must print even when everything under it is a sentinel");
        }
        self::assertStringContainsString('Id: no session id minted yet', $page);
        self::assertStringContainsString('Durable ledger: none recorded.', $page);
    }

    // ── the pure composer ────────────────────────────────────────────────

    public function testUnknownFactsSaySoRatherThanGoingQuietAbsent(): void
    {
        $page = StatusCommand::compose(self::facts([
            'sessionId' => null,
            'sessionName' => null,
            'sessionTurns' => null,
            'cwd' => null,
            'branch' => null,
            'provider' => null,
            'model' => null,
            'contextTokens' => null,
            'contextTokenLimit' => null,
            'ledger' => null,
            'mcpCount' => null,
            'hookCount' => null,
            'serveState' => 'absent',
            'serve' => null,
        ]));

        foreach ([
            'Id: no session id minted yet',
            'Name: not recorded',
            '  Turns: not recorded',
            'Directory: not recorded by this store',
            'Branch: not recorded by this store',
            'Provider: not measured — this session is not on an engine backend.',
            'Context: not measured — this session reports no window.',
            'Durable ledger: none recorded.',
            'MCP servers: not measured',
            'Hooks: not measured',
            'Serve: no server discovered.',
        ] as $row) {
            self::assertStringContainsString($row, $page, "the stated sentinel '{$row}' is the row's whole job");
        }
        self::assertSame(self::PAGE_LINES, substr_count($page, "\n") + 1, 'sentinels keep the page exactly as long as answers');
    }

    public function testAMeasuredPageCarriesEveryValueAndStaysBudgeted(): void
    {
        $page = StatusCommand::compose(self::facts([
            'sessionId' => 'ses_measured',
            'sessionName' => 'the work',
            'sessionTurns' => 12,
            'cwd' => '/srv/project',
            'branch' => 'topic',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-6',
            'contextTokens' => 52_300,
            'contextTokenLimit' => 200_000,
            'ledger' => ['calls' => 9, 'totalTokens' => 182_400, 'costUsd' => 0.421, 'unpriced' => false],
            'mcpCount' => 2,
            'hookCount' => 7,
            'serveState' => 'running',
            'serve' => ['url' => 'http://127.0.0.1:8080/', 'pid' => 4242, 'uptime' => 3725, 'version' => '1.0.0'],
        ]));

        foreach ([
            '  Id: ses_measured',
            '  Name: the work',
            '  Turns: 12',
            '  Directory: /srv/project',
            '  Branch: topic',
            '  Provider: anthropic',
            '  Model: claude-sonnet-4-6',
            '  Context: 52,300 of 200,000 tokens (26%)',
            '  Durable ledger: 9 calls, 182,400 tokens, $0.4210',
            '  MCP servers: 2',
            '  Hooks: 7 registered',
            '  Serve: http://127.0.0.1:8080/ (pid 4242, v1.0.0, up 1h02m)',
        ] as $row) {
            self::assertStringContainsString($row, $page);
        }
        self::assertSame(self::PAGE_LINES, substr_count($page, "\n") + 1);
    }

    /**
     * The unpriced-lower-bound law (SES-1): a ledger row whose unpriced bit is
     * set prints its dollars as a LOWER BOUND, the same `≥` every other dollar
     * surface in SpendLedger carries. Priced bytes stay byte-identical.
     */
    public function testTheDurableLedgerRowCarriesTheLowerBoundLaw(): void
    {
        $unpriced = StatusCommand::compose(self::facts([
            'ledger' => ['calls' => 1, 'totalTokens' => 10, 'costUsd' => 0.5, 'unpriced' => true],
        ]));
        self::assertStringContainsString('Durable ledger: 1 calls, 10 tokens, ≥$0.5000', $unpriced);

        $priced = StatusCommand::compose(self::facts([
            'ledger' => ['calls' => 1, 'totalTokens' => 10, 'costUsd' => 0.5, 'unpriced' => false],
        ]));
        self::assertStringContainsString('Durable ledger: 1 calls, 10 tokens, $0.5000', $priced);
        self::assertStringNotContainsString('≥$', $priced);
    }

    /**
     * The line-budget discipline `NoticesCommandTest` and
     * `PermissionsCommandTest` share: count LINES against the formula, because
     * a raw newline inside one foreign fact is what would make the rest of the
     * screen lie — and directory names, session names, branches, config paths
     * and the serve record are all text this app does not author.
     */
    public function testForeignTextCannotForgeASectionHeader(): void
    {
        $hostile = "evil\nEcosystem:\nforged\tx\x1b[31mred\r";

        $page = StatusCommand::compose(self::facts([
            'version' => $hostile,
            'configPath' => $hostile,
            'theme' => $hostile,
            'sessionId' => $hostile,
            'sessionName' => $hostile,
            'cwd' => $hostile,
            'branch' => $hostile,
            'provider' => $hostile,
            'model' => $hostile,
            'spend' => $hostile,
            'mcpReason' => $hostile,
            'hookReason' => $hostile,
            'serveState' => 'unmeasured',
            'serveReason' => $hostile,
        ]));

        self::assertSame(self::PAGE_LINES, substr_count($page, "\n") + 1, 'one fact may never add two lines — the newline must arrive escaped');
        self::assertStringNotContainsString("\x1b", $page, 'no raw ANSI byte survives reportField()');
        self::assertSame(1, preg_match_all('/^Ecosystem:$/m', $page), 'the forged header must be visible as text mid-row, never operative as a line');
        self::assertStringContainsString('\\nEcosystem:', $page);
        self::assertStringContainsString('\\t', $page);
        self::assertStringContainsString('\\r', $page);
    }

    public function testComposingTheSameFactsTwiceSaysTheSameThing(): void
    {
        $facts = self::facts([]);
        self::assertSame(StatusCommand::compose($facts), StatusCommand::compose($facts), 'pure — no statics under compose()');
    }

    public function testAMissingFactIsRefusedRatherThanSilentlySkipped(): void
    {
        $facts = self::facts([]);
        unset($facts['theme']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('theme');
        StatusCommand::compose($facts);
    }

    public function testAServeStateOutsideTheThreeStatesIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('serveState');
        StatusCommand::compose(self::facts(['serveState' => 'maybe']));
    }

    // ── harness ──────────────────────────────────────────────────────────

    /**
     * A full measured page with the probe's own sentinel values, overridden
     * per test — so every compose() call below passes the COMPLETE fact map
     * the fail-fast door demands without repeating 21 lines per case.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function facts(array $overrides): array
    {
        return $overrides + [
            'version' => '1.0.0-probe',
            'configPath' => '/probe/.candy-code/config.json',
            'theme' => 'dark (default)',
            'sessionId' => 'ses_probe',
            'sessionName' => 'probe',
            'sessionTurns' => 0,
            'cwd' => '/probe',
            'branch' => 'probe',
            'provider' => 'probe-provider',
            'model' => 'probe-model',
            'contextTokens' => 100,
            'contextTokenLimit' => 1000,
            'spend' => 'Spent $0.0000 — no cap.',
            'ledger' => null,
            'mcpCount' => 0,
            'mcpReason' => null,
            'hookCount' => 0,
            'hookReason' => null,
            'serveState' => 'absent',
            'serve' => null,
            'serveReason' => null,
        ];
    }

    private function statusChat(string $draft = '/status'): Chat
    {
        return (new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            inputBuf: $draft,
            backend: new EchoBackend(),
        ))->withSize(120, 30);
    }

    private function submit(Chat $chat): Chat
    {
        [$next] = $chat->update(new KeyMsg(KeyType::Enter));

        return $next;
    }

    private static function readInputBuf(Chat $subject): mixed
    {
        return (new \ReflectionProperty($subject, 'inputBuf'))->getValue($subject);
    }
}
