<?php

declare(strict_types=1);

namespace CandyCore\Log;

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
     */
    public function print(...$args): void
    {
        $msg = \implode(' ', \array_map(fn($a) => (string) $a, $args));
        $level = $this->forceLevel ?? Level::Info;
        $this->logger->log($level, $msg);
    }

    public function printLn(...$args): void
    {
        $this->print(...$args);
    }

    public function fatal(...$args): void
    {
        $msg = \implode(' ', \array_map(fn($a) => (string) $a, $args));
        $this->logger->fatal($msg);
    }

    public function panic(...$args): void
    {
        $msg = \implode(' ', \array_map(fn($a) => (string) $a, $args));
        $this->logger->fatal($msg);
    }
}
