<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Crush\Permissions\DenialKind;

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
final class Message implements \JsonSerializable
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
        /**
         * Whether the provider stopped this assistant turn at its OUTPUT
         * ceiling rather than letting the reply finish - E707 (round 81).
         * Carried across the {@see
         * \SugarCraft\Crush\Backend\EngineBackend} conversion boundary like
         * $reasoning, $imageBytes and $usage before it: the verdict exists on
         * {@see \SugarCraft\Crush\Providers\CompleteResponse::$truncated} and
         * this DTO is where Chat learns it, so the settle arm can append one
         * transcript notice instead of letting a cut-off reply look complete.
         *
         * False means a clean end OR a provider whose wire could not report
         * the stop - it is not a proof of cleanliness (same contract as the
         * carrier field it comes from). EngineBackend ORs it across the steps
         * of one agentic turn: any step that hit the ceiling marks the turn.
         */
        public readonly bool $lengthStopped = false,
        /**
         * Whether the app's OWN step ceiling ended this turn while tool
         * results were still pending - F2 (spawn-latency plan). The sibling
         * of $lengthStopped one seam further in: that one is the provider's
         * wire verdict about its output budget, this one is this harness's
         * verdict about `maxSteps` — the EngineBackend loop ran out before
         * the model produced a tool-free reply, which until F2 exited the
         * turn SILENTLY. Carried on the DTO for the same reason: the settle
         * arm in {@see \SugarCraft\Crush\Chat} turns it into one loud
         * transcript notice instead of a half-finished turn reading as done.
         *
         * False on every clean end and on both explicit breaks (a reply with
         * no tool calls; the spend cap, which writes its own notice) — it
         * names loop exhaustion alone.
         */
        public readonly bool $stepsTruncated = false,
        /**
         * The model's raw arguments for the call a "running" placeholder
         * stands in for - set only alongside {@see $pendingToolCallId}.
         * {@see $content} carries just {@see describeToolCall()}'s bounded
         * one-liner, which for a tool whose call carries a `description`
         * never mentions the command at all; this is what lets
         * {@see \SugarCraft\Crush\Chat} hand the finished
         * {@see ToolResult} the full invocation, so an expanded row can show
         * WHAT ran (`$ ls -la`) and not only its output. Display-only: never
         * part of {@see toWire()}.
         *
         * @var array<string, mixed>
         */
        public readonly array $pendingToolArguments = [],
        /**
         * Whether this row exists for the person at the terminal ONLY and
         * must never reach a model (audit 15b-03): a slash command's echo and
         * its output, `/help`, a queued-prompt or mid-turn refusal notice, a
         * background or runtime notice, a backend error string. Chat keeps
         * every one of these in the same `list<Message>` the transcript is
         * painted from, so without the flag each was replayed to the provider
         * as a real turn - the model was told it had said `/help`'s listing
         * and its own error strings, and on SGLang every System notice was
         * hoisted into the system prompt, breaking the cache prefix.
         *
         * Filtered at every boundary where history becomes a request (see
         * {@see agentVisible()}); persisted by {@see jsonSerialize()} so a
         * resumed session keeps the distinction. Never part of {@see toWire()}.
         */
        public readonly bool $uiOnly = false,
        /**
         * The tool whose identical repeats made the repeat-call loop guard
         * END this turn ({@see \SugarCraft\Crush\Backend\ToolCallLoopGuard::END_TURN_AT}),
         * or null on every other turn. The third harness-side stop beside
         * $lengthStopped and $stepsTruncated, and deliberately not folded into
         * the latter: the step-ceiling notice tells the operator to raise
         * `maxToolSteps`, which is the wrong remedy for a model stuck in a
         * loop. Carried so the settle arm in {@see \SugarCraft\Crush\Chat}
         * can say the turn was stopped and why, instead of leaving it to the
         * model's no-tools summary to mention. Never part of {@see toWire()}.
         */
        public readonly ?string $loopGuardStoppedBy = null,
        /**
         * Audit 15b-15: what happened to an attachment of the turn's own
         * prompt that could NOT reach the model as attached - an image sent to
         * a provider without vision went as a text placeholder - or null when
         * every attachment went out as attached. Decided where the request is
         * built ({@see \SugarCraft\Crush\Backend\EngineBackend::toTypedMessages()},
         * in the forked turn child), carried across the fork result frame on
         * the same rule as $loopGuardStoppedBy, and turned into ONE transcript
         * notice by the settle arm in {@see \SugarCraft\Crush\Chat}, so a
         * degraded attachment is never a silent drop. Transport only: never
         * part of {@see toWire()} and not persisted (the notice row it becomes
         * is).
         */
        public readonly ?string $attachmentNotice = null,
        /**
         * The tool a "running" placeholder stands in for — set only alongside
         * {@see $pendingToolCallId}. Lets a surface tell a still-running call
         * apart by kind before its result names it: the tools sidebar leaves
         * `Task` delegations to the Agents pane, which lists each run itself.
         * Display-only: never part of {@see toWire()}.
         */
        public readonly ?string $pendingToolName = null,
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
     * A {@see Role::System} row written for the user's eyes only - the
     * app reporting on itself (a refusal, a queued prompt, a background
     * status change). Shorthand for `system()->withUiOnly(true)`; see
     * $uiOnly's docblock for why such a row must stay off the model wire.
     */
    public static function notice(string $content, ?int $now = null): self
    {
        return new self(Role::System, $content, $now ?? time(), uiOnly: true);
    }

    /**
     * $history without its {@see $uiOnly} rows - what a backend may be shown.
     *
     * ONE filter for every boundary (Chat's turn, title, suggestion and
     * summary calls; {@see \SugarCraft\Crush\Backend\EngineBackend} and
     * the command backends' encoders) so no two request builders can
     * disagree about what counts as a turn. Re-indexed: callers hand the
     * result on as a `list`.
     *
     * @param array<int, Message> $history
     * @return list<Message>
     */
    public static function agentVisible(array $history): array
    {
        return array_values(array_filter(
            $history,
            static fn(Message $message): bool => !$message->uiOnly,
        ));
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
            pendingToolArguments: $call->arguments,
            pendingToolName: $call->name,
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
            if (is_string($value)) {
                $rendered = $value;
            } else {
                // Compared with false, not `?:` — `0` encodes to "0", which
                // `?:` would have thrown away as falsy.
                $encoded = json_encode($value, self::ARGUMENT_JSON_FLAGS | JSON_PARTIAL_OUTPUT_ON_ERROR);
                $rendered = $encoded === false ? get_debug_type($value) : $encoded;
            }
            if (mb_strlen($rendered) > self::DESCRIPTION_MAX) {
                $rendered = mb_substr($rendered, 0, self::DESCRIPTION_MAX) . '…';
            }
            $parts[] = is_int($key) ? $rendered : "{$key}: " . self::encodeArgument($rendered);
        }

        return $call->name . '(' . implode(', ', $parts) . ')';
    }

    /**
     * Readable unicode, and invalid UTF-8 substituted with U+FFFD instead of
     * failing the whole encode (audit 15b-27). Slashes unescaped: nearly
     * every argument worth showing is a path, and `src\/Foo.php` is noise —
     * `/` is not a character any sink here interprets.
     */
    private const ARGUMENT_JSON_FLAGS = JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * One argument value as a JSON string literal for the one-liner.
     *
     * Audit 15b-27: this used to be a bare `json_encode()`, which returns
     * `false` on a single invalid byte (a Latin-1 `caf\xe9` in a Bash
     * command) — and `false` concatenates as '', so the call was described as
     * `Bash(command: )` and a permission prompt built from it asked the user to
     * approve a command it did not show. `JSON_INVALID_UTF8_SUBSTITUTE` keeps
     * every valid byte and marks the bad ones with U+FFFD; should the encode
     * still fail, the value is shown through
     * {@see \SugarCraft\Core\Util\Sanitize::visibleControls()} rather than
     * blanked.
     *
     * `JSON_UNESCAPED_UNICODE` makes a CJK or accented command readable, but
     * it would also emit the C1 controls (U+0080–U+009F) and the invisible
     * bidi/zero-width format characters raw, where the old escaped output
     * spelled them `\u009b` / `\u202e`. Those are re-escaped here, so the
     * label stays valid JSON and carries nothing a terminal would execute or
     * silently reorder, whichever sink paints it.
     */
    private static function encodeArgument(string $value): string
    {
        $json = json_encode($value, self::ARGUMENT_JSON_FLAGS);
        if ($json === false) {
            return '"' . \SugarCraft\Core\Util\Sanitize::visibleControls($value, false) . '"';
        }

        return preg_replace_callback(
            '/\xC2[\x80-\x9F]|\xD8\x9C|\xE2\x80[\x8B-\x8F\xAA-\xAE]|\xE2\x81[\xA0\xA6-\xA9]|\xEF\xBB\xBF/',
            static fn (array $m): string => sprintf('\\u%04x', mb_ord($m[0], 'UTF-8')),
            $json,
        ) ?? $json;
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

    /**
     * Attach a file. `$contents` is the snapshot {@see Attachment::$data} the
     * wire inlines; see {@see Attachment} for why it is captured once.
     */
    public function attachFile(string $path, ?string $contents = null): self
    {
        return new self(
            role: $this->role,
            content: $this->content,
            createdAt: $this->createdAt,
            attachments: [...$this->attachments, new Attachment($path, AttachmentType::File, $contents)],
            toolCalls: $this->toolCalls,
            toolResults: $this->toolResults,
            pendingToolCallId: $this->pendingToolCallId,
            reasoning: $this->reasoning,
            imageBytes: $this->imageBytes,
            imageProtocol: $this->imageProtocol,
            usage: $this->usage,
            lengthStopped: $this->lengthStopped,
            stepsTruncated: $this->stepsTruncated,
            pendingToolArguments: $this->pendingToolArguments,
            pendingToolName: $this->pendingToolName,
            uiOnly: $this->uiOnly,
            loopGuardStoppedBy: $this->loopGuardStoppedBy,
            attachmentNotice: $this->attachmentNotice,
        );
    }

    /**
     * Attach an image. `$bytes`/`$mimeType` are the snapshot a vision-capable
     * provider is sent ({@see \SugarCraft\Crush\Providers\AttachmentEncoding}).
     */
    public function attachImage(string $path, ?string $bytes = null, ?string $mimeType = null): self
    {
        return new self(
            role: $this->role,
            content: $this->content,
            createdAt: $this->createdAt,
            attachments: [...$this->attachments, new Attachment($path, AttachmentType::Image, $bytes, $mimeType)],
            toolCalls: $this->toolCalls,
            toolResults: $this->toolResults,
            pendingToolCallId: $this->pendingToolCallId,
            reasoning: $this->reasoning,
            imageBytes: $this->imageBytes,
            imageProtocol: $this->imageProtocol,
            usage: $this->usage,
            lengthStopped: $this->lengthStopped,
            stepsTruncated: $this->stepsTruncated,
            pendingToolArguments: $this->pendingToolArguments,
            pendingToolName: $this->pendingToolName,
            uiOnly: $this->uiOnly,
            loopGuardStoppedBy: $this->loopGuardStoppedBy,
            attachmentNotice: $this->attachmentNotice,
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
            lengthStopped: $this->lengthStopped,
            stepsTruncated: $this->stepsTruncated,
            pendingToolArguments: $this->pendingToolArguments,
            pendingToolName: $this->pendingToolName,
            uiOnly: $this->uiOnly,
            loopGuardStoppedBy: $this->loopGuardStoppedBy,
            attachmentNotice: $this->attachmentNotice,
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
            lengthStopped: $this->lengthStopped,
            stepsTruncated: $this->stepsTruncated,
            pendingToolArguments: [],
            pendingToolName: null,
            uiOnly: $this->uiOnly,
            loopGuardStoppedBy: $this->loopGuardStoppedBy,
            attachmentNotice: $this->attachmentNotice,
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
            lengthStopped: $this->lengthStopped,
            stepsTruncated: $this->stepsTruncated,
            pendingToolArguments: $this->pendingToolArguments,
            pendingToolName: $this->pendingToolName,
            uiOnly: $this->uiOnly,
            loopGuardStoppedBy: $this->loopGuardStoppedBy,
            attachmentNotice: $this->attachmentNotice,
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
            lengthStopped: $this->lengthStopped,
            stepsTruncated: $this->stepsTruncated,
            pendingToolArguments: $this->pendingToolArguments,
            pendingToolName: $this->pendingToolName,
            uiOnly: $this->uiOnly,
            loopGuardStoppedBy: $this->loopGuardStoppedBy,
            attachmentNotice: $this->attachmentNotice,
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
            lengthStopped: $this->lengthStopped,
            stepsTruncated: $this->stepsTruncated,
            pendingToolArguments: $this->pendingToolArguments,
            pendingToolName: $this->pendingToolName,
            uiOnly: $this->uiOnly,
            loopGuardStoppedBy: $this->loopGuardStoppedBy,
            attachmentNotice: $this->attachmentNotice,
        );
    }

    /**
     * Attach (or clear, via false) the provider's "stopped at the output
     * ceiling" verdict - see $lengthStopped's docblock. Used at the {@see
     * \SugarCraft\Crush\Backend\EngineBackend} conversion seam alongside
     * {@see withUsage()}'s, so the turn the fold judged is the turn the
     * transcript labels.
     */
    public function withLengthStopped(bool $lengthStopped): self
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
            usage: $this->usage,
            lengthStopped: $lengthStopped,
            stepsTruncated: $this->stepsTruncated,
            pendingToolArguments: $this->pendingToolArguments,
            pendingToolName: $this->pendingToolName,
            uiOnly: $this->uiOnly,
            loopGuardStoppedBy: $this->loopGuardStoppedBy,
            attachmentNotice: $this->attachmentNotice,
        );
    }

    /**
     * Attach (or clear, via false) the harness's own "step ceiling exhausted
     * mid-turn" verdict - see $stepsTruncated's docblock. Written at
     * {@see \SugarCraft\Crush\Backend\EngineBackend::runTurn()}'s loop-exit
     * seam and carried across the fork result frame like the flag it shadows.
     */
    public function withStepsTruncated(bool $stepsTruncated): self
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
            usage: $this->usage,
            lengthStopped: $this->lengthStopped,
            stepsTruncated: $stepsTruncated,
            pendingToolArguments: $this->pendingToolArguments,
            pendingToolName: $this->pendingToolName,
            uiOnly: $this->uiOnly,
            loopGuardStoppedBy: $this->loopGuardStoppedBy,
            attachmentNotice: $this->attachmentNotice,
        );
    }

    /**
     * Mark (or clear, via false) this row as for the user's eyes only - see
     * $uiOnly's docblock. A command echo and its output are built with the
     * ordinary {@see user()}/{@see assistant()} factories, because that is
     * how the transcript renders them, and flagged here.
     */
    public function withUiOnly(bool $uiOnly = true): self
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
            usage: $this->usage,
            lengthStopped: $this->lengthStopped,
            stepsTruncated: $this->stepsTruncated,
            pendingToolArguments: $this->pendingToolArguments,
            pendingToolName: $this->pendingToolName,
            uiOnly: $uiOnly,
            loopGuardStoppedBy: $this->loopGuardStoppedBy,
            attachmentNotice: $this->attachmentNotice,
        );
    }

    /**
     * Attach (or clear, via null) the loop guard's "this turn was ended"
     * verdict — see $loopGuardStoppedBy's docblock. Written at
     * {@see \SugarCraft\Crush\Backend\EngineBackend}'s loop-exit seam
     * beside {@see withStepsTruncated()} and carried across the fork result
     * frame the same way.
     */
    public function withLoopGuardStoppedBy(?string $toolName): self
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
            usage: $this->usage,
            lengthStopped: $this->lengthStopped,
            stepsTruncated: $this->stepsTruncated,
            pendingToolArguments: $this->pendingToolArguments,
            pendingToolName: $this->pendingToolName,
            uiOnly: $this->uiOnly,
            loopGuardStoppedBy: $toolName === '' ? null : $toolName,
            attachmentNotice: $this->attachmentNotice,
        );
    }

    /**
     * Attach (or clear, via null) the attachment-degradation report - see
     * $attachmentNotice's docblock (audit 15b-15). Written by
     * {@see \SugarCraft\Crush\Backend\EngineBackend::complete()} and
     * carried across the fork result frame beside $loopGuardStoppedBy.
     */
    public function withAttachmentNotice(?string $notice): self
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
            usage: $this->usage,
            lengthStopped: $this->lengthStopped,
            stepsTruncated: $this->stepsTruncated,
            pendingToolArguments: $this->pendingToolArguments,
            pendingToolName: $this->pendingToolName,
            uiOnly: $this->uiOnly,
            loopGuardStoppedBy: $this->loopGuardStoppedBy,
            attachmentNotice: $notice === '' ? null : $notice,
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

    /**
     * The persisted shape: every field, keyed by its property name, so a saved
     * transcript row resumes as the message it was ({@see fromArray()}).
     *
     * EXPLICIT rather than json_encode()'s default walk of the public
     * properties, which is what checkpoints used to get and which lost data
     * two ways: {@see AttachmentType} is a pure enum, so any message holding an
     * attachment made the encode THROW and the whole checkpoint was skipped;
     * and raw image bytes are binary, so `JSON_INVALID_UTF8_SUBSTITUTE` turned
     * them into U+FFFD soup. Attachments now travel by case name, and image
     * bytes as base64 under `imageBytesBase64` - the raw `imageBytes` key is
     * never written. The keys `role`, `content` and `pendingToolCallId` keep
     * their old spelling, which is all the `/rewind` reviver reads.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'role' => $this->role->value,
            'content' => $this->content,
            'createdAt' => $this->createdAt,
            // A foreign element (the arrays are typed by docblock only) is
            // handed to json_encode() as it is rather than refused: it may be
            // JsonSerializable itself, and a checkpoint is no place to throw.
            'attachments' => array_map(
                static fn(mixed $a): mixed => $a instanceof Attachment
                    ? [
                        'path' => $a->path,
                        'type' => $a->type->name,
                        // Audit 15b-15: the snapshot the wire is built from.
                        // base64 for the same reason imageBytesBase64 is - an
                        // image is binary, and a file snapshot may hold bytes
                        // json_encode() would refuse - and only when present,
                        // so a path-only row persists exactly as it always did.
                        ...($a->data === null ? [] : ['dataBase64' => base64_encode($a->data)]),
                        ...($a->mimeType === null ? [] : ['mimeType' => $a->mimeType]),
                    ]
                    : $a,
                $this->attachments,
            ),
            'toolCalls' => array_map(
                static fn(mixed $c): mixed => $c instanceof ToolCall
                    ? ['name' => $c->name, 'arguments' => $c->arguments, 'id' => $c->id]
                    : $c,
                $this->toolCalls,
            ),
            'toolResults' => array_map(
                static fn(mixed $r): mixed => !$r instanceof ToolResult ? $r : [
                    'name' => $r->name,
                    'result' => $r->result,
                    'error' => $r->error,
                    'id' => $r->id,
                    'imageBytesBase64' => $r->imageBytes === null ? null : base64_encode($r->imageBytes),
                    'imagePath' => $r->imagePath,
                    'imageProtocol' => $r->imageProtocol,
                    'diff' => $r->diff,
                    'durationMs' => $r->durationMs,
                    'description' => $r->description,
                    'arguments' => $r->arguments,
                    // Audit F-P8: a refusal is recognised by this field, not
                    // its text, so a resumed transcript must keep it or every
                    // real refusal comes back as an ordinary error row.
                    'denial' => $r->denial?->value,
                ],
                $this->toolResults,
            ),
            'pendingToolCallId' => $this->pendingToolCallId,
            'reasoning' => $this->reasoning,
            'imageBytesBase64' => $this->imageBytes === null ? null : base64_encode($this->imageBytes),
            'imageProtocol' => $this->imageProtocol,
            'usage' => $this->usage,
            'lengthStopped' => $this->lengthStopped,
            'stepsTruncated' => $this->stepsTruncated,
            'loopGuardStoppedBy' => $this->loopGuardStoppedBy,
            'pendingToolArguments' => $this->pendingToolArguments,
            // Only when set, so every agent-visible row - i.e. every row a
            // pre-flag transcript holds - serialises byte-for-byte as before.
            ...($this->uiOnly ? ['uiOnly' => true] : []),
            ...($this->pendingToolName !== null ? ['pendingToolName' => $this->pendingToolName] : []),
        ];
    }

    /**
     * Rebuild a message from {@see jsonSerialize()}'s shape - or from any
     * older row that carries a subset of it.
     *
     * Tolerant field by field, because the rows come off disk: a missing or
     * mistyped field takes its default rather than failing the whole
     * transcript, an unknown role is read as `user` (what the `/rewind`
     * reviver has always done), and an attachment, tool call or tool result
     * without its required parts is dropped. A pending placeholder is returned
     * AS a placeholder; whether it should be healed into an "interrupted" row
     * is the caller's decision, see {@see Chat::reviveTranscriptMessage()}.
     *
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $string = static fn(mixed $v): ?string => \is_string($v) ? $v : null;
        $bytes = static function (mixed $v): ?string {
            if (!\is_string($v)) {
                return null;
            }
            $decoded = base64_decode($v, true);

            return $decoded === false ? null : $decoded;
        };

        $attachments = [];
        foreach (\is_array($row['attachments'] ?? null) ? $row['attachments'] : [] as $a) {
            if (!\is_array($a) || !\is_string($a['path'] ?? null)) {
                continue;
            }
            $type = match ($a['type'] ?? null) {
                'Image' => AttachmentType::Image,
                'File' => AttachmentType::File,
                default => null,
            };
            if ($type !== null) {
                $attachments[] = new Attachment(
                    $a['path'],
                    $type,
                    $bytes($a['dataBase64'] ?? null),
                    $string($a['mimeType'] ?? null),
                );
            }
        }

        $toolCalls = [];
        foreach (\is_array($row['toolCalls'] ?? null) ? $row['toolCalls'] : [] as $c) {
            if (\is_array($c) && \is_string($c['name'] ?? null)) {
                $toolCalls[] = new ToolCall(
                    $c['name'],
                    \is_array($c['arguments'] ?? null) ? $c['arguments'] : [],
                    $string($c['id'] ?? null),
                );
            }
        }

        $toolResults = [];
        foreach (\is_array($row['toolResults'] ?? null) ? $row['toolResults'] : [] as $r) {
            if (!\is_array($r) || !\is_string($r['name'] ?? null)) {
                continue;
            }
            $toolResults[] = new ToolResult(
                name: $r['name'],
                result: $string($r['result'] ?? null) ?? '',
                error: $string($r['error'] ?? null),
                id: $string($r['id'] ?? null),
                imageBytes: $bytes($r['imageBytesBase64'] ?? null),
                imagePath: $string($r['imagePath'] ?? null),
                imageProtocol: $string($r['imageProtocol'] ?? null),
                diff: $string($r['diff'] ?? null),
                durationMs: \is_int($r['durationMs'] ?? null) ? $r['durationMs'] : null,
                description: $string($r['description'] ?? null),
                arguments: \is_array($r['arguments'] ?? null) ? $r['arguments'] : [],
                // Absent on a checkpoint written before F-P8: such a row
                // revives as a plain error, never re-derived from its text.
                denial: \is_string($r['denial'] ?? null) ? DenialKind::tryFrom($r['denial']) : null,
            );
        }

        return new self(
            role: Role::tryFrom(\is_string($row['role'] ?? null) ? $row['role'] : '') ?? Role::User,
            content: $string($row['content'] ?? null) ?? '',
            createdAt: \is_int($row['createdAt'] ?? null) ? $row['createdAt'] : time(),
            attachments: $attachments,
            toolCalls: $toolCalls,
            toolResults: $toolResults,
            pendingToolCallId: $string($row['pendingToolCallId'] ?? null),
            reasoning: $string($row['reasoning'] ?? null),
            imageBytes: $bytes($row['imageBytesBase64'] ?? null),
            imageProtocol: $string($row['imageProtocol'] ?? null),
            usage: Usage::fromArray($row['usage'] ?? null),
            lengthStopped: ($row['lengthStopped'] ?? false) === true,
            stepsTruncated: ($row['stepsTruncated'] ?? false) === true,
            pendingToolArguments: \is_array($row['pendingToolArguments'] ?? null) ? $row['pendingToolArguments'] : [],
            pendingToolName: $string($row['pendingToolName'] ?? null),
            uiOnly: ($row['uiOnly'] ?? false) === true,
            loopGuardStoppedBy: $string($row['loopGuardStoppedBy'] ?? null),
        );
    }
}
