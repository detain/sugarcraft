<?php

declare(strict_types=1);

namespace CandyCore\Crush\Backend;

use CandyCore\Crush\Backend;
use CandyCore\Crush\Message;

/**
 * Backend that shells out to an external command. The command
 * receives the JSON-encoded history on stdin and writes the
 * assistant reply to stdout.
 *
 * This is the recommended starting point for hooking SugarCrush
 * to a real LLM: write a small wrapper script in any language,
 * make it executable, point this backend at it. Keeps the PHP
 * core network-dep-free while still letting users plug in
 * anything that has a CLI.
 *
 * Example wrapper (Anthropic via curl + jq, in bash):
 *
 *   #!/usr/bin/env bash
 *   payload=$(jq -nc --argjson h "$(cat)" \
 *     '{model: "claude-opus-4-7", max_tokens: 4096, messages: $h}')
 *   curl -sN https://api.anthropic.com/v1/messages \
 *     -H "x-api-key: $ANTHROPIC_API_KEY" \
 *     -H "anthropic-version: 2023-06-01" \
 *     -H "content-type: application/json" \
 *     -d "$payload" \
 *     | jq -r '.content[0].text'
 *
 * `proc_open` is used so stdin/stdout are wired cleanly and the
 * process exit code is captured. A non-zero exit returns an
 * "[error: …]" assistant message rather than throwing; backend
 * failures shouldn't crash the chat shell.
 */
final class CommandBackend implements Backend
{
    /**
     * @param string|list<string> $command Command + args. Pass a
     *                                     list to avoid shell
     *                                     escaping concerns.
     */
    public function __construct(private readonly string|array $command)
    {}

    public function complete(array $history): Message
    {
        $payload = json_encode(
            array_map(static fn(Message $m) => $m->toWire(), $history),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if ($payload === false) {
            return Message::assistant('_[error: failed to encode history]_');
        }

        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $cmd = is_array($this->command) ? $this->command : $this->command;
        $proc = @proc_open($cmd, $descriptor, $pipes);
        if (!is_resource($proc)) {
            return Message::assistant('_[error: failed to spawn backend command]_');
        }
        fwrite($pipes[0], $payload);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        if ($exit !== 0) {
            $tail = trim($stderr);
            $hint = $tail === '' ? '' : "\n\n```\n{$tail}\n```";
            return Message::assistant("_[backend exited {$exit}]_{$hint}");
        }
        return Message::assistant(trim($stdout));
    }
}
