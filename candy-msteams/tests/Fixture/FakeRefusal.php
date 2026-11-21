<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\Fixture;

use SugarCraft\Plugins\Host\RefusedCall;

/** A refusal the in-process fixture host rejects with, carrying the wire's kind and data. */
final class FakeRefusal extends \RuntimeException implements RefusedCall
{
    public function __construct(private readonly string $kind, string $message)
    {
        parent::__construct($message);
    }

    public function refusalKind(): string
    {
        return $this->kind;
    }

    public function refusalData(): array
    {
        return ['kind' => $this->kind];
    }
}
