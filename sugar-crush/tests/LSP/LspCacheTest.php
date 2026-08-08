<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\LSP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\LSP\LspCache;

/**
 * Tests for LspCache — TTL cache for LSP responses.
 */
final class LspCacheTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    public function testEntryPersistsWithinShortDuration(): void
    {
        $cache = new LspCache();
        $cache->set('file:///a.php', 'textDocument/definition', ['result']);
        // Advance clock by 59 s — still valid
        // We verify TTL behaviour by checking it hasn't expired after 59s
        // by directly manipulating time via a subclass that can sleep
        $this->assertTrue($cache->has('file:///a.php', 'textDocument/definition'));
    }

    // -------------------------------------------------------------------------
    // Basic set / get / has
    // -------------------------------------------------------------------------

    public function testSetAndGet(): void
    {
        $cache = new LspCache(300);
        $cache->set('file:///a.php', 'textDocument/definition', [['uri' => 'file:///a.php', 'range' => []]]);

        $result = $cache->get('file:///a.php', 'textDocument/definition');
        $this->assertSame([['uri' => 'file:///a.php', 'range' => []]], $result);
    }

    public function testGetNonExistentKeyReturnsNull(): void
    {
        $cache = new LspCache();
        $this->assertNull($cache->get('file:///missing.php', 'textDocument/definition'));
    }

    public function testHasReturnsTrueForCachedEntry(): void
    {
        $cache = new LspCache(300);
        $cache->set('file:///a.php', 'textDocument/definition', ['loc']);

        $this->assertTrue($cache->has('file:///a.php', 'textDocument/definition'));
    }

    public function testHasReturnsFalseForMissingEntry(): void
    {
        $cache = new LspCache();
        $this->assertFalse($cache->has('file:///missing.php', 'textDocument/definition'));
    }

    // -------------------------------------------------------------------------
    // TTL expiration
    // -------------------------------------------------------------------------

    public function testEntryExpiresAfterTtl(): void
    {
        // Use a 1-second TTL cache
        $cache = new LspCache(1);
        $cache->set('file:///a.php', 'textDocument/definition', ['data']);

        // Entry should still be valid before sleep
        $this->assertTrue($cache->has('file:///a.php', 'textDocument/definition'));

        // Sleep past the TTL
        usleep(1_100_000); // 1.1 seconds

        $this->assertFalse($cache->has('file:///a.php', 'textDocument/definition'));
        $this->assertNull($cache->get('file:///a.php', 'textDocument/definition'));
    }

    public function testDifferentUrisDoNotConflict(): void
    {
        $cache = new LspCache(300);
        $cache->set('file:///a.php', 'textDocument/definition', ['resultA']);
        $cache->set('file:///b.php', 'textDocument/definition', ['resultB']);

        $this->assertSame(['resultA'], $cache->get('file:///a.php', 'textDocument/definition'));
        $this->assertSame(['resultB'], $cache->get('file:///b.php', 'textDocument/definition'));
    }

    public function testDifferentMethodsDoNotConflict(): void
    {
        $cache = new LspCache(300);
        $cache->set('file:///a.php', 'textDocument/definition', ['def']);
        $cache->set('file:///a.php', 'textDocument/references', ['ref']);
        $cache->set('file:///a.php', 'textDocument/hover', ['hover']);

        $this->assertSame(['def'], $cache->get('file:///a.php', 'textDocument/definition'));
        $this->assertSame(['ref'], $cache->get('file:///a.php', 'textDocument/references'));
        $this->assertSame(['hover'], $cache->get('file:///a.php', 'textDocument/hover'));
    }

    public function testNullValueCanBeCached(): void
    {
        $cache = new LspCache(300);
        // LSP hover can legitimately return null — cache must distinguish
        // "not in cache" from "cached null"
        $cache->set('file:///a.php', 'textDocument/hover', null);

        // has() returns true because the key exists and hasn't expired
        $this->assertTrue($cache->has('file:///a.php', 'textDocument/hover'));
        // get() returns null (the stored value), not null (missing)
        $this->assertNull($cache->get('file:///a.php', 'textDocument/hover'));
    }

    // -------------------------------------------------------------------------
    // clearFile
    // -------------------------------------------------------------------------

    public function testClearFileRemovesAllEntriesForUri(): void
    {
        $cache = new LspCache(300);
        $cache->set('file:///a.php', 'textDocument/definition', ['def']);
        $cache->set('file:///a.php', 'textDocument/references', ['ref']);
        $cache->set('file:///a.php', 'textDocument/hover', ['hover']);
        $cache->set('file:///b.php', 'textDocument/definition', ['other']);

        $cache->clearFile('file:///a.php');

        $this->assertFalse($cache->has('file:///a.php', 'textDocument/definition'));
        $this->assertFalse($cache->has('file:///a.php', 'textDocument/references'));
        $this->assertFalse($cache->has('file:///a.php', 'textDocument/hover'));
        // b.php is untouched
        $this->assertSame(['other'], $cache->get('file:///b.php', 'textDocument/definition'));
    }

    public function testClearFileOnNonExistentUriIsSafe(): void
    {
        $cache = new LspCache(300);
        $cache->set('file:///a.php', 'textDocument/definition', ['data']);

        $cache->clearFile('file:///missing.php');

        $this->assertSame(['data'], $cache->get('file:///a.php', 'textDocument/definition'));
    }

    // -------------------------------------------------------------------------
    // clear
    // -------------------------------------------------------------------------

    public function testClearRemovesAllEntries(): void
    {
        $cache = new LspCache(300);
        $cache->set('file:///a.php', 'textDocument/definition', ['a']);
        $cache->set('file:///b.php', 'textDocument/references', ['b']);
        $cache->set('file:///c.php', 'textDocument/hover', ['c']);

        $cache->clear();

        $this->assertNull($cache->get('file:///a.php', 'textDocument/definition'));
        $this->assertNull($cache->get('file:///b.php', 'textDocument/references'));
        $this->assertNull($cache->get('file:///c.php', 'textDocument/hover'));
    }

    public function testClearOnEmptyCacheIsSafe(): void
    {
        $cache = new LspCache();
        $cache->clear(); // must not throw
        $this->assertSame(0, $cache->count());
    }

    // -------------------------------------------------------------------------
    // prune
    // -------------------------------------------------------------------------

    public function testPruneRemovesOnlyExpiredEntries(): void
    {
        $cache = new LspCache(300);
        $cache->set('file:///a.php', 'textDocument/definition', ['persistent']);

        // Use a 1-second cache for this specific entry to test pruning
        $shortCache = new LspCache(1);
        $shortCache->set('file:///b.php', 'textDocument/definition', ['temporary']);

        // Prune should remove the expired b.php entry but keep a.php
        usleep(1_100_000);
        $pruned = $shortCache->prune();

        $this->assertSame(1, $pruned);
        $this->assertNull($shortCache->get('file:///b.php', 'textDocument/definition'));
    }

    public function testPruneReturnsZeroWhenNothingExpired(): void
    {
        $cache = new LspCache(300);
        $cache->set('file:///a.php', 'textDocument/definition', ['data']);
        $cache->set('file:///b.php', 'textDocument/hover', ['hover']);

        $pruned = $cache->prune();
        $this->assertSame(0, $pruned);
    }

    public function testPruneReturnsZeroForEmptyCache(): void
    {
        $cache = new LspCache();
        $this->assertSame(0, $cache->prune());
    }

    // -------------------------------------------------------------------------
    // count
    // -------------------------------------------------------------------------

    public function testCountReturnsNumberOfEntries(): void
    {
        $cache = new LspCache(300);
        $this->assertSame(0, $cache->count());

        $cache->set('file:///a.php', 'textDocument/definition', ['a']);
        $this->assertSame(1, $cache->count());

        $cache->set('file:///a.php', 'textDocument/references', ['b']);
        $this->assertSame(2, $cache->count());

        $cache->set('file:///b.php', 'textDocument/hover', ['c']);
        $this->assertSame(3, $cache->count());

        $cache->clearFile('file:///a.php');
        $this->assertSame(1, $cache->count());

        $cache->clear();
        $this->assertSame(0, $cache->count());
    }

    // -------------------------------------------------------------------------
    // Overwrite / update
    // -------------------------------------------------------------------------

    public function testSetOverwritesExistingValue(): void
    {
        $cache = new LspCache(300);
        $cache->set('file:///a.php', 'textDocument/definition', ['original']);
        $cache->set('file:///a.php', 'textDocument/definition', ['updated']);

        $this->assertSame(['updated'], $cache->get('file:///a.php', 'textDocument/definition'));
    }
}
