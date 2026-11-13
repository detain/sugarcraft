<?php

declare(strict_types=1);

namespace SugarCraft\Log;

use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Log\Hook\HookRegistry;
use SugarCraft\Log\Support\Stdio;

/**
 * PSR-3 bridge wrapping a Logger instance.
 *
 * Implements {@see LoggerInterface} — all PSR-3 methods
 * (emergency/alert/critical/error/warning/notice/info/debug/log) — so this
 * object can be passed anywhere a `Psr\Log\LoggerInterface` is type-hinted.
 *
 * PSR-3's contract is that logging never fails loudly: the eight severity
 * methods must never propagate a Throwable, even when the wrapped Logger
 * throws (a Fatal record), the message's __toString explodes, or a custom
 * formatter rejects the payload. Such failures are routed to a sanitized
 * one-line notice on STDERR — the only channel that cannot re-enter this
 * class — and swallowed. The sole documented exception is {@see log()},
 * which PSR-3 itself declares may throw {@see InvalidArgumentException} for
 * an unrecognized level string; the eight severity methods pass engine-owned
 * constants and so cannot hit that path.
 *
 * Note: {@see Logger} itself is deliberately NOT a PSR-3 logger — its typed
 * Level-enum API is the house style; this bridge is the interop surface.
 * Hooks fire only through this bridge, after a write attempt completes —
 * including one that failed and was announced as an internal notice — and
 * never for records the wrapped Logger's level filter drops or for a message
 * cast that explodes before the write is attempted.
 *
 * PHP-side addition rather than a port: there is no charmbracelet/log
 * artifact to mirror (PSR-3 is a PHP FIG standard); the closest upstream
 * analog is a log/slog handler adapting structured levels to a backend.
 */
final class PsrBridge implements LoggerInterface
{
    private Logger $logger;
    private HookRegistry $hooks;

    public function __construct(Logger $logger, ?HookRegistry $hooks = null)
    {
        $this->logger = $logger;
        $this->hooks = $hooks ?? new HookRegistry();
    }

    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->write(Level::Fatal, LogLevel::EMERGENCY, $message, $context);
    }

    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->write(Level::Fatal, LogLevel::ALERT, $message, $context);
    }

    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->write(Level::Fatal, LogLevel::CRITICAL, $message, $context);
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->write(Level::Error, LogLevel::ERROR, $message, $context);
    }

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->write(Level::Warn, LogLevel::WARNING, $message, $context);
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->write(Level::Info, LogLevel::NOTICE, $message, $context);
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->write(Level::Info, LogLevel::INFO, $message, $context);
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->write(Level::Debug, LogLevel::DEBUG, $message, $context);
    }

    /**
     * PSR-3 generic entry. The interface leaves $level untyped; only the eight
     * PSR-3 level strings (as string or Stringable) resolve. Anything else —
     * an unknown string, a number, null, an array — is a caller error and
     * raises {@see InvalidArgumentException} as PSR-3 sanctions, instead of
     * silently mislabeling the record as Info.
     *
     * @throws InvalidArgumentException When $level is not a recognized PSR-3 log level.
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        [$sugarCraftLevel, $psrLevel] = self::resolveLevel($level);
        $this->write($sugarCraftLevel, $psrLevel, $message, $context);
    }

    /**
     * @return array{0: Level, 1: string} The mapped level and its PSR-3 string.
     * @throws InvalidArgumentException On an unrecognized or non-string level.
     */
    private static function resolveLevel(mixed $level): array
    {
        $psrLevel = match (true) {
            \is_string($level) => $level,
            $level instanceof \Stringable => (string) $level,
            default => throw new InvalidArgumentException(
                'Log level must be one of the PSR-3 LogLevel constants; got ' . self::describe($level),
            ),
        };

        $sugarCraftLevel = self::toSugarCraftLevel($psrLevel);
        if ($sugarCraftLevel === null) {
            throw new InvalidArgumentException(
                "Unknown log level '" . self::quoteLevelForMessage($psrLevel) . "'; expected one of the PSR-3 LogLevel constants",
            );
        }

        return [$sugarCraftLevel, $psrLevel];
    }

    /**
     * A bounded, single-line echo of the hostile value for the refusal message.
     * Byte-wise on purpose: the input may be invalid UTF-8, and /u patterns
     * match nothing (and return false) on such subjects.
     */
    private static function quoteLevelForMessage(string $psrLevel): string
    {
        $clip = \mb_substr($psrLevel, 0, 64);

        return \preg_replace('/[\x00-\x20\x7f]+/', ' ', $clip) ?? '';
    }

    /**
     * A one-word type name for the failure message, never the hostile value.
     */
    private static function describe(mixed $level): string
    {
        return \get_debug_type($level);
    }

    /**
     * Shared severity-method path: maps an engine-chosen Level onto the
     * Logger, then dispatches hooks — never throwing, per the class contract.
     */
    private function write(Level $sugarCraftLevel, string $psrLevel, string|\Stringable $message, array $context): void
    {
        try {
            $text = (string) $message;
        } catch (\Throwable $e) {
            self::reportInternalFailure('message cast failed', $e);
            return;
        }

        // A record the level filter would drop must not dispatch hooks either
        // (nor pay the write's formatting cost).
        if (!$this->logger->enabled($sugarCraftLevel)) {
            return;
        }

        try {
            $this->logger->log($sugarCraftLevel, $text, $context);
        } catch (\Throwable $e) {
            // Logger throws deliberately once a Fatal record IS written, to
            // signal the direct-API caller. The record has landed; PSR-3
            // forbids propagating. Anything else — a throwing formatter, cast
            // inside the emit path — is a real failure worth reporting.
            // [PHP-01] Typed detection: the old message-string equality was
            // invisible coupling — a wording change, a locale flip between the
            // two Lang::t call sites, or an unrelated RuntimeException
            // carrying identical text silently flipped swallow vs report.
            if ($sugarCraftLevel === Level::Fatal && $e instanceof FatalLoggedException) {
                // fall through to hooks: the record is on the wire
            } else {
                self::reportInternalFailure('log write failed', $e);
            }
        }

        $this->hooks->fire($sugarCraftLevel, $psrLevel, $text, $context);
    }

    /**
     * Emit a sanitized, single-line internal notice on STDERR.
     *
     * This is the failure channel for failures inside the logging path itself.
     * It must never throw and never recurse back into the bridge: STDERR is a
     * constant stream, the payload is control-byte-swept and newline-flattened,
     * and every write is guarded so a closed or unwritable STDERR degrades to
     * silence rather than an error.
     */
    private static function reportInternalFailure(string $what, \Throwable $e): void
    {
        // [PORT-01] SAPI-safe: see SugarCraft\Log\Support\Stdio.
        $stderr = Stdio::err();
        if (null === $stderr) {
            return;
        }
        $detail = \str_replace(["\n", "\r", "\t"], ' ', Sanitize::untrusted($e::class . ': ' . $e->getMessage()));
        \fwrite($stderr, '[sugar-log] ' . $what . ': ' . $detail . "\n");
    }

    /**
     * Map a PSR-3 log level string to our Level enum, or null when the string
     * is not one of the eight PSR-3 constants.
     */
    private static function toSugarCraftLevel(string $psrLevel): ?Level
    {
        // [API-01] The 8-way mapping now lives on the enum so non-bridge
        // callers (env/config strings) can use it too; delegation preserves
        // the existing null-then-throw contract byte-for-byte.
        return Level::fromPsrString($psrLevel);
    }
}
