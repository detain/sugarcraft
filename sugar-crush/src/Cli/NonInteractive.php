<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Cli;

use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Message;

/**
 * The `-p "<prompt>"` / `run "<prompt>"` one-shot, non-interactive CLI path.
 *
 * Mirrors Claude Code's `-p`/`--print`, opencode's `run` subcommand, Codex's
 * `exec`, and Gemini CLI's `-p`/`--prompt`: take a single prompt (optionally
 * with piped stdin prepended as context), run it through the existing
 * synchronous backend, print the result, and exit with a Unix-style status
 * code — no TUI, no alt-screen, no render loop.
 *
 * `EngineBackend::complete()` (src/Backend/EngineBackend.php:130) already
 * runs the full bounded agentic loop synchronously and returns a finished
 * `Message` with no `Program`/`Chat` involved — this class is purely the
 * argv/stdin/stdout/exit-code plumbing wrapped around that existing
 * capability. See crush_feat.md section 2 ("CLI commands, parameters, help
 * screens & non-interactive mode"), Recommendations 2 ("Add sugarcrush -p
 * ... one-shot mode built directly on EngineBackend::complete()"), 3
 * ("--output-format text|json on the one-shot path"), and 4 ("Exit codes
 * matching the Unix convention already implied by other bins in the repo").
 *
 * Production reachability: `bin/sugarcrush` calls `ArgvParser::parse($argv)`
 * first, then `exit(NonInteractive::run($args, null, $args->outputFormat))`
 * whenever `$args->promptRequested` is true (`-p`/`--prompt`/`--prompt=` or
 * the `run "<prompt>"` form appeared at all — including with no value, so the
 * "no prompt given" branch below is the single owner of that error rather
 * than the binary silently opening the TUI), ahead of ever constructing
 * `Bootstrap::chat()`/`Program` — so a real `sugarcrush -p "..." \
 * --output-format json` invocation reaches this class directly, with
 * `$args->outputFormat` (parsed from `--output-format`/`--output-format=`,
 * defaulting to `ParsedArgs::DEFAULT_OUTPUT_FORMAT`) flowing straight into
 * {@see self::format()}, and no TUI or alt-screen ever entered.
 */
final class NonInteractive
{
    public const FORMAT_TEXT = 'text';
    public const FORMAT_JSON = 'json';

    /**
     * Matches Claude Code's documented 10MB piped-stdin cap
     * (code.claude.com/docs/en/headless) so a runaway pipe can't exhaust
     * memory before the prompt is even sent to a backend.
     */
    private const MAX_STDIN_BYTES = 10 * 1024 * 1024;

    /**
     * Run one prompt to completion and print the result.
     *
     * $backend defaults to `Bootstrap::backend($args->root)` (the same
     * env-var-driven selection the interactive TUI path uses) but can be
     * supplied directly — used by tests to avoid depending on
     * `SUGARCRUSH_*` environment state, and available to a future headless
     * server mode that already holds a constructed `Backend`.
     *
     * @return int Unix exit code: `0` on success, `1` when no prompt was
     *   given or the backend threw — matching the existing `sugar-post`/
     *   `sugar-wishlist` bins' 0/1 convention (crush_feat.md
     *   Recommendation 4).
     */
    public static function run(ParsedArgs $args, ?Backend $backend = null, string $outputFormat = self::FORMAT_TEXT): int
    {
        if ($args->prompt === null || \trim($args->prompt) === '') {
            \fwrite(\STDERR, "sugarcrush: no prompt given - pass -p \"<prompt>\" or `sugarcrush run \"<prompt>\"`\n");

            return 1;
        }

        $backend ??= Bootstrap::backend($args->root);
        $history = self::historyFrom($args->prompt, self::readStdinIfPiped());

        try {
            $message = $backend->complete($history);
        } catch (\Throwable $e) {
            \fwrite(\STDERR, $e->getMessage() . "\n");

            return 1;
        }

        echo self::format($message, $outputFormat) . "\n";

        return 0;
    }

    /**
     * Build the one-shot conversation history: piped stdin context (if
     * any) followed by the CLI prompt, collapsed into a single user turn —
     * mirrors Claude Code's `cat file | claude -p '...'` contract, where
     * stdin is context prepended to the prompt rather than a separate
     * message.
     *
     * @return list<Message>
     */
    public static function historyFrom(string $prompt, ?string $stdinContext): array
    {
        $content = ($stdinContext !== null && $stdinContext !== '')
            ? $stdinContext . "\n\n" . $prompt
            : $prompt;

        return [Message::user($content)];
    }

    /**
     * Read piped stdin, capped at {@see self::MAX_STDIN_BYTES}. Returns
     * null when $stream is a TTY (nothing was piped, mirrors
     * `sugar-post/bin/pop`'s `!stream_isatty(STDIN)` guard) or when the
     * pipe was empty.
     *
     * $stream defaults to the real STDIN; tests pass a `php://memory`
     * stream instead so the suite never blocks on, or depends on, the
     * PHPUnit process's actual stdin.
     *
     * @param resource $stream
     */
    public static function readStdinIfPiped($stream = \STDIN): ?string
    {
        if (\stream_isatty($stream)) {
            return null;
        }

        $data = @\stream_get_contents($stream, self::MAX_STDIN_BYTES + 1);
        if ($data === false || $data === '') {
            return null;
        }

        if (\strlen($data) > self::MAX_STDIN_BYTES) {
            \fwrite(\STDERR, "sugarcrush: piped stdin exceeds 10MB cap; truncating.\n");
            $data = \substr($data, 0, self::MAX_STDIN_BYTES);
        }

        return $data;
    }

    /**
     * Render the finished Message per --output-format.
     *
     * `text` (default): the assistant's raw content, matching the plain
     * stdout contract of every tool surveyed in crush_feat.md section 2.
     * `json`: `{"result": "<content>"}`. This is a deliberately minimal
     * first cut of crush_feat.md Recommendation 3 — the recommendation's own
     * sketch also includes `session_id` and `usage` (token-cost) fields,
     * which this step does not yet surface; a caller piping through `jq
     * '.usage'` per that recommendation's full intent gets nothing today.
     * Any value other than `self::FORMAT_JSON` falls back to plain text.
     */
    public static function format(Message $message, string $outputFormat): string
    {
        if ($outputFormat === self::FORMAT_JSON) {
            return (string) \json_encode(
                ['result' => $message->content],
                \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE
            );
        }

        return $message->content;
    }
}
