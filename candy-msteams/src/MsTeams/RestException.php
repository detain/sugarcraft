<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\MsTeams;

/**
 * Every REST/token failure the bundle raises carries a status when one is
 * known — the same posture the discord bundle's RestException ships.
 */
final class RestException extends \RuntimeException
{
    public function __construct(string $message, private readonly int $status = 0, private readonly string $path = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** The request path (never a signed query — those carry bearer material). */
    public function path(): string
    {
        return $this->path;
    }
}
