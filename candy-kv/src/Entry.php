<?php

declare(strict_types=1);

namespace SugarCraft\Kv;

/**
 * A single key/value entry in the store.
 *
 * @property string              $key        The entry key (unique within a database).
 * @property string              $value      The stored value (may be binary, stored as base64).
 * @property bool                $binary     Whether the value is raw binary (base64-encoded).
 * @property \DateTimeImmutable  $createdAt  When the entry was first created.
 * @property \DateTimeImmutable  $modifiedAt When the entry was last updated.
 * @property \DateTimeImmutable|null $expiresAt When the entry expires (null = no expiry).
 */
final class Entry
{
    public readonly string $key;
    public readonly string $value;
    public readonly bool $binary;
    public readonly \DateTimeImmutable $createdAt;
    public readonly \DateTimeImmutable $modifiedAt;
    public readonly ?\DateTimeImmutable $expiresAt;

    public function __construct(
        string $key,
        string $value,
        bool $binary = false,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $modifiedAt = null,
        ?\DateTimeImmutable $expiresAt = null,
    ) {
        $this->key = $key;
        $this->value = $value;
        $this->binary = $binary;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
        $this->modifiedAt = $modifiedAt ?? new \DateTimeImmutable();
        $this->expiresAt = $expiresAt;
    }

    /**
     * Whether this entry has expired (applies only if expiresAt is set).
     *
     * Expiry is stored as an absolute epoch-seconds integer (D4 fix); it no
     * longer depends on the local clock's UTC offset, so a row written on a
     * UTC+X box reads with the same true instant everywhere. Strictly-past
     * comparison: at the exact expiry second the SQL side (`>= :now` with
     * integer time()) still keeps the row, while this check reads
     * sub-second resolution — a same-second race of at most one second
     * remains by design, resolved in the user's favour on reads.
     */
    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }
        return $this->expiresAt < new \DateTimeImmutable();
    }

    /**
     * Build from a database row (key, value, binary, created, modified[, expires_at]).
     *
     * expires_at is an epoch integer (post-D4 schema). Rows still holding a
     * legacy DATE_ATOM string are parsed by instant so pre-migration reads
     * and getRaw() keep working; a future rewrite of the column happens on
     * Database open.
     *
     * @param array{key:string,value:string,binary:int,created:string,modified:string,expires_at?:int|string|null} $row
     */
    public static function fromRow(array $row): self
    {
        $expiresAt = self::parseExpiry($row['expires_at'] ?? null);
        return new self(
            $row['key'],
            $row['value'],
            (bool) $row['binary'],
            new \DateTimeImmutable($row['created']),
            new \DateTimeImmutable($row['modified']),
            $expiresAt,
        );
    }

    /**
     * Normalise an expires_at cell (epoch int, epoch-text, or legacy string)
     * into a DateTimeImmutable, or null when there is no expiry.
     */
    private static function parseExpiry(int|string|null $raw): ?\DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (\is_int($raw) || \preg_match('/^-?[0-9]+$/', $raw) === 1) {
            return (new \DateTimeImmutable())->setTimestamp((int) $raw);
        }

        return new \DateTimeImmutable($raw);
    }

    /**
     * Get the raw (decoded) value if this is binary data.
     *
     * @return string The base64-decoded value, or the original value if not binary.
     */
    public function rawValue(): string
    {
        if (!$this->binary) {
            return $this->value;
        }
        $decoded = \base64_decode($this->value, true);
        return $decoded !== false ? $decoded : $this->value;
    }

    /**
     * Create a binary entry from raw bytes.
     */
    public static function binary(string $key, string $bytes): self
    {
        return new self($key, \base64_encode($bytes), true);
    }
}
