<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\State;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\State\TreeState;

final class TreeStateTest extends TestCase
{
    private const NOW = 1_800_000_000;

    public function testKeyJoinsRootFirstNames(): void
    {
        $this->assertSame("systemd\x1fsshd\x1fbash", TreeState::key(['systemd', 'sshd', 'bash']));
    }

    public function testKeyRejectsWhatCannotBeStored(): void
    {
        $this->assertNull(TreeState::key([]));
        $this->assertNull(TreeState::key(['a', '']));
        $this->assertNull(TreeState::key(['a', "b\x1fc"]));
        $this->assertNull(TreeState::key([str_repeat('x', TreeState::MAX_NAME + 1)]));
        $this->assertNotNull(TreeState::key([str_repeat('x', TreeState::MAX_NAME)]));
        $this->assertNull(TreeState::key(array_fill(0, TreeState::MAX_DEPTH + 1, 'a')));
        $this->assertNotNull(TreeState::key(array_fill(0, TreeState::MAX_DEPTH, 'a')));
    }

    public function testRememberAndLookupBothDirections(): void
    {
        $s = TreeState::empty()->remember('a', true)->remember('b', false);
        $this->assertTrue($s->lookup('a'));
        $this->assertFalse($s->lookup('b'), 'an explicit expand is remembered too');
        $this->assertNull($s->lookup('c'));
        $this->assertSame(0, TreeState::empty()->count(), 'immutable');
    }

    public function testRememberMovesTheKeyToMostRecent(): void
    {
        $s = TreeState::empty()->remember('a', true)->remember('b', true)->remember('a', false);
        $this->assertSame(['b', 'a'], $s->keys());
    }

    public function testCapDropsTheLeastRecentlyUsed(): void
    {
        $s = TreeState::empty();
        for ($i = 0; $i < TreeState::MAX_ENTRIES + 3; $i++) {
            $s = $s->remember('k' . $i, true);
        }
        $this->assertSame(TreeState::MAX_ENTRIES, $s->count());
        $this->assertNull($s->lookup('k0'));
        $this->assertNull($s->lookup('k2'));
        $this->assertTrue($s->lookup('k3'));
        $this->assertTrue($s->lookup('k' . (TreeState::MAX_ENTRIES + 2)));
    }

    public function testNumericNamesSurvive(): void
    {
        $s = TreeState::empty()->remember('123', true);
        $this->assertTrue($s->lookup('123'));
        $this->assertSame(['123'], $s->keys());
        $this->assertSame([['path' => ['123'], 'collapsed' => true, 'at' => self::NOW]], $s->toArray(self::NOW)['entries']);
    }

    public function testToArrayStampsTouchedEntriesAndRoundTrips(): void
    {
        $loaded = TreeState::fromArray(['version' => 1, 'entries' => [
            ['path' => ['init', 'old'], 'collapsed' => true, 'at' => self::NOW - 100],
        ]], self::NOW);
        $s = $loaded->remember("init\x1fnew", false);
        $data = $s->toArray(self::NOW);
        $this->assertSame(1, $data['version']);
        $this->assertSame([
            ['path' => ['init', 'old'], 'collapsed' => true, 'at' => self::NOW - 100],
            ['path' => ['init', 'new'], 'collapsed' => false, 'at' => self::NOW],
        ], $data['entries']);
        $again = TreeState::fromArray($data, self::NOW);
        $this->assertSame($data, $again->toArray(self::NOW + 5));
    }

    public function testTouchedRefreshesOnlyKnownKeys(): void
    {
        $s = TreeState::fromArray(['version' => 1, 'entries' => [
            ['path' => ['a'], 'collapsed' => true, 'at' => self::NOW - 100],
        ]], self::NOW);
        $this->assertSame($s, $s->touched(['zzz']), 'nothing changed: same instance');
        $t = $s->touched(['a']);
        $this->assertNotSame($s, $t);
        $this->assertSame(self::NOW + 9, $t->toArray(self::NOW + 9)['entries'][0]['at']);
        $this->assertSame($t, $t->touched(['a']), 'already touched');
    }

    public function testFromArrayDropsInvalidStaleAndWrongVersion(): void
    {
        $this->assertSame(0, TreeState::fromArray(['version' => 2, 'entries' => []], self::NOW)->count());
        $this->assertSame(0, TreeState::fromArray(['entries' => 'x'], self::NOW)->count());
        $this->assertSame(0, TreeState::fromArray([], self::NOW)->count());

        $s = TreeState::fromArray(['version' => 1, 'entries' => [
            ['path' => ['ok'], 'collapsed' => true, 'at' => self::NOW],
            ['path' => ['stale'], 'collapsed' => true, 'at' => self::NOW - TreeState::MAX_AGE - 1],
            ['path' => ['edge'], 'collapsed' => true, 'at' => self::NOW - TreeState::MAX_AGE],
            ['path' => 'flat', 'collapsed' => true, 'at' => self::NOW],
            ['path' => ['a', 3], 'collapsed' => true, 'at' => self::NOW],
            ['path' => ['k' => 'v'], 'collapsed' => true, 'at' => self::NOW],
            ['path' => [], 'collapsed' => true, 'at' => self::NOW],
            ['path' => ['nobool'], 'collapsed' => 1, 'at' => self::NOW],
            ['path' => ['noat'], 'collapsed' => true],
            ['path' => ['future'], 'collapsed' => false, 'at' => self::NOW + 999],
            'garbage',
        ]], self::NOW);
        $this->assertSame(['ok', 'edge', 'future'], $s->keys());
        $this->assertSame(self::NOW, $s->toArray(self::NOW)['entries'][2]['at'], 'a future time is clamped to now');
    }

    public function testFromArrayLaterDuplicateWinsAndCapKeepsNewest(): void
    {
        $entries = [];
        for ($i = 0; $i < TreeState::MAX_ENTRIES + 5; $i++) {
            $entries[] = ['path' => ['k' . $i], 'collapsed' => true, 'at' => self::NOW];
        }
        $entries[] = ['path' => ['k10'], 'collapsed' => false, 'at' => self::NOW];
        $s = TreeState::fromArray(['version' => 1, 'entries' => $entries], self::NOW);
        $this->assertSame(TreeState::MAX_ENTRIES, $s->count());
        $this->assertFalse($s->lookup('k10'));
        $this->assertSame('k10', $s->keys()[TreeState::MAX_ENTRIES - 1]);
        $this->assertNull($s->lookup('k0'));
    }

    public function testToArrayLeavesOutNonUtf8Paths(): void
    {
        $s = TreeState::empty()->remember("bad\xff", true)->remember('good', true);
        $this->assertSame([['path' => ['good'], 'collapsed' => true, 'at' => self::NOW]], $s->toArray(self::NOW)['entries']);
    }
}
