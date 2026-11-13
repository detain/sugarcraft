<?php

declare(strict_types=1);

namespace SugarCraft\GitServer;

use SugarCraft\GitServer\Lang;
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

    /**
     * Opt-in for mirroring over git:// — the daemon protocol that
     * authenticates NEITHER host nor content (finding 070). Default off:
     * a mirror pull is content admission, and admitting it over an
     * unauthenticated transport hands the decision to whoever can see the
     * wire. Operators who run trusted-LAN mirrors flip this key knowingly.
     */
    public readonly bool $mirrorAllowGitProtocol;

    /**
     * Whether requests with NO authenticated identity may read repositories
     * the operator explicitly marked public. Default OFF (fail-closed): a
     * fresh install exposes nothing to passers-by until this is turned on
     * and repos are published. Push is never affected by this lever.
     */
    public readonly bool $allowAnonymousRead;

    /** HTTP server config. */
    public readonly string $httpListenAddr;
    public readonly string $httpPublicUrl;

    /**
     * Worker-per-request HTTP mode (owner ruling 8b): every accepted
     * connection is owned by a forked worker that serves it to completion
     * and exits, so one wedged request can never delay its neighbours.
     * Hosts without pcntl fall back to the serial loop and the bin NAMES
     * that trade-off at startup — never a silent single-threaded surprise.
     */
    public readonly bool $httpWorkerPerRequest;

    /**
     * RESERVED — NOT yet enforced. No HTTPS/TLS listener is wired (that is a
     * separate feature), so populating these does NOT enable TLS. To avoid a
     * false sense of security, setting BOTH paths is rejected at config load
     * (see the constructor) rather than silently ignored.
     */
    public readonly string $tlsKeyPath;
    public readonly string $tlsCertPath;

    /**
     * How the `X-CandyGitServer-User` request header is trusted:
     *  - `off`   (default) — the header is ignored; requests are anonymous.
     *  - `proxy` — honored only when the peer IP is in {@see $trustedProxies}.
     *  - `token` — honored only with a valid HMAC-SHA256 `X-CandyGitServer-User-Sig`.
     * Fail-closed: anything but a passing check leaves the request anonymous.
     */
    public readonly string $userTrustMode;

    /** @var list<string> Trusted proxy IPs / CIDR ranges (used when userTrustMode is 'proxy'). */
    public readonly array $trustedProxies;

    /** Shared secret keying the HMAC user token (used when userTrustMode is 'token'). */
    public readonly string $authSecret;

    /** Max buffered packfile size in bytes (null = server default). */
    public readonly ?int $maxPackBytes;

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
        $dataPath = \getenv('CANDY_GIT_SERVER_DATA_PATH') ?: \sys_get_temp_dir() . '/candy-git-server';

        $data = [
            'name'     => 'CandyGitServer',
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
                'listen_addr'          => ':9418',
                'max_timeout'          => 0,
                'idle_timeout'         => 3,
                'max_connections'      => 32,
                'allow_anonymous_read' => false,
            ],
            'http'     => [
                'listen_addr'     => ':23232',
                'public_url'      => 'http://localhost:23232',
                'tls_key_path'    => '',
                'tls_cert_path'   => '',
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
        $this->name = (string) ($data['name'] ?? 'CandyGitServer');
        $this->logFormat = (string) ($data['log_format'] ?? 'text');

        $ssh = $data['ssh'] ?? [];
        $this->sshListenAddr    = (string) ($ssh['listen_addr'] ?? ':23231');
        $this->sshPublicUrl     = (string) ($ssh['public_url'] ?? "ssh://localhost:23231");
        $this->sshKeyPath       = $this->resolvePath((string) ($ssh['key_path'] ?? 'ssh/soft_serve_host'), $dataPath);
        $this->sshClientKeyPath = $this->resolvePath((string) ($ssh['client_key_path'] ?? 'ssh/soft_serve_client'), $dataPath);
        $this->sshMaxTimeout    = self::parseInt('ssh.max_timeout', $ssh['max_timeout'] ?? null, 0, 0);
        $this->sshIdleTimeout   = self::parseInt('ssh.idle_timeout', $ssh['idle_timeout'] ?? null, 120, 0);

        $git = $data['git'] ?? [];
        $this->gitListenAddr     = (string) ($git['listen_addr'] ?? ':9418');
        $this->gitMaxTimeout     = self::parseInt('git.max_timeout', $git['max_timeout'] ?? null, 0, 0);
        $this->gitIdleTimeout    = self::parseInt('git.idle_timeout', $git['idle_timeout'] ?? null, 3, 0);
        $this->gitMaxConnections = self::parseInt('git.max_connections', $git['max_connections'] ?? null, 32, 1);
        $this->allowAnonymousRead = self::parseBool('git.allow_anonymous_read', $git['allow_anonymous_read'] ?? null, false);
        $this->mirrorAllowGitProtocol = self::parseBool('git.mirror_allow_git_protocol', $git['mirror_allow_git_protocol'] ?? null, false);

        $http = $data['http'] ?? [];
        $this->httpListenAddr = (string) ($http['listen_addr'] ?? ':23232');
        $this->httpPublicUrl  = (string) ($http['public_url'] ?? 'http://localhost:23232');
        $this->tlsKeyPath     = $this->resolvePath((string) ($http['tls_key_path'] ?? ''), $dataPath);
        $this->tlsCertPath    = $this->resolvePath((string) ($http['tls_cert_path'] ?? ''), $dataPath);

        // No silent failures (CONTRIBUTING.md): there is no HTTPS listener yet,
        // so a configured key+cert would be a silent no-op — an operator would
        // believe TLS is on when it is not. Half a pair is the same lie with
        // fewer words: a lone key_path or lone cert_path can never serve
        // anything, and a config that loads with one set has quietly decided
        // the operator meant plain HTTP. Refuse on either side.
        if ($this->tlsKeyPath !== '' || $this->tlsCertPath !== '') {
            throw new \InvalidArgumentException(Lang::t('config.tls_not_supported'));
        }

        // max_pack_bytes is a CAP, and a cap that decodes from garbage is
        // worse than no cap: '30s' must not become 30 bytes, and 0/-1 must
        // not silently mean "the 256 MiB default" or an unbounded floor.
        // Absent ⇒ null (the default lives in maxRequestBodyBytes()).
        $this->maxPackBytes   = isset($http['max_pack_bytes'])
            ? self::parseInt('http.max_pack_bytes', $http['max_pack_bytes'], 1, 1)
            : null;
        $this->httpWorkerPerRequest = self::parseBool('http.worker_per_request', $http['worker_per_request'] ?? null, true);
        $this->userTrustMode  = self::parseUserTrustMode((string) ($http['user_trust_mode'] ?? 'off'));
        $this->trustedProxies = self::parseTrustedProxies($http['trusted_proxies'] ?? []);
        $this->authSecret     = (string) ($http['auth_secret'] ?? '');

        $lfs = $data['lfs'] ?? [];
        $this->lfsEnabled   = self::parseBool('lfs.enabled', $lfs['enabled'] ?? null, true);
        $this->lfsSshEnabled = self::parseBool('lfs.ssh_enabled', $lfs['ssh_enabled'] ?? null, false);

        $jobs = $data['jobs'] ?? [];
        $this->mirrorPullSchedule = (string) ($jobs['mirror_pull'] ?? '@every 10m');

        $stats = $data['stats'] ?? [];
        $this->statsListenAddr = (string) ($stats['listen_addr'] ?? ':23233');

        $this->dataPath = $dataPath;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * S07 (finding 007): `(bool) 'false'` is TRUE in PHP, and `(bool)` of a
     * typo'd string is whatever the caster needed to be true — so every
     * boolean config knob now runs through a named vocabulary: real bools,
     * int 0/1, and the case-folded, whitespace-trimmed strings
     * true/false/yes/no/on/off/1/0.
     * Anything else — 'maybe', '2', an array, a float — throws, naming the
     * key and the offending shape. A security lever (anonymous read,
     * git:// admission, worker mode) that silently decodes to the wrong
     * posture is exactly the fail-open this refuses.
     *
     * The message is a raw-English config diagnostic (same class as the
     * transport failure lines), not a UI string: config corruption is
     * reported to whoever edits config.yaml, in the words they typed the
     * key with.
     *
     * @param mixed $value raw YAML value (null when the key is absent)
     */
    private static function parseBool(string $key, mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }
        if (\is_bool($value)) {
            return $value;
        }
        $v = \is_int($value) ? (string) $value : (\is_string($value) ? \strtolower(\trim($value)) : '');
        return match ($v) {
            '1', 'true', 'yes', 'on'  => true,
            '0', 'false', 'no', 'off' => false,
            default => throw new \InvalidArgumentException(
                'config: ' . $key . ' must be a boolean (true/false/yes/no/on/off/1/0), got '
                . self::describeValue($value)
            ),
        };
    }

    /**
     * S07 (finding 086): `(int) '30s'` is 30 and `(int) '1e9'` is 1 — silent
     * unit truncation on knobs that take bare numbers. Accepted shapes:
     * real ints (within bounds) and digit strings only; bools, floats and
     * non-numeric strings throw. Absent key ⇒ default.
     */
    private static function parseInt(string $key, mixed $value, int $default, int $min, ?int $max = null): int
    {
        if ($value === null) {
            return $default;
        }
        $int = null;
        if (\is_int($value)) {
            $int = $value;
        } elseif (\is_string($value) && \preg_match('/^[+-]?[0-9]+$/', \trim($value)) === 1) {
            $int = (int) \trim($value);
            // Overflow guard: '99999999999999999999' parses as a digit
            // string but wraps silently; a float result means out-of-range.
            if ((string) $int !== \ltrim(\trim($value), '+')) {
                $int = null;
            }
        }
        if ($int === null) {
            throw new \InvalidArgumentException(
                'config: ' . $key . ' must be an integer, got ' . self::describeValue($value)
            );
        }
        if ($int < $min || ($max !== null && $int > $max)) {
            $range = $max === null ? '>= ' . $min : $min . '–' . $max;
            throw new \InvalidArgumentException('config: ' . $key . ' must be ' . $range . ', got ' . $int);
        }

        return $int;
    }

    private static function describeValue(mixed $value): string
    {
        if (\is_array($value)) {
            return 'an array';
        }
        if (\is_object($value)) {
            return 'an object';
        }
        if (\is_string($value)) {
            return "string '" . (\strlen($value) > 40 ? \substr($value, 0, 40) . '…' : $value) . "'";
        }
        if (\is_float($value)) {
            return 'number ' . \var_export($value, true);
        }
        return \get_debug_type($value) . ' ' . \var_export($value, true);
    }

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
     * Validate `http.user_trust_mode` to one of the three known modes.
     *
     * No silent failures (CONTRIBUTING.md): an unknown mode is a config
     * mistake that would otherwise silently fall back to a less-safe
     * behavior, so reject it up front.
     *
     * @throws \InvalidArgumentException on an unrecognized mode
     */
    private static function parseUserTrustMode(string $mode): string
    {
        $mode = \strtolower(\trim($mode));
        if ($mode === '') {
            return 'off';
        }
        if (!\in_array($mode, ['off', 'proxy', 'token'], true)) {
            throw new \InvalidArgumentException(
                Lang::t('config.invalid_user_trust_mode', ['mode' => $mode]),
            );
        }
        return $mode;
    }

    /**
     * Normalize `http.trusted_proxies` into a list of non-empty strings.
     * Accepts a YAML list or a comma-separated string.
     *
     * @param mixed $value
     * @return list<string>
     */
    private static function parseTrustedProxies($value): array
    {
        if (\is_string($value)) {
            $value = $value === '' ? [] : \explode(',', $value);
        }
        if (!\is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $entry) {
            $entry = \trim((string) $entry);
            if ($entry !== '') {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * Parse a YAML config document via symfony/yaml.
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
