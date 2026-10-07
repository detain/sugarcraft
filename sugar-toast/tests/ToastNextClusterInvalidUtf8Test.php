<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use SugarCraft\Toast\Toast;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Mirrors candy-core's WidthInvalidUtf8Test::malformed() shapes onto Toast's
 * nextCluster() seam — a thin delegate to the canonical
 * `Width::nextCluster()` since the fork body was deleted (@85466ebd2 promoted
 * the core method to public): with the core's invalid-UTF-8 guards (ICU
 * position rejection + continuation-byte validation) the cluster walk must
 * reproduce malformed input byte-for-byte, and a stray lead byte must never
 * swallow the ASCII that follows it. These pins defend behaviour, not
 * implementation — they hold whatever the seam delegates through.
 */
final class ToastNextClusterInvalidUtf8Test extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        yield 'stray lead byte' => ["aaa\xffb"];
        yield 'leading stray bytes' => ["\xff\xfeab"];
        yield 'truncated tail' => ["ab\xc3"];
        yield 'broken sequence before ascii' => ["\xe2AB"];
        // 0xA5, not 0x80-0x9F: Ansi::strip() (which the walkers run first)
        // deliberately removes lone C1-range bytes as 8-bit controls.
        yield 'lone continuation byte' => ["x\xa5y"];
    }

    #[DataProvider('malformed')]
    public function testClusterWalkReproducesMalformedInputByteForByte(string $in): void
    {
        $toast = Toast::new(50);
        $out = '';
        $i = 0;
        $len = \strlen($in);
        while ($i < $len) {
            $cluster = $this->cutCluster($toast, $in, $i);
            $this->assertNotSame('', $cluster, 'a cluster cut must never be empty');
            $this->assertGreaterThan($i, $i + \strlen($cluster), 'the walk must always advance');
            $out .= $cluster;
            $i += \strlen($cluster);
        }

        $this->assertSame($in, $out);
    }

    public function testBrokenSequenceDoesNotSwallowFollowingAscii(): void
    {
        // `\xe2` claims 3 bytes, but `A` and `B` are not continuations: the
        // cut must be the lone stray byte, leaving both ASCII cells visible.
        $this->assertSame("\xe2", $this->cutCluster(Toast::new(50), "\xe2AB", 0));
        $this->assertSame('A', $this->cutCluster(Toast::new(50), "\xe2AB", 1));
    }

    public function testStrayLeadByteYieldsItselfNotTheNextCluster(): void
    {
        // Pre-guard, ICU answered `"b"` at index 3 of "aaa\xffb" — the walk
        // duplicated 'b' and dropped the bad byte.
        $this->assertSame("\xff", $this->cutCluster(Toast::new(50), "aaa\xffb", 3));
    }

    private function cutCluster(Toast $toast, string $s, int $i): string
    {
        $meth = (new \ReflectionClass($toast))->getMethod('nextCluster');
        $meth->setAccessible(true);
        return $meth->invoke($toast, $s, $i);
    }
}
