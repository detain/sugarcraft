<?php

declare(strict_types=1);

namespace SugarCraft\Wishlist;

/**
 * Parses an OpenSSH config file (~/.ssh/config) into a list of Endpoints.
 *
 * Handles:
 * - `Host <pattern>` blocks (including `Host *`, which matches every host)
 * - Per-host options: HostName, User, Port, IdentityFile, ProxyJump
 * - Multi-pattern Host lines ("Host a b c") become one endpoint per pattern
 *
 * Precedence mirrors openssh-client/ssh_config.5 exactly: "For each
 * parameter, the first obtained value will be used." Resolution walks ALL
 * blocks in file order and, for each endpoint, takes the first value a
 * matching block defines. `Host *` is therefore NOT a subordinate defaults
 * section — it is simply conventionally written last, which is what makes
 * it lose. A `Host *` written FIRST in the file wins over every later
 * specific block, exactly as OpenSSH would honour it.
 * IdentityFile is the documented exception: values accumulate across
 * matching blocks in file order (ssh tries them in that order).
 */
final class SshConfigParser
{
    /**
     * Every Host block in file order — wildcard blocks included, because
     * precedence is positional, not categorical.
     *
     * @var list<array{patterns:list<string>, options:array<string,string|list<string>>}>
     */
    private array $blocks = [];

    /**
     * @return list<Endpoint>
     */
    public function parse(string $raw): array
    {
        $this->blocks = [];

        /** @var list<string>|null $currentHostPatterns */
        $currentHostPatterns = null;
        /** @var array<string,string|list<string>> $currentOptions */
        $currentOptions = [];

        foreach (explode("\n", $raw) as $rawLine) {
            $line = preg_replace('/\s+#.*$/', '', $rawLine) ?? $rawLine;
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Host keyword opens a new block
            if (preg_match('/^host\s+(.+)$/i', $line, $m)) {
                if ($currentHostPatterns !== null) {
                    $this->blocks[] = ['patterns' => $currentHostPatterns, 'options' => $currentOptions];
                }
                // Split multi-pattern Host lines (e.g. "Host a b c") into
                // individual patterns, preserving left-to-right order.
                $rawPatterns = preg_split('/\s+/', trim($m[1]));
                $currentHostPatterns = array_values(array_filter(
                    $rawPatterns,
                    static fn(string $p) => $p !== ''
                ));
                $currentOptions = [];
                continue;
            }

            // Keyword VALUE lines (inside a Host block)
            if ($currentHostPatterns !== null && preg_match('/^(\w+)\s+(.+)$/i', $line, $m)) {
                $key = strtolower($m[1]);
                $value = trim($m[2]);
                $this->applyKeyword($currentOptions, $key, $value);
                continue;
            }
        }

        // Flush final block
        if ($currentHostPatterns !== null) {
            $this->blocks[] = ['patterns' => $currentHostPatterns, 'options' => $currentOptions];
        }

        return $this->buildEndpoints();
    }

    /**
     * @param array<string,string|list<string>> $options
     */
    private function applyKeyword(array &$options, string $key, string $value): void
    {
        match ($key) {
            // First-obtained-wins within the block too (ssh_config.5):
            // a repeated scalar keyword keeps its first value.
            'hostname' => $options['hostname'] ??= $value,
            'user' => $options['user'] ??= $value,
            'port' => $options['port'] ??= $value,
            'identityfile' => $this->appendList($options, 'identityfile', $value),
            'proxyjump' => $options['proxyjump'] ??= $value,
            default => null,
        };
    }

    /**
     * @return list<Endpoint>
     */
    private function buildEndpoints(): array
    {
        $endpoints = [];
        foreach ($this->blocks as $block) {
            foreach ($block['patterns'] as $pattern) {
                if ($pattern === '*') {
                    // A wildcard pattern never names an endpoint of its own;
                    // it only contributes options (see resolveOptions).
                    continue;
                }
                $endpoints[] = $this->makeEndpoint($pattern, $this->resolveOptions($pattern));
            }
        }
        return array_values(array_filter($endpoints, fn(Endpoint $e) => $e->host !== ''));
    }

    /**
     * Resolve the effective options for one host pattern by walking every
     * block in file order (ssh_config.5 first-obtained-value semantics).
     *
     * @return array<string,string|list<string>>
     */
    private function resolveOptions(string $pattern): array
    {
        $resolved = [];
        foreach ($this->blocks as $block) {
            $applies = in_array('*', $block['patterns'], true)
                || in_array($pattern, $block['patterns'], true);
            if (!$applies) {
                continue;
            }
            foreach ($block['options'] as $key => $value) {
                if ($key === 'identityfile') {
                    // Accumulates across matching blocks, first-seen first,
                    // without duplicates — exactly what ssh(1) does.
                    /** @var list<string> $existing */
                    $existing = $resolved['identityfile'] ?? [];
                    /** @var list<string> $incoming */
                    $incoming = is_array($value) ? $value : [$value];
                    foreach ($incoming as $file) {
                        if (!in_array($file, $existing, true)) {
                            $existing[] = $file;
                        }
                    }
                    if ($existing !== []) {
                        $resolved['identityfile'] = $existing;
                    }
                    continue;
                }
                $resolved[$key] ??= $value;
            }
        }
        return $resolved;
    }

    /**
     * @param array<string,string|list<string>> $options
     */
    private function makeEndpoint(string $hostPattern, array $options): Endpoint
    {
        $host = $options['hostname'] ?? $hostPattern;
        // parsePort enforces 1-65535 and refuses junk loudly, naming the
        // host — an ssh_config "Port 99999" must not become a silent 0.
        $port = isset($options['port'])
            ? Endpoint::parsePort($hostPattern, $options['port'])
            : 22;
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
        if (strncmp($path, '~', 1) !== 0) {
            return $path;
        }

        // Parse ~user/path or ~/path using a simple string scan instead of regex
        $len = strlen($path);
        if ($len >= 2 && $path[1] === '/') {
            // ~/path — current user's home
            $home = getenv('HOME') ?? '/root';
            return $home . substr($path, 1);
        }

        // ~user/path — find the first slash
        $slashPos = strpos($path, '/');
        if ($slashPos === false) {
            // ~user with no slash — entire path is the user name
            $user = substr($path, 1);
            $rest = '';
        } else {
            $user = substr($path, 1, $slashPos - 1);
            $rest = substr($path, $slashPos);
        }

        if ($user === '') {
            // Edge case: just ~ or ~/... (already handled above)
            $home = getenv('HOME') ?? '/root';
            return $home . $rest;
        }

        // ~user/path — try to resolve via posix_getpwnam
        if (function_exists('posix_getpwnam')) {
            $pw = @posix_getpwnam($user);
            if ($pw !== false && isset($pw['dir'])) {
                return $pw['dir'] . $rest;
            }
        }

        // Cannot resolve ~user — return path unchanged rather than
        // producing garbage like <home>user/...
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
