<?php

declare(strict_types=1);

namespace SugarCraft\Wishlist;

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
    /**
     * @param array<string> $identityFiles
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
    ) {}

    /**
     * Build the argv list for `ssh(1)`. The first element is the
     * binary name (`ssh`); the rest is the flags + destination.
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
        if ($this->identityFiles !== [] && $this->identityFiles[0] !== '') {
            $argv[] = '-i';
            $argv[] = $this->identityFiles[0];
        }
        if ($this->proxyJump !== null && $this->proxyJump !== '') {
            $this->assertNotOption('proxyJump', $this->proxyJump);
            $argv[] = '-J';
            $argv[] = $this->proxyJump;
        }
        foreach ($this->options as $opt) {
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
        return $argv;
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

    public function displayLine(): string
    {
        $dest = ($this->user !== null && $this->user !== '' ? "{$this->user}@" : '') . $this->host;
        if ($this->port !== 22) {
            $dest .= ":{$this->port}";
        }
        return "{$this->name}  ─  {$dest}";
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
}
