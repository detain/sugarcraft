<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\CodeShot\SvgRenderer;

/**
 * candy-codeshot-048 (cs-27): the font-embed cap must bind the READ, not
 * just the stat. The old gate was `filesize() <= 32 MiB` followed by an
 * unbounded `file_get_contents` — a stat-time check on a mutable filesystem
 * (swap the file after the stat) or on a lying one (`/dev/zero` stats 0 and
 * streams forever under --unsafe-paths). The renderer now demands a regular
 * file, reads at most the cap plus one byte, and reconciles the byte count
 * against the stat.
 */
final class ConfinedIoTest extends TestCase
{
    /**
     * Test-only stream wrapper whose url_stat LIES: it declares a regular
     * file of `$claim` bytes while the stream actually supplies `$supply`.
     * This is the deterministic in-process shape of the swap race — nothing
     * on a real filesystem lets a test win that race on purpose.
     */
    public static int $claim = 0;
    public static int $supply = 0;
    public static int $bytesRead = 0;

    /**
     * @internal registered as stream protocol "liar"
     */
    public static function registerLiar(): void
    {
        if (!in_array('liar', stream_get_wrappers(), true)) {
            stream_wrapper_register('liar', LiarFontWrapper::class);
        }
    }

    private function rendererFor(string $path): SvgRenderer
    {
        // Direct construction: withFont() carries its own file_exists gate and
        // the constructor's public readonly $fontPath is the trust boundary
        // the render-time checks must survive.
        return new SvgRenderer(
            window: false,
            shadow: false,
            border: false,
            fontPath: $path,
        );
    }

    public function testDeviceFontPathLikeZeroIsRefusedBeforeAnyRead(): void
    {
        if (!is_file('/dev/zero') && !file_exists('/dev/zero')) {
            $this->markTestSkipped('no character-device /dev/zero on this platform');
        }
        // /dev/zero exists and passes the withFont-era file_exists door but is
        // NOT a regular file: the is_file gate must refuse before fopen.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not a regular file/');
        $this->rendererFor('/dev/zero')->render("x\n");
    }

    public function testFifoFontPathIsRefusedInsteadOfBlockingForever(): void
    {
        if (!function_exists('posix_mkfifo')) {
            $this->markTestSkipped('posix_mkfifo unavailable');
        }
        $fifo = sys_get_temp_dir() . '/cs27-' . bin2hex(random_bytes(6)) . '.fifo';
        if (!posix_mkfifo($fifo, 0600)) {
            $this->markTestSkipped('could not create FIFO (named pipes not permitted here)');
        }
        try {
            // No writer is ever attached: an unguarded read would block the
            // suite forever, which is the -048/-049 shape this row closes.
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/not a regular file/');
            $this->rendererFor($fifo)->render("x\n");
        } finally {
            unlink($fifo);
        }
    }

    public function testStatLyingFontIsRefusedByTheBoundedReconciliation(): void
    {
        self::registerLiar();
        self::$claim  = 4;   // url_stat size the gate sees
        self::$supply = 10;  // bytes the stream actually yields
        self::$bytesRead = 0;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/changed while being embedded/');
        $this->rendererFor('liar://font')->render("x\n");
    }

    public function testEndlessFontSpikeIsCappedAtTheEmbedLimit(): void
    {
        self::registerLiar();
        self::$claim  = 1;
        self::$supply = 64 * 1024 * 1024; // /dev/zero analogue: regular-statted, effectively endless
        self::$bytesRead = 0;

        try {
            $this->rendererFor('liar://font')->render("x\n");
            $this->fail('an endless-supply font must not embed');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/changed while being embedded/', $e->getMessage());
        }
        // The finding's core claim: the base64 spike is capped even under a
        // lying size. stream_get_contents hard-caps the RETURNED string at
        // cap+1; the engine may request one final chunk-sized read past that
        // boundary before noticing, so the supply counter is bounded at
        // cap+1+slack — while the old unbounded read would have consumed the
        // whole 64 MiB supply and base64'd it.
        $this->assertLessThanOrEqual(
            SvgRenderer::MAX_EMBEDDED_FONT_BYTES + 1 + 16384,
            self::$bytesRead,
            'bounded read must stop at the embed cap plus engine chunk slack',
        );
    }

    public function testHonestWrapperFontStillEmbedsAfterTheNewDoors(): void
    {
        self::registerLiar();
        self::$claim  = 10;
        self::$supply = 10;
        self::$bytesRead = 0;

        $svg = $this->rendererFor('liar://font')->render("x\n");

        // Polarity: the reconciliation must not jail honest sources.
        $this->assertStringContainsString('data:font/ttf;base64,', $svg);
        $this->assertSame(10, self::$bytesRead);
    }
}

/**
 * Stream wrapper backing ConfinedIoTest. Public methods match the
 * php_user_filter contract (untyped by design — the engine calls these
 * internally and the lib's no-naked-internal-visibility roster rule
 * (candy-code era precedent) keeps in-repo helpers scannable).
 */
final class LiarFontWrapper
{
    /** @var resource|null */
    public $context;

    private int $position = 0;

    /** @return array<string,int> */
    public function url_stat(string $path, int $flags): array
    {
        return ['size' => ConfinedIoTest::$claim, 'mode' => 0100644];
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        ConfinedIoTest::$bytesRead = 0;
        $this->position = 0;
        return true;
    }

    public function stream_read(int $count): string
    {
        $remaining = ConfinedIoTest::$supply - $this->position;
        if ($remaining <= 0) {
            return '';
        }
        $take = min($count, $remaining);
        $this->position += $take;
        ConfinedIoTest::$bytesRead += $take;
        return str_repeat('A', $take);
    }

    /** @return array<string,int> */
    public function stream_stat(): array
    {
        return ['size' => max(0, ConfinedIoTest::$supply - $this->position), 'mode' => 0100644];
    }

    public function stream_eof(): bool
    {
        return $this->position >= ConfinedIoTest::$supply;
    }
}
