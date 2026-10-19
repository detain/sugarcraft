<?php

declare(strict_types=1);

namespace SugarCraft\Code\Cli;

use SugarCraft\Code\Events\SpendCapBreached;
use SugarCraft\Code\Events\SubAgentActivity;
use SugarCraft\Code\Events\ToolFinished;
use SugarCraft\Code\Events\ToolProgress;
use SugarCraft\Code\Events\ToolStarted;
use SugarCraft\Code\Host\SessionEvent;
use SugarCraft\Code\Protocol\EventEnvelope;

/**
 * NDJSON writer for `--output-format stream-json` (AUT-3).
 *
 * ONE MAPPING PLACE from the engine's typed observer callbacks to protocol
 * event lines. The type strings are NOT a third vocabulary: every one is a
 * {@see SessionEvent} constant — the same namespaced words the durable
 * session log, the JSON-RPC `event` notification and RemoteBackend already
 * speak, so `candy-code -p --output-format stream-json` and a hosted turn
 * differ only in transport (stdout lines vs. notifications), never in the
 * event's name or data shape. {@see \SugarCraft\Code\Protocol\Schema\EventSchemas}
 * is the data contract for each type and the census that catches drift.
 *
 * THE ENVELOPE is {@see EventEnvelope::server()} verbatim — `{sessionId:
 * null, type, ts, durable: false, data}` — one JSON document per line,
 * newline-terminated, the same flags as
 * {@see NonInteractive::encodeDocument()} so bytes agree across the two
 * writers.
 *
 * WHAT DOES NOT GO THROUGH HERE: refusals (stderr discipline is preserved —
 * text stdout's contract that a pipe sees the answer and nothing else rides
 * unchanged on stream-json), notices, and every error/usage path, which all
 * stay on stderr while the TERMINAL line carries the typed error document.
 * The emitter never validates against the schema at runtime; the shapes are
 * hand-mirrored here and pinned line-for-line by StreamJsonEmitterTest,
 * whose fixtures re-read EventSchemas::data() required-lists so a renamed
 * key reddens a test rather than a client.
 *
 * Unclassified observer payloads are silently skipped. The two the engine
 * can actually hand a headless run — {@see \SugarCraft\Code\Events\ContextLedgerChanged}
 * and {@see \SugarCraft\Code\Events\MediaProgress} — have no wire type in
 * the protocol vocabulary, and inventing one at the emitter is exactly the
 * third vocabulary this class exists to prevent.
 */
final class StreamJsonEmitter
{
    /** The delta schema's required part key; this class writes one part per stream. */
    private const ANSWER_PART = 'answer';
    private const REASONING_PART = 'reasoning';

    private int $answerOffset = 0;

    private int $reasoningOffset = 0;

    /** @param resource $out */
    public function __construct(private readonly mixed $out = \STDOUT)
    {
    }

    /**
     * A prose delta — the string arm of `$onToken`.
     */
    public function onToken(string $text): void
    {
        if ($text === '') {
            return;
        }

        $this->emit(SessionEvent::ASSISTANT_DELTA, [
            'text' => $text,
            'partId' => self::ANSWER_PART,
            'offset' => $this->answerOffset,
        ]);
        $this->answerOffset += \strlen($text);
    }

    /**
     * A reasoning delta — the string arm of `$onReasoning`.
     */
    public function onReasoning(string $text): void
    {
        if ($text === '') {
            return;
        }

        $this->emit(SessionEvent::REASONING_DELTA, [
            'text' => $text,
            'partId' => self::REASONING_PART,
            'offset' => $this->reasoningOffset,
        ]);
        $this->reasoningOffset += \strlen($text);
    }

    /**
     * A typed event from the `$onEvent` observer, mapped to its wire line.
     */
    public function onEvent(object $event): void
    {
        if ($event instanceof ToolStarted) {
            $this->emit(SessionEvent::TOOL_STARTED, [
                'toolCallId' => $event->toolCallId,
                'name' => $event->toolName,
                'arguments' => $event->arguments,
            ]);

            return;
        }

        if ($event instanceof ToolFinished) {
            $this->emit(SessionEvent::TOOL_FINISHED, [
                'toolCallId' => $event->toolCallId,
                'name' => $event->toolName,
                'isError' => $event->result->isError(),
                'content' => $event->result->content(),
            ]);

            return;
        }

        if ($event instanceof ToolProgress) {
            $this->emit(SessionEvent::TOOL_PROGRESS, [
                'toolCallId' => $event->toolCallId,
                'name' => $event->toolName,
                'seq' => $event->seq,
                'tail' => $event->tail,
                'truncated' => $event->truncated,
                'tailBudget' => $event->tailBudget,
            ]);

            return;
        }

        if ($event instanceof SpendCapBreached) {
            $this->emit(SessionEvent::SPEND_CAP_BREACHED, [
                'calls' => $event->completedCalls,
                'spent' => $event->spentUsd,
                'cap' => $event->capUsd,
            ]);

            return;
        }

        if ($event instanceof SubAgentActivity) {
            // The P-B v2 beat already IS the wire shape — toArray() keys match
            // the subagent schema verbatim (that is what the fork frame
            // carries). The op chooses which of the three subagent types.
            $type = match ($event->op) {
                'started' => SessionEvent::SUBAGENT_STARTED,
                'finished' => SessionEvent::SUBAGENT_FINISHED,
                default => SessionEvent::SUBAGENT_PROGRESS,
            };
            $this->emit($type, $event->toArray());
        }
    }

    /**
     * The terminal line — the already-encoded success/error document,
     * written last so a reader can treat any earlier line as an event and
     * the final line as the schema-1 answer.
     */
    public function emitLine(string $encodedDocument): void
    {
        \fwrite($this->out, $encodedDocument . "\n");
    }

    /**
     * @param array<string, mixed> $data
     */
    private function emit(string $type, array $data): void
    {
        $envelope = EventEnvelope::server($type, $data);
        $json = NonInteractive::encodeDocument($envelope->toArray());
        \fwrite($this->out, $json . "\n");
    }
}
