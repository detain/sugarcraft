<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\Settings\SessionSettings;
use SugarCraft\Code\Config\Settings\UiSettings;
use SugarCraft\Code\Renderer;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Theme;
use SugarCraft\Core\I18n\T;

/**
 * M-12: the settled diff render is a memo, not a per-frame walk.
 *
 * While a tool row's diff is expanded, renderToolResults() re-enters
 * renderDiff() on EVERY painted frame (30fps idle ticks included) for a
 * diff string that cannot change — a settled tool result is immutable.
 * The render costs a flat ~11 ms per call because its work is setup
 * (gutter/header/language walks plus per-row scrub, truncate and box),
 * not proportional to the row count.
 *
 * These pins are structural counters — cache membership, key census,
 * eviction order — and never wall-clock timings (E646: CI flake
 * discipline). They prove the second paint of one settled diff reads
 * the memo (a poisoned entry answers instead of a re-walk), that every
 * ambient state renderDiff() reads is folded into the memo key, and
 * that swapping the theme invalidates the memo without changing one
 * output byte. The source-shape census pins the ambient whitelist
 * itself: a future DIRECT ambient read inside renderDiff() that is
 * neither in the key nor in the documented-pure list fails here. The
 * census scans renderDiff()'s direct body with comments stripped —
 * its callees were audited ambient-free by hand, not pinned here.
 */
final class SettledDiffMemoTest extends TestCase
{
    use HomeSandboxTrait;

    /** Value written INTO the memo to prove a repaint reads it back. */
    private const SENTINEL = 'M12-SENTINEL';

    /** A small settled unified diff — settled is what the memo serves. */
    private const DIFF_A = <<<'DIFF'
        --- a/alpha.txt
        +++ b/alpha.txt
        @@ -1,3 +1,3 @@
         context one
        -removed line
        +added line
         context two
        DIFF;

    /** A different settled diff — same shape, new content. */
    private const DIFF_B = <<<'DIFF'
        --- a/beta.txt
        +++ b/beta.txt
        @@ -1,3 +1,3 @@
         context three
        -removed other
        +added other
         context four
        DIFF;

    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/crush-m12-' . bin2hex(random_bytes(6));
        mkdir($this->home . '/.candy-code', 0o700, true);
        $this->useHomeSandbox($this->home);
        SessionSettings::reset();
        UiSettings::forget();
        self::clearMemo();
    }

    protected function tearDown(): void
    {
        SessionSettings::reset();
        UiSettings::forget();
        Bootstrap::useConfigPath(null);
        $this->restoreHomeSandbox();
        self::removeTree($this->home);
        T::setLocale('en');
        self::clearMemo();
    }

    public function testTheFirstPaintFillsTheMemoExactlyOneEntry(): void
    {
        self::paint(self::DIFF_A, Theme::default(), 40);

        self::assertCount(1, self::memo(), 'the settled render is stored once under its ambient key');
    }

    public function testTheSecondPaintOfTheSameSettledDiffIsServedFromTheMemo(): void
    {
        self::paint(self::DIFF_A, Theme::default(), 40);

        // Poison the one entry. If the repaint re-walked the diff it would
        // paint fresh bytes and this assert would fail; only a cache hit
        // can answer with the sentinel.
        $keys = array_keys(self::memo());
        self::setMemo([$keys[0] => self::SENTINEL]);

        self::assertSame(self::SENTINEL, self::paint(self::DIFF_A, Theme::default(), 40));
    }

    public function testTheWarmRenderIsByteIdenticalToTheColdOne(): void
    {
        $cold = self::paint(self::DIFF_A, Theme::default(), 40);
        self::clearMemo();
        $warm = self::paint(self::DIFF_A, Theme::default(), 40);

        self::assertSame($cold, $warm, 'the memo must serve the very bytes the walk paints');
    }

    public function testADiffContentChangeStartsANewEntry(): void
    {
        self::paint(self::DIFF_A, Theme::default(), 40);
        self::paint(self::DIFF_B, Theme::default(), 40);

        self::assertCount(2, self::memo(), 'a different settled diff is a different key');
    }

    public function testAWidthChangeStartsANewEntry(): void
    {
        self::paint(self::DIFF_A, Theme::default(), 40);
        self::paint(self::DIFF_A, Theme::default(), 62);

        self::assertCount(2, self::memo(), 'width is folded into the key — a resize must not serve stale rows');
    }

    public function testAStyleSettingChangeStartsANewEntry(): void
    {
        self::paint(self::DIFF_A, Theme::default(), 40);
        self::writeSettings(['diffStyle' => 'split']);
        self::paint(self::DIFF_A, Theme::default(), 40);

        self::assertCount(2, self::memo(), 'the split/unified setting is ambient state — it must bust the key');
    }

    public function testARowCapSettingChangeStartsANewEntry(): void
    {
        self::paint(self::DIFF_A, Theme::default(), 40);
        self::writeSettings(['diffPreviewRows' => 4]);
        self::paint(self::DIFF_A, Theme::default(), 40);

        self::assertCount(2, self::memo(), 'the preview row cap changes the trailer row — it must bust the key');
    }

    public function testAScreenReaderModeChangeStartsANewEntry(): void
    {
        self::paint(self::DIFF_A, Theme::default(), 40);
        self::writeSettings(['screenReader' => true]);
        self::paint(self::DIFF_A, Theme::default(), 40);

        self::assertCount(2, self::memo(), 'the screen-reader border glyph is ambient state — it must bust the key');
    }

    public function testALocaleChangeStartsANewEntry(): void
    {
        self::paint(self::DIFF_A, Theme::default(), 40);
        T::setLocale('de');
        self::paint(self::DIFF_A, Theme::default(), 40);

        self::assertCount(2, self::memo(), 'the overflow trailer is translated — locale is ambient state');
    }

    public function testAThemeSwapEmptiesTheMemoAndStillPaintsIdenticalBytes(): void
    {
        $dark = self::paint(self::DIFF_A, Theme::default(), 40);
        self::paint(self::DIFF_B, Theme::default(), 40);
        self::assertCount(2, self::memo());

        $light = self::paint(self::DIFF_A, Theme::byName('light'), 40);
        self::assertCount(1, self::memo(), 'a theme switch costs one cold frame: the whole memo drops');

        self::assertSame($dark, self::paint(self::DIFF_A, Theme::default(), 40), 'the dark bytes are unchanged after the reset round-trip');
        self::assertNotSame($dark, $light, 'the light frame is its own render (and its own memo era)');
    }

    public function testAHitMovesTheEntryToTheYoungestEndOfTheMemo(): void
    {
        self::paint(self::DIFF_A, Theme::default(), 40);
        self::paint(self::DIFF_B, Theme::default(), 40);
        $aKey = self::memoKeys()[0];
        $bKey = self::memoKeys()[1];

        self::paint(self::DIFF_A, Theme::default(), 40);
        $order = array_keys(self::memo());
        self::assertSame([$bKey, $aKey], $order, 'a hit re-inserts at the youngest end');
    }

    public function testTheMemoEvictsTheOldestEntryBeyondItsCap(): void
    {
        $cap = self::diffMemoMax();

        // Paint f0 FIRST and capture its real memo key through the same
        // memoKeys() window the hit-order test reads. A literal label can
        // never appear among the width:strlen:xxh128:... keys, so only the
        // captured key makes the eviction an identity claim.
        self::paint("--- a/f0\n+++ b/f0\n@@ -1 +1 @@\n-x\n+y\n", Theme::default(), 40);
        $f0Key = self::memoKeys()[0];

        for ($i = 1; $i <= $cap; $i++) {
            self::paint("--- a/f{$i}\n+++ b/f{$i}\n@@ -1 +1 @@\n-x\n+y\n", Theme::default(), 40);
        }

        self::assertCount($cap, self::memo(), 'one store past the cap drops exactly one entry');
        self::assertNotContains($f0Key, self::memoKeys(), 'the entry dropped past the cap is the OLDEST one, by identity');

        // The evicted entry is cold again: repainting f0 must land it back
        // under the very same key (and push the next-oldest out in exchange).
        self::paint("--- a/f0\n+++ b/f0\n@@ -1 +1 @@\n-x\n+y\n", Theme::default(), 40);
        self::assertCount($cap, self::memo());
        self::assertContains($f0Key, self::memoKeys(), 'the repaint of f0 re-enters the memo under its original key');
    }

    public function testTheMemoKeyCensusCoversEveryAmbientReadOfRenderDiff(): void
    {
        // Source-shape census: renderDiff()'s DIRECT body must read EXACTLY
        // the ambient state the key folds. An un-whitelisted ambient call
        // inside it would serve stale bytes after a setting change — this
        // fails first. The scan runs on the COMMENT-STRIPPED body: a future
        // edit that deletes a reader call while leaving prose that names it
        // cannot false-green here.
        $method = new ReflectionMethod(Renderer::class, 'renderDiff');
        $lines = file($method->getFileName());
        self::assertIsArray($lines);
        // The open-tag prefix is load-bearing: without it token_get_all() hands
        // back a single T_INLINE_HTML blob and comments are never stripped.
        $body = self::codeWithoutComments("<?php\n" . implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        )));

        // The key must fold every reader renderDiff can observe ambient:
        $keyReaders = [
            'self::diffStyle()',
            'self::diffPreviewRows()',
            'ScreenReaderMode::enabled()',
            'T::locale()',
        ];
        foreach ($keyReaders as $reader) {
            self::assertStringContainsString($reader, $body, $reader . ' belongs to the ambient key');
        }

        // Every direct ambient entry point renderDiff may read is a key reader.
        // Settings::value / getenv / Tty / size reads outside those four would
        // be the stale-serve class M-12 cannot ship with.
        foreach (['UiSettings::', 'Settings::value(', 'getenv(', 'filter_var(', '$_SERVER[', '$_ENV[', 'Tty::'] as $outlaw) {
            self::assertStringNotContainsString($outlaw, $body, $outlaw . ' is not part of the settled-diff key — fold it in or invalidate on its change');
        }
    }

    public function testUntrustedContentIsStoredRenderedNotRaw(): void
    {
        $hostile = "--- a/x\n+++ b/x\n@@ -1 +1 @@\n-\e[31mevil\e[0m\n+safe\n";
        $paint = self::paint($hostile, Theme::default(), 40);

        // The per-row scrub strips the raw SGR before any memo store: neither
        // the raw payload nor an unscrubbed repeat may persist in the entry.
        self::assertStringNotContainsString("\e[31m", $paint);
        $entries = self::memo();
        self::assertCount(1, $entries);
        self::assertStringNotContainsString("\e[31m", (string) reset($entries));
    }

    /**
     * @return array<string,string>
     */
    private static function memo(): array
    {
        $property = new ReflectionProperty(Renderer::class, 'diffMemo');

        return (array) $property->getValue();
    }

    /**
     * @return list<string>
     */
    private static function memoKeys(): array
    {
        return array_keys(self::memo());
    }

    /**
     * @param array<string,string> $entries
     */
    private static function setMemo(array $entries): void
    {
        $property = new ReflectionProperty(Renderer::class, 'diffMemo');
        $property->setValue(null, $entries);
    }

    private static function clearMemo(): void
    {
        self::setMemo([]);
        $theme = new ReflectionProperty(Renderer::class, 'diffMemoTheme');
        $theme->setValue(null, null);
    }

    private static function diffMemoMax(): int
    {
        $constant = (new \ReflectionClass(Renderer::class))->getReflectionConstant('DIFF_MEMO_MAX');

        return (int) $constant->getValue();
    }

    /**
     * House-canonical comment stripper (byte-identical twin of the four in
     * HookLogConcurrentAppendTest, AuditHookConcurrentAppendTest,
     * BinCandyCodeWiringTest and ForeignAgentPresetWiringTest): the memo-key
     * census must match CODE only, so prose that merely names a reader can
     * never carry a contains-leg across a call-site deletion.
     */
    private static function codeWithoutComments(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (\is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }
            $code .= $token;
        }

        return $code;
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            @unlink($dir);

            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($dir . '/' . $entry);
            }
        }
        @rmdir($dir);
    }

    /**
     * @param array<string,mixed> $settings
     */
    private function writeSettings(array $settings): void
    {
        file_put_contents(
            $this->home . '/.candy-code/config.json',
            (string) json_encode($settings),
        );
        UiSettings::forget();
    }

    private function paint(string $diff, Theme $theme, int $width): string
    {
        $method = new ReflectionMethod(Renderer::class, 'renderDiff');
        $method->setAccessible(true);

        return (string) $method->invoke(null, $diff, $theme, $width);
    }
}
