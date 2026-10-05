<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\Context\Pruning\CompressionBlock;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\NudgePolicy;
use SugarCraft\Crush\Context\Pruning\PruningMode;
use SugarCraft\Crush\Events\ContextLedgerChanged;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\ToolCall as RowCall;
use SugarCraft\Crush\ToolResult as RowResult;
use SugarCraft\Crush\Tools\BuiltIn\Compress;
use SugarCraft\Crush\Tools\BuiltIn\Prune;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap 3.B-4, in the engine: on a turn the person started with
 * `/compress`, the model is offered `Compress` (manual by default — on no
 * other turn), its call lands in the turn's own ledger so the very next step
 * already sends the summary in the range's place, the host is shown the
 * block live, and the turn hands it back on its reply. And once the context
 * fills up, the model is nudged — on a tool result or a prompt, never on an
 * assistant row.
 */
final class InTurnCompressionTest extends TestCase
{
    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->root !== null) {
            @rmdir($this->root);
        }
    }

    public function testACompressTurnOffersTheToolAndTheNextStepSendsTheSummary(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('k1', 'Compress', [
                'topic' => 'Reading a.php',
                'ranges' => [['from' => 'r1', 'to' => 'r2', 'summary' => 'a.php defines login().']],
            ])]),
            new CompleteResponse(content: 'Compressed the a.php exploration.'),
        ], contextWindow: 1_000_000);
        $events = [];

        $reply = $this->engine($provider)->withContextLedger(self::auto())->complete(
            self::history(Compress::triggerPrompt('the a.php work')),
            onEvent: static function (object $event) use (&$events): void {
                $events[] = $event;
            },
        );

        $this->assertContains('Compress', self::offered($provider->requests[0]), 'offered on the /compress turn');
        $second = $provider->requests[1]->messages;
        $this->assertTrue(CompressionBlock::isSummaryRow($second[0]), 'the next step already sends the summary');
        $this->assertStringContainsString('a.php defines login().', $second[0]->content());
        $this->assertNotContains('old', self::resultIds($second), 'the compressed output is not sent');

        $block = $reply->contextLedger?->block(1);
        $this->assertNotNull($block, 'the turn hands the block back');
        $this->assertTrue($block->isRange());
        $changes = array_values(array_filter($events, static fn (object $e): bool => $e instanceof ContextLedgerChanged));
        $this->assertSame(1, \count($changes[0]->delta->blocks ?? []), 'and shows it to the host live');
    }

    public function testAManualSessionIsOfferedCompressOnlyOnTheCompressTurn(): void
    {
        $manual = ContextLedger::new()->withDefaultMode(PruningMode::Manual);
        $provider = new ScriptedProvider([new CompleteResponse(content: 'nothing closed yet')], contextWindow: 1_000_000);
        $this->engine($provider)->withContextLedger($manual)->complete(self::history(Compress::triggerPrompt('')));

        $offered = self::offered($provider->requests[0]);
        $this->assertContains('Compress', $offered, 'the /compress turn offers it in manual');
        $this->assertNotContains('Prune', $offered, 'manual never offers the model Prune');

        $plain = new ScriptedProvider([new CompleteResponse(content: 'hi')], contextWindow: 1_000_000);
        $this->engine($plain)->withContextLedger($manual)->complete(self::history('just a question'));
        $this->assertNotContains('Compress', self::offered($plain->requests[0]));
    }

    public function testOnAnyOtherTurnTheToolIsNotOffered(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'hi')], contextWindow: 1_000_000);

        $this->engine($provider)->withContextLedger(self::auto())->complete(self::history('just a question'));

        $offered = self::offered($provider->requests[0]);
        $this->assertNotContains('Compress', $offered, 'manual by default');
        $this->assertContains('Prune', $offered);
    }

    public function testAFullContextIsNudgedOnAToolResultNeverAnAssistantRow(): void
    {
        $step = 0;
        $provider = new ScriptedProvider([
            static function (CompleteRequest $request) use (&$step): CompleteResponse {
                $step++;

                return $step <= 2
                    ? new CompleteResponse(content: '', toolCalls: [new ToolCall("c{$step}", 'Read', ['file_path' => "{$step}.php"])])
                    : new CompleteResponse(content: 'done');
            },
        ], contextWindow: 1_000_000);

        // Two ~64k-token reads: past the reminder's 120k limit, and still under
        // the 150k in-turn step budget the N-P4b default cap sets on a 1M
        // window — so the request is reminded rather than relieved first.
        $reply = $this->engine($provider, str_repeat('source line here ', 15_000))
            ->withContextLedger(self::auto())
            ->complete([Message::user('read everything')]);

        $nudged = [];
        foreach ($provider->requests[2]->messages as $message) {
            if (str_contains($message->content(), NudgePolicy::OPEN)) {
                $nudged[] = $message;
            }
        }
        $this->assertNotEmpty($nudged, 'past the minimum, the model is reminded');
        foreach ($nudged as $message) {
            $this->assertNotInstanceOf(AssistantMessage::class, $message);
            $this->assertTrue($message instanceof ToolResultMessage || $message instanceof UserMessage);
        }
        $this->assertNotSame([], $reply->contextLedger?->nudges, 'the anchor is kept for the next turn');
    }

    /** W9 integration: `contextPruning.compress: auto` offers Compress unprompted — where Prune is offered. */
    public function testCompressAutoOffersTheToolOnEveryTurnWhereTheModelPrunes(): void
    {
        $config = CompactorConfig::fromSettings([CompactorConfig::SETTING_COMPRESS => 'auto']);
        $provider = new ScriptedProvider([new CompleteResponse(content: 'hi')], contextWindow: 1_000_000);

        $this->engine($provider)->withCompactorConfig($config)->withContextLedger(self::auto())->complete(self::history('just a question'));

        $offered = self::offered($provider->requests[0]);
        $this->assertContains('Compress', $offered, 'auto: no /compress needed');
        $this->assertContains('Prune', $offered);

        $manual = new ScriptedProvider([new CompleteResponse(content: 'hi')], contextWindow: 1_000_000);
        $this->engine($manual)->withCompactorConfig($config)
            ->withContextLedger(ContextLedger::new()->withDefaultMode(PruningMode::Manual))
            ->complete(self::history('just a question'));
        $this->assertNotContains('Compress', self::offered($manual->requests[0]), 'never where the model may not prune');
    }

    /** W9 integration: the reminder thresholds are the contextPruning.* settings. */
    public function testTheReminderThresholdsAreSettings(): void
    {
        $step = 0;
        $provider = new ScriptedProvider([
            static function (CompleteRequest $request) use (&$step): CompleteResponse {
                $step++;

                return $step <= 2
                    ? new CompleteResponse(content: '', toolCalls: [new ToolCall("c{$step}", 'Read', ['file_path' => "{$step}.php"])])
                    : new CompleteResponse(content: 'done');
            },
        ], contextWindow: 1_000_000);

        $this->engine($provider, str_repeat('source line here ', 15_000))
            ->withCompactorConfig(CompactorConfig::fromSettings([
                CompactorConfig::SETTING_NUDGE_MIN_TOKENS => 400_000,
                CompactorConfig::SETTING_NUDGE_MAX_TOKENS => 500_000,
            ]))
            ->withContextLedger(self::auto())
            ->complete([Message::user('read everything')]);

        foreach ($provider->requests[2]->messages as $message) {
            $this->assertStringNotContainsString(NudgePolicy::OPEN, $message->content(), 'under the configured minimum, no reminder');
        }
    }

    // ── harness ─────────────────────────────────────────────────────────

    private function engine(ScriptedProvider $provider, string $output = 'a.php line'): EngineBackend
    {
        $this->root ??= sys_get_temp_dir() . '/crush-compress-' . bin2hex(random_bytes(6));
        if (!is_dir($this->root)) {
            mkdir($this->root, 0o700, true);
        }

        return EngineBackend::new($provider, 'm')->withoutHooks()->withRoot($this->root)
            ->withTools([self::readTool($output), Prune::new(), Compress::new()]);
    }

    private static function auto(): ContextLedger
    {
        return ContextLedger::new()->withDefaultMode(PruningMode::Auto);
    }

    /** @return list<Message> */
    private static function history(string $prompt): array
    {
        return [
            Message::user('read a.php'),
            Message::assistant('')->withToolCalls([new RowCall('Read', ['file_path' => 'a.php'], 'old')])->withStepId('s_x_1')->withUserVisible(false),
            Message::assistant(str_repeat('a.php line ', 400))->withToolResults([new RowResult('Read', str_repeat('a.php line ', 400), null, 'old')])->withStepId('s_x_1'),
            Message::assistant('read it')->withStepId('s_x_2'),
            Message::user($prompt),
        ];
    }

    /** @return list<string> */
    private static function offered(CompleteRequest $request): array
    {
        return array_map(static fn (Tool $t): string => $t->name(), $request->tools ?? []);
    }

    /**
     * @param list<mixed> $messages
     * @return list<string>
     */
    private static function resultIds(array $messages): array
    {
        $ids = [];
        foreach ($messages as $message) {
            if ($message instanceof ToolResultMessage) {
                $ids[] = $message->toolCallId();
            }
        }

        return $ids;
    }

    private static function readTool(string $output): Tool
    {
        return new class ($output) implements Tool {
            public function __construct(private readonly string $output)
            {
            }

            public function name(): string
            {
                return 'Read';
            }

            public function description(): string
            {
                return 'reads';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => ['file_path' => ['type' => 'string']]];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult('', $this->output);
            }
        };
    }
}
