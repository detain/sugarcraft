<?php

declare(strict_types=1);

namespace SugarCraft\Log;

use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Style\Style;

/**
 * Formats an uncaught exception into a human-readable panic report.
 *
 * Mirrors ratatui ecosystem's `color_eyre` panic prettifier.
 */
final class PanicFormatter
{
    /** @var list<string> Paths to redact from backtrace (e.g. /etc/secrets). */
    private readonly array $redactPaths;

    /** Whether to include local variable dumps in the backtrace. */
    private readonly bool $showLocals;

    private function __construct(
        private readonly Style $titleStyle,
        private readonly Style $exceptionStyle,
        private readonly Style $backtraceStyle,
        private readonly Style $mutedStyle,
        bool $showLocals = false,
        array $redactPaths = [],
    ) {
        $this->showLocals = $showLocals;
        $this->redactPaths = $redactPaths;
    }

    /**
     * Default pretty formatter with color-coded output.
     *
     * SECURITY: $showLocals dumps the scalar arguments carried by each
     * backtrace frame, which frequently include passwords, API tokens, and
     * connection strings passed into the call that panicked. Leave it OFF for
     * any report that may reach logs, CI output, or an end user. When it must
     * be on, list every sensitive substring (secret values AND file paths) in
     * $redactPaths — each is replaced with "[redacted]" in frame paths, dumped
     * locals, and the exception message.
     *
     * ENGINE REALITY (M6): PHP's own exception traces NEVER carry an "args"
     * key — the engine omits call arguments from exception backtraces
     * entirely — so for a plain uncaught exception this knob renders nothing
     * beyond the frames themselves. It is live for traces that DO carry
     * arguments (manually captured debug_backtrace() arrays injected into a
     * report, or frame providers that add them), and the branch is kept for
     * those callers; it is not dead, it is engine-starved.
     *
     * @param list<string> $redactPaths Substrings to redact from paths, locals, and message.
     */
    public static function pretty(bool $showLocals = false, array $redactPaths = []): self
    {
        return new self(
            titleStyle: Style::new()->bold()->foreground(\SugarCraft\Core\Util\Color::ansi(1)),
            exceptionStyle: Style::new()->foreground(\SugarCraft\Core\Util\Color::ansi(7)),
            backtraceStyle: Style::new()->foreground(\SugarCraft\Core\Util\Color::ansi(8)),
            mutedStyle: Style::new()->foreground(\SugarCraft\Core\Util\Color::ansi(8)),
            showLocals: $showLocals,
            redactPaths: $redactPaths,
        );
    }

    /**
     * Plain formatter for non-TTY environments (e.g. files / CI).
     *
     * Takes the same locals/redaction knobs as {@see pretty()} — plain means
     * "no ANSI", not "no redaction": a destination gate must never silently
     * drop the caller's privacy settings.
     *
     * @param list<string> $redactPaths Substrings to redact from paths and locals.
     */
    public static function plain(bool $showLocals = false, array $redactPaths = []): self
    {
        $s = Style::new();
        return new self(
            titleStyle: $s,
            exceptionStyle: $s,
            backtraceStyle: $s,
            mutedStyle: $s,
            showLocals: $showLocals,
            redactPaths: $redactPaths,
        );
    }

    /**
     * Format an exception into a panic report string.
     */
    public function format(\Throwable $e): string
    {
        $lines = [];

        // Banner
        $lines[] = '';
        $lines[] = $this->titleStyle->render('  PANIC  ') . ' ' . $this->exceptionStyle->render(\get_class($e));
        $lines[] = '';

        // Message — attacker-reachable text (exception messages routinely
        // embed input). Swept for control bytes, configured secret paths are
        // redacted here as well as in the trace, and newlines are flattened:
        // a raw \n in the message would print as an extra box row, forging a
        // line the report never promised (ESC[2J and friends likewise must
        // never reach the terminal of whoever displays this report).
        $lines[] = '  ' . $this->exceptionStyle->render($this->safeMessage($e->getMessage()));
        $lines[] = '';

        // Backtrace
        $lines = array_merge($lines, $this->formatBacktrace($e));
        $lines[] = '';

        // Report footer (neutral attribution, no internal tooling hints)
        $lines[] = $this->mutedStyle->render('  report rendered by sugar-log — include the trace above when filing an issue');
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function formatBacktrace(\Throwable $e): array
    {
        $lines = [];
        $trace = $e->getTrace();
        $file = $e->getFile();
        $line = $e->getLine();

        // Redact paths in primary exception file to match backtrace redaction
        foreach ($this->redactPaths as $path) {
            $file = str_replace($path, '[redacted]', $file);
        }

        // Group consecutive repeated frames.
        $grouped = [];
        $lastFrame = null;
        $count = 0;

        foreach ($trace as $frame) {
            $sig = ($frame['file'] ?? '?') . ':' . ($frame['line'] ?? '?');
            if ($sig === $lastFrame) {
                $count++;
                continue;
            }
            if ($count > 1) {
                $grouped[] = ['#' => '... ' . ($count - 1) . ' more', 'collapsed' => true];
            }
            $grouped[] = $frame + ['#' => $sig];
            $lastFrame = $sig;
            $count = 1;
        }
        if ($count > 1) {
            // $count totals the run INCLUDING the one frame printed above,
            // so the hidden remainder is $count - 1.
            $grouped[] = ['#' => '... ' . ($count - 1) . ' more', 'collapsed' => true];
        }

        $lines[] = $this->backtraceStyle->render("  {$file}:{$line}");
        $lines[] = '';

        foreach ($grouped as $frame) {
            if (($frame['collapsed'] ?? false) === true) {
                $lines[] = '    ' . $this->mutedStyle->render($frame['#']);
                continue;
            }

            $func = self::ctrl(($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '?'));
            $frameFile = self::ctrl($frame['file'] ?? '?');
            $frameLine = $frame['line'] ?? '?';

            foreach ($this->redactPaths as $path) {
                $frameFile = str_replace($path, '[redacted]', $frameFile);
            }

            // %s, not %d: internal frames (call_user_func et al.) carry no
            // file/line, and '%d' would render the '?' placeholder as '0'.
            $line = sprintf('    %s:%s %s()', $frameFile, $frameLine, $func);
            $lines[] = $this->backtraceStyle->render($line);

            if ($this->showLocals && isset($frame['args'])) {
                foreach ($frame['args'] as $arg) {
                    $argStr = is_scalar($arg) ? (string) $arg : gettype($arg);
                    // Redact configured secret substrings from dumped locals,
                    // not just from file paths — a token/password passed as a
                    // call argument would otherwise leak verbatim. Redact BEFORE
                    // truncation so secrets longer than the cap are still caught.
                    foreach ($this->redactPaths as $path) {
                        if ($path !== '') {
                            $argStr = str_replace($path, '[redacted]', $argStr);
                        }
                    }
                    if (mb_strlen($argStr) > 40) {
                        $argStr = mb_substr($argStr, 0, 40) . '...';
                    }
                    $lines[] = '      ' . $this->mutedStyle->render('→ ' . self::ctrl($argStr));
                }
            }
        }

        return $lines;
    }

    /**
     * Exception messages routinely embed user input: sweep terminal-control
     * bytes, redact configured secret substrings (a message quoting a secret
     * path is still a leak), and flatten newlines/tabs so one message renders
     * as exactly one report line.
     */
    private function safeMessage(string $message): string
    {
        $s = self::ctrl($message);

        foreach ($this->redactPaths as $path) {
            if ($path !== '') {
                $s = str_replace($path, '[redacted]', $s);
            }
        }

        return $s;
    }

    /**
     * Strip terminal-control bytes and flatten line breaks to spaces so a
     * hostile string can never open a screen, split a report line, or forge
     * a box row.
     */
    private static function ctrl(string $s): string
    {
        return str_replace(["\n", "\r", "\t"], ' ', Sanitize::untrusted($s));
    }
}