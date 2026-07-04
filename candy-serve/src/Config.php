<?php

declare(strict_types=1);

namespace SugarCraft\Serve;

use SugarCraft\Serve\Lang;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

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

    /** Max buffered packfile size in bytes (null = server default). */
    public readonly ?int $maxPackBytes;

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
            throw new \RuntimeException(Lang::t('config.not_found', ['path' => $path]));
        }

        $yaml = \file_get_contents($path);
        if ($yaml === false) {
            throw new \RuntimeException(Lang::t('config.read_failed', ['path' => $path]));
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
        // YAML type-inference can yield ints/bools where a string is
        // expected (e.g. an unquoted `:8080` in flow style parses as int
        // 8080) — cast string fields defensively rather than TypeError.
        $this->name = (string) ($data['name'] ?? 'CandyServe');
        $this->logFormat = (string) ($data['log_format'] ?? 'text');

        $ssh = $data['ssh'] ?? [];
        $this->sshListenAddr    = (string) ($ssh['listen_addr'] ?? ':23231');
        $this->sshPublicUrl     = (string) ($ssh['public_url'] ?? "ssh://localhost:23231");
        $this->sshKeyPath       = $this->resolvePath((string) ($ssh['key_path'] ?? 'ssh/soft_serve_host'), $dataPath);
        $this->sshClientKeyPath = $this->resolvePath((string) ($ssh['client_key_path'] ?? 'ssh/soft_serve_client'), $dataPath);
        $this->sshMaxTimeout    = (int) ($ssh['max_timeout'] ?? 0);
        $this->sshIdleTimeout   = (int) ($ssh['idle_timeout'] ?? 120);

        $git = $data['git'] ?? [];
        $this->gitListenAddr     = (string) ($git['listen_addr'] ?? ':9418');
        $this->gitMaxTimeout     = (int) ($git['max_timeout'] ?? 0);
        $this->gitIdleTimeout    = (int) ($git['idle_timeout'] ?? 3);
        $this->gitMaxConnections = (int) ($git['max_connections'] ?? 32);

        $http = $data['http'] ?? [];
        $this->httpListenAddr = (string) ($http['listen_addr'] ?? ':23232');
        $this->httpPublicUrl  = (string) ($http['public_url'] ?? 'http://localhost:23232');
        $this->tlsKeyPath     = $this->resolvePath((string) ($http['tls_key_path'] ?? ''), $dataPath);
        $this->tlsCertPath    = $this->resolvePath((string) ($http['tls_cert_path'] ?? ''), $dataPath);
        $this->maxPackBytes   = isset($http['max_pack_bytes']) ? (int) $http['max_pack_bytes'] : null;

        $db = $data['db'] ?? [];
        $this->dbDriver     = (string) ($db['driver'] ?? 'sqlite');
        $this->dbDataSource = $this->resolvePath((string) ($db['data_source'] ?? 'candy-serve.db'), $dataPath);

        $lfs = $data['lfs'] ?? [];
        $this->lfsEnabled   = (bool) ($lfs['enabled'] ?? true);
        $this->lfsSshEnabled = (bool) ($lfs['ssh_enabled'] ?? false);

        $jobs = $data['jobs'] ?? [];
        $this->mirrorPullSchedule = (string) ($jobs['mirror_pull'] ?? '@every 10m');

        $stats = $data['stats'] ?? [];
        $this->statsListenAddr = (string) ($stats['listen_addr'] ?? ':23233');

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

    /** Directory holding LFS objects (created on first use). */
    public function lfsPath(): string
    {
        $p = $this->dataPath . '/lfs';
        if (!\is_dir($p)) {
            \mkdir($p, 0755, true);
        }
        return $p;
    }

    private function resolvePath(string $path, string $dataPath): string
    {
        if ($path === '') return '';
        if (\str_starts_with($path, '/')) return $path;
        return $dataPath . '/' . $path;
    }

    /**
     * Parse a YAML config document via symfony/yaml (plan item 7.7).
     *
     * The previous hand-rolled parser silently IGNORED indented nested
     * keys (only inline `key: { a: b }` maps nested); with a real YAML
     * parser, block-style nesting now works, so config files written the
     * way the README documents them finally take effect. YAML quirk to
     * know: values starting with a reserved indicator (`:8080`,
     * `@every 10m`) must be quoted.
     *
     * @return array<string, mixed>
     */
    private static function parseYaml(string $yaml): array
    {
        try {
            $data = Yaml::parse($yaml);
        } catch (ParseException $e) {
            throw new \RuntimeException(
                Lang::t('config.parse_failed', ['error' => $e->getMessage()]),
                0,
                $e,
            );
        }

        if ($data === null) {
            return [];
        }
        if (!\is_array($data)) {
            throw new \RuntimeException(
                Lang::t('config.parse_failed', ['error' => 'top-level YAML value must be a mapping']),
            );
        }

        return $data;
    }
}
