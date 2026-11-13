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
     * Expiry is stored as an absolute epoch-seconds integer; it does not
     * depend on the local clock's UTC offset, so a row written on a UTC+X
     * box reads with the same true instant everywhere. THE ONE liveness
     * rule: a row is expired exactly when its epoch is strictly before the
     * integer-second "now" — SQL readers apply `expires_at IS NULL OR
     * expires_at >= :now` (the column's INTEGER affinity makes the bare
     * comparison total; kv-128's rebuild retired the old CAST bridge) and
     * pruneExpired deletes the complement; this method carries the same
     * rule at DateTime precision.
     * At the exact expiry second the integer-second SQL comparison keeps
     * the row while a sub-second-aware read here may already call it
     * expired — a same-second boundary of at most one second, resolved in
     * the user's favour on SQL reads.
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
     * expires_at is an epoch integer (current schema). Rows still holding a
     * legacy DATE_ATOM string are parsed by instant so pre-migration reads
     * and raw() keep working; a future rewrite of the column happens on
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
     * A binary-flagged row whose stored text is not valid base64 can only
     * come from a corrupted or hand-edited database; returning the broken
     * text (the old behaviour) dressed up corruption as content, so it now
     * raises. Non-binary entries pass their value through untouched.
     *
     * @return string The base64-decoded value, or the original value if not binary.
     * @throws \RuntimeException when a binary entry holds invalid base64.
     */
    public function rawValue(): string
    {
        if (!$this->binary) {
            return $this->value;
        }
        $decoded = \base64_decode($this->value, true);
        if ($decoded === false) {
            throw new \RuntimeException(
                \sprintf("Binary entry '%s' does not hold valid base64.", $this->key)
            );
        }
        return $decoded;
    }

    /**
     * Create a binary entry from raw bytes.
     */
    public static function binary(string $key, string $bytes): self
    {
        return new self($key, \base64_encode($bytes), true);
    }
}
