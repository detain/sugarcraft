<?php

declare(strict_types=1);

namespace SugarCraft\QuickPick\Tests\History;

use SugarCraft\QuickPick\FilteredItem;
use SugarCraft\QuickPick\History\FileHistory;
use PHPUnit\Framework\TestCase;

/**
 * Verify FileHistory persistent history functionality.
 */
final class FileHistoryTest extends TestCase
{
    private string $tmpPath;
    private string $baseDir;

    protected function setUp(): void
    {
        $this->tmpPath = \sys_get_temp_dir() . '/hermit_history_' . \uniqid('', true) . '.jsonl';
        $this->baseDir = \sys_get_temp_dir() . '/hermit_base_' . \uniqid('', true);
        \mkdir($this->baseDir, 0700, true);
    }

    protected function tearDown(): void
    {
        if (\is_file($this->tmpPath)) {
            \unlink($this->tmpPath);
        }
        foreach (\glob($this->baseDir . '/*') ?: [] as $f) {
            @\unlink($f);
        }
        @\rmdir($this->baseDir);
        // Clean up an escape artifact in the parent, in case confinement is bypassed.
        @\unlink(\dirname($this->baseDir) . '/hermit_evil.jsonl');
    }

    public function testAppendStoresItem(): void
    {
        $history = new FileHistory($this->tmpPath);
        $item = new FilteredItem(1, 'apple');

        $history->append($item);

        $this->assertFileExists($this->tmpPath);
    }

    public function testAllReturnsEmptyArrayWhenNoFile(): void
    {
        $history = new FileHistory($this->tmpPath);
        $this->assertSame([], $history->all());
    }

    public function testAllReturnsStoredItems(): void
    {
        $history = new FileHistory($this->tmpPath);
        $history->append(new FilteredItem(1, 'apple'));
        $history->append(new FilteredItem(2, 'banana'));

        $items = $history->all();

        $this->assertCount(2, $items);
        $this->assertSame(1, $items[0]->number());
        $this->assertSame('apple', $items[0]->value());
        $this->assertSame(2, $items[1]->number());
        $this->assertSame('banana', $items[1]->value());
    }

    public function testClearEmptiesHistory(): void
    {
        // The documented contract is "clear history", not "remove the inode":
        // clear() truncates under LOCK_EX (see FileHistory::clear) so the
        // history file's owner/mode stay stable and a racing append cannot
        // be redirected into an unlinked file.
        $history = new FileHistory($this->tmpPath);
        $history->append(new FilteredItem(1, 'test'));

        $history->clear();

        $this->assertSame([], $history->all());
        \clearstatcache();
        $this->assertSame(0, \filesize($this->tmpPath));
    }

    public function testPathReturnsCorrectPath(): void
    {
        $history = new FileHistory($this->tmpPath);
        $this->assertSame($this->tmpPath, $history->path());
    }

    public function testAllSkipsCorruptLine(): void
    {
        // Write a good line, a bad JSON line, a decodable-but-not-an-entry
        // line (review M7: bare scalars/arrays used to mint FilteredItem(0,'')
        // ghosts), then another good line.
        \file_put_contents(
            $this->tmpPath,
            \json_encode(['n' => 1, 'v' => 'first']) . "\n"
                . "{bad json\n"
                . "42\n\"str\"\ntrue\nnull\n[1,2]\n"
                . \json_encode(['n' => 2, 'v' => 'third']) . "\n",
        );
        // fd-trust verification (S-16) refuses group/other-accessible
        // history files, so this hand-planted fixture tightens itself first.
        \chmod($this->tmpPath, 0600);

        $history = new FileHistory($this->tmpPath);
        $items = $history->all();

        $this->assertCount(2, $items);
        $this->assertSame('first', $items[0]->value());
        $this->assertSame(1, $items[0]->number());
        $this->assertSame('third', $items[1]->value());
        $this->assertSame(2, $items[1]->number());
    }

    public function testAllClosesHandleEvenOnCorruptLines(): void
    {
        // Write corrupt lines that would throw JsonException during read.
        \file_put_contents($this->tmpPath, "{bad}\n{also bad}\n");
        \chmod($this->tmpPath, 0600); // S-16 fd-trust: hand-planted fixtures must be private

        $history = new FileHistory($this->tmpPath);

        // Calling all() repeatedly must not exhaust file descriptors.
        // If the handle leaked, a second call would fail or behave differently.
        $first = $history->all();
        $second = $history->all();

        $this->assertSame([], $first);
        $this->assertSame([], $second);
    }

    public function testBaseDirConfinementRejectsTraversalEscape(): void
    {
        // A '..' traversal that resolves outside the base dir must be rejected at
        // construction. Revert the confinement check and this escape is allowed —
        // the constructor would silently accept a path that append() writes into
        // the parent directory, outside the intended base.
        $this->expectException(\InvalidArgumentException::class);
        new FileHistory($this->baseDir . '/../hermit_evil.jsonl', $this->baseDir);
    }

    public function testBaseDirConfinementRejectsAbsoluteOutsideBase(): void
    {
        // An absolute path pointing entirely outside the base is rejected too.
        $this->expectException(\InvalidArgumentException::class);
        new FileHistory('/etc/hermit_evil.jsonl', $this->baseDir);
    }

    public function testBaseDirConfinementAllowsInBasePath(): void
    {
        // A legitimate path inside the base dir is accepted and works end to end.
        $history = new FileHistory($this->baseDir . '/hist.jsonl', $this->baseDir);
        $history->append(new FilteredItem(1, 'apple'));

        $items = $history->all();
        $this->assertCount(1, $items);
        $this->assertSame('apple', $items[0]->value());
        // The resolved path lives inside the base directory.
        $this->assertStringStartsWith(\realpath($this->baseDir), $history->path());
    }

    public function testMultipleAppendsPersist(): void
    {
        $history = new FileHistory($this->tmpPath);

        for ($i = 1; $i <= 5; $i++) {
            $history->append(new FilteredItem($i, "item_$i"));
        }

        $items = $history->all();
        $this->assertCount(5, $items);

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($i + 1, $items[$i]->number());
            $this->assertSame("item_" . ($i + 1), $items[$i]->value());
        }
    }

    public function testConfineRejectsNonExistentBaseDir(): void
    {
        // When the baseDir does not exist, confine() must throw an
        // InvalidArgumentException rather than silently failing.
        $nonExistent = \sys_get_temp_dir() . '/hermit_nonexistent_base_' . \uniqid();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('base directory does not exist');

        new FileHistory($this->tmpPath, $nonExistent);
    }

    public function testConfineHandlesRelativePathInsideBaseDir(): void
    {
        // A relative path that resolves inside baseDir should work.
        // This exercises the else-branch in confine() where isAbsolute is false.
        // The subdirectory must exist for realpath to resolve it.
        \mkdir($this->baseDir . '/subdir', 0700, true);
        $history = new FileHistory('subdir/history.jsonl', $this->baseDir);
        $history->append(new FilteredItem(1, 'relative'));

        $items = $history->all();
        $this->assertCount(1, $items);
        $this->assertSame('relative', $items[0]->value());
        $this->assertStringStartsWith(\realpath($this->baseDir), $history->path());
    }

    /**
     * S-21/-040 kept honest by S-23/-075: the archived "n" is not read on
     * load at all, so the old garbage-coercion ("7px" → 7) cannot even
     * reach "ordinal 0" — it is irrelevant. Rows carry their load position.
     * (The values still survive: a garbage "n" never made a row unloadable.)
     */
    public function testNonIntegerOrdinalNeverReachesDisplay(): void
    {
        \file_put_contents($this->tmpPath, "{\"n\":[9],\"v\":\"array-ordinal\"}\n{\"n\":\"7px\",\"v\":\"string-ordinal\"}\n");
        \chmod($this->tmpPath, 0600);

        $items = (new FileHistory($this->tmpPath))->all();

        $this->assertCount(2, $items);
        $this->assertSame([1, 2], array_map(static fn($i) => $i->number(), $items));
    }

    /** S-21/-067: every write stamps the format version on the row. */
    public function testAppendStampsFormatVersion(): void
    {
        $history = new FileHistory($this->tmpPath);
        $history->append(new FilteredItem(3, 'stamped'));

        $raw = (string) \file_get_contents($this->tmpPath);
        $decoded = \json_decode(\trim($raw), true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('f', $decoded);
        $this->assertSame(1, $decoded['f']);
    }

    /**
     * S-21/-067: rows predating the "f" field are the original (n,v) shape
     * and must still load — the gate opens backwards, never sideways.
     */
    public function testLegacyRowsWithoutFormatFieldStillLoad(): void
    {
        \file_put_contents($this->tmpPath, "{\"n\":2,\"v\":\"legacy-row\"}\n");
        \chmod($this->tmpPath, 0600);

        $items = (new FileHistory($this->tmpPath))->all();

        $this->assertCount(1, $items);
        $this->assertSame(1, $items[0]->number()); // S-23: load position, not the archived "n":2
        $this->assertSame('legacy-row', $items[0]->value());
    }

    /**
     * S-21/-067: a row from a FUTURE format is skipped whole, never
     * interpreted — old readers guessing at new bytes is how corpora get
     * laundered into nonsense rows.
     */
    public function testUnknownFormatRowsAreSkipped(): void
    {
        \file_put_contents(
            $this->tmpPath,
            "{\"n\":1,\"v\":\"known\"}\n{\"n\":2,\"v\":\"future\",\"f\":99}\n",
        );
        \chmod($this->tmpPath, 0600);

        $items = (new FileHistory($this->tmpPath))->all();

        $this->assertSame(['known'], array_map(static fn($i) => $i->value(), $items));
    }

    /**
     * S-22/-030 kept honest by S-23/-075: 20 identical selections through
     * appendDistinct() leave ONE row — and its DISPLAY ordinal is the load
     * position (1). The newest write-time ordinal survives only as archived
     * bytes in the row, which the raw-byte pin already covers.
     */
    public function testAppendDistinctCollapsesRepeatsToTheNewestRow(): void
    {
        $history = new FileHistory($this->tmpPath);
        for ($i = 1; $i <= 20; $i++) {
            $history->appendDistinct(new FilteredItem($i, 'dup'));
        }

        $items = $history->all();

        $this->assertCount(1, $items);
        $this->assertSame('dup', $items[0]->value());
        $this->assertSame(1, $items[0]->number());
        // The archived bytes still carry the newest write-time ordinal:
        $this->assertStringContainsString('"n":20', (string) \file_get_contents($this->tmpPath));
    }

    /**
     * S-22/-030: a repeat is a RE-selection — the survivor moves to the end
     * (last-wins position), it does not stay parked where it was first seen.
     */
    public function testAppendDistinctMovesRepeatedValueToNewestPosition(): void
    {
        $history = new FileHistory($this->tmpPath);
        $history->appendDistinct(new FilteredItem(1, 'a'));
        $history->appendDistinct(new FilteredItem(2, 'b'));
        $history->appendDistinct(new FilteredItem(3, 'a'));

        $items = $history->all();

        $this->assertSame(['b', 'a'], array_map(static fn($i) => $i->value(), $items));
        $this->assertSame([1, 2], array_map(static fn($i) => $i->number(), $items)); // S-23: display ordinals are load positions
    }

    /** S-22/-030: the constructor cap trims the rewrite to the newest N rows. */
    public function testAppendDistinctTrimsToNewestCap(): void
    {
        $history = new FileHistory($this->tmpPath, null, 2);
        $history->appendDistinct(new FilteredItem(1, 'a'));
        $history->appendDistinct(new FilteredItem(2, 'b'));
        $history->appendDistinct(new FilteredItem(3, 'c'));

        $items = $history->all();

        $this->assertSame(['b', 'c'], array_map(static fn($i) => $i->value(), $items));
    }

    /** S-22/-030: null cap (the default) bounds nothing — distinct rows all stay. */
    public function testAppendDistinctWithoutCapKeepsEveryDistinctRow(): void
    {
        $history = new FileHistory($this->tmpPath);
        $history->appendDistinct(new FilteredItem(1, 'a'));
        $history->appendDistinct(new FilteredItem(2, 'b'));
        $history->appendDistinct(new FilteredItem(3, 'c'));

        $this->assertCount(3, $history->all());
    }

    /**
     * S-22/-030: append() is the RAW journal — the acceptance clause "plain
     * append byte-behavior unchanged" means repeats stay repeated and the
     * cap must never trim honest appends.
     */
    public function testPlainAppendIgnoresDedupeAndCap(): void
    {
        $history = new FileHistory($this->tmpPath, null, 1);
        $history->append(new FilteredItem(1, 'a'));
        $history->append(new FilteredItem(2, 'a'));
        $history->append(new FilteredItem(3, 'b'));

        $items = $history->all();

        $this->assertCount(3, $items);
        $this->assertSame(['a', 'a', 'b'], array_map(static fn($i) => $i->value(), $items));
    }

    /**
     * S-22/-030: the managed rewrite doubles as housekeeping — rows the
     * reader already ignores (unparseable, future-format) are not carried
     * forward, while honest rows survive.
     */
    public function testAppendDistinctDropsRowsTheReaderIgnores(): void
    {
        \file_put_contents(
            $this->tmpPath,
            "{\"n\":1,\"v\":\"keep\"}\n{bad json\n{\"n\":2,\"v\":\"future\",\"f\":99}\n",
        );
        \chmod($this->tmpPath, 0600);

        $history = new FileHistory($this->tmpPath);
        $history->appendDistinct(new FilteredItem(3, 'fresh'));

        $items = $history->all();

        $this->assertSame(['keep', 'fresh'], array_map(static fn($i) => $i->value(), $items));

        // Byte-level: the rewrite must not launder garbage back onto disk —
        // an assertion through all() alone could not see a carried-forward
        // unparseable row (it stays invisible to readers).
        $raw = (string) \file_get_contents($this->tmpPath);
        $this->assertStringNotContainsString('bad json', $raw);
        $this->assertStringNotContainsString('"f":99', $raw);
    }

    /**
     * S-22/-030: the rewrite manages WHICH rows exist, never what an honest
     * row says — surviving bytes (here a legacy no-"f" row) are re-emitted
     * verbatim instead of being silently re-serialized into the new shape.
     */
    public function testAppendDistinctPreservesRawBytesOfSurvivingRows(): void
    {
        $legacy = '{"n":5,"v":"legacy"}';
        \file_put_contents($this->tmpPath, $legacy . "\n");
        \chmod($this->tmpPath, 0600);

        $history = new FileHistory($this->tmpPath);
        $history->appendDistinct(new FilteredItem(6, 'fresh'));

        $raw = (string) \file_get_contents($this->tmpPath);

        $this->assertStringContainsString($legacy, $raw);
        $items = $history->all();
        $this->assertSame(['legacy', 'fresh'], array_map(static fn($i) => $i->value(), $items));
        $this->assertSame(1, $items[0]->number()); // S-23: display is load position; the archived "n":5 rides on untouched
    }

    /** S-22/-030: a cap below 1 would keep nothing on every rewrite: a mis-set setting, refused at the door. */
    public function testConstructorRefusesUselessCap(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('entry cap must be >= 1');

        new FileHistory($this->tmpPath, null, 0);
    }

    /**
     * S-29/-051: house factory forwards all THREE seeds — the cap pin here
     * is what proves the third argument actually rides through (an
     * args-dropping forwarder is otherwise invisible).
     */
    public function testNewFactoryMatchesConstructor(): void
    {
        $history = FileHistory::new($this->tmpPath, null, 2);
        $this->assertSame($this->tmpPath, $history->path());

        $history->appendDistinct(FilteredItem::new(1, 'a'));
        $history->appendDistinct(FilteredItem::new(2, 'b'));
        $history->appendDistinct(FilteredItem::new(3, 'c'));

        $this->assertSame(['b', 'c'], array_map(static fn($i) => $i->value(), $history->all()));
    }
}
