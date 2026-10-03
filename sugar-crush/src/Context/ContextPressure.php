<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context;

use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message as TypedMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Usage;
use SugarCraft\Crush\Util\TokenEstimate;

/**
 * How full one step's request is, measured just before it is sent (roadmap
 * 2.1): the figure, how it was reached, and whether it is over the step's
 * {@see ContextBudget}.
 *
 * THE FIGURE IS ANCHORED, NOT RE-ESTIMATED. After the first step the provider
 * has already counted the previous request — system prompt, tool schemas and
 * history included, by its own tokenizer — so the next request is that count
 * plus an estimate of only the rows added since (the assistant's reply and the
 * tool results it read). A script-weighted estimate of the whole request is
 * kept beside it ({@see $estimatedTokens}), and is the figure itself on the
 * first step and on any step whose predecessor reported nothing usable.
 *
 * WHICH USAGE FIELD IS THE ANCHOR ({@see promptTokensOf()}): the prompt side
 * of the previous response. {@see Usage::promptTokens()} is null unless all
 * three input buckets were reported, and an OpenAI-shaped server (SGLang)
 * never reports cache creation, so the chain falls back to the input bucket
 * plus the cached reads it excludes, then to the provider's total less what
 * it says it wrote. Nothing reported means no anchor, never an anchor of 0.
 *
 * A plain value: it crosses the fork on the `step` frame as {@see toArray()}
 * (the parent decodes with `allowed_classes => false`), and
 * {@see fromArray()} refuses a shape it did not write.
 */
final readonly class ContextPressure
{
    /**
     * Per-message overhead in the estimate — the role and separators every
     * chat template wraps a message in. The same 10 Chat's raw token proxy
     * adds, so the two estimates agree on what a row costs.
     */
    public const MESSAGE_OVERHEAD_TOKENS = 10;

    /**
     * @param int  $tokens          the figure judged against the budget:
     *                              `$anchorTokens + $deltaTokens` when
     *                              anchored, `$estimatedTokens` otherwise
     * @param ?int $anchorTokens    the previous step's prompt as its provider
     *                              counted it, or null (first step, or nothing
     *                              usable reported)
     * @param int  $deltaTokens     estimated tokens of the rows added since the
     *                              anchored request (0 when not anchored)
     * @param int  $estimatedTokens script-weighted estimate of this whole
     *                              request: system prompt + tool schemas +
     *                              messages
     * @param int  $systemTokens    the system prompt's share of the estimate
     * @param int  $toolTokens      the tool schemas' share of the estimate
     * @param int  $threshold       {@see ContextBudget::threshold()}
     * @param int  $window          {@see ContextBudget::$window}
     */
    public function __construct(
        public int $tokens,
        public ?int $anchorTokens,
        public int $deltaTokens,
        public int $estimatedTokens,
        public int $systemTokens,
        public int $toolTokens,
        public int $threshold,
        public int $window,
    ) {
    }

    /**
     * Measure $request against $budget.
     *
     * @param list<TypedMessage> $rows        the conversation the request was
     *                                        built from (the turn's
     *                                        `App::$messages`)
     * @param ?int               $anchorTokens the previous step's prompt
     *                                        ({@see promptTokensOf()})
     * @param int                $anchorRows  how many of $rows that anchored
     *                                        request already held; the delta
     *                                        is everything after them
     */
    public static function measure(ContextBudget $budget, CompleteRequest $request, array $rows, ?int $anchorTokens = null, int $anchorRows = 0): self
    {
        $system = TokenEstimate::ofText($request->systemPrompt ?? '');
        $tools = TokenEstimate::ofToolSchemas($request->tools ?? []);
        $estimated = $system + $tools + self::ofMessages($request->messages);

        $delta = 0;
        if ($anchorTokens !== null) {
            $delta = self::ofMessages(array_slice($rows, max(0, $anchorRows)));
        }

        return new self(
            $anchorTokens !== null ? $anchorTokens + $delta : $estimated,
            $anchorTokens,
            $delta,
            $estimated,
            $system,
            $tools,
            $budget->threshold(),
            $budget->window,
        );
    }

    /**
     * The prompt side of one provider response, or null when it reported
     * nothing usable. See the class docblock for the fallback order.
     */
    public static function promptTokensOf(?Usage $usage): ?int
    {
        if ($usage === null) {
            return null;
        }

        $prompt = $usage->promptTokens();
        if ($prompt !== null) {
            return $prompt;
        }

        // An OpenAI-shaped wire reports `prompt_tokens` split into the fresh
        // input and the cached part (SglangProvider::parseUsage()), and no
        // cache-creation bucket at all: the prompt is the sum of what it did say.
        if ($usage->inputTokens !== null) {
            return $usage->inputTokens + ($usage->cacheReadTokens ?? 0) + ($usage->cacheCreationTokens ?? 0);
        }

        if ($usage->outputTokens !== null && $usage->ownTokens() > $usage->outputTokens) {
            return $usage->ownTokens() - $usage->outputTokens;
        }

        return null;
    }

    /**
     * Script-weighted estimate of $messages: each one's text, the calls an
     * assistant row asked for (name + arguments, as they travel), and
     * {@see MESSAGE_OVERHEAD_TOKENS} per message.
     *
     * @param array<mixed> $messages
     */
    public static function ofMessages(array $messages): int
    {
        $tokens = 0;
        foreach ($messages as $message) {
            if (!$message instanceof TypedMessage) {
                continue;
            }
            $tokens += self::MESSAGE_OVERHEAD_TOKENS + TokenEstimate::ofText($message->content());
            if ($message instanceof AssistantMessage) {
                foreach ($message->toolCalls() ?? [] as $call) {
                    if (!$call instanceof ToolCall) {
                        continue;
                    }
                    $arguments = json_encode($call->arguments(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                    $tokens += TokenEstimate::ofText($call->name() . ' ' . ($arguments === false ? '' : $arguments));
                }
            }
        }

        return $tokens;
    }

    /** The verdict: true once the request reaches the step budget. */
    public function isOverBudget(): bool
    {
        return $this->tokens >= $this->threshold;
    }

    /** The figure as a whole percentage of the window (may exceed 100). */
    public function percentOfWindow(): int
    {
        return $this->window > 0 ? (int) floor($this->tokens * 100 / $this->window) : 0;
    }

    /**
     * @return array{tokens:int,anchorTokens:?int,deltaTokens:int,estimatedTokens:int,systemTokens:int,toolTokens:int,threshold:int,window:int}
     */
    public function toArray(): array
    {
        return [
            'tokens' => $this->tokens,
            'anchorTokens' => $this->anchorTokens,
            'deltaTokens' => $this->deltaTokens,
            'estimatedTokens' => $this->estimatedTokens,
            'systemTokens' => $this->systemTokens,
            'toolTokens' => $this->toolTokens,
            'threshold' => $this->threshold,
            'window' => $this->window,
        ];
    }

    /**
     * Rebuild from {@see toArray()}; anything else — a missing or non-int
     * field, a corrupt frame — is null, never a guessed figure.
     */
    public static function fromArray(mixed $raw): ?self
    {
        if (!is_array($raw)) {
            return null;
        }

        $ints = [];
        foreach (['tokens', 'deltaTokens', 'estimatedTokens', 'systemTokens', 'toolTokens', 'threshold', 'window'] as $key) {
            $value = $raw[$key] ?? null;
            if (!is_int($value)) {
                return null;
            }
            $ints[$key] = $value;
        }
        $anchor = $raw['anchorTokens'] ?? null;
        if ($anchor !== null && !is_int($anchor)) {
            return null;
        }

        return new self(
            $ints['tokens'],
            $anchor,
            $ints['deltaTokens'],
            $ints['estimatedTokens'],
            $ints['systemTokens'],
            $ints['toolTokens'],
            $ints['threshold'],
            $ints['window'],
        );
    }
}
