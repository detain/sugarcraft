<?php

declare(strict_types=1);

namespace SugarCraft\SshLauncher;

use SugarCraft\Core\Concerns\Mutable;

/**
 * One row in a wishlist directory — a host the user can pick to
 * `ssh` into. Held as an immutable value object; build via
 * `new Endpoint(...)` or via {@see Config::load()}.
 *
 * `name` is what's shown in the picker; everything else feeds the
 * `ssh(1)` argv when {@see Launcher} dispatches.
 */
final class Endpoint
{
    use Mutable;

    /** Inclusive valid TCP port range for ssh(1) `-p`. */
    public const MIN_PORT = 1;
    public const MAX_PORT = 65535;

    /**
     * @param array<string> $identityFiles
     * @param list<string>  $flags ssh(1) flag letters, emitted as `-<letter>` before the destination
     *
     * @throws \RuntimeException when $port is outside {@see MIN_PORT}-{@see MAX_PORT}
     */
    public function __construct(
        public readonly string        $name,
        public readonly string        $host,
        public readonly int           $port = 22,
        public readonly ?string      $user = null,
        public readonly array         $identityFiles = [],
        public readonly ?string       $description = null,
        public readonly ?string      $proxyJump = null,
        /** @var list<string> Extra `-o KEY=VALUE` options for ssh */
        public readonly array         $options = [],
        /** @var list<string> ssh(1) flag letters (`t`, `N`), emitted as `-t`, `-N` */
        public readonly array         $flags = [],
        public readonly ?string       $remoteCommand = null,
        /**
         * -027: per-endpoint transport binary (upstream wishlist's `command:`),
         * replacing the global `--ssh` for this row — the mosh-style lane.
         * Not an argv token: it becomes `pcntl_exec`'s path argument, judged
         * by the same exists-and-executable gate that `--ssh` passes, so the
         * leading-dash option-injection family does not apply to it.
         */
        public readonly ?string       $command = null,
    ) {
        self::assertPort($name, $port);
        // -023: README documents `name` as required (non-empty) — mirror the
        // F-22 host_empty shape and refuse a blank identifier by name. A
        // blank-label row rendered `  ─  h` and dispatched normally: names
        // are how endpoints are addressed (-100), so an empty one is invalid.
        if ($name === '') {
            throw new \InvalidArgumentException(Lang::t('endpoint.name_empty'));
        }
        // F-22: an empty host used to mint a selectable picker row that
        // dispatched a blank destination — refuse construction by name.
        if ($host === '') {
            throw new \InvalidArgumentException(Lang::t('endpoint.host_empty', ['name' => $name]));
        }
    }

    /**
     * Parse+validate a config-supplied port before it reaches the ctor.
     * Accepts ints and unsigned decimal strings only — booleans, floats and
     * anything `((int))` would silently mangle ("notanumber" → 0) are
     * refused by name, not coerced.
     *
     * @throws \RuntimeException describing the offending host and value
     */
    public static function parsePort(string $host, mixed $raw): int
    {
        if (is_int($raw)) {
            self::assertPort($host, $raw);
            return $raw;
        }
        // Digit-only guard: rejects bool, float, '', '-1', ' 22', '22abc'.
        if (!is_string($raw) || preg_match('/^\d{1,5}$/', $raw) !== 1) {
            throw new \RuntimeException(
                Lang::t('endpoint.port_invalid', [
                    'host' => $host,
                    'port' => is_scalar($raw) ? var_export($raw, true) : get_debug_type($raw),
                ])
            );
        }
        $port = (int) $raw;
        self::assertPort($host, $port);
        return $port;
    }

    /**
     * @throws \RuntimeException when the port cannot address an ssh(1) peer
     */
    private static function assertPort(string $host, int $port): void
    {
        if ($port < self::MIN_PORT || $port > self::MAX_PORT) {
            throw new \RuntimeException(
                Lang::t('endpoint.port_invalid', ['host' => $host, 'port' => (string) $port])
            );
        }
    }



    /**
     * Build the argv list for `ssh(1)`. The first element is the
     * binary name (`ssh`); the rest is options, the destination and —
     * per the ssh(1) synopsis `ssh [options] destination [command]` —
     * an optional remote command riding after the destination.
     * Suitable for `pcntl_exec($argv[0], array_slice($argv, 1))`.
     *
     * @return list<string>
     */
    public function toSshArgv(string $binary = 'ssh'): array
    {
        $argv = [$binary];
        if ($this->port !== 22) {
            $argv[] = '-p';
            $argv[] = (string) $this->port;
        }
        // -033: boolean-flag lane — `-A`/`-t`/`-T`/`-N`/`-f`/`-X` carry no
        // value and are not expressible through `-o`. Entries are the flag
        // LETTERS; the leading-dash guard refuses an entry that already
        // carries one, so the lane cannot smuggle a stand-alone option
        // (or a `-N`-style payload) past the `--` boundary design.
        // Empty entries are config noise: skipped, never a bare `-`.
        foreach ($this->flags as $flag) {
            if ($flag === '') {
                continue;
            }
            $this->assertNotOption('flag', $flag);
            $argv[] = '-' . $flag;
        }
        // Emit one `-i <file>` pair per identity file, preserving order.
        // Previously only identityFiles[0] was passed, silently dropping every
        // additional key ssh(1) should have been offered.
        // -074: identity values now pass the same leading-dash guard as every
        // other argv token. The old exemption trusted an OpenSSH-only contract
        // ("ssh consumes the next token after -i") while this project ships to
        // alternate clients too (README documents dropbear/busybox, whose getopt
        // re-parses a `-` value into an option). Defense in depth: no value
        // reaches argv starting with a dash. Empty entries still skip.
        foreach ($this->identityFiles as $identityFile) {
            if ($identityFile === '') {
                continue;
            }
            $this->assertNotOption('identityFile', $identityFile);
            $argv[] = '-i';
            $argv[] = $identityFile;
        }
        if ($this->proxyJump !== null && $this->proxyJump !== '') {
            $this->assertNotOption('proxyJump', $this->proxyJump);
            $argv[] = '-J';
            $argv[] = $this->proxyJump;
        }
        foreach ($this->options as $opt) {
            // F-22: '-o' with an empty value is shipped garbage, not a
            // setting — refuse by name before the leading-dash guard.
            if ($opt === '') {
                throw new \InvalidArgumentException(Lang::t('endpoint.option_empty', ['name' => $this->name]));
            }
            $this->assertNotOption('option', $opt);
            $argv[] = '-o';
            $argv[] = $opt;
        }
        // `--` stops SSH from interpreting the destination as an option.
        // Belt-and-suspenders with the leading-dash guard below.
        $argv[] = '--';
        $dest = $this->user !== null && $this->user !== ''
            ? "{$this->user}@{$this->host}"
            : $this->host;
        $this->assertNotOption('host', $dest);
        $argv[] = $dest;
        // -007: the ssh(1) synopsis is `ssh [options] destination [command]` —
        // the remote command is the POSITIONAL after the destination, not a
        // token behind `--` (that would turn the destination itself into the
        // command). One argv element: ssh re-joins the command words verbatim,
        // so `kubectl exec -it bash` rides unchanged. A leading dash would land
        // in the command's own argv-0 position — same guard, refused by name.
        if ($this->remoteCommand !== null && $this->remoteCommand !== '') {
            $this->assertNotOption('remoteCommand', $this->remoteCommand);
            $argv[] = $this->remoteCommand;
        }
        return $argv;
    }

    /**
     * Build the argv list for a custom transport (-027): binary, the
     * `-p` port leg when it is not 22, the raw flag lane, then the
     * destination — nothing else. The ssh-only machinery
     * (`-i`/`-J`/`-o` legs and the remote-command positional) is left
     * out because mosh-class transports reject it; {@see
     * transportIncompatibles()} names what was set but dropped so the
     * launcher can warn instead of silently trimming. The `flags` lane
     * rides verbatim by design — the user picked a custom binary
     * precisely to speak its dialect.
     *
     * @return list<string>
     */
    public function toTransportArgv(string $binary): array
    {
        $argv = [$binary];
        if ($this->port !== 22) {
            $argv[] = '-p';
            $argv[] = (string) $this->port;
        }
        foreach ($this->flags as $flag) {
            if ($flag === '') {
                continue;
            }
            $this->assertNotOption('flag', $flag);
            $argv[] = '-' . $flag;
        }
        $dest = $this->user !== null && $this->user !== ''
            ? "{$this->user}@{$this->host}"
            : $this->host;
        $this->assertNotOption('host', $dest);
        $argv[] = $dest;
        return $argv;
    }

    /**
     * The fixed field names whose values would be silently lost by
     * {@see toTransportArgv()} — computed from what is actually set, so
     * a plain mosh row (`command: /usr/bin/mosh` and nothing else)
     * carries no warning debt.
     *
     * @return list<string>
     */
    public function transportIncompatibles(): array
    {
        $dropped = [];
        if ($this->identityFiles !== []) {
            $dropped[] = 'identityFiles';
        }
        if ($this->proxyJump !== null && $this->proxyJump !== '') {
            $dropped[] = 'proxyJump';
        }
        if ($this->options !== []) {
            $dropped[] = 'options';
        }
        if ($this->remoteCommand !== null && $this->remoteCommand !== '') {
            $dropped[] = 'remoteCommand';
        }
        return $dropped;
    }

    /**
     * Guard against option-injection: a leading dash makes a string
     * look like an ssh flag. Throws if $value would be misinterpreted
     * as a flag by ssh(1).
     *
     * @throws \InvalidArgumentException
     */
    private function assertNotOption(string $field, string $value): void
    {
        if ($value !== '' && str_starts_with($value, '-')) {
            throw new \InvalidArgumentException(
                Lang::t('endpoint.option_injection', ['field' => $field, 'value' => $value])
            );
        }
    }

    /**
     * The host-key-trust-destruction family (candy-ssh-launcher-029).
     *
     * `options:` entries ride to ssh verbatim (trusted-input posture), so
     * a row can carry `-o StrictHostKeyChecking=no` + `-o UserKnownHostsFile=/dev/null`
     * + `-o LogLevel=QUIET` and silently accept ANY host key while hiding
     * every warning — a textbook MITM shape that still looks like a normal
     * login. The trust gate (sshl-23) judges the FILE; this judges the
     * well-formed payload inside a permitted file: the bin prints each hit
     * to stderr, by name, before the exec. Notice only — lab configs
     * legitimately pin `StrictHostKeyChecking=no`, and refusing them would
     * break the tool's own documented escape hatch.
     *
     * Two policies, stated: keys that only ever carry a degrading form
     * (`UserKnownHostsFile`, `GlobalKnownHostsFile`, `IdentityAgent`,
     * `CertificateFile` — an explicit override is the payload shape) are
     * named whenever set; enum-valued keys are judged by value, so the
     * strengthening settings (`StrictHostKeyChecking=yes`, `LogLevel=INFO`)
     * never nag. `accept-new` counts as degrading: it auto-trusts a first
     * contact, which is exactly the MITM window the warning exists for.
     *
     * @return list<string> the option entries as written in the config
     */
    public function unsafeOptions(): array
    {
        // ssh(1) option names are case-sensitive; values enumerated here
        // are compared case-insensitively (ssh itself accepts any case).
        $degradingValues = [
            'StrictHostKeyChecking' => ['no', 'never', 'accept-new'],
            'LogLevel' => ['quiet', 'error', 'fatal'],
        ];
        $alwaysUnsafe = [
            'UserKnownHostsFile', 'GlobalKnownHostsFile', 'IdentityAgent', 'CertificateFile',
        ];

        $hits = [];
        foreach ($this->options as $opt) {
            $eq = strpos($opt, '=');
            if ($eq === false) {
                continue;
            }
            $key   = substr($opt, 0, $eq);
            $value = strtolower(substr($opt, $eq + 1));
            if (in_array($key, $alwaysUnsafe, true)) {
                $hits[] = $opt;
            } elseif (in_array($value, $degradingValues[$key] ?? [], true)) {
                $hits[] = $opt;
            }
        }

        return $hits;
    }

    /**
     * The picker row for this endpoint.
     *
     * One visibility rule on every surface (-077): the port is ALWAYS
     * rendered, default 22 included. `--list` has always shown it, and a
     * picker that hid it made the same endpoint look different on the two
     * surfaces — and silently merged "port unset" with "port explicitly 22".
     */
    public function displayLine(): string
    {
        $dest = ($this->user !== null && $this->user !== '' ? "{$this->user}@" : '') . $this->host;

        return "{$this->name}  ─  {$dest}:{$this->port}";
    }

    // -------------------------------------------------------------------------
    // Immutable builders (with* methods) — all delegate to mutate()
    // -------------------------------------------------------------------------

    public function withName(string $name): self
    {
        return $this->mutate(['name' => $name]);
    }

    public function withHost(string $host): self
    {
        return $this->mutate(['host' => $host]);
    }

    public function withPort(int $port): self
    {
        return $this->mutate(['port' => $port]);
    }

    public function withUser(?string $user): self
    {
        // Null is valid here (means no user, ssh uses env default).
        return $this->mutate(['user' => $user]);
    }

    /**
     * @param array<string> $identityFiles
     */
    public function withIdentityFiles(array $identityFiles): self
    {
        return $this->mutate(['identityFiles' => $identityFiles]);
    }

    public function withDescription(?string $description): self
    {
        // Null is valid here (no description shown).
        return $this->mutate(['description' => $description]);
    }

    public function withProxyJump(?string $proxyJump): self
    {
        return $this->mutate(['proxyJump' => $proxyJump]);
    }

    /**
     * @param list<string> $options
     */
    public function withOptions(array $options): self
    {
        return $this->mutate(['options' => $options]);
    }

    /**
     * @param list<string> $flags ssh(1) flag letters without the dash
     */
    public function withFlags(array $flags): self
    {
        return $this->mutate(['flags' => $flags]);
    }

    public function withRemoteCommand(?string $remoteCommand): self
    {
        // Null is valid here (plain interactive session, no command positional).
        return $this->mutate(['remoteCommand' => $remoteCommand]);
    }

    public function withCommand(?string $command): self
    {
        // -027: null restores the global ssh binary for this row.
        return $this->mutate(['command' => $command]);
    }
}
