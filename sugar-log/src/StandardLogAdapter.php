<?php

declare(strict_types=1);

namespace SugarCraft\Log;

use SugarCraft\Log\Formatter\ValueCoercion;

/**
 * Go-style stdlog call pattern (Print/Println/Fatal/Panic) over a sugar-log
 * {@see Logger}.
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

    public function fatal(mixed ...$args): void
    {
        $this->logger->fatal($this->message($args));
    }

    public function panic(mixed ...$args): void
    {
        $this->logger->fatal($this->message($args));
    }

    /** Space-joined line body with formatter-grade value coercion. */
    private function message(array $args): string
    {
        return \implode(' ', \array_map(static fn($a) => ValueCoercion::stringify($a), $args));
    }
}
