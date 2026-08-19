<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

/**
 * One turn in a chat conversation. Immutable, role-tagged,
 * timestamped. The chat history is a `list<Message>` carried
 * on the {@see Chat} model.
 *
 * Content stays as a plain string here — Markdown is rendered
 * lazily at view time via CandyShine. That keeps `Message`
 * cheap to build (every keystroke updates the in-flight user
 * message) and keeps the backend adapter API ASCII-only.
 */
final class Message
{
    /**
     * @param list<Attachment> $attachments
     * @param list<ToolCall> $toolCalls
     * @param list<ToolResult> $toolResults
     */
    public function __construct(
        public readonly Role  $role,
        public readonly string $content,
        public readonly int   $createdAt,
        public readonly array $attachments = [],
        public readonly array $toolCalls = [],
        public readonly array $toolResults = [],
        /**
         * Set only on a transient "tool X is running" placeholder (see
         * {@see toolRunning()}) - the ToolCall::$id it stands in for, so
         * {@see Chat}'s ToolResultsMsg handler can find and replace it with
         * the real result once execution finishes. Null on every other
         * message, including the finished result itself.
         */
        public readonly ?string $pendingToolCallId = null,
        /**
         * Model "thinking" text split out by {@see
         * \SugarCraft\Crush\Providers\Concerns\ReasoningExtractor} (§12 D3),
         * carried across the engine seam from {@see
         * \SugarCraft\Crush\Messages\AssistantMessage::reasoning()} so
         * {@see Renderer} can surface it instead of silently dropping it at
         * the {@see \SugarCraft\Crush\Backend\EngineBackend} conversion
         * boundary. Null on every non-assistant turn and on any assistant
         * turn whose provider/parser didn't produce reasoning.
         */
        public readonly ?string $reasoning = null,
        /**
         * Image bytes carried across the {@see
         * \SugarCraft\Crush\Backend\EngineBackend} conversion boundary from
         * an image-bearing tool result (e.g. {@see
         * \SugarCraft\Crush\Tools\BuiltIn\Doctor}'s capability swatch, see
         * {@see \SugarCraft\Crush\Messages\ToolResultMessage}) - W1.G2
         * reachability fix. Null on every message with no such tool result.
         */
        public readonly ?string $imageBytes = null,
        /**
         * The candy-mosaic protocol ({@see
         * \SugarCraft\Mosaic\Mosaic::protocol()}) detected when
         * $imageBytes was captured. Null whenever $imageBytes is null.
         */
        public readonly ?string $imageProtocol = null,
        /**
         * What this assistant turn cost in PROVIDER-COUNTED tokens and
         * dollars, summed over every step of the agentic loop that produced
         * it, or null when nothing was reported (crush_code.md Phase 5 item
         * 7). Carried across the {@see
         * \SugarCraft\Crush\Backend\EngineBackend} conversion boundary for
         * the same reason $reasoning and $imageBytes are: every provider
         * already returned it on
         * {@see \SugarCraft\Crush\Providers\CompleteResponse}, and this DTO
         * was where it stopped, so {@see \SugarCraft\Crush\Util\TokenTracker}
         * had nothing to accumulate and could not be constructed anywhere.
         *
         * Null on every non-assistant turn, and on any assistant turn whose
         * provider reported neither a token count nor a cost - which is the
         * usual answer for a streamed turn. Null is NOT "$0.00 spent"; see
         * {@see Usage}.
         */
        public readonly ?Usage $usage = null,
    ) {}

    public static function user(string $content, ?int $now = null): self
    {
        return new self(Role::User, $content, $now ?? time());
    }

    public static function assistant(string $content, ?int $now = null, ?string $reasoning = null): self
    {
        return new self(Role::Assistant, $content, $now ?? time(), reasoning: $reasoning);
    }

    public static function system(string $content, ?int $now = null): self
    {
        return new self(Role::System, $content, $now ?? time());
    }

    /**
     * A transient placeholder shown the moment a tool call is dispatched,
     * before it finishes - see this class's $pendingToolCallId docblock and
     * {@see \SugarCraft\Crush\Renderer::renderToolResults()} for how it's
     * displayed distinctly from a finished result. $call->id must be
     * non-null and unique per in-flight turn for the later replace-by-id
     * lookup to find the right placeholder; {@see \SugarCraft\Crush\Chat}
     * only ever calls this with backend-issued tool calls, which always
     * carry an id.
     */
    public static function toolRunning(ToolCall $call, ?int $now = null): self
    {
        return new self(
            role: Role::System,
            content: self::describeToolCall($call),
            createdAt: $now ?? time(),
            pendingToolCallId: $call->id ?? $call->name,
        );
    }

    /**
     * Longest single argument value (or model-authored description) rendered
     * into a tool-call one-liner before it is elided. The label is drawn on a
     * single terminal row, so an unbounded value would push the row past the
     * viewport width.
     */
    private const DESCRIPTION_MAX = 80;

    /**
     * Human-readable one-liner for a tool invocation.
     *
     * crush_feat.md §3 E2: the same model turn that emits a tool call also
     * emits a `description` argument (every built-in tool's `inputSchema()`
     * marks it required), so no extra LLM round-trip is needed to get a
     * Claude-Code-Bash-style "List files in current directory" label. That
     * model-authored summary wins when present; otherwise this falls back to
     * the mechanical `bash(command: "ls -la")` argument dump so older
     * backends/models that don't populate it don't regress.
     *
     * Used both for the running placeholder and (via Renderer) the finished
     * marker's label.
     */
    public static function describeToolCall(ToolCall $call): string
    {
        $described = $call->arguments['description'] ?? null;
        if (is_string($described)) {
            $described = self::singleLine($described);
            if ($described !== '') {
                return $described;
            }
        }

        if ($call->arguments === []) {
            return $call->name . '()';
        }

        $parts = [];
        foreach ($call->arguments as $key => $value) {
            $rendered = is_string($value) ? $value : (json_encode($value) ?: '');
            if (mb_strlen($rendered) > self::DESCRIPTION_MAX) {
                $rendered = mb_substr($rendered, 0, self::DESCRIPTION_MAX) . '…';
            }
            $parts[] = is_int($key) ? $rendered : "{$key}: " . json_encode($rendered);
        }

        return $call->name . '(' . implode(', ', $parts) . ')';
    }

    /**
     * Flatten model-controlled text into one bounded, control-byte-free line.
     *
     * A tool call's arguments come straight from the model, and the resulting
     * label is written into a single row of the TUI frame: a newline would
     * desynchronise the renderer's line accounting and a raw ESC would let the
     * model inject its own SGR/cursor sequences into the chrome.
     */
    private static function singleLine(string $text): string
    {
        // \p{C} covers C0/C1 controls (incl. ESC, CR, LF, TAB) plus unassigned
        // and format code points such as bidi overrides.
        $flattened = preg_replace('/[\p{C}\s]+/u', ' ', $text);
        if ($flattened === null) {
            // Invalid UTF-8 makes the /u pattern bail; strip byte-wise instead
            // so malformed input can never smuggle control bytes into a frame.
            $flattened = preg_replace('/[[:cntrl:]\s]+/', ' ', $text) ?? '';
        }
        $flattened = trim($flattened);
        if (mb_strlen($flattened) > self::DESCRIPTION_MAX) {
            $flattened = mb_substr($flattened, 0, self::DESCRIPTION_MAX) . '…';
        }

        return $flattened;
    }

    public function attachFile(string $path): self
    {
        return new self(
            role: $this->role,
            content: $this->content,
            createdAt: $this->createdAt,
            attachments: [...$this->attachments, new Attachment($path, AttachmentType::File)],
            toolCalls: $this->toolCalls,
            toolResults: $this->toolResults,
            pendingToolCallId: $this->pendingToolCallId,
            reasoning: $this->reasoning,
            imageBytes: $this->imageBytes,
            imageProtocol: $this->imageProtocol,
            usage: $this->usage,
        );
    }

    public function attachImage(string $path): self
    {
        return new self(
            role: $this->role,
            content: $this->content,
            createdAt: $this->createdAt,
            attachments: [...$this->attachments, new Attachment($path, AttachmentType::Image)],
            toolCalls: $this->toolCalls,
            toolResults: $this->toolResults,
            pendingToolCallId: $this->pendingToolCallId,
            reasoning: $this->reasoning,
            imageBytes: $this->imageBytes,
            imageProtocol: $this->imageProtocol,
            usage: $this->usage,
        );
    }

    /**
     * Create a message with tool calls (for assistant responses that invoke tools).
     *
     * @param list<ToolCall> $toolCalls
     */
    public function withToolCalls(array $toolCalls): self
    {
        return new self(
            role: $this->role,
            content: $this->content,
            createdAt: $this->createdAt,
            attachments: $this->attachments,
            toolCalls: $toolCalls,
            toolResults: $this->toolResults,
            pendingToolCallId: $this->pendingToolCallId,
            reasoning: $this->reasoning,
            imageBytes: $this->imageBytes,
            imageProtocol: $this->imageProtocol,
            usage: $this->usage,
        );
    }

    /**
     * Attach the tool result(s) this message reports, keeping its existing
     * content/role/attachments/toolCalls untouched - {@see Renderer} uses a
     * non-empty $toolResults to render a distinct "tool call" marker instead
     * of a plain assistant bubble.
     *
     * @param list<ToolResult> $toolResults
     */
    public function withToolResults(array $toolResults): self
    {
        return new self(
            role: $this->role,
            content: $this->content,
            createdAt: $this->createdAt,
            attachments: $this->attachments,
            toolCalls: $this->toolCalls,
            toolResults: $toolResults,
            pendingToolCallId: null,
            reasoning: $this->reasoning,
            imageBytes: $this->imageBytes,
            imageProtocol: $this->imageProtocol,
            usage: $this->usage,
        );
    }

    /**
     * Attach (or clear, via null) the model's extracted reasoning/thinking
     * text — see this class's $reasoning docblock. Used at the {@see
     * \SugarCraft\Crush\Backend\EngineBackend} conversion seam so the value
     * {@see \SugarCraft\Crush\Messages\AssistantMessage::reasoning()} already
     * computed isn't thrown away when crossing into the root {@see Message}
     * DTO the TUI actually renders.
     */
    public function withReasoning(?string $reasoning): self
    {
        return new self(
            role: $this->role,
            content: $this->content,
            createdAt: $this->createdAt,
            attachments: $this->attachments,
            toolCalls: $this->toolCalls,
            toolResults: $this->toolResults,
            pendingToolCallId: $this->pendingToolCallId,
            reasoning: $reasoning,
            imageBytes: $this->imageBytes,
            imageProtocol: $this->imageProtocol,
            usage: $this->usage,
        );
    }

    /**
     * Attach image bytes captured by a tool result during this turn (e.g.
     * {@see \SugarCraft\Crush\Tools\BuiltIn\Doctor}'s capability swatch) -
     * W1.G2 reachability fix. Used at the {@see
     * \SugarCraft\Crush\Backend\EngineBackend} conversion seam, mirroring
     * {@see withReasoning()}'s role for the parallel `reasoning` field.
     */
    public function withImage(?string $imageBytes, ?string $imageProtocol): self
    {
        return new self(
            role: $this->role,
            content: $this->content,
            createdAt: $this->createdAt,
            attachments: $this->attachments,
            toolCalls: $this->toolCalls,
            toolResults: $this->toolResults,
            pendingToolCallId: $this->pendingToolCallId,
            reasoning: $this->reasoning,
            imageBytes: $imageBytes,
            imageProtocol: $imageProtocol,
            usage: $this->usage,
        );
    }

    /**
     * Attach (or clear, via null) what the turn cost - see $usage's docblock.
     * Used at the {@see \SugarCraft\Crush\Backend\EngineBackend} conversion
     * seam, where the per-step figures {@see
     * \SugarCraft\Crush\Messages\AssistantMessage::usage()} carries have just
     * been summed over the whole bounded loop, mirroring {@see
     * withReasoning()}'s role for the parallel `reasoning` field.
     */
    public function withUsage(?Usage $usage): self
    {
        return new self(
            role: $this->role,
            content: $this->content,
            createdAt: $this->createdAt,
            attachments: $this->attachments,
            toolCalls: $this->toolCalls,
            toolResults: $this->toolResults,
            pendingToolCallId: $this->pendingToolCallId,
            reasoning: $this->reasoning,
            imageBytes: $this->imageBytes,
            imageProtocol: $this->imageProtocol,
            usage: $usage,
        );
    }

    /**
     * True when this message carries image bytes captured by a tool result
     * during this turn - see $imageBytes's docblock.
     */
    public function hasImage(): bool
    {
        return $this->imageBytes !== null;
    }

    /**
     * Wire-format dict used by every HTTP backend adapter. Caller
     * decides whether to filter system messages out (some APIs
     * don't accept them in the messages list).
     *
     * @return array{role:string,content:string,attachments?:list<array{type:string,path:string}>,tool_calls?:list<array{name:string,arguments:array<string,mixed>,id?:string}>}
     */
    public function toWire(): array
    {
        $wire = ['role' => $this->role->value, 'content' => $this->content];
        if ($this->attachments !== []) {
            $wire['attachments'] = array_map(
                static fn(Attachment $a) => ['type' => $a->type->name, 'path' => $a->path],
                $this->attachments,
            );
        }
        if ($this->toolCalls !== []) {
            $wire['tool_calls'] = array_map(
                static fn(ToolCall $tc) => $tc->toArray(),
                $this->toolCalls,
            );
        }
        return $wire;
    }
}
