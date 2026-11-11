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
 * M-9: the settled tool-row body leg is a memo, not a per-frame sweep.
 *
 * While a tool row is expanded (or keeps its whole body collapsed, e.g.
 * errors and image rows), renderToolResults() re-entered the scrub +
 * collapse/expand walk on EVERY painted frame for a body that cannot
 * change — a settled tool result is immutable. The review measured the
 * re-scrub + re-collapse of 1 MiB bodies at 128.8 ms per frame pass.
 *
 * The collapsed-hidden arm was already memoized by hiddenBodyLineCount()
 * and the diff arm by renderDiff()'s M-12 memo; settledToolBody() closes
 * the last per-frame full-body sweep between them. These pins mirror the
 * SettledDiffMemoTest house shape: structural counters — cache
 * membership, key census, eviction order — never wall-clock timings
 * (E646: CI flake discipline). They prove the second paint of one
 * settled body reads the memo (a poisoned entry answers instead of a
 * re-walk), that every ambient state settledToolBody() reads is folded
 * into the memo key, and that swapping the theme invalidates the memo
 * without changing one output byte. The census scans settledToolBody()'s
 * direct body with comments stripped — its callees were audited
 * ambient-free by hand, not pinned here.
 */
final class SettledToolBodyMemoTest extends TestCase
{
    use HomeSandboxTrait;

    /** Value written INTO the memo to prove a repaint reads it back. */
    private const SENTINEL = 'M9-SENTINEL';

    /** A short settled body — expanded is the arm this memo exists for. */
    private const BODY_A = "alpha line\nbeta line\ngamma line\n";

    /** A different settled body — same shape, new content. */
    private const BODY_B = "delta line\nepsilon line\n";

    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/crush-m9-' . bin2hex(random_bytes(6));
        mkdir($this->home . '/.candy-code', 0o700, true);
        $this->useHomeSandbox($this->home);
        SessionSettings::reset();
        UiSettings::forget();
        self::resetBodyMemo();
    }

    protected function tearDown(): void
    {
        SessionSettings::reset();
        UiSettings::forget();
        Bootstrap::useConfigPath(null);
        $this->restoreHomeSandbox();
        self::removeTree($this->home);
        T::setLocale('en');
        self::resetBodyMemo();
    }

    public function testTheFirstPaintFillsTheMemoExactlyOneEntry(): void
    {
        self::paint(self::BODY_A, true, true, Theme::default());

        self::assertCount(1, self::bodyMemo(), 'the settled body render is stored once under its ambient key');
    }

    public function testTheSecondPaintOfTheSameSettledBodyIsServedFromTheMemo(): void
    {
        self::paint(self::BODY_A, true, true, Theme::default());

        // Poison the one entry. If the repaint re-scrubbed and re-collapsed
        // the body it would paint fresh bytes and this assert would fail;
        // only a cache hit can answer with the sentinel.
        $keys = array_keys(self::bodyMemo());
        self::storeBodyMemo([$keys[0] => self::SENTINEL]);

        self::assertSame(self::SENTINEL, self::paint(self::BODY_A, true, true, Theme::default()));
    }

    public function testTheWarmRenderIsByteIdenticalToTheColdOne(): void
    {
        $cold = self::paint(self::BODY_A, true, true, Theme::default());
        self::resetBodyMemo();
        $warm = self::paint(self::BODY_A, true, true, Theme::default());

        self::assertSame($cold, $warm, 'the memo must serve the very bytes the walk paints');
    }

    public function testAnEmptyBodyIsStoredEmptyInsteadOfSkipped(): void
    {
        self::paint('', true, true, Theme::default());

        // The call site's "if ($body !== '')" skip stays byte-exact: the memo
        // stores the empty answer, so the scrub of an empty settled body is
        // skipped on repaints too, never re-attempted per frame.
        $entries = self::bodyMemo();
        self::assertCount(1, $entries);
        self::assertSame('', (string) reset($entries), 'the empty body is memoized as empty');
        self::assertSame('', self::paint('', true, true, Theme::default()));
    }

    public function testABodyContentChangeStartsANewEntry(): void
    {
        self::paint(self::BODY_A, true, true, Theme::default());
        self::paint(self::BODY_B, true, true, Theme::default());

        self::assertCount(2, self::bodyMemo(), 'a different settled body is a different key');
    }

    public function testTheExpandedFlagStartsANewEntry(): void
    {
        self::paint(self::BODY_A, true, true, Theme::default());
        self::paint(self::BODY_A, true, false, Theme::default());

        self::assertCount(2, self::bodyMemo(), 'expanded and collapsed answers differ — the flag must bust the key');
    }

    public function testTheKeepCollapsedFlagStartsANewEntry(): void
    {
        self::paint(self::BODY_A, true, false, Theme::default());
        self::paint(self::BODY_A, false, false, Theme::default());

        self::assertCount(2, self::bodyMemo(), 'keep-whole and hidden-count arms differ — the flag must bust the key');
    }

    public function testAPreviewLineSettingChangeStartsANewEntry(): void
    {
        self::paint(self::BODY_A, true, false, Theme::default());
        self::writeSettings(['toolOutputPreviewLines' => 3]);
        self::paint(self::BODY_A, true, false, Theme::default());

        self::assertCount(2, self::bodyMemo(), 'the preview line budget is ambient state — it must bust the key');
    }

    public function testALocaleChangeStartsANewEntry(): void
    {
        self::paint(self::BODY_A, true, true, Theme::default());
        T::setLocale('de');
        self::paint(self::BODY_A, true, true, Theme::default());

        self::assertCount(2, self::bodyMemo(), 'the truncated trailer is translated — locale is ambient state');
    }

    public function testAThemeSwapEmptiesTheMemoAndStillPaintsIdenticalBytes(): void
    {
        $dark = self::paint(self::overflowBody(), true, false, Theme::default());
        self::paint(self::BODY_A, true, true, Theme::default());
        self::assertCount(2, self::bodyMemo());

        $light = self::paint(self::overflowBody(), true, false, Theme::byName('light'));
        self::assertCount(1, self::bodyMemo(), 'a theme switch costs one cold frame: the whole memo drops');

        self::assertSame($dark, self::paint(self::overflowBody(), true, false, Theme::default()), 'the dark bytes are unchanged after the reset round-trip');
        self::assertNotSame($dark, $light, 'the overflow trailer carries theme bytes, so the light era is its own render');
    }

    public function testAHitMovesTheEntryToTheYoungestEndOfTheMemo(): void
    {
        self::paint(self::BODY_A, true, true, Theme::default());
        self::paint(self::BODY_B, true, true, Theme::default());
        $aKey = self::bodyMemoKeys()[0];
        $bKey = self::bodyMemoKeys()[1];

        self::paint(self::BODY_A, true, true, Theme::default());
        $order = array_keys(self::bodyMemo());
        self::assertSame([$bKey, $aKey], $order, 'a hit re-inserts at the youngest end');
    }

    public function testTheMemoEvictsTheOldestEntryBeyondItsCap(): void
    {
        $cap = self::toolBodyMemoMax();

        // Paint f0 FIRST and capture its real memo key through the same
        // memoKeys() window the hit-order test reads. A literal label can
        // never appear among the strlen:xxh128:... keys, so only the
        // captured key makes the eviction an identity claim.
        self::paint("settled body zero\nsecond line\n", true, true, Theme::default());
        $f0Key = self::bodyMemoKeys()[0];

        for ($i = 1; $i <= $cap; $i++) {
            self::paint("settled body {$i}\nsecond line\n", true, true, Theme::default());
        }

        self::assertCount($cap, self::bodyMemo(), 'one store past the cap drops exactly one entry');
        self::assertNotContains($f0Key, self::bodyMemoKeys(), 'the entry dropped past the cap is the OLDEST one, by identity');

        // The evicted entry is cold again: repainting f0 must land it back
        // under the very same key (and push the next-oldest out in exchange).
        self::paint("settled body zero\nsecond line\n", true, true, Theme::default());
        self::assertCount($cap, self::bodyMemo());
        self::assertContains($f0Key, self::bodyMemoKeys(), 'the repaint of f0 re-enters the memo under its original key');
    }

    public function testTheMemoKeyCensusCoversEveryAmbientReadOfSettledToolBody(): void
    {
        // Source-shape census: settledToolBody()'s DIRECT body must read
        // EXACTLY the state the key folds plus the two work legs. An
        // un-whitelisted ambient call inside it would serve stale bytes
        // after a setting change — this fails first. The scan runs on the
        // COMMENT-STRIPPED body: a future edit that deletes a reader call
        // while leaving prose that names it cannot false-green here.
        $method = new ReflectionMethod(Renderer::class, 'settledToolBody');
        $lines = file($method->getFileName());
        self::assertIsArray($lines);
        // The open-tag prefix is load-bearing: without it token_get_all() hands
        // back a single T_INLINE_HTML blob and comments are never stripped.
        $body = self::codeWithoutComments("<?php\n" . implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        )));

        // The key must fold every reader settledToolBody can observe ambient,
        // and the memo value must come from the two documented work legs.
        $keyReaders = [
            'self::toolOutputPreviewLines()',
            'self::TOOL_OUTPUT_MAX_CHARS',
            'T::locale()',
            'self::untrusted(',
            'self::renderToolBody(',
        ];
        foreach ($keyReaders as $reader) {
            self::assertStringContainsString($reader, $body, $reader . ' belongs to the settled-body key or its work legs');
        }

        // Every direct ambient entry point settledToolBody may read is a key
        // reader. Settings::value / getenv / Tty / screen-reader reads outside
        // those would be the stale-serve class M-9 cannot ship with.
        foreach (['UiSettings::', 'Settings::value(', 'getenv(', 'filter_var(', '$_SERVER[', '$_ENV[', 'Tty::', 'ScreenReaderMode::'] as $outlaw) {
            self::assertStringNotContainsString($outlaw, $body, $outlaw . ' is not part of the settled-body key — fold it in or invalidate on its change');
        }
    }

    public function testUntrustedContentIsStoredRenderedNotRaw(): void
    {
        $hostile = "\e[31mevil\e[0m\nsafe line\n";
        $paint = self::paint($hostile, true, true, Theme::default());

        // The scrub runs before any memo store: neither the raw payload nor
        // an unscrubbed repeat may persist in the entry.
        self::assertStringNotContainsString("\e[31m", $paint);
        $entries = self::bodyMemo();
        self::assertCount(1, $entries);
        self::assertStringNotContainsString("\e[31m", (string) reset($entries));
    }

    /**
     * @return array<string,string>
     */
    private static function bodyMemo(): array
    {
        $property = new ReflectionProperty(Renderer::class, 'toolBodyMemo');

        return (array) $property->getValue();
    }

    /**
     * @return list<string>
     */
    private static function bodyMemoKeys(): array
    {
        return array_keys(self::bodyMemo());
    }

    /**
     * @param array<string,string> $entries
     */
    private static function storeBodyMemo(array $entries): void
    {
        $property = new ReflectionProperty(Renderer::class, 'toolBodyMemo');
        $property->setValue(null, $entries);
    }

    private static function resetBodyMemo(): void
    {
        self::storeBodyMemo([]);
        $theme = new ReflectionProperty(Renderer::class, 'toolBodyMemoTheme');
        $theme->setValue(null, null);
    }

    private static function toolBodyMemoMax(): int
    {
        $constant = (new \ReflectionClass(Renderer::class))->getReflectionConstant('TOOL_BODY_MEMO_MAX');

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

    /**
     * Past TOOL_OUTPUT_MAX_CHARS (15 * 200 = 3000 chars over the 2000
     * budget): the keep-collapsed arm paints the overflow trailer, and the
     * trailer is the only part of a body render that carries theme bytes.
     */
    private static function overflowBody(): string
    {
        return str_repeat("line of output\n", 200);
    }

    private function paint(string $rawBody, bool $keepWhenCollapsed, bool $isExpanded, Theme $theme): string
    {
        $method = new ReflectionMethod(Renderer::class, 'settledToolBody');
        $method->setAccessible(true);

        return (string) $method->invoke(null, $rawBody, $keepWhenCollapsed, $isExpanded, $theme);
    }
}
