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
    ) {
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

    /** Every measured part summed: the next request's estimated size. */
    public function totalTokens(): int
    {
        return ($this->systemTokens() ?? 0) + ($this->toolTokens ?? 0) + $this->historyTokens;
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
