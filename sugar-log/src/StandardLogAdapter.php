<?php

declare(strict_types=1);

namespace SugarCraft\Log;

use SugarCraft\Log\Formatter\ValueCoercion;

/**
 * Go-style stdlog call pattern (Print/Printf/Println/Fatal/Fatalf/Panic/
 * Panicf, plus an Errorf twin of {@see Logger::errorf()} that Go's stdlib
 * never had) over a sugar-log {@see Logger}.
 *
 * Go separates panic (recoverable unwind) from Fatal (exit); in this port
 * the exit IS a throw, so the two verbs collapse — {@see self::panic()} is
 * fatal()'s alias and both are catchable.
 *
 * M16 truth: PHP has no `*log.Logger` interface and no net/http — this class
 * implements NO contract. It exists so Go-shaped call sites port
 * mechanically, mirroring charmbracelet/log's StandardLog adapter.
 */
final class StandardLogAdapter
{
    private Logger $logger;
    private ?Level $forceLevel;

    public function __construct(Logger $logger, ?Level $forceLevel = null)
    {
        $this->logger = $logger;
        $this->forceLevel = $forceLevel;
    }

    public function logger(): Logger
    {
        return $this->logger;
    }

    /**
     * Print a line — unconditionally when no level was forced.
     *
     * Go's stdlib logger has no level filter and always writes; the faithful
     * analogue is {@see Logger::print()} (bypasses minLevel, labels INF), so
     * print()/printLn() use it unless a level above Info was forced, which
     * rides the filtered {@see Logger::log()} path instead (m7).
     *
     * Arguments are space-joined; non-string values are coerced the same
     * way the formatters render context (arrays become '[a b]', never the
     * literal 'Array' of an (string) cast).
     *
     * @param mixed ...$args Values to concatenate and log.
     */
    public function print(mixed ...$args): void
    {
        $message = $this->message($args);

        if ($this->forceLevel === null || $this->forceLevel === Level::Info) {
            $this->logger->print($message);

            return;
        }

        $this->logger->log($this->forceLevel, $message);
    }

    public function printLn(mixed ...$args): void
    {
        $this->print(...$args);
    }

    /**
     * Write an FTL record, then throw.
     *
     * Go's Fatal exits the process; the port's exit is a throw, so catching
     * around this call and recovering is a real option, not a language trick.
     *
     * @param mixed ...$args Values to concatenate and log.
     *
     * @throws FatalLoggedException after the record is written
     */
    public function fatal(mixed ...$args): void
    {
        $this->logger->fatal($this->message($args));
    }

    /**
     * Alias of {@see self::fatal()} — the name kept for Go-shaped call sites.
     *
     * Go distinguishes panic (recoverable) from Fatal (exit(2)); here both
     * write the FTL record and throw the same typed exception — catch-and-
     * recover works exactly as the Go panic idiom advertises.
     *
     * @param mixed ...$args Values to concatenate and log.
     *
     * @throws FatalLoggedException after the record is written
     */
    public function panic(mixed ...$args): void
    {
        $this->logger->fatal($this->message($args));
    }

    /**
     * Formatted {@see self::print()} — Go stdlog Printf parity.
     *
     * Mirrors the {@see Logger} `*f` shape: the arguments are folded into one
     * message with vsprintf() and forwarded through print(), so the forced/
     * un-forced bypass rule applies unchanged.
     */
    public function printf(string $format, mixed ...$args): void
    {
        $this->print(\vsprintf($format, $args));
    }

    /**
     * Formatted Error record — the adapter twin of {@see Logger::errorf()}.
     *
     * Go stdlog has no Errorf; this arm exists so sugar-log's `*f` roster is
     * complete on the adapter too. Rides the ordinary filtered error path.
     */
    public function errorf(string $format, mixed ...$args): void
    {
        $this->logger->error(\vsprintf($format, $args));
    }

    /**
     * Formatted {@see self::fatal()} — Go stdlog Fatalf parity.
     *
     * @throws FatalLoggedException after the record is written
     */
    public function fatalf(string $format, mixed ...$args): void
    {
        $this->fatal(\vsprintf($format, $args));
    }

    /**
     * Formatted {@see self::panic()} — Go stdlog Panicf parity.
     *
     * @throws FatalLoggedException after the record is written
     */
    public function panicf(string $format, mixed ...$args): void
    {
        $this->panic(\vsprintf($format, $args));
    }

    /** Space-joined line body with formatter-grade value coercion. */
    private function message(array $args): string
    {
        return \implode(' ', \array_map(static fn($a) => ValueCoercion::stringify($a), $args));
    }
}
