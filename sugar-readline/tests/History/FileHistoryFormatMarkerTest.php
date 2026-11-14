<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests\History;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\History\FileHistory;

/**
 * S-46 (finding 069): the bounded full rewrite stamps
 * `#sugar-readline-history/v2` as the file's first line, making the format
 * self-describing for future dispatch. Markerless files decode exactly as
 * before (the v2 escape rule is the only rule so far), append paths never
 * add the marker, and every decoder skips it - adoption is a trajectory,
 * not a migration.
 */
final class FileHistoryFormatMarkerTest extends TestCase
{
    private const HEADER = '#sugar-readline-history/v2';

    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/srl_marker_' . uniqid('', true);
        mkdir($this->dir, 0700);
        $this->path = $this->dir . '/history.txt';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function plant(string $content): void
    {
        file_put_contents($this->path, $content);
        clearstatcache();
    }

    private function diskBytes(): string
    {
        return file_exists($this->path) ? (string) file_get_contents($this->path) : '';
    }

    public function testMarkerlessLegacyFileDecodesUnchanged(): void
    {
        $this->plant("alpha\nbeta\n");
        $history = new FileHistory($this->path, 0);
        $this->assertSame(['beta', 'alpha'], $history->entries());

        // The unlimited append path never adds the marker.
        $history->push('gamma');
        $this->assertSame("alpha\nbeta\ngamma\n", $this->diskBytes());
    }

    public function testBoundedRewriteStampsTheMarkerOnce(): void
    {
        $this->plant("old\n");
        $history = new FileHistory($this->path, 5, '', true);
        $history->push('x');
        $history->flush(); // cap > 0 -> merge arm -> full rewrite

        $this->assertSame(self::HEADER . "\nold\nx\n", $this->diskBytes());

        // A rewrite of a file that already carries it must not double-stamp.
        $history->push('y');
        $history->flush();
        $this->assertSame(1, substr_count($this->diskBytes(), self::HEADER));
        $this->assertSame(self::HEADER . "\nold\nx\ny\n", $this->diskBytes());
    }

    public function testHeaderIsSkippedByLoadAndNeverBecomesAnEntry(): void
    {
        $this->plant(self::HEADER . "\nfirst\nsecond\n");
        $history = new FileHistory($this->path, 0);

        $this->assertSame(['second', 'first'], $history->entries());

        // Round-trip: re-writing keeps the decoded entries and exactly one
        // marker line - the header never rides in through the merge either.
        $bounded = new FileHistory($this->path, 5, '', true);
        $bounded->push('third');
        $bounded->flush();
        $this->assertSame(self::HEADER . "\nfirst\nsecond\nthird\n", $this->diskBytes());
    }

    public function testHeaderOnlyFileDecodesToAnEmptyStore(): void
    {
        $this->plant(self::HEADER . "\n");
        $history = new FileHistory($this->path, 0);
        $this->assertSame([], $history->entries());

        $history->push('sole');
        $this->assertSame(self::HEADER . "\nsole\n", $this->diskBytes());
    }

    public function testLegacyEscapeSemanticsSurviveTheMarker(): void
    {
        // v2 escaping (S-12 era): embedded newlines round-trip as ONE entry;
        // an unknown backslash escape stays verbatim. Pinned through a
        // marker-stamped file so marker and escapes are proven together.
        $this->plant(self::HEADER . "\nlit\\nteral\nback\\\\slash\nweird\\q\n");
        $history = new FileHistory($this->path, 0);

        $this->assertSame(['weird\\q', 'back\\slash', "lit\nteral"], $history->entries());
    }
}
