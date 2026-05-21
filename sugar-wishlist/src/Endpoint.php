<?php

declare(strict_types=1);

namespace SugarCraft\Wishlist;

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
            $argv[] = '-J';
            $argv[] = $this->proxyJump;
        }
        foreach ($this->options as $opt) {
            $argv[] = '-o';
            $argv[] = $opt;
        }
        $argv[] = $this->user !== null && $this->user !== ''
            ? "{$this->user}@{$this->host}"
            : $this->host;
        return $argv;
    }

    public function displayLine(): string
    {
        $dest = ($this->user !== null && $this->user !== '' ? "{$this->user}@" : '') . $this->host;
        if ($this->port !== 22) {
            $dest .= ":{$this->port}";
        }
        return "{$this->name}  ─  {$dest}";
    }

    /**
     * @param array<string> $files
     */
    public function withIdentityFiles(array $files): self
    {
        return new self(
            name:          $this->name,
            host:          $this->host,
            port:          $this->port,
            user:          $this->user,
            identityFiles: $files,
            description:   $this->description,
            proxyJump:     $this->proxyJump,
            options:       $this->options,
        );
    }

    public function withProxyJump(?string $jump): self
    {
        return new self(
            name:          $this->name,
            host:          $this->host,
            port:          $this->port,
            user:          $this->user,
            identityFiles: $this->identityFiles,
            description:   $this->description,
            proxyJump:     $jump,
            options:       $this->options,
        );
    }
}
