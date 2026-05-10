<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Backend;

use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Message;

/**
 * Streaming-capable backend that shells out to an external command
 * and calls the `$onToken` callback for each line/token as it arrives.
 *
 * This enables real-time token-by-token display in the SugarCrush UI
 * rather than waiting for the complete response.
 *
 * The external command should write one token per line to stdout.
 * This is compatible with many LLM APIs that support streaming SSE
 * or line-delimited output.
 *
 * Example wrapper (Ollama streaming):
 *
 *   #!/usr/bin/env bash
 *   payload=$(jq -nc --argjson h "$(cat)" \
 *     '{model: "llama3", stream: true, messages: $h}')
 *   curl -sN http://localhost:11434/api/chat \
 *     -d "$payload" \
 *     | jq -r '.message.content'  # streams one word per line
 *
 * Usage:
 *
 *   $chat = new Chat(
 *       backend: new StreamingCommandBackend(['./ollama-stream.sh']),
 *   );
 *   $chat->withStreaming(true);
 */
final class StreamingCommandBackend implements Backend
{
    /**
     * @param string|list<string> $command Command + args. Pass a
     *                                     list to avoid shell
     *                                     escaping concerns.
     */
    public function __construct(
        private readonly string|array $command,
        private readonly int $timeout = 120,
    ) {}

    public function complete(array $history, callable $onToken = null): Message
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
        $proc = @proc_open($cmd, $descriptor, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            return Message::assistant('_[error: failed to spawn streaming backend command]_');
        }

        // Set stdout to non-blocking so we can read line by line
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        fwrite($pipes[0], $payload);
        fclose($pipes[0]);

        $stderr = '';
        $tokens = [];
        $deadline = time() + $this->timeout;

        // Keep reading until process exits AND both pipes are exhausted
        $running = true;
        $iterations = 0;
        while (true) {
            $iterations++;

            // Read stdout line by line
            $lineCount = 0;
            while (($line = fgets($pipes[1])) !== false) {
                $lineCount++;
                $token = rtrim($line, "\r\n");
                if ($token !== '') {
                    $tokens[] = $token;
                    if ($onToken !== null) {
                        $onToken($token);
                    }
                }
            }

            // Read stderr (non-blocking)
            while (($line = fgets($pipes[2])) !== false) {
                $stderr .= $line;
            }

            // Check if process is still running
            if ($running) {
                $status = proc_get_status($proc);
                if (!$status['running']) {
                    $running = false;
                }
            }

            // Check if we've exhausted both pipes
            if (!$running && feof($pipes[1]) && feof($pipes[2])) {
                break;
            }

            // Check for timeout
            if (time() > $deadline) {
                proc_terminate($proc, SIGTERM);
                return Message::assistant("_[error: streaming backend timed out after {$iterations} iterations]_");
            }

            // If we read lines this iteration, keep looping to drain
            // If not and process is still running, wait for more data
            if ($lineCount === 0 && $running) {
                usleep(5000); // 5ms
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        if ($exit !== 0) {
            $tail = trim($stderr);
            $hint = $tail === '' ? '' : "\n\n```\n{$tail}\n```";
            return Message::assistant("_[error: streaming backend exited {$exit}]_{$hint}");
        }

        $body = implode('', $tokens);
        return Message::assistant(trim($body));
    }

    public function completeAsync(array $history, callable $onToken = null): PromiseInterface
    {
        return new \React\Promise\Promise(function (callable $resolve, callable $reject) use ($history, $onToken): void {
            Loop::futureTick(function () use ($history, $onToken, $resolve, $reject): void {
                try {
                    $message = $this->complete($history, $onToken);
                    $resolve($message);
                } catch (\Throwable $e) {
                    $reject($e);
                }
            });
        });
    }
}
