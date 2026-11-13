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

    /**
     * Test seam: Windows folds path case at the filesystem, so there redaction
     * matching must fold too. Defaults from the running OS family; a Linux CI
     * pins both arms through the private constructor (see {@see redact()}).
     */
    private readonly bool $caseFoldPaths;

    private function __construct(
        private readonly Style $titleStyle,
        private readonly Style $exceptionStyle,
        private readonly Style $backtraceStyle,
        private readonly Style $mutedStyle,
        bool $showLocals = false,
        array $redactPaths = [],
        bool $caseFoldPaths = \PHP_OS_FAMILY === 'Windows',
    ) {
        $this->showLocals = $showLocals;
        $this->caseFoldPaths = $caseFoldPaths;
        $this->redactPaths = self::normalizeRedactPaths($redactPaths);
    }

    /**
     * Expand the configured redaction substrings once, at construction.
     *
     * Empty entries are dropped — a '' needle would splice '[redacted]'
     * between every character of the subject (PHP 8 str_replace semantics),
     * turning a config typo into a shredded report. Beside each configured
     * spelling we keep its realpath when that path resolves: traces report
     * canonical paths while configs quote authored ones ('/../' and '/./'
     * spellings), and both must match. Unresolvable entries are NOT dropped —
     * a secret path on a machine that never mounts it still has to redact the
     * literal bytes.
     *
     * @param array<array-key, string> $paths
     *
     * @return list<string>
     */
    private static function normalizeRedactPaths(array $paths): array
    {
        $out = [];
        foreach ($paths as $path) {
            if ($path === '') {
                continue;
            }

            $candidates = [$path];
            $resolved = \realpath($path);
            if ($resolved !== false) {
                $candidates[] = $resolved;
            }

            foreach ($candidates as $candidate) {
                if (!\in_array($candidate, $out, true)) {
                    $out[] = $candidate;
                }
            }
        }

        return $out;
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
     * @param list<string> $redactPaths Substrings to redact from paths, locals, and
     *                                  message: exact-substring matching (case-folded
     *                                  on Windows), compared against both the configured
     *                                  spelling and its realpath when that path resolves;
     *                                  empty entries are ignored.
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
     * @param list<string> $redactPaths Substrings to redact from paths, locals, and
     *                                  message: exact-substring matching (case-folded
     *                                  on Windows), compared against both the configured
     *                                  spelling and its realpath when that path resolves;
     *                                  empty entries are ignored.
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
     * Style surface: each banner role is replaceable without subclassing.
     *
     * The class holds four readonly {@see Style} slots: title (the PANIC
     * label), exception (class name and message rows), backtrace (the
     * primary header and frame rows) and muted (footer, collapsed-frame
     * counters and dumped locals). {@see pretty()} picks the colored
     * defaults, {@see plain()} bare styles, and the withers below re-set a
     * single slot while carrying everything else (showLocals, the
     * normalized redaction list, the platform path-fold setting) forward.
     * The readonly properties rule out clone-and-assign, so each wither
     * routes through {@see restyle()}, the one definition that touches the
     * private constructor.
     */
    private function restyle(Style $title, Style $exception, Style $backtrace, Style $muted): self
    {
        return new self($title, $exception, $backtrace, $muted, $this->showLocals, $this->redactPaths, $this->caseFoldPaths);
    }

    /**
     * Replace the style of the PANIC banner label.
     */
    public function withTitleStyle(Style $style): self
    {
        return $this->restyle($style, $this->exceptionStyle, $this->backtraceStyle, $this->mutedStyle);
    }

    /**
     * Replace the style of the exception class name and message rows.
     */
    public function withExceptionStyle(Style $style): self
    {
        return $this->restyle($this->titleStyle, $style, $this->backtraceStyle, $this->mutedStyle);
    }

    /**
     * Replace the style of the primary file header and the frame rows.
     */
    public function withBacktraceStyle(Style $style): self
    {
        return $this->restyle($this->titleStyle, $this->exceptionStyle, $style, $this->mutedStyle);
    }

    /**
     * Replace the style of the footer, collapsed-frame counters and locals.
     */
    public function withMutedStyle(Style $style): self
    {
        return $this->restyle($this->titleStyle, $this->exceptionStyle, $this->backtraceStyle, $style);
    }

    /**
     * Format an exception into a panic report string.
     */
    public function format(\Throwable $e): string
    {
        $lines = [];

        // Banner
        $lines[] = '';
        $lines[] = $this->titleStyle->render('  PANIC  ') . ' ' . $this->exceptionStyle->render($this->safeClassName(\get_class($e)));
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

        // The primary exception file is attacker-reachable (crafted upload /
        // phar names reach getFile()) just like every frame path: redact
        // configured secrets, then sweep control bytes so the header row can
        // neither leak a path nor forge extra report rows.
        $file = self::ctrl($this->redact($file));

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

            $func = self::ctrl($this->safeClassName((string) ($frame['class'] ?? '')) . ($frame['type'] ?? '') . ($frame['function'] ?? '?'));
            $frameFile = self::ctrl($this->redact($frame['file'] ?? '?'));
            $frameLine = $frame['line'] ?? '?';

            // %s, not %d: internal frames (call_user_func et al.) carry no
            // file/line, and '%d' would render the '?' placeholder as '0'.
            $line = sprintf('    %s:%s %s()', $frameFile, $frameLine, $func);
            $lines[] = $this->backtraceStyle->render($line);

            if ($this->showLocals && isset($frame['args'])) {
                foreach ($frame['args'] as $arg) {
                    $argStr = is_scalar($arg) ? (string) $arg : gettype($arg);
                    // Redact configured secret substrings from dumped locals,
                    // not just from file paths — a token/password passed as a
                    // call argument would otherwise leak verbatim. Redact on
                    // the RAW bytes before truncation (secrets longer than the
                    // cap are still caught) and before the ctrl() sweep below
                    // (order policy: see redact()).
                    $argStr = $this->redact($argStr);
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
     * Exception messages routinely embed user input: configured secret
     * substrings are redacted on the raw bytes first, then terminal-control
     * bytes are swept and newlines/tabs flattened so one message renders as
     * exactly one report line.
     */
    private function safeMessage(string $message): string
    {
        return self::ctrl($this->redact($message));
    }

    /**
     * The single redaction policy, shared by the primary file, frame files,
     * dumped locals, and the message.
     *
     * ORDER (redact then ctrl, never the reverse): redaction is path
     * semantics and must match on untouched bytes — a configured secret that
     * itself contains a sweep-target byte (a tab is legal in a POSIX file
     * name) would be folded out of reach of matching if ctrl() ran first.
     * ctrl() then runs LAST as the render invariant: no byte reaches a report
     * row without having passed through it, and the '[redacted]' placeholder
     * is a constant that survives the sweep unchanged.
     *
     * Matching is exact substring on POSIX; on Windows ($caseFoldPaths, the
     * OS default) the filesystem is case-insensitive, so 'C:\Secrets\key' and
     * 'c:\secrets\KEY' are the same secret and must both hit.
     */
    private function redact(string $s): string
    {
        foreach ($this->redactPaths as $path) {
            $s = $this->caseFoldPaths
                ? \str_ireplace($path, '[redacted]', $s)
                : \str_replace($path, '[redacted]', $s);
        }

        return $s;
    }

    /**
     * A class name is not a safe identifier: PHP renders anonymous classes as
     * 'Prefix@anonymous\0/creating/file.php:line$counter' (NUL-separated on
     * 8.x), and the embedded creating path is neither stripped by {@see ctrl()}
     * (the bytes around it are printable) nor covered by {@see redact()}
     * unless the developer happens to have configured that exact file — and a
     * NUL spliced through a configured path would split it out of reach of
     * redaction, which matches on raw bytes. Collapse the
     * creation tail — keeping the declared prefix so an anonymous subclass of
     * RuntimeException still reads as 'RuntimeException@anonymous' — then run
     * the remainder through the message ladder as defense in depth against
     * any other hostile name shape.
     */
    private function safeClassName(string $class): string
    {
        $nul = strpos($class, "\0");
        if ($nul !== false) {
            $class = substr($class, 0, $nul);
        }

        $at = strpos($class, '@anonymous');
        if ($at !== false) {
            $class = substr($class, 0, $at) . '@anonymous';
        }

        return $this->safeMessage($class);
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