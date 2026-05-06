<?php

declare(strict_types=1);

namespace CandyCore\Serve;

/**
 * User account with SSH public key authentication.
 *
 * Port of charmbracelet/soft-serve User.
 *
 * @see https://github.com/charmbracelet/soft-serve
 */
final class User
{
    public readonly string $username;
    public readonly bool $isAdmin;
    public readonly bool $isActive;
    public readonly ?string $password;

    /** Full authorized_keys content (one key per line). */
    private string $authorizedKeys;

    private function __construct(
        string $username,
        bool $isAdmin = false,
        bool $isActive = true,
        ?string $password = null,
        string $authorizedKeys = '',
    ) {
        $this->username       = $username;
        $this->isAdmin        = $isAdmin;
        $this->isActive       = $isActive;
        $this->password       = $password;
        $this->authorizedKeys = $authorizedKeys;
    }

    public static function new(string $username): self
    {
        return new self($username);
    }

    public function withUsername(string $u): self
    {
        return new self($u, $this->isAdmin, $this->isActive, $this->password, $this->authorizedKeys);
    }

    public function withAdmin(bool $v = true): self
    {
        return new self($this->username, $v, $this->isActive, $this->password, $this->authorizedKeys);
    }

    public function withActive(bool $v = true): self
    {
        return new self($this->username, $v, $this->isActive, $this->password, $this->authorizedKeys);
    }

    public function withPassword(?string $password): self
    {
        return new self($this->username, $this->isAdmin, $this->isActive, $password, $this->authorizedKeys);
    }

    public function withAuthorizedKeys(string $keys): self
    {
        $clone = clone $this;
        $clone->authorizedKeys = $keys;
        return $clone;
    }

    /**
     * Add a public key to this user's authorized_keys.
     * Supports ssh-ed25519, ssh-rsa, ecdsa-sha2-*, sk-ssh-ed25519@openssh.com
     */
    public function addAuthorizedKey(string $pubKey): self
    {
        $key = \trim($pubKey);
        if ($key === '') return $this;

        // Validate format: type base64data [comment]
        if (!\preg_match('/^(ssh-[a-z0-9-]+)\s+([A-Za-z0-9+\/=]+)(\s+.+)?$/', $key)) {
            throw new \InvalidArgumentException("Invalid SSH public key format");
        }

        $keys = $this->authorizedKeys === '' ? [] : \explode("\n", \rtrim($this->authorizedKeys, "\n"));
        $keys[] = $key;

        $clone = clone $this;
        $clone->authorizedKeys = \implode("\n", $keys);
        return $clone;
    }

    /**
     * Verify a presented public key matches one of this user's authorized keys.
     */
    public function verifyPublicKey(string $presentedKey): bool
    {
        if ($this->authorizedKeys === '') return false;

        $presented = \trim($presentedKey);
        $keys = \explode("\n", \rtrim($this->authorizedKeys, "\n"));

        foreach ($keys as $key) {
            $key = \trim($key);
            if ($key === '') continue;

            if ($this->keysMatch($presented, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all authorized keys.
     *
     * @return list<string>
     */
    public function authorizedKeysList(): array
    {
        if ($this->authorizedKeys === '') return [];
        return \array_values(
            \array_filter(
                \explode("\n", $this->authorizedKeys),
                fn($k) => \trim($k) !== ''
            )
        );
    }

    /**
     * The default SSH public key comment for generated keys.
     */
    public function publicKeyComment(): string
    {
        return $this->username . '@candy-serve';
    }

    /**
     * Generate an ssh-ed25519 key pair.
     *
     * Requires the ssh2 extension. Returns ['private' => ..., 'public' => ...].
     *
     * @return array{private: string, public: string}|null  null if ssh2 not available
     */
    public function generateKeyPair(): ?array
    {
        if (!\extension_loaded('ssh2')) {
            return null;
        }

        $keyPath = \sys_get_temp_dir() . '/candy-serve-key-' . \bin2hex(\random_bytes(8));

        // Generate using ssh-keygen
        $comment = \escapeshellarg($this->publicKeyComment());
        $cmd = "ssh-keygen -t ed25519 -f {$keyPath} -N '' -C {$comment} 2>&1";
        $out = [];
        $rc  = 0;
        \exec($cmd, $out, $rc);

        if ($rc !== 0 || !\file_exists($keyPath)) {
            return null;
        }

        $private = \file_get_contents($keyPath) ?: '';
        $public  = \file_get_contents($keyPath . '.pub') ?: '';

        // Cleanup
        @\unlink($keyPath);
        @\unlink($keyPath . '.pub');

        return [
            'private' => $private,
            'public'  => \trim($public),
        ];
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Compare two SSH public keys for equality.
     *
     * Keys match if they have the same type and base64 blob.
     * Comments may differ.
     */
    private function keysMatch(string $a, string $b): bool
    {
        $parse = function (string $key): string {
            $parts = \preg_split('/\s+/', \trim($key));
            return \sprintf('%s %s', $parts[0] ?? '', $parts[1] ?? '');
        };

        return $parse($a) === $parse($b);
    }
}
