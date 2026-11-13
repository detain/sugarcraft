<?php

declare(strict_types=1);

namespace SugarCraft\GitServer;

use SugarCraft\GitServer\Lang;
use SugarCraft\GitServer\Support\BoundedCommand;

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

    /**
     * password_hash() digest for HTTP Basic auth — the ONLY credential
     * this account model carries. A cleartext $password property used to
     * exist here; it was dead weight (nothing authenticated against it)
     * and the classic leak vector, so it is gone: persistence keeps the
     * digest, comparison runs through password_verify().
     */
    public readonly ?string $passwordHash;

    /** Full authorized_keys content (one key per line). */
    private string $authorizedKeys;

    private function __construct(
        string $username,
        bool $isAdmin = false,
        bool $isActive = true,
        string $authorizedKeys = '',
        ?string $passwordHash = null,
    ) {
        $this->username       = $username;
        $this->isAdmin        = $isAdmin;
        $this->isActive       = $isActive;
        $this->authorizedKeys = $authorizedKeys;
        $this->passwordHash   = $passwordHash;
    }

    public static function new(string $username): self
    {
        return new self($username);
    }

    public function withUsername(string $u): self
    {
        return new self($u, $this->isAdmin, $this->isActive, $this->authorizedKeys, $this->passwordHash);
    }

    public function withAdmin(bool $v = true): self
    {
        return new self($this->username, $v, $this->isActive, $this->authorizedKeys, $this->passwordHash);
    }

    public function withActive(bool $v = true): self
    {
        // The constructor order is
        // (username, isAdmin, isActive, keys) — this setter used to
        // write $v into the isAdmin slot, so 'deactivating' a user stripped
        // admin and left the account ACTIVE. A trust gate on isActive cannot
        // exist while this flips the wrong field.
        return new self($this->username, $this->isAdmin, $v, $this->authorizedKeys, $this->passwordHash);
    }

    /** Attach a password_hash() digest (the only password form the store keeps). */
    public function withPasswordHash(?string $hash): self
    {
        return new self($this->username, $this->isAdmin, $this->isActive, $this->authorizedKeys, $hash);
    }

    /**
     * Serializable form used by UserStore. Deliberately never emits a
     * cleartext password: persistence carries the digest only.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'username'        => $this->username,
            'admin'           => $this->isAdmin,
            'active'          => $this->isActive,
            'password_hash'   => $this->passwordHash,
            'authorized_keys' => $this->authorizedKeysList(),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $username = (string) ($row['username'] ?? '');
        if ($username === '') {
            throw new \RuntimeException('user store row without a username');
        }

        $keys = $row['authorized_keys'] ?? [];
        $user = new self(
            $username,
            (bool) ($row['admin'] ?? false),
            (bool) ($row['active'] ?? true),
            \is_array($keys) ? \implode("\n", \array_map('strval', $keys)) : '',
            isset($row['password_hash']) ? (string) $row['password_hash'] : null,
        );

        return $user;
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
     *
     * The input may be a whole multi-line paste (a key file handed to
     * `user key` streams verbatim), so EVERY non-empty line is validated
     * on its own and any single bad line refuses the entire call before a
     * byte is appended. The old single-string match had no final anchor
     * (no `D` modifier) and group 3 `(\s+.+)?$` stopped at the first
     * newline — so "valid-key-blob\n<anything>" passed, and the trailing
     * segment landed in the store as its own authorized_keys line. In
     * sshd's grammar a leading word on such a line is an OPTION
     * (`command="…"`, `no-agent-forwarding`, …): a key-add could plant a
     * login-time command execution hook. Atomic refusal is the only safe
     * shape: partial acceptance would make the store differ from what was
     * validated.
     */
    public function addAuthorizedKey(string $pubKey): self
    {
        $lines = \preg_split('/\r?\n/', \trim($pubKey)) ?: [];
        $validated = [];
        foreach ($lines as $line) {
            $line = \trim($line);
            if ($line === '') {
                continue; // blank separator inside a paste
            }
            $validated[] = self::validatedAuthorizedKeyLine($line);
        }
        if ($validated === []) {
            return $this; // whitespace-only input: no key to add (old contract kept)
        }

        $keys = $this->authorizedKeys === '' ? [] : \explode("\n", \rtrim($this->authorizedKeys, "\n"));
        foreach ($validated as $line) {
            $keys[] = $line;
        }

        $clone = clone $this;
        $clone->authorizedKeys = \implode("\n", $keys);
        return $clone;
    }

    /**
     * One authorized_keys line, validated end-anchored; returns the line
     * unchanged so the caller can commit all-or-nothing.
     *
     * @throws \InvalidArgumentException when the line is not a key
     */
    private static function validatedAuthorizedKeyLine(string $line): string
    {
        // Validate format: type base64data [comment], D-anchored — no
        // trailing newline survives, the blob class must run to the
        // subject's true end (or to whitespace starting a comment).
        if (!\preg_match('/^((?:ssh|ecdsa|sk)-[A-Za-z0-9@.-]+)\s+([A-Za-z0-9+\/=]+)(\s.+)?$/D', $line, $m)) {
            throw new \InvalidArgumentException(Lang::t('user.invalid_ssh_key'));
        }

        // A well-formed-looking but truncated blob cannot be a real key; the
        // minimums are the base64 length of the smallest valid wire blob per
        // key type (ed25519 blob is exactly 51 bytes → 68 base64 chars).
        $type = $m[1];
        $blob = $m[2];
        $minBlobLen = match (true) {
            \str_contains($type, 'ed25519') => 68,
            \str_contains($type, 'rsa')     => 256,
            \str_contains($type, 'ecdsa')   => 100,
            default                          => 64,
        };
        if (\strlen($blob) < $minBlobLen) {
            throw new \InvalidArgumentException(Lang::t('user.ssh_key_too_short', [
                'type' => $type,
                'len'  => (string) \strlen($blob),
                'min'  => (string) $minBlobLen,
            ]));
        }

        return $line;
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
        return $this->username . '@candy-git-server';
    }

    /**
     * Generate an ssh-ed25519 key pair by delegating to ssh-keygen(1).
     *
     * Returns ['private' => ..., 'public' => ...], or null when the
     * capability gate is closed (this build refuses to shell out unless
     * the ssh2 extension is present) or ssh-keygen is missing/fails.
     *
     * @return array{private: string, public: string}|null
     */
    public function generateKeyPair(): ?array
    {
        if (!\extension_loaded('ssh2')) {
            return null;
        }

        $keyPath = \sys_get_temp_dir() . '/candy-git-server-key-' . \bin2hex(\random_bytes(8));

        $generated = BoundedCommand::run([
            'ssh-keygen', '-t', 'ed25519', '-f', $keyPath, '-N', '', '-C', $this->publicKeyComment(),
        ]);

        if ($generated['code'] !== 0 || !\file_exists($keyPath)) {
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
     * A key's IDENTITY is its type and its base64 blob; the trailing
     * comment is a free-form label (it defaults to user@host and every
     * regeneration or copy across machines rewrites it). Requiring the
     * comment to match made public-key auth fail against every realistic
     * client — and against this package's own generator, which stamps
     * `<username>@candy-git-server` while the key may have been added
     * under any other label. Whitespace between fields is normalized;
     * the comment is ignored.
     */
    private function keysMatch(string $a, string $b): bool
    {
        $identity = static function (string $key): ?string {
            $parts = \preg_split('/\s+/', \trim($key)) ?: [];
            if (\count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
                return null; // malformed line cannot match anything
            }

            return $parts[0] . ' ' . $parts[1];
        };

        $ida = $identity($a);

        return $ida !== null && $ida === $identity($b);
    }
}
