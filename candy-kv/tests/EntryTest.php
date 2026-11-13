<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Tests;

use SugarCraft\Kv\Entry;
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

    public function testIsExpiredTrueJustAfterExpiry(): void
    {
        // One second past expiry: expired on BOTH sides. (The exact-boundary
        // case cannot be point-tested against a moving wall clock; the
        // alignment itself — alive at equality, mirroring SQL
        // `expires_at >= :now` — is expressed by the strict `<` in
        // Entry::isExpired() and pinned SQL-side by
        // DatabaseTest::testCountExcludesExpiredRows / testSetWithTtl.)
        $past = (new \DateTimeImmutable())->modify('-1 second');
        $entry = new Entry('k', 'v', false, null, null, $past);
        $this->assertTrue($entry->isExpired());
    }

    public function testIsExpiredFalseWhileValueIsStrictlyInTheFuture(): void
    {
        // Polarity twin: the strict comparison must not flip alive rows.
        $entry = new Entry('k', 'v', false, null, null, new \DateTimeImmutable('+1 hour'));
        $this->assertFalse($entry->isExpired());
    }

    // ─── fromRow ─────────────────────────────────────────────────────────────

    public function testFromRowCorruptExpiryRefusesInTheKeyedShape(): void
    {
        // candy-kv-066: one corruption, one error class. The date
        // constructor's bare exception named nothing; a corrupt expiry now
        // raises the sibling rawValue refusal's shape — a RuntimeException
        // that can be acted on because it says WHICH entry.
        $row = self::row(['expires_at' => 'not a moment in time']);
        try {
            Entry::fromRow($row);
            $this->fail('corrupt expiry must refuse');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("Entry 'row-key' does not hold a valid expiry date", $e->getMessage());
            $this->assertStringContainsString('not a moment in time', $e->getMessage());
            $this->assertInstanceOf(\Exception::class, $e->getPrevious(), 'the date engine\'s reason stays in the chain');
        }
    }

    public function testFromRowCorruptCreatedDateRefusesInTheSameShape(): void
    {
        $row = self::row(['created' => '###not-a-date###']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Entry 'row-key' does not hold a valid created date");
        Entry::fromRow($row);
    }

    /** A complete valid row with named overrides — the fromRow corpus shape. */
    private static function row(array $overrides = []): array
    {
        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);
        return array_merge([
            'key' => 'row-key',
            'value' => 'row-value',
            'binary' => 0,
            'created' => $now,
            'modified' => $now,
            'expires_at' => null,
        ], $overrides);
    }

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

    public function testRawValueBinaryInvalidBase64Raises(): void
    {
        // "not-valid-base64!!" cannot decode; returning the broken text (the
        // old behaviour) dressed corruption up as content. Fail loudly.
        $entry = new Entry('k', 'not-valid-base64!!', true);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Binary entry 'k' does not hold valid base64.");
        $entry->rawValue();
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
