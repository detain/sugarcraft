<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Providers;

use SugarCraft\Query\Admin\AdminProviderInterface;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Db\Flavor;

/**
 * MySQL implementation of AdminProviderInterface wrapping ServerContext.
 *
 * Bridges ServerContext's MySQL-specific methods to the flavor-agnostic
 * AdminProviderInterface. ServerContext already handles SHOW GLOBAL STATUS,
 * SHOW GLOBAL VARIABLES, SHOW PLUGINS, and reset detection — this adapter
 * adds processlist fetching and normalizes naming.
 *
 * @see AdminProviderInterface
 * @see ServerContext
 */
final class MysqlAdminProvider implements AdminProviderInterface
{
    public function __construct(
        private readonly ServerContextInterface $context,
    ) {}

    /**
     * Create a new instance from an existing ServerContext.
     */
    public static function new(ServerContextInterface $context): self
    {
        return new self($context);
    }

    public function flavor(): Flavor
    {
        return $this->context->flavor();
    }

    /** @return array<string, string> */
    public function fetchStatusVariables(): array
    {
        return $this->context->statusVariables();
    }

    /** @return array<string, string> */
    public function fetchServerVariables(): array
    {
        return $this->context->serverVariables();
    }

    /**
     * @return list<array{
     *     processId: int,
     *     user: string,
     *     host: string,
     *     database: string,
     *     command: string,
     *     time: int,
     *     state: ?string,
     *     info: ?string,
     *     connectionAttr: array<string, string>
     * }>
     */
    public function fetchProcesslist(): array
    {
        try {
            $rows = $this->context->connection()->query('SHOW FULL PROCESSLIST');
            $results = [];
            foreach ($rows as $row) {
                $results[] = [
                    'processId' => (int) ($row['Id'] ?? 0),
                    'user' => (string) ($row['User'] ?? ''),
                    'host' => (string) ($row['Host'] ?? ''),
                    'database' => (string) ($row['db'] ?? ''),
                    'command' => (string) ($row['Command'] ?? ''),
                    'time' => (int) ($row['Time'] ?? 0),
                    'state' => ($row['State'] ?? null) !== null ? (string) $row['State'] : null,
                    'info' => ($row['Info'] ?? null) !== null ? (string) $row['Info'] : null,
                    'connectionAttr' => [],
                ];
            }
            return $results;
        } catch (\PDOException) {
            return [];
        }
    }

    public function maxConnections(): int
    {
        $vars = $this->context->serverVariables();
        return (int) ($vars['max_connections'] ?? 151);
    }

    public function statusVariablesTs(): float
    {
        return $this->context->statusVariablesTs();
    }

    public function wasReset(): bool
    {
        return $this->context->wasReset();
    }

    public function refresh(): void
    {
        $this->context->refresh();
    }
}
