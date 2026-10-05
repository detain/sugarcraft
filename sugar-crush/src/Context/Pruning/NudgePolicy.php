<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Pruning;

use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message as TypedMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * When to remind the model that its context is filling up, and where the
 * reminder sits (roadmap 3.B-4, DCP §13.2 E / §4.7) — the model's own
 * context-management tools (`Prune`, and `Compress` when it is offered) are
 * worth nothing if it never thinks to use them.
 *
 * ANCHORED, NOT APPENDED. A reminder is not a row added to the end of each
 * request — that would move along the tail and rewrite the bytes the provider
 * cached on every step. {@see decide()} instead ANCHORS a nudge on one row
 * the model has read (a tool result or a user prompt, by its
 * {@see ContextLedger::rowKeys()} key) and the {@see ContextProjector}
 * appends the nudge's fixed text to that row on every later request: one
 * row's bytes change, once. NEVER on an assistant row — a request that ends
 * on a synthetic assistant turn is a "prefill" some providers reject (DCP
 * #520), and the projector only renders nudges on tool results and user rows.
 *
 * THE RULES (DCP's, with sugar-crush's tool rows standing in for its
 * messages):
 * - only in the `auto` pruning mode — `manual` and `off` are the person's
 *   own management, and get no reminders;
 * - right after a successful `Prune` or `Compress` call, every anchor is
 *   cleared and none is added: the COOLDOWN — the model just acted;
 * - below {@see MIN_CONTEXT_TOKENS} nothing is added;
 * - above {@see MAX_CONTEXT_TOKENS} the newest row gets a
 *   {@see KIND_LIMIT} nudge;
 * - in between, a turn's prompt gets a {@see KIND_TURN} nudge, and a run of
 *   {@see ITERATION_THRESHOLD} tool results since the last prompt gets a
 *   {@see KIND_ITERATION} nudge on the newest;
 * - a new anchor needs {@see NUDGE_FREQUENCY} rows since the last one, so a
 *   long run is reminded at a steady pace rather than on every step.
 *
 * The texts are constants ({@see text()}), pure functions of the kind, so an
 * anchored row renders the same bytes on every request.
 */
final readonly class NudgePolicy
{
    public const KIND_TURN = 'turn';

    public const KIND_ITERATION = 'iteration';

    public const KIND_LIMIT = 'limit';

    /** Below this many tokens the context is not worth managing yet. */
    public const MIN_CONTEXT_TOKENS = 60_000;

    /** Above this many tokens every new anchor is the strong {@see KIND_LIMIT} one. */
    public const MAX_CONTEXT_TOKENS = 120_000;

    /** Rows (tool results and prompts) between two anchors. */
    public const NUDGE_FREQUENCY = 5;

    /** Tool results since the last prompt that make an {@see KIND_ITERATION} nudge due. */
    public const ITERATION_THRESHOLD = 10;

    /** The tag the text is wrapped in, so the model can tell it from tool output. */
    public const OPEN = '<context-reminder>';

    public const CLOSE = '</context-reminder>';

    private const TEXTS = [
        self::KIND_LIMIT => 'Your context is past its working limit. Manage it NOW, before more exploration: '
            . 'Prune the tool outputs you are finished with (each ends with a <ctx-ref r="N"/> tag; distill the '
            . 'ones whose facts you still need), and, when the Compress tool is offered, compress the oldest '
            . 'closed range of the conversation into an exhaustive summary. If you are mid-way through an '
            . 'atomic change, finish that step first, then manage the context.',
        self::KIND_TURN => 'Your context is growing. Before you continue, look for tool outputs you are '
            . 'finished with and Prune them (distill what you still need), and, when the Compress tool is '
            . 'offered, compress closed ranges that no longer bear on this request. Keep whatever you will '
            . 'edit against or quote exactly.',
        self::KIND_ITERATION => 'You have been working for a while since the last prompt. If a portion of '
            . 'this run is closed — research finished before implementation, a dead end — Prune or distill '
            . 'its tool outputs now (or compress it, when the Compress tool is offered). Do this alongside '
            . 'your next real tool call.',
    ];

    /** The names of the tools whose successful call starts the cooldown. */
    private const MANAGEMENT_TOOLS = ['Prune', 'Compress'];

    private function __construct(
        public int $minContextTokens,
        public int $maxContextTokens,
        public int $nudgeFrequency,
        public int $iterationThreshold,
    ) {
    }

    public static function new(): self
    {
        return new self(self::MIN_CONTEXT_TOKENS, self::MAX_CONTEXT_TOKENS, self::NUDGE_FREQUENCY, self::ITERATION_THRESHOLD);
    }

    public function withContextTokens(int $min, int $max): self
    {
        $min = max(0, $min);

        return new self($min, max($min, $max), $this->nudgeFrequency, $this->iterationThreshold);
    }

    public function withNudgeFrequency(int $rows): self
    {
        return new self($this->minContextTokens, $this->maxContextTokens, max(1, $rows), $this->iterationThreshold);
    }

    public function withIterationThreshold(int $results): self
    {
        return new self($this->minContextTokens, $this->maxContextTokens, $this->nudgeFrequency, max(1, $results));
    }

    /** Whether $kind is one this policy writes. */
    public static function isKind(string $kind): bool
    {
        return isset(self::TEXTS[$kind]);
    }

    /** The fixed text of a $kind nudge, wrapped in its tag; '' for no kind. */
    public static function text(string $kind): string
    {
        return isset(self::TEXTS[$kind]) ? self::OPEN . "\n" . self::TEXTS[$kind] . "\n" . self::CLOSE : '';
    }

    /** $content with the $kind nudge on its own last lines. Idempotent. */
    public static function appendTo(string $content, string $kind): string
    {
        $text = self::text($kind);
        if ($text === '' || str_ends_with($content, $text)) {
            return $content;
        }

        return $content === '' ? $text : $content . "\n" . $text;
    }

    /**
     * $text with every reminder block the model echoed removed (DCP #608:
     * models copy whole reminder blocks into their replies).
     */
    public static function stripFrom(string $text): string
    {
        if (!str_contains($text, self::OPEN)) {
            return $text;
        }

        return preg_replace('/\n?' . preg_quote(self::OPEN, '/') . '.*?' . preg_quote(self::CLOSE, '/') . '/s', '', $text) ?? $text;
    }

    /**
     * The anchors to add before the next request — or the cooldown's clear —
     * as a delta the turn applies to its ledger; an empty delta when nothing
     * is due.
     *
     * @param int                $tokens   the context's size now, in tokens:
     *                                     the provider's count for the last
     *                                     request plus an estimate of what
     *                                     came since
     * @param list<TypedMessage> $messages the rows the next request is built from
     */
    public function decide(int $tokens, array $messages, ContextLedger $ledger): LedgerDelta
    {
        $delta = LedgerDelta::new();
        if ($ledger->effectiveMode() !== PruningMode::Auto) {
            return $delta;
        }
        $messages = array_values($messages);

        if (self::justManaged($messages)) {
            return $ledger->nudges === [] ? $delta : $delta->withNudgesCleared();
        }
        if ($tokens <= $this->minContextTokens) {
            return $delta;
        }

        $keys = ContextLedger::rowKeys($messages, true);
        if ($keys === []) {
            return $delta;
        }
        $positions = array_keys($keys);
        $lastAnchor = null;
        foreach ($keys as $index => $key) {
            if (isset($ledger->nudges[$key])) {
                $lastAnchor = $index;
            }
        }
        $since = $lastAnchor === null
            ? PHP_INT_MAX
            : \count(array_filter($positions, static fn (int $index): bool => $index > $lastAnchor));
        if ($since < $this->nudgeFrequency) {
            return $delta;
        }

        $newest = $positions[array_key_last($positions)];
        if ($tokens > $this->maxContextTokens) {
            return $delta->withNudge($keys[$newest], self::KIND_LIMIT);
        }

        // The newest prompt, and how many tool results came after it.
        $prompt = null;
        foreach ($keys as $index => $key) {
            if ($messages[$index] instanceof UserMessage) {
                $prompt = $index;
            }
        }
        $resultsSince = 0;
        $lastAssistant = null;
        foreach ($messages as $index => $message) {
            if ($message instanceof AssistantMessage) {
                $lastAssistant = $index;
            }
            if ($message instanceof ToolResultMessage && ($prompt === null || $index > $prompt)) {
                $resultsSince++;
            }
        }

        if ($prompt !== null && ($lastAssistant === null || $lastAssistant < $prompt)) {
            return $delta->withNudge($keys[$prompt], self::KIND_TURN);
        }
        if ($resultsSince >= $this->iterationThreshold && $messages[$newest] instanceof ToolResultMessage) {
            return $delta->withNudge($keys[$newest], self::KIND_ITERATION);
        }

        return $delta;
    }

    /**
     * Whether the newest step called `Prune` or `Compress` and one of those
     * calls succeeded — the cooldown.
     *
     * @param list<TypedMessage> $messages
     */
    private static function justManaged(array $messages): bool
    {
        $step = null;
        foreach ($messages as $index => $message) {
            if ($message instanceof AssistantMessage) {
                $step = $index;
            }
        }
        if ($step === null) {
            return false;
        }
        $managing = [];
        foreach ($messages[$step]->toolCalls() ?? [] as $call) {
            if ($call instanceof ToolCall && \in_array($call->name(), self::MANAGEMENT_TOOLS, true)) {
                $managing[$call->id()] = true;
            }
        }
        foreach (\array_slice($messages, $step + 1) as $message) {
            if ($message instanceof ToolResultMessage && isset($managing[$message->toolCallId()]) && !$message->isError()) {
                return true;
            }
        }

        return false;
    }
}
