<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context;

use SugarCraft\Crush\Host\ContextMeter;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Usage;
use SugarCraft\Crush\Util\TokenEstimate;

/**
 * What the next request's context window is spent on — the figures `/context`
 * (and its `/tokens` alias) prints, roadmap 5.6.
 *
 * The status bar shows one number, `~81K / 131K context (38%)`, and that number
 * is the HISTORY alone. A request also carries the system prompt and the tool
 * schemas, and the user asking "why is my context full?" needs the parts:
 * which prompt layer is big, how much the tool definitions cost, which few
 * messages dominate the history, and whether the provider is serving the
 * stable prefix from its cache. Every figure here is measured from the same
 * sources the request is built from; nothing is a second copy that could
 * drift from what is sent.
 *
 * WHAT IS ESTIMATED AND WHAT IS REPORTED. Token figures for the prompt, the
 * tools and the messages are script-weighted estimates
 * ({@see TokenEstimate}), so the report prints them with `~`. The cache
 * figures are the provider's own counts off each reply's {@see Usage}.
 *
 * WHAT IS UNKNOWN STAYS NULL. A backend that does not assemble its own prompt
 * (a command backend) cannot report its sections, and one with no tool list
 * has no schemas to price: those parts are null, and the report says "not
 * measured" rather than printing a 0 that reads as "nothing there". The same
 * rule for the cache: no reply that reported a prompt split means no ratio.
 *
 * A plain immutable value; {@see measure()} is the one constructor callers
 * use, and it is pure, so the whole report is testable from a history.
 */
final readonly class ContextBreakdown
{
    /** How many of the biggest messages {@see measure()} keeps by default. */
    public const LARGEST_MESSAGES = 5;

    /** Characters of a message's first line a largest-message row previews. */
    public const PREVIEW_CHARS = 60;

    /**
     * @param int $window the context window every figure is a share of
     * @param list<array{label: string, stability: string, sections: int, bytes: int, tokens: int}>|null $sections
     *        the system prompt per layer ({@see \SugarCraft\Crush\Runtime::promptSectionSizes()}),
     *        null when the backend does not report it
     * @param int|null $toolCount   tools whose schemas ride each request, null when unknown
     * @param int|null $toolTokens  their estimated cost, null when unknown
     * @param int $historyTokens    the history's estimate — the status bar's figure
     * @param int $historyMessages  history rows the model is sent
     * @param int $uiOnlyRows       transcript rows never sent (command echoes, notices)
     * @param list<array{index: int, role: string, tokens: int, preview: string}> $largest
     *        the biggest sent messages, biggest first; `index` is the row's
     *        1-based position in the transcript
     * @param array{read: int, prompt: int}|null $lastCache    the newest reply's cache split
     * @param array{read: int, prompt: int, replies: int}|null $sessionCache every reply's, summed
     * @param array{mode: string, sessionMode: bool, outputs: int, outputTokens: int, contextRows: int, contextRowTokens: int, block: ?array{id: int, compressed: int, summary: int}, rows: list<array{ref: ?int, tool: string, reason: string, by: string, tokens: int}>}|null $pruning
     *        what the session's context ledger takes out of the history the
     *        model is sent (roadmap 5.6 / 3.B), null when not measured
     *        ({@see withPruning()})
     * @param array{breaks: int, last: ?array{from: int, to: int}}|null $cacheBreaks
     *        the session's prompt-cache breaks — requests that lost the prefix
     *        the request before them had cached — and the newest as the
     *        cached share before and after it (roadmap 3.B-5); null when the
     *        backend does not track them ({@see withCacheBreaks()})
     */
    public function __construct(
        public int $window,
        public ?array $sections,
        public ?int $toolCount,
        public ?int $toolTokens,
        public int $historyTokens,
        public int $historyMessages,
        public int $uiOnlyRows,
        public array $largest,
        public ?array $lastCache,
        public ?array $sessionCache,
        public ?array $pruning = null,
        public ?array $cacheBreaks = null,
    ) {
    }

    /** How many pruned outputs {@see withPruning()} lists, newest first. */
    public const PRUNED_ROWS = 5;

    /**
     * This breakdown with what $ledger takes out of the history the model is
     * sent (roadmap 5.6 remainder): the pruned tool outputs — the newest
     * {@see PRUNED_ROWS} named by ref, tool, reason and author — the
     * superseded `<turn-context>` rows left out, and the active step summary.
     * Only what still names a row of $history counts ({@see
     * \SugarCraft\Crush\Context\Pruning\ContextLedger::syncAgainstHistory()}),
     * and the figures are the ledger's own estimates of what each saves.
     *
     * @param list<Message> $history
     */
    public function withPruning(\SugarCraft\Crush\Context\Pruning\ContextLedger $ledger, array $history): self
    {
        $ledger = $ledger->syncAgainstHistory($history);
        $tools = [];
        foreach ($history as $row) {
            foreach ($row->toolResults as $result) {
                if ($result instanceof \SugarCraft\Crush\ToolResult && $result->id !== null) {
                    $tools[$result->id] = $result->name;
                }
            }
        }

        $rows = [];
        $outputTokens = 0;
        foreach ($ledger->prunes as $id => $entry) {
            $outputTokens += $entry->tokens;
            $rows[] = [
                'ref' => $ledger->refOf((string) $id),
                'tool' => $tools[(string) $id] ?? 'tool',
                'reason' => $entry->reason->value,
                'by' => $entry->by->value,
                'tokens' => $entry->tokens,
            ];
        }
        $block = $ledger->activeBlock();

        return new self(
            $this->window,
            $this->sections,
            $this->toolCount,
            $this->toolTokens,
            $this->historyTokens,
            $this->historyMessages,
            $this->uiOnlyRows,
            $this->largest,
            $this->lastCache,
            $this->sessionCache,
            [
                'mode' => $ledger->effectiveMode()->value,
                'sessionMode' => $ledger->mode !== null,
                'outputs' => \count($rows),
                'outputTokens' => $outputTokens,
                'contextRows' => \count($ledger->droppedContextRows),
                'contextRowTokens' => array_sum($ledger->droppedContextRows),
                'block' => $block === null ? null : ['id' => $block->id, 'compressed' => $block->compressedTokens, 'summary' => $block->summaryTokens],
                'rows' => array_reverse(\array_slice($rows, -self::PRUNED_ROWS)),
            ],
            $this->cacheBreaks,
        );
    }

    /**
     * This breakdown with the session's prompt-cache breaks (roadmap 3.B-5,
     * DCP §13.2 P2-10): $breaks requests so far read under half of the prefix
     * the request before them had cached, the newest moving the cached share
     * from `$last['from']`% to `$last['to']`%. The provider's own counts,
     * read off the backend's watch
     * ({@see \SugarCraft\Crush\Backend\EngineBackend::cacheBreaks()}).
     *
     * @param array{from: int, to: int}|null $last
     */
    public function withCacheBreaks(int $breaks, ?array $last): self
    {
        return new self(
            $this->window,
            $this->sections,
            $this->toolCount,
            $this->toolTokens,
            $this->historyTokens,
            $this->historyMessages,
            $this->uiOnlyRows,
            $this->largest,
            $this->lastCache,
            $this->sessionCache,
            $this->pruning,
            ['breaks' => max(0, $breaks), 'last' => $last],
        );
    }

    /**
     * The tokens the ledger saves the next request — pruned outputs, left-out
     * state rows and the active summary's net — or 0 when not measured.
     */
    public function prunedTokens(): int
    {
        if ($this->pruning === null) {
            return 0;
        }
        $block = $this->pruning['block'];

        return $this->pruning['outputTokens'] + $this->pruning['contextRowTokens']
            + ($block === null ? 0 : max(0, $block['compressed'] - $block['summary']));
    }

    /**
     * Measure a conversation.
     *
     * @param list<Message> $history the transcript as it stands
     * @param list<array{label: string, stability: string, sections: int, bytes: int, tokens: int}>|null $sections
     * @param iterable<Tool>|null $tools the tools sent with each request, null when unknown
     * @param int $historyTokens the history estimate the status bar shows — passed
     *        in rather than recomputed so the two can never disagree (it carries
     *        the session's calibration, which only `Chat` holds)
     */
    public static function measure(
        array $history,
        ?array $sections,
        ?iterable $tools,
        int $window,
        int $historyTokens,
        int $largest = self::LARGEST_MESSAGES,
    ): self {
        $toolCount = null;
        $toolTokens = null;
        if ($tools !== null) {
            $list = is_array($tools) ? array_values($tools) : iterator_to_array($tools, false);
            $toolCount = count($list);
            $toolTokens = TokenEstimate::ofToolSchemas($list);
        }

        $meter = ContextMeter::new();
        $sent = 0;
        $uiOnly = 0;
        $sizes = [];
        $lastCache = null;
        $cacheRead = 0;
        $cachePrompt = 0;
        $cacheReplies = 0;

        foreach (array_values($history) as $i => $message) {
            if ($message->uiOnly) {
                ++$uiOnly;
                continue;
            }
            ++$sent;
            $sizes[] = [
                'index' => $i + 1,
                'role' => $message->role->value,
                'tokens' => $meter->rawTokens([$message]),
                'preview' => self::preview($message->content),
            ];

            $split = $message->usage === null ? null : self::cacheSplitOf($message->usage);
            if ($split !== null) {
                $lastCache = $split;
                $cacheRead += $split['read'];
                $cachePrompt += $split['prompt'];
                ++$cacheReplies;
            }
        }

        // Biggest first; the earlier row wins a tie so the order is stable.
        usort($sizes, static fn (array $a, array $b): int => [$b['tokens'], $a['index']] <=> [$a['tokens'], $b['index']]);

        return new self(
            $window,
            $sections,
            $toolCount,
            $toolTokens,
            $historyTokens,
            $sent,
            $uiOnly,
            array_slice($sizes, 0, max(0, $largest)),
            $lastCache,
            $cacheReplies > 0 ? ['read' => $cacheRead, 'prompt' => $cachePrompt, 'replies' => $cacheReplies] : null,
        );
    }

    /**
     * One reply's cache split — tokens read from the provider's prompt cache,
     * and the prompt they are a share of — or null when it reported none.
     *
     * The same rule the status bar's cache segment reads
     * (`Renderer::cacheIndicator()`): the three-bucket prompt when all three
     * were reported, else — on an OpenAI-shaped wire (SGLang), which never
     * reports a cache WRITE — the cached read plus the fresh input it
     * excludes.
     *
     * @return array{read: int, prompt: int}|null
     */
    public static function cacheSplitOf(Usage $usage): ?array
    {
        $prompt = $usage->promptTokens();
        if ($prompt === null
            && $usage->cacheCreationTokens === null
            && $usage->cacheReadTokens !== null
            && $usage->inputTokens !== null
        ) {
            $prompt = $usage->cacheReadTokens + $usage->inputTokens;
        }
        if ($prompt === null || $prompt <= 0 || $usage->cacheReadTokens === null) {
            return null;
        }

        return ['read' => $usage->cacheReadTokens, 'prompt' => $prompt];
    }

    /** The system prompt's estimate, or null when not measured. */
    public function systemTokens(): ?int
    {
        return $this->sections === null ? null : array_sum(array_column($this->sections, 'tokens'));
    }

    /** The system prompt's size in bytes, or null when not measured. */
    public function systemBytes(): ?int
    {
        return $this->sections === null ? null : array_sum(array_column($this->sections, 'bytes'));
    }

    /**
     * Every measured part summed, less what the context ledger prunes out of
     * the history: the next request's estimated size.
     */
    public function totalTokens(): int
    {
        return max(0, ($this->systemTokens() ?? 0) + ($this->toolTokens ?? 0) + $this->historyTokens - $this->prunedTokens());
    }

    /** {@see totalTokens()} as a whole percentage of the window; not clamped. */
    public function percentOfWindow(): int
    {
        return $this->window > 0 ? (int) round($this->totalTokens() * 100 / $this->window) : 0;
    }

    /** The newest reply's cached share as a whole percentage, or null. */
    public function lastCachePercent(): ?int
    {
        return $this->lastCache === null ? null : (int) round($this->lastCache['read'] * 100 / $this->lastCache['prompt']);
    }

    /** The cached share over every reporting reply, or null. */
    public function sessionCachePercent(): ?int
    {
        return $this->sessionCache === null ? null : (int) round($this->sessionCache['read'] * 100 / $this->sessionCache['prompt']);
    }

    /** A message's first non-empty line, whitespace-collapsed and clipped. */
    private static function preview(string $content): string
    {
        foreach (preg_split('/\R/u', $content) ?: [] as $line) {
            $line = trim((string) preg_replace('/\s+/u', ' ', $line));
            if ($line !== '') {
                return mb_strlen($line) > self::PREVIEW_CHARS ? mb_substr($line, 0, self::PREVIEW_CHARS - 1) . '…' : $line;
            }
        }

        return '';
    }
}
