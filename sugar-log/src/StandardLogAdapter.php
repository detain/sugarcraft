<?php

declare(strict_types=1);

namespace SugarCraft\Log;

use SugarCraft\Log\Formatter\ValueCoercion;

/**
 * Wrap a Logger as a *log.Logger interface for compatibility with
 * code that expects the standard log.Logger type (e.g. net/http Server#ErrorLog).
 * Mirrors charmbracelet/log's StandardLog adapter.
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
     * Print a line at the configured or forced level.
     *
     * Arguments are space-joined; non-string values are coerced the same
     * way the formatters render context (arrays become '[a b]', never the
     * literal 'Array' of an (string) cast).
     *
     * @param mixed ...$args Values to concatenate and log.
     */
    public function print(mixed ...$args): void
    {
        $this->logger->log($this->forceLevel ?? Level::Info, $this->message($args));
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
