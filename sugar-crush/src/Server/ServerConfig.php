<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Server;

use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Permissions\PermissionMode;

/**
 * Everything `sugarcrush serve` binds, admits and serves with, resolved once
 * at start (Appendix O §4.7, §8.3, §8.4).
 *
 * SECURE BY DEFAULT, and each default is a decision rather than a convenience:
 * loopback only, port 7420, no extra origins or hosts, no bypass. A wider
 * setting has to be asked for, and the two that open the box to the network or
 * hand clients the bypass modes are flags or user-tier keys only — never a
 * project file, because a cloned repository must not be able to open a port.
 *
 * PRECEDENCE, per value: the `serve` flag, then its `SUGARCRUSH_SERVER_*`
 * variable, then the user-config `server.*` key, then the default. The highest
 * source that is SET wins outright; lists are not merged across sources, so
 * what a flag says is exactly what the server allows.
 */
final class ServerConfig
{
    public const DEFAULT_HOST = '127.0.0.1';

    /** User decision (Appendix O §4.7): fixed, memorable, unassigned. */
    public const DEFAULT_PORT = 7420;

    /** The one WebSocket subprotocol this server speaks (Appendix O §6.1). */
    public const SUBPROTOCOL = 'sugarcrush.v1';

    /**
     * The protocol MAJOR `/api/health` reports. The method and event roster
     * that version names is O-3b's; the integer is the transport's promise.
     */
    public const PROTOCOL_MAJOR = 1;

    /** A non-browser client may carry its token as `sugarcrush.auth.<token>`. */
    public const AUTH_SUBPROTOCOL_PREFIX = 'sugarcrush.auth.';

    /** Client→server WebSocket message ceiling (prompts; uploads come later). */
    public const MAX_CLIENT_MESSAGE_BYTES = 1_048_576;

    /** Every HTTP API body is a few hundred bytes; anything near this is hostile. */
    public const MAX_API_BODY_BYTES = 65_536;

    /** In-flight HTTP requests across all clients. */
    public const MAX_CONCURRENT_REQUESTS = 32;

    /** Hosts a bind may name without `--allow-remote`. */
    public const LOOPBACK_HOSTS = ['127.0.0.1', '::1', 'localhost'];

    /** Appendix O §4.4: sessions open at once (`server.maxOpenSessions`). */
    public const DEFAULT_MAX_OPEN_SESSIONS = 32;

    /** Appendix O §4.4: turns in flight at once (`server.maxConcurrentTurns`). */
    public const DEFAULT_MAX_CONCURRENT_TURNS = 4;

    /** Appendix O §6.7: 0 waits for a permission answer forever (`server.askTimeoutSeconds`). */
    public const DEFAULT_ASK_TIMEOUT_SECONDS = 0.0;

    /**
     * Appendix O §4.6: how long a stopping server waits for running turns
     * (`server.drainSeconds`). Kept well inside `serve stop`'s own wait, which
     * stretches to cover a longer configured drain.
     */
    public const DEFAULT_DRAIN_SECONDS = 10.0;

    /**
     * @param list<string> $allowedOrigins normalised `scheme://host[:port]`
     * @param list<string> $allowedHosts   lower-cased `host` or `host:port`
     * @param list<string> $trustedProxies IPs or CIDRs whose X-Forwarded-* count
     * @param list<string> $interfaceAddresses this machine's own IPs, for a wildcard bind ({@see InterfaceAddresses})
     */
    private function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly bool $allowRemote,
        public readonly array $allowedOrigins,
        public readonly array $allowedHosts,
        public readonly array $trustedProxies,
        public readonly ?string $webRoot,
        public readonly bool $web,
        public readonly bool $allowBypass,
        public readonly bool $allowRoot,
        public readonly PermissionMode $permissionMode,
        public readonly string $stateDir,
        public readonly ?string $root,
        public readonly int $maxOpenSessions = self::DEFAULT_MAX_OPEN_SESSIONS,
        public readonly int $maxConcurrentTurns = self::DEFAULT_MAX_CONCURRENT_TURNS,
        public readonly float $askTimeoutSeconds = self::DEFAULT_ASK_TIMEOUT_SECONDS,
        public readonly float $drainSeconds = self::DEFAULT_DRAIN_SECONDS,
        public readonly array $interfaceAddresses = [],
    ) {
    }

    public static function new(string $stateDir): self
    {
        return new self(
            self::DEFAULT_HOST,
            self::DEFAULT_PORT,
            false,
            [],
            [],
            [],
            null,
            true,
            false,
            false,
            PermissionMode::Default,
            $stateDir,
            null,
        );
    }

    /**
     * The configuration a `serve` launch runs with.
     *
     * @param array<string, string|true> $flags      the verb's own flags ({@see \SugarCraft\Crush\Cli\ParsedArgs::$subcommandFlags})
     * @param array<string, string>      $env        the process environment
     * @param array<string, mixed>       $userConfig `~/.sugar-crush/config.json`, decoded
     *
     * @throws ServerConfigException naming the value and why it was refused
     */
    public static function resolve(
        array $flags,
        array $env,
        array $userConfig,
        string $defaultStateDir,
        ?string $permissionModeFlag = null,
        ?string $root = null,
    ): self {
        $pick = static function (string $flag, string $variable, string $key) use ($flags, $env, $userConfig): mixed {
            if (isset($flags[$flag]) && \is_string($flags[$flag])) {
                return $flags[$flag];
            }
            if (isset($env[$variable]) && \trim($env[$variable]) !== '') {
                return \trim($env[$variable]);
            }

            return $userConfig[$key] ?? null;
        };

        $config = self::new(self::stateDirFrom($env, $defaultStateDir));

        $host = $pick('--host', 'SUGARCRUSH_SERVER_HOST', 'server.host');
        if ($host !== null) {
            $config = $config->withHost(self::stringValue('server host', $host));
        }

        $port = $pick('--port', 'SUGARCRUSH_SERVER_PORT', 'server.port');
        if ($port !== null) {
            $config = $config->withPort(self::portValue($port));
        }

        $origins = $pick('--allowed-origin', 'SUGARCRUSH_SERVER_ALLOWED_ORIGINS', 'server.allowedOrigins');
        if ($origins !== null) {
            $config = $config->withAllowedOrigins(self::listValue('allowed origins', $origins));
        }

        $hosts = $pick('--allowed-host', 'SUGARCRUSH_SERVER_ALLOWED_HOSTS', 'server.allowedHosts');
        if ($hosts !== null) {
            $config = $config->withAllowedHosts(self::listValue('allowed hosts', $hosts));
        }
        if (isset($userConfig['server.trustedProxies'])) {
            $config = $config->withTrustedProxies(self::listValue('trusted proxies', $userConfig['server.trustedProxies']));
        }

        $webRoot = $flags['--web-root'] ?? (isset($env['SUGARCRUSH_SERVER_WEB_ROOT']) && $env['SUGARCRUSH_SERVER_WEB_ROOT'] !== '' ? $env['SUGARCRUSH_SERVER_WEB_ROOT'] : null);
        if (\is_string($webRoot)) {
            $config = $config->withWebRoot($webRoot);
        }

        if (isset($userConfig['server.maxOpenSessions'])) {
            $config = $config->withMaxOpenSessions(self::countValue('server.maxOpenSessions', $userConfig['server.maxOpenSessions'], 1));
        }
        if (isset($userConfig['server.maxConcurrentTurns'])) {
            $config = $config->withMaxConcurrentTurns(self::countValue('server.maxConcurrentTurns', $userConfig['server.maxConcurrentTurns'], 1));
        }
        if (isset($userConfig['server.drainSeconds'])) {
            $config = $config->withDrainSeconds(self::secondsValue('server.drainSeconds', $userConfig['server.drainSeconds']));
        }
        if (isset($userConfig['server.askTimeoutSeconds'])) {
            $config = $config->withAskTimeoutSeconds(self::secondsValue('server.askTimeoutSeconds', $userConfig['server.askTimeoutSeconds']));
        }

        $allowBypassKey = $userConfig['server.allowBypass'] ?? false;
        if (!\is_bool($allowBypassKey)) {
            throw new ServerConfigException('server.allowBypass in the user config must be true or false');
        }

        $mode = $permissionModeFlag;
        if ($mode === null && isset($env['SUGARCRUSH_PERMISSION_MODE']) && \trim($env['SUGARCRUSH_PERMISSION_MODE']) !== '') {
            $mode = \trim($env['SUGARCRUSH_PERMISSION_MODE']);
        }
        if ($mode !== null) {
            $parsed = PermissionMode::tryFrom($mode);
            if ($parsed === null) {
                throw new ServerConfigException(\sprintf(
                    'permission mode "%s" is not one of: %s',
                    $mode,
                    \implode(', ', \array_map(static fn (PermissionMode $m): string => $m->value, PermissionMode::cases())),
                ));
            }
            $config = $config->withPermissionMode($parsed);
        }

        return $config
            ->withAllowRemote(isset($flags['--allow-remote']))
            ->withWeb(!isset($flags['--no-web']))
            ->withAllowBypass(isset($flags['--allow-bypass']) || $allowBypassKey)
            ->withAllowRoot(isset($flags['--allow-root']))
            ->withRoot($root);
    }

    /**
     * `SUGARCRUSH_SERVER_DIR`, else the caller's default (`~/.sugar-crush/server`).
     *
     * @param array<string, string> $env
     */
    public static function stateDirFrom(array $env, string $default): string
    {
        $dir = $env['SUGARCRUSH_SERVER_DIR'] ?? '';

        return \trim($dir) === '' ? $default : \rtrim($dir, '/');
    }

    public function withHost(string $host): self
    {
        $host = \strtolower(\trim($host, " \t[]"));
        if ($host === 'localhost') {
            $host = self::DEFAULT_HOST;
        }
        if (@\inet_pton($host) === false) {
            throw new ServerConfigException(\sprintf(
                'server host "%s" is not an IP address (use 127.0.0.1, ::1 or localhost, or an interface address with --allow-remote)',
                $host,
            ));
        }

        return $this->mutate(host: $host);
    }

    public function withPort(int $port): self
    {
        if ($port < 0 || $port > 65535) {
            throw new ServerConfigException(\sprintf('server port %d is outside 0-65535', $port));
        }

        return $this->mutate(port: $port);
    }

    public function withAllowRemote(bool $allowRemote): self
    {
        return $this->mutate(allowRemote: $allowRemote);
    }

    /** @param list<string> $origins */
    public function withAllowedOrigins(array $origins): self
    {
        return $this->mutate(allowedOrigins: \array_values(\array_unique(\array_map(self::normaliseOrigin(...), $origins))));
    }

    /**
     * `host` or `host:port`, lower-cased, an IPv6 address bracketed (a bare
     * `::1` is taken as the address, not as a port).
     *
     * @param list<string> $hosts
     */
    public function withAllowedHosts(array $hosts): self
    {
        $normalised = [];
        foreach ($hosts as $host) {
            $host = \strtolower(\trim($host));
            if (\str_contains($host, ':') && !\str_starts_with($host, '[') && @\inet_pton($host) !== false) {
                $host = '[' . $host . ']';
            }
            if (
                $host === ''
                || \preg_match('/^(\[[0-9a-f:.]+\]|[a-z0-9.-]+)(?::(\d{1,5}))?$/', $host, $m) !== 1
                || (\str_starts_with($m[1], '[') && @\inet_pton(\substr($m[1], 1, -1)) === false)
                || (isset($m[2]) && (int) $m[2] > 65535)
            ) {
                throw new ServerConfigException(Lang::t('cli.serve.config.allowed_host_invalid', ['host' => $host]));
            }
            $normalised[] = $host;
        }

        return $this->mutate(allowedHosts: \array_values(\array_unique($normalised)));
    }

    /**
     * This machine's own addresses ({@see InterfaceAddresses::detect()}),
     * which a wildcard `--allow-remote` bind answers to and names in its
     * sign-in URLs ({@see reachableHosts()}).
     *
     * @param list<string> $addresses
     */
    public function withInterfaceAddresses(array $addresses): self
    {
        return $this->mutate(interfaceAddresses: InterfaceAddresses::usable($addresses));
    }

    /** @param list<string> $proxies */
    public function withTrustedProxies(array $proxies): self
    {
        foreach ($proxies as $proxy) {
            if (!Http\ClientAddress::isValidRange($proxy)) {
                throw new ServerConfigException(\sprintf('trusted proxy "%s" is not an IP address or CIDR range', $proxy));
            }
        }

        return $this->mutate(trustedProxies: \array_values($proxies));
    }

    public function withWebRoot(?string $webRoot): self
    {
        return $this->mutate(webRoot: $webRoot === null || $webRoot === '' ? null : \rtrim($webRoot, '/'));
    }

    public function withWeb(bool $web): self
    {
        return $this->mutate(web: $web);
    }

    public function withAllowBypass(bool $allowBypass): self
    {
        return $this->mutate(allowBypass: $allowBypass);
    }

    public function withAllowRoot(bool $allowRoot): self
    {
        return $this->mutate(allowRoot: $allowRoot);
    }

    public function withPermissionMode(PermissionMode $permissionMode): self
    {
        return $this->mutate(permissionMode: $permissionMode);
    }

    public function withStateDir(string $stateDir): self
    {
        return $this->mutate(stateDir: $stateDir);
    }

    public function withRoot(?string $root): self
    {
        return $this->mutate(root: $root);
    }

    public function withMaxOpenSessions(int $maxOpenSessions): self
    {
        if ($maxOpenSessions < 1) {
            throw new ServerConfigException(\sprintf('server.maxOpenSessions must be at least 1; got %d', $maxOpenSessions));
        }

        return $this->mutate(maxOpenSessions: $maxOpenSessions);
    }

    public function withMaxConcurrentTurns(int $maxConcurrentTurns): self
    {
        if ($maxConcurrentTurns < 1) {
            throw new ServerConfigException(\sprintf('server.maxConcurrentTurns must be at least 1; got %d', $maxConcurrentTurns));
        }

        return $this->mutate(maxConcurrentTurns: $maxConcurrentTurns);
    }

    /** 0 stops at once, cancelling whatever runs. */
    public function withDrainSeconds(float $seconds): self
    {
        if ($seconds < 0) {
            throw new ServerConfigException('server.drainSeconds must not be negative');
        }

        return $this->mutate(drainSeconds: $seconds);
    }

    /** 0 (the default) waits for a permission answer forever. */
    public function withAskTimeoutSeconds(float $seconds): self
    {
        if ($seconds < 0) {
            throw new ServerConfigException('server.askTimeoutSeconds must not be negative');
        }

        return $this->mutate(askTimeoutSeconds: $seconds);
    }

    /** Whether the bind address is this machine only. */
    public function isLoopback(): bool
    {
        return \in_array($this->host, self::LOOPBACK_HOSTS, true)
            || \str_starts_with($this->host, '127.');
    }

    /** Whether the bind address is every interface: `0.0.0.0` or `::`. */
    public function isWildcard(): bool
    {
        $packed = @\inet_pton($this->host);

        return $packed === "\0\0\0\0" || $packed === \str_repeat("\0", 16);
    }

    /**
     * The hosts, as a URL authority spells them (no port), that a client on
     * another machine dials this server under: the bind address, or — on a
     * wildcard `--allow-remote` bind — each of this machine's
     * {@see $interfaceAddresses} (IPv4 only on `0.0.0.0`), falling back to
     * loopback when there are none. The first is the one a single URL names.
     *
     * @return list<string>
     */
    public function reachableHosts(): array
    {
        if (!$this->isWildcard() || !$this->allowRemote) {
            return [$this->hostForUrl()];
        }
        $v4Only = \strlen((string) @\inet_pton($this->host)) === 4;
        $hosts = [];
        foreach ($this->interfaceAddresses as $address) {
            if (\str_contains($address, ':')) {
                if (!$v4Only) {
                    $hosts[] = '[' . $address . ']';
                }
                continue;
            }
            $hosts[] = $address;
        }

        return $hosts !== [] ? $hosts : [$v4Only ? self::DEFAULT_HOST : '[::1]'];
    }

    /** The `tcp://` URI the listener binds, IPv6 bracketed. */
    public function bindUri(): string
    {
        return 'tcp://' . $this->hostForUrl() . ':' . $this->port;
    }

    /** The host as a URL authority spells it (IPv6 bracketed). */
    public function hostForUrl(): string
    {
        return \str_contains($this->host, ':') ? '[' . $this->host . ']' : $this->host;
    }

    /**
     * Whether a client may put a session in $mode (Appendix O §8.4).
     *
     * `bypass-permissions` and `dont-ask` answer every Ask without asking, so
     * over the wire they are refused unless the operator started the server
     * with `--allow-bypass` or `server.allowBypass: true`. Every other mode —
     * including the heuristic `auto` — is the client's to choose.
     */
    public function admitsPermissionMode(PermissionMode $mode): bool
    {
        return $this->allowBypass || !\in_array($mode, [PermissionMode::BypassPermissions, PermissionMode::DontAsk], true);
    }

    /**
     * Why this configuration may not start, one sentence each; empty when it
     * may. Only CONFIGURATION reasons — the runtime ones (extensions, root)
     * are {@see Preflight}'s.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        if (!$this->isLoopback() && !$this->allowRemote) {
            $problems[] = \sprintf(
                'refusing to bind %s: it is not a loopback address, and anyone who can reach the port can run code as you — pass --allow-remote (and put TLS in front of it)',
                $this->host,
            );
        }
        if (!$this->admitsPermissionMode($this->permissionMode)) {
            $problems[] = \sprintf(
                'refusing to serve sessions in %s mode: it answers every permission ask without asking — pass --allow-bypass (or set "server.allowBypass": true) to permit it',
                $this->permissionMode->value,
            );
        }

        return $problems;
    }

    private static function normaliseOrigin(string $origin): string
    {
        $parts = \parse_url(\trim($origin));
        if (!\is_array($parts)) {
            throw new ServerConfigException(\sprintf('allowed origin "%s" is not of the form http(s)://host[:port]', $origin));
        }
        $scheme = \strtolower((string) ($parts['scheme'] ?? ''));
        $host = \strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if (
            !\in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || ($path !== '' && $path !== '/')
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new ServerConfigException(\sprintf('allowed origin "%s" is not of the form http(s)://host[:port]', $origin));
        }

        $port = $parts['port'] ?? null;
        $default = $scheme === 'https' ? 443 : 80;

        return $scheme . '://' . $host . ($port === null || $port === $default ? '' : ':' . $port);
    }

    private static function stringValue(string $what, mixed $value): string
    {
        if (!\is_string($value) || \trim($value) === '') {
            throw new ServerConfigException($what . ' must be a non-empty string');
        }

        return \trim($value);
    }

    private static function portValue(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && \preg_match('/^\d{1,5}$/', \trim($value)) === 1) {
            return (int) \trim($value);
        }

        throw new ServerConfigException(\sprintf('server port "%s" is not a number', \is_scalar($value) ? (string) $value : \get_debug_type($value)));
    }

    private static function countValue(string $key, mixed $value, int $min): int
    {
        if (!\is_int($value) || $value < $min) {
            throw new ServerConfigException(\sprintf('%s in the user config must be a whole number of at least %d', $key, $min));
        }

        return $value;
    }

    private static function secondsValue(string $key, mixed $value): float
    {
        if ((!\is_int($value) && !\is_float($value)) || $value < 0) {
            throw new ServerConfigException(\sprintf('%s in the user config must be a number of seconds, 0 or more', $key));
        }

        return (float) $value;
    }

    /**
     * A comma-separated string (flag or variable) or a JSON array (config key).
     *
     * @return list<string>
     */
    private static function listValue(string $what, mixed $value): array
    {
        if (\is_string($value)) {
            return \array_values(\array_filter(\array_map('trim', \explode(',', $value)), static fn (string $v): bool => $v !== ''));
        }
        if (\is_array($value) && \array_is_list($value)) {
            $out = [];
            foreach ($value as $item) {
                if (!\is_string($item)) {
                    throw new ServerConfigException($what . ' must be a list of strings');
                }
                $out[] = $item;
            }

            return $out;
        }

        throw new ServerConfigException($what . ' must be a list of strings or a comma-separated string');
    }

    private function mutate(mixed ...$changes): self
    {
        $args = \get_object_vars($this);
        foreach ($changes as $name => $value) {
            $args[$name] = $value;
        }

        return new self(...$args);
    }
}
