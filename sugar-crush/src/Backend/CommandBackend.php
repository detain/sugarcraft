<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Backend;

use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Message;

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
 *
 * STDOUT IS RETURNED WITH EXACTLY ONE TRANSFORMATION APPLIED: `trim()`, at the
 * two ends. Everything INSIDE survives — every newline, every blank line,
 * every indent — which is what makes the wrapper above usable. What does not
 * survive is whitespace at the ends, and the claim is stated with its
 * exception because the exception is reachable: an indented FIRST line loses
 * its indentation (so a reply that opens with a four-space code block opens
 * with prose instead), a trailing newline is dropped, and a reply consisting
 * only of whitespace comes back empty. The trim stays deliberately — a
 * wrapper's `echo` adds a trailing newline nobody wants rendered, and every
 * caller of this class renders the content as markdown.
 *
 * {@see StreamingCommandBackend} deliberately does NOT do this — it treats one
 * terminated line as one token, an empty line as a literal newline, and joins
 * with the empty string — so the wrapper above is not interchangeable between
 * the two, and each has its own env var (`$SUGARCRUSH_BACKEND_CMD` here,
 * `$SUGARCRUSH_BACKEND_CMD_STREAM` there). There is also no completion
 * deadline of any kind on this path, deliberately; a completion can
 * legitimately run tens of minutes.
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

    /**
     * $onEvent is accepted and ignored: the external command owns whatever tool
     * use happens on its side and reports nothing back but final text, so this
     * backend has no tool lifecycle it can honestly emit.
     */
    public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
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
        // `proc_open()` already differentiates the two shapes natively — a
        // string goes through `/bin/sh -c`, a list is exec'd directly — so
        // there is nothing for the caller to branch on. This line used to be
        // `$cmd = is_array($this->command) ? $this->command : $this->command;`,
        // both arms identical; the same dead ternary sat in
        // {@see StreamingCommandBackend}, where it was next to the option that
        // was supposed to make it mean something.
        //
        // NO OPTIONS, for either shape — identical to
        // {@see StreamingCommandBackend}, which makes the same escaping promise
        // in the same words and must not disagree with this one. The option in
        // question is `bypass_shell`, which is WINDOWS-ONLY. MEASURED on PHP
        // 8.3 / Linux it is inert for both shapes (the string `"printf a;
        // printf b"` still reaches `/bin/sh -c` with it set; the list
        // `["printf", "a;b"]` still exec's directly), and a LIST does not need
        // it anywhere: passing an array means PHP opens the process directly,
        // WITHOUT going through a shell, and escapes the arguments itself (PHP
        // 7.4 UPGRADING — "the process will be opened directly … PHP will take
        // care of any necessary argument escaping"). Nothing is claimed here
        // about what Windows does with a STRING command; that is unmeasured on
        // this platform.
        $proc = @proc_open($this->command, $descriptor, $pipes);
        if (!is_resource($proc)) {
            return Message::assistant('_[error: failed to spawn backend command]_');
        }
        fwrite($pipes[0], $payload);
        fclose($pipes[0]);
        // `=== false` and not `?:`. `stream_get_contents()` returns
        // `string|false`, and `"0" ?: ''` is `''` in PHP — so a wrapper whose
        // ENTIRE reply is the single character `0` with no trailing newline
        // used to come back as an empty assistant message. Same for a stderr
        // tail of `"0"` on the failure path below.
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        if ($stdout === false) {
            $stdout = '';
        }
        if ($stderr === false) {
            $stderr = '';
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        if ($exit !== 0) {
            $tail = trim($stderr);
            $hint = $tail === '' ? '' : "\n\n```\n{$tail}\n```";
            return Message::assistant("_[error: backend exited {$exit}]_{$hint}");
        }
        return Message::assistant(trim($stdout));
    }

    public function completeAsync(array $history, callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
    {
        return new \React\Promise\Promise(function (callable $resolve, callable $reject) use ($history, $onToken, $cancellation): void {
            if ($cancellation?->isCancelled() === true) {
                $reject(new \RuntimeException('Request cancelled'));

                return;
            }
            try {
                $message = $this->complete($history, $onToken);
                $resolve($message);
            } catch (\Throwable $e) {
                $reject($e);
            }
        });
    }
}
