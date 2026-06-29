<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use React\Promise\PromiseInterface;
use SugarCraft\Buffer\Buffer;
use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\Diff\DiffEncoder;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;

/**
 * The chat shell, as a SugarCraft {@see Model}.
 *
 * Three pieces of state:
 *
 *   - `history`    — `list<Message>` accumulated so far
 *   - `inputBuf`   — the user's in-progress draft of the next turn
 *   - `inFlight`   — `true` while a backend call is in progress.
 *                    Input is suppressed and the renderer shows a
 *                    "thinking…" indicator.
 *
 * Sending: pressing Enter on a non-empty input pushes the
 * Message onto history, clears the buffer, sets `inFlight`,
 * and schedules a Cmd that calls `Backend::complete()` and
 * dispatches the result back as an {@see AssistantMsg}.
 *
 * The Backend is held privately and isn't part of equality —
 * tests use {@see Backend\EchoBackend}, prod uses whatever
 * adapter the user wires in {@see bin/sugarcrush}.
 *
 * **Tool Use:** Callbacks can be registered via `registerTool()`.
 * When an assistant message contains tool calls, they are executed
 * and the results are appended to history before the next backend
 * call continues.
 */
final class Chat implements Model
{
    private readonly Backend $backend;

    /** @var Buffer|null Previous rendered frame for diff-based emission */
    private ?Buffer $previousFrame = null;

    /** @var int|null Previous output height for dimension-change detection */
    private ?int $prevHeight = null;

    /** @var int|null Previous terminal width for resize detection */
    private ?int $prevWidth = null;

    /** Terminal width for buffer rendering. Updated from Renderer on each view(). */
    private int $width = 80;

    /**
     * @param list<Message> $history
     * @param array<string, callable> $tools Map of tool name => callable(array $arguments): mixed
     * @param callable|null $onToolCall Optional callback called when tools are invoked
     */
    public function __construct(
        public readonly array $history = [],
        public readonly string $inputBuf = '',
        public readonly bool $inFlight = false,
        ?Backend $backend = null,
        private readonly bool $streaming = false,
        private readonly ?\Closure $onToken = null,
        private readonly array $tools = [],
        private readonly ?\Closure $onToolCall = null,
    ) {
        $this->backend = $backend ?? new Backend\EchoBackend();
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof AssistantMsg) {
            $message = $msg->message;

            // Check if the message has tool calls to execute
            if ($message->toolCalls !== [] && $this->tools !== []) {
                return $this->handleToolCalls($message);
            }

            return [new self(
                history: [...$this->history, $message],
                inputBuf: $this->inputBuf,
                inFlight: false,
                backend: $this->backend,
                streaming: $this->streaming,
                onToken: $this->onToken,
                tools: $this->tools,
                onToolCall: $this->onToolCall,
            ), null];
        }
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === "\x03" /* ^C */) {
            return [$this, Cmd::quit()];
        }
        if ($this->inFlight) {
            // Ignore keystrokes while waiting for the backend
            // (avoids the user racing ahead and queuing another
            // turn into a half-formed history).
            return [$this, null];
        }

        return match (true) {
            $msg->type === KeyType::Enter
                => $this->submit(),
            $msg->type === KeyType::Char
                => [$this->withInputBuf($this->inputBuf . $msg->rune), null],
            $msg->type === KeyType::Space
                => [$this->withInputBuf($this->inputBuf . ' '), null],
            $msg->type === KeyType::Backspace
                => [$this->withInputBuf(self::dropLast($this->inputBuf)), null],
            $msg->type === KeyType::Escape
                => [$this, Cmd::quit()],
            default => [$this, null],
        };
    }

    /**
     * Handle tool calls in an assistant message.
     * Executes each tool and schedules a follow-up backend call with results.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleToolCalls(Message $message): array
    {
        $toolResults = [];
        foreach ($message->toolCalls as $toolCall) {
            $result = $this->executeTool($toolCall);
            $toolResults[] = $result;
        }

        // Add assistant message and tool results to history
        $newHistory = [...$this->history, $message];
        foreach ($toolResults as $result) {
            $newHistory[] = Message::assistant($result->isError() ? "Tool error: {$result->error}" : $result->result)
                ->withToolResults([$result]);
        }

        // Schedule follow-up backend call with updated history
        $next = new self(
            history: $newHistory,
            inputBuf: $this->inputBuf,
            inFlight: true,
            backend: $this->backend,
            streaming: $this->streaming,
            onToken: $this->onToken,
            tools: $this->tools,
            onToolCall: $this->onToolCall,
        );

        $backend = $this->backend;
        $history = $next->history;
        $onToken = $this->streaming ? $this->onToken : null;
        $cmd = Cmd::promise(static function () use ($backend, $history, $onToken): PromiseInterface {
            return $backend->completeAsync($history, $onToken)->then(
                static fn(Message $msg): ?Msg => new AssistantMsg($msg),
                static fn(\Throwable $e): ?Msg => new AssistantMsg(Message::assistant('_[error: ' . $e->getMessage() . ']_')),
            );
        });

        return [$next, $cmd];
    }

    /**
     * Execute a single tool call and return the result.
     */
    private function executeTool(ToolCall $toolCall): ToolResult
    {
        $name = $toolCall->name;
        $args = $toolCall->arguments;

        if (!isset($this->tools[$name])) {
            return ToolResult::error($name, "Unknown tool: {$name}", $toolCall->id);
        }

        try {
            $callback = $this->tools[$name];
            $result = $callback($args);

            // Notify tool call listener if set
            if ($this->onToolCall !== null) {
                ($this->onToolCall)($name, $args, $result);
            }

            return ToolResult::ok($name, is_string($result) ? $result : (json_encode($result) ?: 'null'), $toolCall->id);
        } catch (\Throwable $e) {
            return ToolResult::error($name, $e->getMessage(), $toolCall->id);
        }
    }

    public function view(): string
    {
        // Get actual terminal dimensions from TUI Renderer (queries Tty for real size).
        $size = TuiRenderer::getTerminalSize();
        $width = $size['cols'];
        $fullOutput = Renderer::render($this);
        $height = substr_count($fullOutput, "\n") + 1;

        // Detect terminal resize: reset diff state on width or height change.
        if ($this->previousFrame !== null
            && ($this->prevWidth !== null && $this->prevWidth !== $width)
        ) {
            $this->previousFrame = null;
        }
        $this->prevWidth = $width;
        $this->prevHeight = $height;

        // First frame or dimension change: emit full output and store as previousFrame.
        if ($this->previousFrame === null) {
            $this->previousFrame = $this->bufferFromOutput($fullOutput, $width, $height);
            return $fullOutput;
        }

        // Subsequent frames with same dimensions: compute diff and emit delta.
        $currentFrame = $this->bufferFromOutput($fullOutput, $width, $height);
        $ops = $currentFrame->diff($this->previousFrame);
        $this->previousFrame = $currentFrame;

        $encoder = new DiffEncoder();
        return $encoder->encode($ops);
    }

    public function backend(): Backend
    {
        return $this->backend;
    }

    public function withStreaming(bool $enable): self
    {
        return new self(
            history: $this->history,
            inputBuf: $this->inputBuf,
            inFlight: $this->inFlight,
            backend: $this->backend,
            streaming: $enable,
            onToken: $this->onToken,
            tools: $this->tools,
            onToolCall: $this->onToolCall,
        );
    }

    public function onToken(callable $callback): self
    {
        return new self(
            history: $this->history,
            inputBuf: $this->inputBuf,
            inFlight: $this->inFlight,
            backend: $this->backend,
            streaming: $this->streaming,
            onToken: $callback instanceof \Closure ? $callback : \Closure::fromCallable($callback),
            tools: $this->tools,
            onToolCall: $this->onToolCall,
        );
    }

    /**
     * Register a tool/function that the AI can call.
     *
     * @param string $name The tool name (must be unique)
     * @param callable(array $arguments): mixed $callback The function to call
     * @return self A new Chat with the tool registered
     */
    public function registerTool(string $name, callable $callback): self
    {
        $tools = $this->tools;
        $tools[$name] = $callback instanceof \Closure ? $callback : \Closure::fromCallable($callback);
        return new self(
            history: $this->history,
            inputBuf: $this->inputBuf,
            inFlight: $this->inFlight,
            backend: $this->backend,
            streaming: $this->streaming,
            onToken: $this->onToken,
            tools: $tools,
            onToolCall: $this->onToolCall,
        );
    }

    /**
     * Register a callback for tool call events.
     *
     * @param callable(string $name, array $arguments, mixed $result): void $callback
     * @return self
     */
    public function onToolCall(callable $callback): self
    {
        return new self(
            history: $this->history,
            inputBuf: $this->inputBuf,
            inFlight: $this->inFlight,
            backend: $this->backend,
            streaming: $this->streaming,
            onToken: $this->onToken,
            tools: $this->tools,
            onToolCall: $callback instanceof \Closure ? $callback : \Closure::fromCallable($callback),
        );
    }

    public function isStreaming(): bool
    {
        return $this->streaming;
    }

    /**
     * @return array<string, callable>
     */
    public function getTools(): array
    {
        return $this->tools;
    }

    /**
     * @return array{0:Chat,1:?\Closure}
     */
    private function submit(): array
    {
        $text = trim($this->inputBuf);
        if ($text === '') {
            return [$this, null];
        }
        $next = new self(
            history: [...$this->history, Message::user($text)],
            inputBuf: '',
            inFlight: true,
            backend: $this->backend,
            streaming: $this->streaming,
            onToken: $this->onToken,
            tools: $this->tools,
            onToolCall: $this->onToolCall,
        );
        $backend = $this->backend;
        $history = $next->history;
        $onToken = $this->streaming ? $this->onToken : null;
        $cmd = Cmd::promise(static function () use ($backend, $history, $onToken): PromiseInterface {
            return $backend->completeAsync($history, $onToken)->then(
                static fn(Message $msg): ?Msg => new AssistantMsg($msg),
                static fn(\Throwable $e): ?Msg => new AssistantMsg(Message::assistant('_[error: ' . $e->getMessage() . ']_')),
            );
        });
        return [$next, $cmd];
    }

    private function withInputBuf(string $buf): self
    {
        return new self(
            history: $this->history,
            inputBuf: $buf,
            inFlight: $this->inFlight,
            backend: $this->backend,
            streaming: $this->streaming,
            onToken: $this->onToken,
            tools: $this->tools,
            onToolCall: $this->onToolCall,
        );
    }

    /**
     * Drop the last UTF-8 codepoint from `$s`. Plain `substr(-1)`
     * would corrupt multi-byte input — a backspace after typing
     * an emoji should remove the whole grapheme.
     */
    private static function dropLast(string $s): string
    {
        if ($s === '') {
            return $s;
        }
        $i = strlen($s) - 1;
        while ($i > 0 && (ord($s[$i]) & 0xc0) === 0x80) {
            $i--;
        }
        return substr($s, 0, $i);
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }

    /**
     * Build a Buffer from a multi-line string output.
     *
     * All cells are created with null style — the diff algorithm will
     * still work correctly for detecting changed character positions.
     *
     * Uses Buffer::fromGrid() for O(w×h) bulk construction instead of
     * O(w²×h) repeated withCellAt() calls, and mb_str_split per row
     * instead of per-cell mb_substr for O(w) vs O(w²) string ops.
     *
     * @param string $output Multi-line string from Renderer::render()
     * @param int    $width  Buffer width in cells
     * @param int    $height Buffer height in rows
     */
    private function bufferFromOutput(string $output, int $width, int $height): Buffer
    {
        $lines = \explode("\n", $output);
        $grid = [];

        for ($row = 0; $row < $height; $row++) {
            $line = $lines[$row] ?? '';
            // mb_str_split is O(width) per row vs mb_substr called width×height times (O(width²×height))
            $chars = \mb_str_split($line, 1) ?: [];
            for ($col = 0; $col < $width; $col++) {
                $char = $chars[$col] ?? ' ';
                $grid[] = Cell::new($char, null, null, 1);
            }
        }

        return Buffer::fromGrid($width, $height, $grid);
    }

    /**
     * Reset the previous-frame buffer, forcing the next view to emit
     * a full frame (used on window resize or cursor-position-lost events).
     */
    public function resetPreviousFrame(): void
    {
        $this->previousFrame = null;
    }
}
