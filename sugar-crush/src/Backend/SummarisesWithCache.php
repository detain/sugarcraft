<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Backend;

use React\Promise\PromiseInterface;
use SugarCraft\Crush\Message;

/**
 * Opt-in declaration that a backend can summarise a conversation by sending
 * the request a turn of that conversation would send, plus one final
 * instruction row (roadmap 2.4-2).
 *
 * WHY A SEPARATE METHOD. A summary asked of a separate tool-less backend
 * carries its own system prompt and no tool schemas, so not one byte of it is
 * a prefix the provider has cached: the whole earlier conversation is
 * prefilled again, at full price, for the largest single call the app makes.
 * The request this method sends is the conversation's own — the same system
 * prompt, the same tool schemas, the same history — so everything before the
 * instruction is a prefix the previous turn already paid for. The tools stay
 * ADVERTISED and can never RUN: the reply is taken the moment the assistant
 * message arrives, and any call it asked for anyway is dropped.
 *
 * A side interface rather than a wider {@see \SugarCraft\Crush\Backend}, for
 * the reason {@see ObservesReasoning} gives: a method added to the base
 * contract is a load-time fatal for every narrower implementation
 * (`BackendContractWideningTest`).
 */
interface SummarisesWithCache
{
    /**
     * Send $history as a turn would, plus $instruction as a final user row,
     * and resolve to the reply: an assistant {@see Message} carrying the
     * request's usage and its length-stop flag, with no tool calls.
     *
     * Never runs a tool and never raises a permission prompt. Like
     * {@see \SugarCraft\Crush\Backend::completeAsync()} it carries no total
     * timeout and honours $cancellation best-effort; a failed call rejects.
     *
     * @param list<Message> $history the conversation as the next turn would
     *                               send it (UI-only rows are dropped here)
     *
     * @return PromiseInterface<Message>
     */
    public function summariseAsync(array $history, string $instruction, ?CancellationToken $cancellation = null): PromiseInterface;
}
