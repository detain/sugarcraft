<?php

declare(strict_types=1);

namespace SugarCraft\Skate\Tests;

use SugarCraft\Skate\Entry;
use PHPUnit\Framework\TestCase;

final class EntryTest extends TestCase
{
    // ─── Constructor ─────────────────────────────────────────────────────────

    public function testConstructorDefaults(): void
    {
        $entry = new Entry('mykey', 'myvalue');

        $this->assertSame('mykey', $entry->key);
        $this->assertSame('myvalue', $entry->value);
        $this->assertFalse($entry->binary);
        $this->assertInstanceOf(\DateTimeImmutable::class, $entry->createdAt);
        $this->assertInstanceOf(\DateTimeImmutable::class, $entry->modifiedAt);
        $this->assertNull($entry->expiresAt);
    }

    public function testConstructorFullArgs(): void
    {
        $created = new \DateTimeImmutable('2024-01-01T00:00:00+00:00');
        $modified = new \DateTimeImmutable('2024-06-15T12:30:00+00:00');
        $expires = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');

        $entry = new Entry('key', 'val', true, $created, $modified, $expires);

        $this->assertSame('key', $entry->key);
        $this->assertSame('val', $entry->value);
        $this->assertTrue($entry->binary);
        $this->assertSame($created, $entry->createdAt);
        $this->assertSame($modified, $entry->modifiedAt);
        $this->assertSame($expires, $entry->expiresAt);
    }

    // ─── isExpired ────────────────────────────────────────────────────────────

    public function testIsExpiredFalseWhenNoExpiry(): void
    {
        $entry = new Entry('k', 'v');
        $this->assertFalse($entry->isExpired());
    }

    public function testIsExpiredFalseWhenFutureExpiry(): void
    {
        $future = (new \DateTimeImmutable())->modify('+1 hour');
        $entry = new Entry('k', 'v', false, null, null, $future);
        $this->assertFalse($entry->isExpired());
    }

    public function testIsExpiredTrueWhenPastExpiry(): void
    {
        $past = (new \DateTimeImmutable())->modify('-1 hour');
        $entry = new Entry('k', 'v', false, null, null, $past);
        $this->assertTrue($entry->isExpired());
    }

    public function testIsExpiredTrueAtExactExpiryMoment(): void
    {
        // At the exact boundary, isExpired considers the entry expired
        $past = (new \DateTimeImmutable())->modify('-1 second');
        $entry = new Entry('k', 'v', false, null, null, $past);
        $this->assertTrue($entry->isExpired());
    }

    // ─── fromRow ─────────────────────────────────────────────────────────────

    public function testFromRowWithExpiry(): void
    {
        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $future = (new \DateTimeImmutable('+1 hour'))->format(\DATE_ATOM);

        $row = [
            'key' => 'row-key',
            'value' => 'row-value',
            'binary' => 0,
            'created' => $now,
            'modified' => $now,
            'expires_at' => $future,
        ];

        $entry = Entry::fromRow($row);

        $this->assertSame('row-key', $entry->key);
        $this->assertSame('row-value', $entry->value);
        $this->assertFalse($entry->binary);
        $this->assertNotNull($entry->expiresAt);
        $this->assertFalse($entry->isExpired());
    }

    public function testFromRowWithoutExpiry(): void
    {
        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);

        $row = [
            'key' => 'row-key',
            'value' => 'row-value',
            'binary' => 1,
            'created' => $now,
            'modified' => $now,
        ];

        $entry = Entry::fromRow($row);

        $this->assertSame('row-key', $entry->key);
        $this->assertSame('row-value', $entry->value);
        $this->assertTrue($entry->binary);
        $this->assertNull($entry->expiresAt);
    }

    public function testFromRowWithNullExpiresAt(): void
    {
        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);

        $row = [
            'key' => 'row-key',
            'value' => 'row-value',
            'binary' => 0,
            'created' => $now,
            'modified' => $now,
            'expires_at' => null,
        ];

        $entry = Entry::fromRow($row);
        $this->assertNull($entry->expiresAt);
    }

    public function testFromRowWithEmptyStringExpiresAt(): void
    {
        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);

        $row = [
            'key' => 'row-key',
            'value' => 'row-value',
            'binary' => 0,
            'created' => $now,
            'modified' => $now,
            'expires_at' => '',
        ];

        $entry = Entry::fromRow($row);
        $this->assertNull($entry->expiresAt);
    }

    // ─── rawValue ─────────────────────────────────────────────────────────────

    public function testRawValueNonBinaryReturnsValueAsIs(): void
    {
        $entry = new Entry('k', 'hello world', false);
        $this->assertSame('hello world', $entry->rawValue());
    }

    public function testRawValueBinaryDecodesBase64(): void
    {
        $raw = "\x00\xff\xfe\xfd";
        $entry = new Entry('k', \base64_encode($raw), true);
        $this->assertSame($raw, $entry->rawValue());
    }

    public function testRawValueBinaryInvalidBase64FallsBackToValue(): void
    {
        // "not-valid-base64!!" is not valid base64 but Entry should not throw
        $entry = new Entry('k', 'not-valid-base64!!', true);
        $result = $entry->rawValue();
        // base64_decode with strict=true returns false, so rawValue returns original
        $this->assertSame('not-valid-base64!!', $result);
    }

    public function testRawValueEmptyBinaryEntry(): void
    {
        $entry = new Entry('k', '', true);
        $this->assertSame('', $entry->rawValue());
    }

    // ─── binary factory ───────────────────────────────────────────────────────

    public function testBinaryFactoryCreatesBinaryEntry(): void
    {
        $raw = "raw bytes \x00\xff";
        $entry = Entry::binary('bin-key', $raw);

        $this->assertSame('bin-key', $entry->key);
        $this->assertTrue($entry->binary);
        $this->assertSame($raw, $entry->rawValue());
        // The stored value should be base64-encoded
        $this->assertSame(\base64_encode($raw), $entry->value);
    }

    public function testBinaryFactoryEmptyBytes(): void
    {
        $entry = Entry::binary('empty', '');
        $this->assertSame('', $entry->value);
        $this->assertSame('', $entry->rawValue());
        $this->assertTrue($entry->binary);
    }

    // ─── readonly properties are enforced ───────────────────────────────────

    public function testKeyIsReadonly(): void
    {
        $entry = new Entry('original-key', 'value');
        $reflection = new \ReflectionClass($entry);
        $prop = $reflection->getProperty('key');
        $this->assertTrue($prop->isReadOnly());
    }

    public function testValueIsReadonly(): void
    {
        $entry = new Entry('key', 'original-value');
        $reflection = new \ReflectionClass($entry);
        $prop = $reflection->getProperty('value');
        $this->assertTrue($prop->isReadOnly());
    }

    public function testBinaryIsReadonly(): void
    {
        $entry = new Entry('key', 'value', false);
        $reflection = new \ReflectionClass($entry);
        $prop = $reflection->getProperty('binary');
        $this->assertTrue($prop->isReadOnly());
    }

    public function testCreatedAtIsReadonly(): void
    {
        $entry = new Entry('key', 'value');
        $reflection = new \ReflectionClass($entry);
        $prop = $reflection->getProperty('createdAt');
        $this->assertTrue($prop->isReadOnly());
    }

    public function testModifiedAtIsReadonly(): void
    {
        $entry = new Entry('key', 'value');
        $reflection = new \ReflectionClass($entry);
        $prop = $reflection->getProperty('modifiedAt');
        $this->assertTrue($prop->isReadOnly());
    }

    public function testExpiresAtIsReadonly(): void
    {
        $entry = new Entry('key', 'value');
        $reflection = new \ReflectionClass($entry);
        $prop = $reflection->getProperty('expiresAt');
        $this->assertTrue($prop->isReadOnly());
    }
}
