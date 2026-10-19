<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\ReportsPromptSections;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\ContextCommand;
use SugarCraft\Code\Context\ContextBreakdown;
use SugarCraft\Code\Context\EnvironmentBlock;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Message;
use SugarCraft\Code\Runtime;
use SugarCraft\Code\Tests\Prompt\PromptFixture;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Usage;
use SugarCraft\Code\Util\TokenEstimate;

/**
 * Roadmap 5.6: `/context` (alias `/tokens`) breaks the next request down into
 * the system prompt per layer, the tool schemas, the history, the largest
 * messages and the cache-hit share — measured, never invented.
 */
final class ContextCommandTest extends TestCase
{
    use HomeSandboxTrait;

    private ?PromptFixture $fixture = null;

    private string $sandbox = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-context-' . bin2hex(random_bytes(6));
        $this->useHomeSandbox($this->sandbox . '/home');
    }

    protected function tearDown(): void
    {
        $this->fixture?->destroy();
        $this->restoreHomeSandbox();
        if (is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox));
        }
    }

    public function testThePromptLayersSumToTheAssembledPromptExactly(): void
    {
        $this->fixture = (new PromptFixture())
            ->write('CLAUDE.md', "# Conventions\n\nUse tabs.\n")
            ->write('AGENTS.md', "# Agents\n\nBe brief.\n");
        $app = $this->fixture->app();
        $runtime = $this->runtime($app);

        $rows = $runtime->promptSectionSizes($app);
        $prompt = $this->fixture->systemPrompt($app, $runtime);

        self::assertSame(strlen($prompt), array_sum(array_column($rows, 'bytes')), 'the rows are the prompt, byte for byte');
        $labels = array_column($rows, 'label');
        self::assertSame('base', $labels[0], 'the base identity opens the prompt');
        self::assertSame('env', $labels[count($labels) - 1], '<env> stays last');
        self::assertContains('maxims', $labels);
        $instructions = $rows[array_search('project-instructions', $labels, true)];
        self::assertSame(2, $instructions['sections'], 'two documents are one row that counts both');
        self::assertSame('per-session', $instructions['stability']);
        foreach ($rows as $row) {
            self::assertGreaterThan(0, $row['tokens'], "{$row['label']} is estimated, not zero");
            self::assertSame(count($rows), count(array_unique($labels)), 'one row per label');
        }
    }

    public function testTheBreakdownIsMeasuredFromTheHistoryItIsGiven(): void
    {
        $big = str_repeat('word ', 400);
        $history = [
            Message::user('short question'),
            Message::assistant($big)->withUsage(Usage::new(400, 0.0, inputTokens: 100, cacheReadTokens: 300)),
            Message::user('/help')->withUiOnly(),
            Message::assistant('help listing')->withUiOnly(),
            Message::user("second\nline two")->withUsage(null),
            Message::assistant('ok')->withUsage(Usage::new(200, 0.0, inputTokens: 50, cacheReadTokens: 150)),
        ];

        $b = ContextBreakdown::measure($history, null, null, 100_000, 1234, largest: 2);

        self::assertSame(4, $b->historyMessages);
        self::assertSame(2, $b->uiOnlyRows);
        self::assertSame(1234, $b->historyTokens, 'the status bar figure is passed through, never recomputed');
        self::assertCount(2, $b->largest);
        self::assertSame(2, $b->largest[0]['index'], 'the biggest row first, by its transcript position');
        self::assertSame('assistant', $b->largest[0]['role']);
        self::assertSame(75, $b->lastCachePercent(), 'the newest reply: 150 of 200');
        self::assertSame(75, $b->sessionCachePercent(), '450 of 600 across both');
        self::assertSame(2, $b->sessionCache['replies'] ?? null);
        self::assertNull($b->systemTokens(), 'an unreported prompt stays unknown');
        self::assertSame(1234, $b->totalTokens());
    }

    public function testTheReportSaysNotMeasuredRatherThanPrintingAZero(): void
    {
        $report = ContextCommand::compose(ContextBreakdown::measure([Message::user('hi')], null, null, 8192, 12));

        self::assertStringContainsString('System prompt: not measured', $report);
        self::assertStringContainsString('Tool schemas: not measured', $report);
        self::assertStringContainsString('Instruction files: not measured', $report, 'a breakdown that never priced its documents says so, never lists nothing');
        self::assertStringContainsString('Prompt cache: no reply has reported a cache split yet.', $report);
        self::assertStringContainsString('History: ~12 (1 message sent to the model; 0 UI-only rows never sent)', $report);
    }

    public function testTheReportListsEveryLayerToolsAndLargestMessages(): void
    {
        $sections = [
            ['label' => 'base', 'stability' => 'static', 'sections' => 1, 'bytes' => 4000, 'tokens' => 1000],
            ['label' => 'project-instructions', 'stability' => 'per-session', 'sections' => 3, 'bytes' => 2048, 'tokens' => 512],
        ];
        $history = [Message::user("look at \x1b[31mthis\x1b[0m please")];

        $report = ContextCommand::compose(ContextBreakdown::measure($history, $sections, [], 200_000, 20));

        self::assertStringContainsString('System prompt: ~1.5K (5.9 KB, 4 sections)', $report);
        self::assertMatchesRegularExpression('/^  base\s+~1K\s+static$/m', $report);
        self::assertMatchesRegularExpression('/^  project-instructions ×3\s+~512\s+per-session$/m', $report);
        self::assertStringContainsString('Tool schemas: ~0 (0 tools)', $report);
        self::assertStringContainsString('Context: ~1.5K of 200K tokens (1%)', $report);
        self::assertStringNotContainsString("\x1b", $report, 'quoted transcript text cannot carry an escape');
        self::assertMatchesRegularExpression('/^  #1\s+user\s+~\d+\s+look at/m', $report);
    }

    /**
     * ISS-101: the layer rows pad by display cells, not bytes — '×' is two
     * bytes for one cell and a CJK label is three bytes for two, and both
     * must still put the stability column at cell 35
     * (2 indent + 24 label + 1 + 7 tokens + 1).
     */
    public function testSectionRowsAlignByDisplayCellsNotBytes(): void
    {
        $sections = [
            ['label' => 'base', 'stability' => 'static', 'sections' => 1, 'bytes' => 100, 'tokens' => 10],
            ['label' => 'project-instructions', 'stability' => 'per-session', 'sections' => 2, 'bytes' => 100, 'tokens' => 20],
            ['label' => 'セッション', 'stability' => 'static', 'sections' => 1, 'bytes' => 100, 'tokens' => 30],
        ];

        $report = ContextCommand::compose(ContextBreakdown::measure([Message::user('hi')], $sections, [], 200_000, 20));

        $rows = array_values(array_filter(
            explode("\n", $report),
            static fn (string $line): bool => (bool) preg_match('/^  (base |project-instructions|セッション)/', $line . ' '),
        ));
        self::assertCount(3, $rows, 'all three layer rows are painted');

        foreach ($rows as $row) {
            $field = str_contains($row, 'per-session') ? 'per-session' : 'static';
            $offset = strpos($row, $field);
            self::assertIsInt($offset);
            self::assertSame(35, \SugarCraft\Core\Util\Width::string(substr($row, 0, $offset)), "column drift in: {$row}");
        }
    }

    public function testSlashContextAndItsTokensAliasAnswerLocally(): void
    {
        $backend = new class () implements Backend, ReportsPromptSections {
            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                throw new \LogicException('/context must not call the model');
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?\SugarCraft\Code\Backend\CancellationToken $cancellation = null, ?callable $onEvent = null): \React\Promise\PromiseInterface
            {
                throw new \LogicException('/context must not call the model');
            }

            public function promptSectionSizes(): array
            {
                return [['label' => 'base', 'stability' => 'static', 'sections' => 1, 'bytes' => 40, 'tokens' => 10]];
            }
        };

        foreach (['/context', '/tokens', '/context ignored words'] as $draft) {
            $chat = (new Chat(history: [Message::user('hello'), Message::assistant('hi')], inputBuf: $draft, backend: $backend))->withSize(120, 30);
            [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter));

            self::assertNull($cmd, "{$draft} calls no model");
            self::assertFalse($next->inFlight);
            $added = array_slice($next->history, 2);
            self::assertCount(2, $added, "{$draft} appends its echo and its answer");
            self::assertTrue($added[0]->uiOnly && $added[1]->uiOnly, 'neither row ever reaches a model');
            self::assertStringContainsString('System prompt: ~10 (40 B, 1 section)', $added[1]->content);
        }
    }

    public function testTheEngineBackendMeasuresItsOwnSystemPrompt(): void
    {
        $this->fixture = (new PromptFixture())->write('CLAUDE.md', "# Conventions\n\nUse tabs.\n");
        $backend = \SugarCraft\Code\Backend\EngineBackend::new(new \SugarCraft\Code\Providers\EchoProvider(), 'm')
            ->withRoot($this->fixture->root());

        self::assertInstanceOf(ReportsPromptSections::class, $backend, '/context would say "not measured" on the real backend');
        $rows = $backend->promptSectionSizes();
        self::assertSame('base', $rows[0]['label'] ?? null, 'the engine reports its prompt from the base identity on');
        self::assertGreaterThan(0, array_sum(array_column($rows, 'bytes')));
    }

    public function testToolSchemasArePricedLikeTheRequestPricesThem(): void
    {
        $tools = [new \SugarCraft\Code\Tools\BuiltIn\Read()];
        $b = ContextBreakdown::measure([], null, $tools, 1000, 0);

        self::assertSame(1, $b->toolCount);
        self::assertSame(TokenEstimate::ofToolSchemas($tools), $b->toolTokens);
    }

    /**
     * Roadmap 3.B-5 (DCP §13.2 P2-10): `/context` says how often a request
     * lost the prefix the request before it had cached — the telemetry
     * opencode-dcp #614 was diagnosed from — and "not measured" where the
     * backend keeps no such count, never a zero.
     */
    public function testTheReportCarriesTheCacheBreakLine(): void
    {
        $cached = [Message::user('q'), Message::assistant('a')->withUsage(Usage::new(2000, 0.0, inputTokens: 200, cacheReadTokens: 1800))];
        $measured = ContextBreakdown::measure($cached, null, null, 100_000, 10);

        self::assertStringContainsString(
            "Cache breaks: not measured — this backend does not track its requests' cache reuse.",
            ContextCommand::compose($measured),
        );
        self::assertStringContainsString('Cache breaks: none this session.', ContextCommand::compose($measured->withCacheBreaks(0, null)));
        self::assertStringNotContainsString(
            'Cache breaks',
            ContextCommand::compose(ContextBreakdown::measure([Message::user('hi')], null, null, 8192, 1)->withCacheBreaks(0, null)),
            'with no cache split reported, the cache line above already says it all',
        );

        $report = ContextCommand::compose($measured->withCacheBreaks(2, ['from' => 90, 'to' => 5]));
        self::assertStringContainsString(
            'Cache breaks: 2 this session — the newest read 5% of its prompt from cache, after 90% on the request before.',
            $report,
        );
        self::assertSame(2, $measured->withCacheBreaks(2, null)->withPruning(\SugarCraft\Code\Context\Pruning\ContextLedger::new(), $cached)->cacheBreaks['breaks'] ?? null, 'withPruning() keeps the breaks');
    }

    /**
     * The figure is the engine's own: a turn whose second and fourth requests
     * read almost none of the prefix the one before had cached counts two breaks
     * on the backend's shared watch, and `/context` reads them through
     * {@see \SugarCraft\Code\Backend\EngineBackend::cacheBreaks()}.
     */
    public function testSlashContextReadsTheEnginesCacheBreaks(): void
    {
        $provider = new class () implements \SugarCraft\Code\Providers\ProviderInterface {
            public int $calls = 0;

            public function name(): string { return 'cache-breaking'; }
            public function supportsStreaming(): bool { return false; }
            public function supportsFunctionCalling(): bool { return true; }
            public function supportsVision(): bool { return false; }
            public function supportsDocuments(): bool { return false; }
            public function supportsJsonSchema(): bool { return false; }
            public function contextWindow(): int { return 100_000; }
            public function costPer1kTokens(string $model, string $direction): float { return 0.0; }

            public function complete(\SugarCraft\Code\Providers\CompleteRequest $request): \SugarCraft\Code\Providers\CompleteResponse
            {
                $call = ++$this->calls;
                // 90% of a 2,000-token prompt from cache; then 5%, 95% and
                // 5% of 2,100: two breaks, with a recovery between them.
                $read = [1 => 1800, 2 => 105, 3 => 1995][$call] ?? 105;

                return new \SugarCraft\Code\Providers\CompleteResponse(
                    content: $call < 4 ? 'checking' : 'done',
                    toolCalls: $call < 4 ? [new \SugarCraft\Code\Tools\ToolCall('call_' . $call, 'no_such_tool', [])] : null,
                    usage: Usage::new(2100, 0.0, 2100 - $read - ($call === 1 ? 100 : 0), 20, $read, 0),
                );
            }

            public function completeStream(\SugarCraft\Code\Providers\CompleteRequest $request): \Generator
            {
                yield $this->complete($request);
            }

            public function embeddings(\SugarCraft\Code\Providers\EmbeddingsRequest $request): \SugarCraft\Code\Providers\EmbeddingsResponse
            {
                return new \SugarCraft\Code\Providers\EmbeddingsResponse([]);
            }
        };
        $backend = \SugarCraft\Code\Backend\EngineBackend::new($provider, 'm');

        \SugarCraft\Code\Diagnostics\RuntimeNoticeSink::reset();
        $previous = ini_set('error_log', $this->sandbox . '-error.log');
        try {
            $backend->complete([Message::user('go')]);
        } finally {
            ini_set('error_log', (string) $previous);
            @unlink($this->sandbox . '-error.log');
            \SugarCraft\Code\Diagnostics\RuntimeNoticeSink::reset();
        }

        self::assertSame(4, $provider->calls);
        self::assertSame(2, $backend->withMaxSteps(9)->cacheBreaks(), 'every clone reads the one shared watch');
        self::assertSame(['from' => 95, 'to' => 5], $backend->lastCacheBreak());

        $reply = (new \SugarCraft\Code\Host\Commands\ContextHostCommand())->run(
            \SugarCraft\Code\Host\Commands\CommandContext::new(history: [Message::user('go')], backend: $backend, contextTokenLimit: 100_000, contextTokens: 5),
            '/context',
        );
        $text = implode("\n", array_map(static fn (Message $m): string => $m->content, $reply->rows));
        self::assertStringContainsString('Cache breaks: 2 this session — the newest read 5% of its prompt from cache, after 95% on the request before.', $text);
    }

    /**
     * Roadmap VIS-2a: `/context` names the instruction documents the system
     * prompt splices — the repository-shipped ones, the operator's personal
     * one (marked, because provenance frames it differently), and the paths
     * the loader refused. Names and sizes only; bodies never reach the panel.
     */
    public function testTheReportNamesTheInstructionFilesAndRefusals(): void
    {
        $files = [
            'files' => [
                ['path' => '/repo/CLAUDE.md', 'bytes' => 2048, 'personal' => false],
                ['path' => '/home/op/.candy-code/AGENTS.md', 'bytes' => 512, 'personal' => true],
            ],
            'refused' => [
                ['path' => '/repo/imported.md', 'reason' => 'over the size ceiling'],
            ],
        ];
        $report = ContextCommand::compose(ContextBreakdown::measure([Message::user('hi')], null, null, 8192, 12, instructionFiles: $files));

        self::assertStringContainsString('Instruction files:', $report);
        self::assertMatchesRegularExpression('/^  2 KB\s+\/repo\/CLAUDE\.md$/m', $report);
        self::assertMatchesRegularExpression('/^  512 B\s+\/home\/op\/\.candy-code\/AGENTS\.md \(personal\)$/m', $report);
        self::assertStringContainsString('not read: /repo/imported.md — over the size ceiling', $report);
        self::assertStringNotContainsString('Instruction files: not measured', $report);
    }

    public function testPricedButEmptyInstructionFilesSayNoneNotNothingAtAll(): void
    {
        $report = ContextCommand::compose(ContextBreakdown::measure(
            [Message::user('hi')], null, null, 8192, 12,
            instructionFiles: ['files' => [], 'refused' => []],
        ));

        self::assertStringContainsString("Instruction files:\n  - none", $report);
        self::assertStringNotContainsString('Instruction files: not measured', $report);
    }

    /**
     * The ctor is POSITIONAL and both withers re-new it by hand — one shifted
     * slot and pruning lands in the breaks or the documents vanish under a
     * wither. Pinned by slot identity, not by re-deriving the wither bodies.
     */
    public function testInstructionFilesRideEveryPositionalWitherWithoutShiftingSlots(): void
    {
        $b = new ContextBreakdown(200, null, null, null, 1, 1, 0, [], null, null, ['slot' => 'pruning'], ['slot' => 'breaks'], ['slot' => 'files']);
        self::assertSame(['slot' => 'pruning'], $b->pruning);
        self::assertSame(['slot' => 'breaks'], $b->cacheBreaks);
        self::assertSame(['slot' => 'files'], $b->instructionFiles);

        $documents = ['files' => [['path' => '/x/CLAUDE.md', 'bytes' => 10, 'personal' => false]], 'refused' => []];
        $history = [Message::user('hi')];
        $measured = ContextBreakdown::measure($history, null, null, 8192, 12, instructionFiles: $documents);
        $pruned = $measured->withPruning(\SugarCraft\Code\Context\Pruning\ContextLedger::new(), $history);
        self::assertSame($documents, $pruned->instructionFiles, 'withPruning() must carry the documents through');
        $both = $pruned->withCacheBreaks(1, ['from' => 90, 'to' => 5]);
        self::assertSame($documents, $both->instructionFiles, 'withCacheBreaks() must carry them too');
        self::assertSame(['breaks' => 1, 'last' => ['from' => 90, 'to' => 5]], $both->cacheBreaks, 'the breaks still land in their own slot');
        self::assertIsArray($both->pruning);
    }

    /**
     * End to end: `/context` on a real session root names the CLAUDE.md that
     * root actually ships — the loader, not a fixture array, feeds the panel.
     */
    public function testTheHostCommandFeedsTheRealLoader(): void
    {
        $this->fixture = (new PromptFixture())->write('CLAUDE.md', "# Conventions\n\nUse tabs.\n");

        $reply = (new \SugarCraft\Code\Host\Commands\ContextHostCommand())->run(
            \SugarCraft\Code\Host\Commands\CommandContext::new(
                history: [Message::user('go')],
                root: $this->fixture->root(),
                contextTokenLimit: 100_000,
                contextTokens: 5,
            ),
            '/context',
        );
        $text = implode("\n", array_map(static fn (Message $m): string => $m->content, $reply->rows));

        self::assertStringContainsString('Instruction files:', $text);
        self::assertStringContainsString($this->fixture->root() . '/CLAUDE.md', $text);
        self::assertStringNotContainsString('Instruction files: not measured', $text);
    }

    private function runtime(App $app): Runtime
    {
        return new Runtime(
            $app->provider,
            new HookManager(new HookRegistry()),
            new EnvironmentBlock($this->fixture?->root() ?? '/', $app->model, new \DateTimeImmutable('2026-01-15 12:00:00 UTC'), 'linux'),
        );
    }
}
