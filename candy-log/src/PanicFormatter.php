<?php

declare(strict_types=1);

namespace SugarCraft\Log;

use SugarCraft\Sprinkles\Style;

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
     * SECURITY: $showLocals dumps each backtrace frame's scalar arguments,
     * which frequently include passwords, API tokens, and connection strings
     * passed into the call that panicked. Leave it OFF for any report that may
     * reach logs, CI output, or an end user. When it must be on, list every
     * sensitive substring (secret values AND file paths) in $redactPaths — each
     * is replaced with "[redacted]" in both frame paths and dumped locals.
     *
     * @param list<string> $redactPaths Substrings to redact from paths and locals.
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
     */
    public static function plain(): self
    {
        $s = Style::new();
        return new self(
            titleStyle: $s,
            exceptionStyle: $s,
            backtraceStyle: $s,
            mutedStyle: $s,
            showLocals: false,
            redactPaths: [],
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

        // Message
        $lines[] = '  ' . $this->exceptionStyle->render($e->getMessage());
        $lines[] = '';

        // Backtrace
        $lines = array_merge($lines, $this->formatBacktrace($e));
        $lines[] = '';

        // Caliber hint
        $lines[] = $this->mutedStyle->render('  consider `caliber refresh` if this is config-related');
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
                $grouped[] = ['#' => '... ' . $count . ' more', 'collapsed' => true];
            }
            $grouped[] = $frame + ['#' => $sig];
            $lastFrame = $sig;
            $count = 1;
        }
        if ($count > 1) {
            $grouped[] = ['#' => '... ' . $count . ' more', 'collapsed' => true];
        }

        $lines[] = $this->backtraceStyle->render("  {$file}:{$line}");
        $lines[] = '';

        foreach ($grouped as $frame) {
            if (($frame['collapsed'] ?? false) === true) {
                $lines[] = '    ' . $this->mutedStyle->render($frame['#']);
                continue;
            }

            $func = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '?');
            $frameFile = $frame['file'] ?? '?';
            $frameLine = $frame['line'] ?? '?';

            foreach ($this->redactPaths as $path) {
                $frameFile = str_replace($path, '[redacted]', $frameFile);
            }

            $line = sprintf('    %s:%d %s()', $frameFile, $frameLine, $func);
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
                        $argStr = mb_substr($argStr, 0, 40) . '…';
                    }
                    $lines[] = '      ' . $this->mutedStyle->render('→ ' . $argStr);
                }
            }
        }

        return $lines;
    }
}