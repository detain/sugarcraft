<?php

declare(strict_types=1);

namespace SugarCraft\Code\Hooks;

use SugarCraft\Code\Cli\LaunchContext;
use SugarCraft\Code\Share\JsonlTranscriptWriter;

/**
 * The JSON a hook is asked to act on, in the shape every transport sends it.
 *
 * C-EXT-6.c (LATE) moved this out of {@see ScriptHook}, where it began as the
 * bytes written to a script's stdin, because a hook that is dialled over HTTP
 * is consulted about the SAME call and must see the same object: session id,
 * cwd, event name, tool name, tool arguments, tool response, transcript path.
 * Two builders of that payload is two answers to "what did the hook see", and
 * the day they disagree is the day a guard decides from one field over a pipe
 * and from another one over a socket. {@see ScriptHook::stdinPayload()} and
 * {@see HttpHook::execute()} are the two callers.
 *
 * THE FIELD NAMES ARE THE CONTRACT, not an implementation detail. They are
 * Claude Code's names (`session_id`, `hook_event_name`, `tool_input`,
 * `tool_response`, `transcript_path`), which is what lets a hook written
 * against that tool run under candy-code over either transport; a rename here
 * would break every script in every user's `~/.candy-code/hooks/` at once.
 *
 * THE PAYLOAD IS NOT SECRET-FREE and this class is where that is stated: it
 * carries tool arguments and tool output, so it can hold file contents, command
 * lines and whatever a read of a config file returned. A command hook spends
 * that on a local process. An HTTP hook spends it on the network, which is why
 * the URL that receives it is resolved only through
 * {@see \SugarCraft\Code\Support\PublicUrlGuard} — the repo's one SSRF rule —
 * and never through a target this class was handed un-vetted.
 *
 * @see HttpHook
 */
final class HookPayload
{
    private function __construct()
    {
    }

    /**
     * The consult-body for one call of one event, as one JSON object string.
     *
     * @param string $eventValue the wire value of the {@see HookEvent} being
     *        fired, which is the only thing here a caller cannot read off the
     *        context: a script hook knows its own event, and an HTTP hook is
     *        registered against one
     */
    public static function stdin(HookContext $context, string $eventValue): string
    {
        $payload = \json_encode([
            'session_id' => $context->sessionId,
            'cwd' => $context->projectRoot,
            'hook_event_name' => $eventValue,
            'tool_name' => $context->toolName,
            'tool_input' => $context->toolArgs === [] ? new \stdClass() : $context->toolArgs,
            'tool_response' => $context->toolOutput,
            // The launch's settings root, not $context->projectRoot: this is
            // the file the store's mirror writes, and the store was wired
            // against the root the settings tiers resolved against. Task-list
            // and host contexts carry a cwd that can differ from it; the
            // journal's location is decided once at launch, not per context.
            'transcript_path' => JsonlTranscriptWriter::enabled()
                ? JsonlTranscriptWriter::pathFor(
                    $context->sessionId,
                    LaunchContext::projectRootForSettings(),
                )
                : null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $payload === false ? '{}' : $payload;
    }
}
