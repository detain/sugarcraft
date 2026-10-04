<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Usage;

/**
 * How a reply that stopped early is asked to go on (roadmap 2.7-2 / 2.7-3):
 * the rows that ask for the rest, and how the rest is joined to what came
 * before.
 *
 * Two stops are continued. A reply cut at the output-token ceiling
 * (`finish_reason: length`, no tool call) is continued by the turn loop, up to
 * {@see MAX_LENGTH_CONTINUATIONS} times (Aider, nanobot, Cline). A stream that
 * dropped after its text was already painted is continued by the Runtime's
 * stream retry (Zed's "Continue where you left off", OpenClaw) — restarting it
 * would paint the reply twice.
 *
 * Two ways to ask. A provider that takes an assistant PREFILL
 * ({@see AcceptsAssistantPrefill}) is sent the partial reply as the request's
 * last message and answers with the rest of that same message — the exact
 * continuation. Any other provider is sent the partial reply plus a user row
 * asking for the rest, with the last {@see TAIL_CHARS} characters quoted so
 * the model knows exactly where it stopped (nanobot's
 * `<already_delivered_tail>`); the answer is then glued on.
 *
 * The prefill is sent right-trimmed: the Anthropic API refuses a final
 * assistant turn that ends in whitespace, and the joined reply is built from
 * the same trimmed text, so it is what the model actually continued.
 */
final class ReplyContinuation
{
    /** Continuations of one length-stopped reply before its notice stands. */
    public const MAX_LENGTH_CONTINUATIONS = 3;

    /** The user row that asks a reply cut at the output ceiling for its rest. */
    public const LENGTH_PROMPT = 'Your previous reply was cut off at the output-token limit. Continue it from its exact '
        . 'endpoint: output only the new text, in the same language and style. Do not repeat any of it, restart it, '
        . 'recap it or acknowledge this instruction.';

    /** The user row that asks a reply whose stream dropped for its rest. */
    public const RESUME_PROMPT = 'Continue where you left off: the connection dropped while you were replying. Output '
        . 'only the rest of the reply, from its exact endpoint. Do not repeat any of it, restart it, recap it or '
        . 'acknowledge this instruction.';

    /** How much of the partial reply the user row quotes back. */
    public const TAIL_CHARS = 64;

    /** The fence around the quoted tail. */
    public const TAIL_TAG = 'already_delivered_tail';

    /** Whether $provider is continued by prefill for $model. */
    public static function prefills(ProviderInterface $provider, string $model): bool
    {
        return $provider instanceof AcceptsAssistantPrefill && $provider->acceptsAssistantPrefill($model);
    }

    /**
     * Whether $partial can be continued at all: a reply with text and no tool
     * call. A reply that only thought has nothing to prefill, and one that
     * called a tool is a step, not a cut-off answer.
     */
    public static function continuable(AssistantMessage $partial): bool
    {
        return rtrim($partial->content()) !== '' && ($partial->toolCalls() ?? []) === [];
    }

    /**
     * The rows to append to the request so the model continues $partial.
     *
     * @return list<Message>
     */
    public static function rows(AssistantMessage $partial, bool $prefill, string $prompt): array
    {
        if ($prefill) {
            return [new AssistantMessage(rtrim($partial->content()))];
        }

        $tail = mb_substr($partial->content(), -self::TAIL_CHARS);

        return [
            new AssistantMessage($partial->content()),
            new UserMessage(sprintf("%s\n\n<%s>%s</%s>", $prompt, self::TAIL_TAG, $tail, self::TAIL_TAG)),
        ];
    }

    /**
     * $partial and its continuation $next as the one reply they are: the text
     * joined, the reasoning joined, both responses' usage summed (each was a
     * billed call), and the tool calls and length stop of $next, which is
     * where the reply now ends.
     */
    public static function merge(AssistantMessage $partial, AssistantMessage $next, bool $prefill): AssistantMessage
    {
        $reasoning = ($partial->reasoning() ?? '') . ($next->reasoning() ?? '');

        return new AssistantMessage(
            ($prefill ? rtrim($partial->content()) : $partial->content()) . $next->content(),
            $next->toolCalls(),
            $reasoning === '' ? null : $reasoning,
            Usage::sum([$partial->usage(), $next->usage()]),
            $next->lengthStopped(),
        );
    }

    /**
     * Whether $failure is a provider refusing the prefill itself — Anthropic's
     * "This model does not support assistant message prefill" — so the same
     * continuation can be asked for again with a user row.
     */
    public static function rejectsPrefill(\Throwable|CompleteResponse $failure): bool
    {
        if ($failure instanceof CompleteResponse) {
            return $failure->isError
                && $failure->errorTransient !== true
                && preg_match('/\bprefill/i', (string) $failure->errorMessage) === 1;
        }

        if (TransientFailure::isTransient($failure)) {
            return false;
        }
        for ($link = $failure; $link !== null; $link = $link->getPrevious()) {
            if (preg_match('/\bprefill/i', $link->getMessage()) === 1) {
                return true;
            }
        }

        return false;
    }
}
