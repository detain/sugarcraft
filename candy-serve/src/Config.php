<?php

declare(strict_types=1);

namespace CandyCore\Serve;

/**
 * Server configuration loaded from config.yaml.
 *
 * Port of charmbracelet/soft-serve Config.
 *
 * @see https://github.com/charmbracelet/soft-serve
 */
final class Config
{
    public readonly string $name;
    public readonly string $logFormat;

    /** SSH server config. */
    public readonly string $sshListenAddr;
    public readonly string $sshPublicUrl;
    public readonly string $sshKeyPath;
    public readonly string $sshClientKeyPath;
    public readonly int $sshMaxTimeout;
    public readonly int $sshIdleTimeout;

    /** Git daemon config. */
    public readonly string $gitListenAddr;
    public readonly int $gitMaxTimeout;
    public readonly int $gitIdleTimeout;
    public readonly int $gitMaxConnections;

    /** HTTP server config. */
    public readonly string $httpListenAddr;
    public readonly string $httpPublicUrl;
    public readonly string $tlsKeyPath;
    public readonly string $tlsCertPath;

    /** Database. */
    public readonly string $dbDriver;
    public readonly string $dbDataSource;

    /** Git LFS. */
    public readonly bool $lfsEnabled;
    public readonly bool $lfsSshEnabled;

    /** Mirror job schedule (cron format). */
    public readonly string $mirrorPullSchedule;

    /** Stats server. */
    public readonly string $statsListenAddr;

    /** Path to data directory. */
    public readonly string $dataPath;

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    /**
     * Load config from a YAML file.
     *
     * @throws \RuntimeException If file not found or invalid YAML
     */
    public static function load(string $path): self
    {
        if (!\file_exists($path)) {
            throw new \RuntimeException("Config file not found: {$path}");
        }

        $yaml = \file_get_contents($path);
        if ($yaml === false) {
            throw new \RuntimeException("Failed to read config: {$path}");
        }

        $data = self::parseYaml($yaml);
        $dataPath = \dirname($path);

        return new self($data, $dataPath);
    }

    /**
     * Load config from env + defaults.
     */
    public static function fromDefaults(): self
    {
        $dataPath = \getenv('CANDY_SERVE_DATA_PATH') ?: \sys_get_temp_dir() . '/candy-serve';

        $data = [
            'name'     => 'CandyServe',
            'log_format' => 'text',
            'ssh'      => [
                'listen_addr'      => ':23231',
                'public_url'       => 'ssh://localhost:23231',
                'key_path'         => 'ssh/soft_serve_host',
                'client_key_path'  => 'ssh/soft_serve_client',
                'max_timeout'      => 0,
                'idle_timeout'     => 120,
            ],
            'git'      => [
                'listen_addr'     => ':9418',
                'max_timeout'     => 0,
                'idle_timeout'    => 3,
                'max_connections' => 32,
            ],
            'http'     => [
                'listen_addr'     => ':23232',
                'public_url'      => 'http://localhost:23232',
                'tls_key_path'    => '',
                'tls_cert_path'   => '',
            ],
            'db'       => [
                'driver'      => 'sqlite',
                'data_source' => 'candy-serve.db',
            ],
            'lfs'      => [
                'enabled'     => true,
                'ssh_enabled' => false,
            ],
            'jobs'     => ['mirror_pull' => '@every 10m'],
            'stats'    => ['listen_addr' => ':23233'],
        ];

        return new self($data, $dataPath);
    }

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    private function __construct(array $data, string $dataPath)
    {
        $this->name = $data['name'] ?? 'CandyServe';
        $this->logFormat = $data['log_format'] ?? 'text';

        $ssh = $data['ssh'] ?? [];
        $this->sshListenAddr    = $ssh['listen_addr'] ?? ':23231';
        $this->sshPublicUrl     = $ssh['public_url'] ?? "ssh://localhost:23231";
        $this->sshKeyPath       = $this->resolvePath($ssh['key_path'] ?? 'ssh/soft_serve_host', $dataPath);
        $this->sshClientKeyPath = $this->resolvePath($ssh['client_key_path'] ?? 'ssh/soft_serve_client', $dataPath);
        $this->sshMaxTimeout    = (int) ($ssh['max_timeout'] ?? 0);
        $this->sshIdleTimeout   = (int) ($ssh['idle_timeout'] ?? 120);

        $git = $data['git'] ?? [];
        $this->gitListenAddr     = $git['listen_addr'] ?? ':9418';
        $this->gitMaxTimeout     = (int) ($git['max_timeout'] ?? 0);
        $this->gitIdleTimeout    = (int) ($git['idle_timeout'] ?? 3);
        $this->gitMaxConnections = (int) ($git['max_connections'] ?? 32);

        $http = $data['http'] ?? [];
        $this->httpListenAddr = $http['listen_addr'] ?? ':23232';
        $this->httpPublicUrl  = $http['public_url'] ?? 'http://localhost:23232';
        $this->tlsKeyPath     = $this->resolvePath($http['tls_key_path'] ?? '', $dataPath);
        $this->tlsCertPath    = $this->resolvePath($http['tls_cert_path'] ?? '', $dataPath);

        $db = $data['db'] ?? [];
        $this->dbDriver     = $db['driver'] ?? 'sqlite';
        $this->dbDataSource = $this->resolvePath($db['data_source'] ?? 'candy-serve.db', $dataPath);

        $lfs = $data['lfs'] ?? [];
        $this->lfsEnabled   = (bool) ($lfs['enabled'] ?? true);
        $this->lfsSshEnabled = (bool) ($lfs['ssh_enabled'] ?? false);

        $jobs = $data['jobs'] ?? [];
        $this->mirrorPullSchedule = $jobs['mirror_pull'] ?? '@every 10m';

        $stats = $data['stats'] ?? [];
        $this->statsListenAddr = $stats['listen_addr'] ?? ':23233';

        $this->dataPath = $dataPath;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function reposPath(): string
    {
        $p = $this->dataPath . '/repositories';
        if (!\is_dir($p)) {
            \mkdir($p, 0755, true);
        }
        return $p;
    }

    public function sshPath(): string
    {
        $p = $this->dataPath . '/ssh';
        if (!\is_dir($p)) {
            \mkdir($p, 0700, true);
        }
        return $p;
    }

    public function dbPath(): string
    {
        return $this->dbDataSource;
    }

    private function resolvePath(string $path, string $dataPath): string
    {
        if ($path === '') return '';
        if (\str_starts_with($path, '/')) return $path;
        return $dataPath . '/' . $path;
    }

    /**
     * Minimal YAML parser for config files.
     *
     * Supports: key: value, nested key: { nested: value }, lists.
     *
     * @return array<string, mixed>
     */
    private static function parseYaml(string $yaml): array
    {
        $lines  = \explode("\n", $yaml);
        $result = [];
        $stack  = [&$result];
        $indentStack = [-1];

        foreach ($lines as $line) {
            if (\preg_match('/^(\s*)#/', $line, $m) && \trim(\substr($line, \strlen($m[0]))) === '') {
                continue;
            }

            $trimmed = \rtrim($line);
            if ($trimmed === '' || \preg_match('/^\s+#/', $trimmed)) continue;

            $indent = \strlen($line) - \strlen(\ltrim($line));

            // Pop stack to correct depth
            while ($indent <= \end($indentStack)) {
                \array_pop($stack);
                \array_pop($indentStack);
            }

            if (\preg_match('/^(\S+):\s*(.*)$/', $trimmed, $matches)) {
                $key   = $matches[1];
                $value = $matches[2];

                $parent = &$stack[\count($stack) - 1];

                if ($value === '') {
                    // New block
                    $parent[$key] = [];
                    $stack[]      = &$parent[$key];
                    $indentStack[] = $indent;
                } elseif (\preg_match('/^\{(.*)\}$/', $value, $braceMatch)) {
                    // Inline map: key: { inner: val }
                    $parent[$key] = self::parseInlineMap($braceMatch[1]);
                } elseif (\preg_match('/^\[(.*)\]$/', $value, $bracketMatch)) {
                    // Inline list
                    $parent[$key] = self::parseInlineList($bracketMatch[1]);
                } else {
                    $parent[$key] = self::unquote($value);
                }
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private static function parseInlineMap(string $s): array
    {
        $result = [];
        foreach (\preg_split('/,/', $s) as $pair) {
            $pair = \trim($pair);
            if (\preg_match('/^(\S+):\s*(.+)$/', $pair, $m)) {
                $result[$m[1]] = self::unquote($m[2]);
            }
        }
        return $result;
    }

    /** @return list<mixed> */
    private static function parseInlineList(string $s): array
    {
        if ($s === '') return [];
        return \array_map(self::unquote(...), \array_map('trim', \explode(',', $s)));
    }

    private static function unquote(string $s): mixed
    {
        $s = \trim($s);
        if (\preg_match('/^"(.*)"$/', $s, $m)) return $m[1];
        if (\preg_match("/^'(.*)'$/", $s, $m)) return $m[1];
        if ($s === 'true')  return true;
        if ($s === 'false') return false;
        if (\is_numeric($s)) return \str_contains($s, '.') ? (float) $s : (int) $s;
        return $s;
    }
}
