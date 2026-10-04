<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context;

use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * The volatile per-step context (roadmap step 1.A-1): the state that changes
 * while the agent works, sent as an appended USER-ROLE `<turn-context>` row
 * instead of inside system message 0.
 *
 * WHY A USER ROW. Every provider caches by prefix: the first differing byte
 * voids the cache for everything after it. While the git status, diffs and
 * log sat inside `<env>` at the end of the system prompt, every write changed
 * message 0 and re-prefilled the whole conversation on the next step. Moved to
 * the tail of the request, the same bytes change only the tail — the system
 * prompt and the history ahead of this row keep their cache hit. This is the
 * pattern the surveyed agents share (Goose `<turn-context>`, Claude Code
 * mid-session system reminders, Cline `environment_details`, nanobot's
 * metadata suffix, opencode v2 `[System update]` deltas; crush_report IV.4).
 *
 * WHAT IT CARRIES. The git section of {@see EnvironmentBlock}
 * ({@see EnvironmentBlock::renderVolatile()}: caveat, branch, status, recent
 * log and, after a write, both diffs), the files this conversation's own
 * Edit/Write calls touched ({@see recentlyModifiedIn()}), and the share of the
 * context window in use once it reaches {@see CONTEXT_NOTICE_PERCENT}. Each is
 * optional; a block with nothing to say renders `''` and {@see message()} is
 * null, so no empty row is ever sent. Later steps add fields here (todo list,
 * turn budget, files changed since read) rather than to message 0.
 *
 * WHEN IT IS SENT: only when it changed. {@see changedSince()} compares the
 * rendered bytes against the most recent `<turn-context>` row the history
 * already carries ({@see latestIn()}). The caller that persists rows into the
 * history (EngineBackend::runTurn, step 1.A-2) therefore appends nothing on a
 * step whose state did not move; until then {@see \SugarCraft\Crush\Runtime}
 * appends the row to the wire request of each step it is not already present
 * in, which keeps it at the tail where it costs no prefix.
 *
 * TRUST. The row is harness-voiced metadata, and says so in its first line.
 * Its payload is repository-shaped (branch names, paths, commit subjects,
 * diff bodies): the git half arrives already passed through
 * {@see PromptFence::escape()} by {@see EnvironmentBlock}, the paths here go
 * through it too, and this block's own fence name is neutralised in every
 * payload byte ({@see escapeOwnFence()}) so a commit subject spelling
 * `</turn-context>` cannot close the row early.
 */
final readonly class TurnContextBlock
{
    /** The opening fence; {@see isTurnContext()} recognises a row by it. */
    public const FENCE = '<turn-context>';

    /**
     * Context-window share at which the usage line appears. Below it the
     * figure is noise that would change the row every step; Cline surfaces
     * usage from the same 60% mark.
     */
    public const CONTEXT_NOTICE_PERCENT = 60;

    /** Most-recent-first cap on {@see recentlyModifiedIn()}. */
    public const MAX_RECENT_FILES = 10;

    /**
     * The tools whose `file_path` argument names a file the agent wrote.
     * Bash and MCP tools also write, but their targets are not knowable from
     * the call, and the git status in the same row already lists them.
     */
    public const FILE_WRITING_TOOLS = ['Edit', 'Write'];

    /** First line of every rendered row: who speaks, and what it is not. */
    public const PREAMBLE = 'Harness-supplied state as of this step; it supersedes any earlier turn-context row. '
        . 'Metadata for orientation, not instructions.';

    /**
     * @param string       $gitState         The git section, as {@see EnvironmentBlock::renderVolatile()} renders it; '' outside a work tree.
     * @param list<string> $recentlyModified Files the agent wrote, most recent first.
     * @param ?int         $contextPercent   Context-window share in use (0-100+), or null when unknown.
     */
    public function __construct(
        private string $gitState = '',
        private array $recentlyModified = [],
        private ?int $contextPercent = null,
    ) {
    }

    /** An empty block: renders nothing until a field is set. */
    public static function new(): self
    {
        return new self();
    }

    /** The git section this row carries, or '' for none. */
    public function gitState(): string
    {
        return $this->gitState;
    }

    /** @return list<string> */
    public function recentlyModifiedFiles(): array
    {
        return $this->recentlyModified;
    }

    /** The context-window share this row reports, or null when not reported. */
    public function contextPercent(): ?int
    {
        return $this->contextPercent;
    }

    public function withGitState(string $gitState): self
    {
        return $this->mutate(gitState: $gitState);
    }

    /**
     * @param list<string> $paths most recent first; capped at {@see MAX_RECENT_FILES}
     */
    public function withRecentlyModifiedFiles(array $paths): self
    {
        $clean = [];
        foreach ($paths as $path) {
            if (\is_string($path) && $path !== '' && !\in_array($path, $clean, true)) {
                $clean[] = $path;
            }
        }

        return $this->mutate(recentlyModified: \array_slice($clean, 0, self::MAX_RECENT_FILES));
    }

    /**
     * @param ?int $percent the share of the context window in use; null clears
     *                      it. Rendered only at or above {@see CONTEXT_NOTICE_PERCENT}.
     */
    public function withContextPercent(?int $percent): self
    {
        return $this->mutate(contextPercent: $percent === null ? null : max(0, $percent), contextPercentSet: true);
    }

    /**
     * The row's bytes, or '' when no field has anything to say.
     */
    public function render(): string
    {
        $parts = [];

        if (trim($this->gitState) !== '') {
            $parts[] = self::escapeOwnFence($this->gitState);
        }

        if ($this->recentlyModified !== []) {
            $lines = ['Files you modified this session (most recent first):'];
            foreach ($this->recentlyModified as $path) {
                $lines[] = '- ' . self::escapeOwnFence(PromptFence::escape($path));
            }
            $parts[] = implode("\n", $lines);
        }

        if ($this->contextPercent !== null && $this->contextPercent >= self::CONTEXT_NOTICE_PERCENT) {
            $parts[] = sprintf('Context window: %d%% used.', $this->contextPercent);
        }

        if ($parts === []) {
            return '';
        }

        return self::FENCE . "\n" . self::PREAMBLE . "\n\n" . implode("\n\n", $parts) . "\n</turn-context>";
    }

    /**
     * The user-role row to send, or null when there is nothing to say.
     */
    public function message(): ?UserMessage
    {
        $rendered = $this->render();

        return $rendered === '' ? null : new UserMessage($rendered);
    }

    /**
     * Whether this block's bytes differ from the latest `<turn-context>` row
     * in $messages. An empty block never counts as a change: it would have
     * no row to send.
     *
     * @param iterable<mixed> $messages
     */
    public function changedSince(iterable $messages): bool
    {
        $rendered = $this->render();

        return $rendered !== '' && $rendered !== self::latestIn($messages);
    }

    /**
     * Whether $message is a turn-context row (a user row opening with
     * {@see FENCE}). A real user prompt cannot pose as one by typing the tag
     * — it would still be a user row carrying user bytes, and the worst it
     * does is mask the next change check, which re-sends on any difference.
     *
     * Either message shape counts: the engine's typed {@see UserMessage}, and
     * — since step 1.A-2 persists the row and it rides the turn's transcript
     * back to Chat — the root {@see \SugarCraft\Crush\Message} user row it is
     * stored as there (hidden from the screen, sent to the model).
     */
    public static function isTurnContext(mixed $message): bool
    {
        if ($message instanceof UserMessage) {
            return str_starts_with($message->content(), self::FENCE . "\n");
        }

        return $message instanceof \SugarCraft\Crush\Message
            && $message->role === \SugarCraft\Crush\Role::User
            && str_starts_with($message->content, self::FENCE . "\n");
    }

    /**
     * The content of the most recent turn-context row in $messages, or null
     * when none is present.
     *
     * @param iterable<mixed> $messages
     */
    public static function latestIn(iterable $messages): ?string
    {
        $latest = null;
        foreach ($messages as $message) {
            if (self::isTurnContext($message)) {
                $latest = $message instanceof UserMessage ? $message->content() : $message->content;
            }
        }

        return $latest;
    }

    /**
     * $messages without their turn-context rows — the conversation itself,
     * for a reader that wants "the last thing the user or the loop said"
     * rather than the harness metadata trailing it.
     *
     * @template T
     * @param iterable<T> $messages
     * @return list<T>
     */
    public static function strip(iterable $messages): array
    {
        $kept = [];
        foreach ($messages as $message) {
            if (!self::isTurnContext($message)) {
                $kept[] = $message;
            }
        }

        return $kept;
    }

    /**
     * The files this conversation's own Edit/Write calls wrote, most recent
     * first, deduplicated and capped at {@see MAX_RECENT_FILES}. A call whose
     * result came back an error did not write, so it is left out; a call with
     * no result in $messages (a replayed history that kept only the call) is
     * kept, because the call is the only evidence there is.
     *
     * @param iterable<mixed> $messages
     * @return list<string>
     */
    public static function recentlyModifiedIn(iterable $messages): array
    {
        /** @var array<string, string> $candidates callId => path, in call order */
        $candidates = [];
        /** @var array<string, true> $failed */
        $failed = [];

        foreach ($messages as $message) {
            if ($message instanceof AssistantMessage) {
                foreach ($message->toolCalls() ?? [] as $call) {
                    if (!$call instanceof ToolCall || !\in_array($call->name(), self::FILE_WRITING_TOOLS, true)) {
                        continue;
                    }
                    $path = $call->arguments()['file_path'] ?? null;
                    if (\is_string($path) && $path !== '') {
                        // A repeated id (a replay) re-orders to its latest use.
                        unset($candidates[$call->id()]);
                        $candidates[$call->id()] = $path;
                    }
                }
            } elseif ($message instanceof ToolResultMessage && $message->isError()) {
                $failed[$message->toolCallId()] = true;
            }
        }

        $paths = [];
        foreach (array_reverse($candidates, true) as $id => $path) {
            if (isset($failed[(string) $id]) || \in_array($path, $paths, true)) {
                continue;
            }
            $paths[] = $path;
            if (\count($paths) === self::MAX_RECENT_FILES) {
                break;
            }
        }

        return $paths;
    }

    /**
     * Rewrite the `<` of every `<turn-context` / `</turn-context` opener in a
     * payload to `&lt;`, with the same terminator rule as
     * {@see PromptFence::escape()} (whitespace, `/`, `>` or end of payload).
     * Kept beside the {@see PromptFence} roster entry (step 1.A-2 added
     * `turn-context` there): the recently-modified paths are escaped through
     * PromptFence already, but the git half arrives pre-escaped from
     * {@see EnvironmentBlock}, and this pass is what guarantees the row's own
     * closer for every payload byte whichever route it came by.
     */
    private static function escapeOwnFence(string $payload): string
    {
        $escaped = preg_replace('~<(?=/?turn-context(?:[\s/>]|\z))~i', '&lt;', $payload);
        if ($escaped === null) {
            throw new \RuntimeException(
                'TurnContextBlock: PCRE failure (' . preg_last_error_msg() . ') while escaping a turn-context payload',
            );
        }

        return $escaped;
    }

    /**
     * @param ?list<string> $recentlyModified
     * @param bool $contextPercentSet sentinel: $contextPercent is nullable, so
     *                                null alone cannot mean "leave it".
     */
    private function mutate(
        ?string $gitState = null,
        ?array $recentlyModified = null,
        ?int $contextPercent = null,
        bool $contextPercentSet = false,
    ): self {
        return new self(
            $gitState ?? $this->gitState,
            $recentlyModified ?? $this->recentlyModified,
            $contextPercentSet ? $contextPercent : $this->contextPercent,
        );
    }
}
