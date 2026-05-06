<?php

declare(strict_types=1);

namespace CandyCore\Skate;

/**
 * A single key/value entry in the store.
 *
 * @property string    $key        The entry key (unique within a database).
 * @property string    $value      The stored value (may be binary, stored as base64).
 * @property bool      $binary     Whether the value is raw binary (base64-encoded).
 * @property \DateTimeImmutable $createdAt When the entry was first created.
 * @property \DateTimeImmutable $modifiedAt When the entry was last updated.
 */
final class Entry
{
    public readonly string $key;
    public readonly string $value;
    public readonly bool $binary;
    public readonly \DateTimeImmutable $createdAt;
    public readonly \DateTimeImmutable $modifiedAt;

    public function __construct(
        string $key,
        string $value,
        bool $binary = false,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $modifiedAt = null,
    ) {
        $this->key = $key;
        $this->value = $value;
        $this->binary = $binary;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
        $this->modifiedAt = $modifiedAt ?? new \DateTimeImmutable();
    }

    /**
     * Build from a database row (key, value, binary, created, modified).
     *
     * @param array{key:string,value:string,binary:int,created:string,modified:string} $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            $row['key'],
            $row['value'],
            (bool) $row['binary'],
            new \DateTimeImmutable($row['created']),
            new \DateTimeImmutable($row['modified']),
        );
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
