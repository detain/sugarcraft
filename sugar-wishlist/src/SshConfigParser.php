<?php

declare(strict_types=1);

namespace SugarCraft\Wishlist;

use SugarCraft\Wishlist\Endpoint;

/**
 * Parses an OpenSSH config file (~/.ssh/config) into a list of Endpoints.
 *
 * Handles:
 * - `Host <pattern>` blocks (including `Host *` for global defaults)
 * - Per-host options: HostName, User, Port, IdentityFile, ProxyJump
 * - Global options from `Host *` that apply to all subsequent hosts
 * - Pattern matching (first match wins per SSH config rules)
 *
 * Mirrors openssh-client/ssh_config.5 behavior: options set in a Host block
 * apply to the first Host pattern that matches; `Host *` provides defaults
 * that are overridden by more specific host blocks.
 */
final class SshConfigParser
{
    /** @var array<string,array<string,string|list<string>>> */
    private array $globalOptions = [];

    /** @var list<array{host:string,line:int}> */
    private array $hostBlocks = [];

    /**
     * @return list<Endpoint>
     */
    public function parse(string $raw): array
    {
        $this->globalOptions = [];
        $this->hostBlocks = [];

        $currentHost = null;
        /** @var array<string,string|list<string>> $currentOptions */
        $currentOptions = [];
        $inGlobalBlock = false;

        foreach (explode("\n", $raw) as $lineNum => $rawLine) {
            $line = preg_replace('/\s+#.*$/', '', $rawLine) ?? $rawLine;
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Host keyword opens a new block
            if (preg_match('/^host\s+(.+)$/i', $line, $m)) {
                if ($currentHost !== null) {
                    $this->storeHostBlock($currentHost, $currentOptions);
                }
                $currentHost = $m[1];
                $currentOptions = [];
                $inGlobalBlock = strtolower($currentHost) === '*';
                if ($inGlobalBlock) {
                    $currentOptions = $this->globalOptions;
                }
                continue;
            }

            // Keyword VALUE lines (inside a Host block)
            if ($currentHost !== null && preg_match('/^(\w+)\s+(.+)$/i', $line, $m)) {
                $key = strtolower($m[1]);
                $value = trim($m[2]);
                $this->applyKeyword($currentOptions, $key, $value, $inGlobalBlock);
                continue;
            }
        }

        // Flush final block
        if ($currentHost !== null) {
            $this->storeHostBlock($currentHost, $currentOptions);
        }

        return $this->buildEndpoints();
    }

    /**
     * @param array<string,string|list<string>> $options
     */
    private function storeHostBlock(string $hostPattern, array $options): void
    {
        if (strtolower($hostPattern) === '*') {
            $this->globalOptions = $options;
            return;
        }
        $this->hostBlocks[] = ['host' => $hostPattern, 'options' => $options, 'line' => 0];
    }

    /**
     * @param array<string,string|list<string>> $options
     */
    private function applyKeyword(array &$options, string $key, string $value, bool $inGlobalBlock): void
    {
        match ($key) {
            'hostname' => $options['hostname'] = $value,
            'user' => $options['user'] = $value,
            'port' => $options['port'] = $value,
            'identityfile' => $this->appendList($options, 'identityfile', $value),
            'proxyjump' => $options['proxyjump'] = $value,
            default => null,
        };
    }

    /**
     * @return list<Endpoint>
     */
    private function buildEndpoints(): array
    {
        $endpoints = [];
        foreach ($this->hostBlocks as $block) {
            $merged = $this->globalOptions;
            foreach ($block['options'] as $k => $v) {
                $merged[$k] = $v;
            }
            $endpoints[] = $this->makeEndpoint($block['host'], $merged);
        }
        return array_values(array_filter($endpoints, fn(Endpoint $e) => $e->host !== ''));
    }

    /**
     * @param array<string,string|list<string>> $options
     */
    private function makeEndpoint(string $hostPattern, array $options): Endpoint
    {
        $host = $options['hostname'] ?? $hostPattern;
        $port = isset($options['port']) ? (int) $options['port'] : 22;
        $user = $options['user'] ?? null;

        $identityFiles = [];
        if (isset($options['identityfile']) && is_array($options['identityfile'])) {
            foreach ($options['identityfile'] as $f) {
                $identityFiles[] = $this->expandPath((string) $f);
            }
        }

        return new Endpoint(
            name: $hostPattern,
            host: $host,
            port: $port,
            user: $user !== null ? (string) $user : null,
            identityFiles: $identityFiles,
            proxyJump: isset($options['proxyjump']) ? (string) $options['proxyjump'] : null,
        );
    }

    private function expandPath(string $path): string
    {
        if (str_starts_with($path, '~')) {
            $home = getenv('HOME') ?: (function_exists('posix_getpwuid') && posix_getpwuid(posix_geteuid()) !== false ? posix_getpwuid(posix_geteuid())['dir'] : '/root');
            return $home . substr($path, 1);
        }
        return $path;
    }

    /**
     * @param array<string,string|list<string>> $options
     * @param list<string> $value
     */
    private function appendList(array &$options, string $key, string $value): void
    {
        if (!isset($options[$key])) {
            $options[$key] = [];
        }
        /** @var list<string> $bucket */
        $bucket = $options[$key];
        $bucket[] = $value;
        $options[$key] = $bucket;
    }
}
