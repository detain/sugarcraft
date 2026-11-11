<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui\DiffReview;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use SugarCraft\Code\Tui\DiffReview\DiffReview;
use SugarCraft\Code\Tui\Renderer;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Syntax\RegexHighlighter;
use SugarCraft\Core\Util\Sanitize;

/**
 * F-m13d (rollup M-13): the review-pane sweep.
 *
 * Before this lane, {@see Renderer::renderDiffReview()} re-scrubbed and
 * re-scanned the ENTIRE selected patch on every painted frame while the diff
 * pane was open — measured 2.5-30.4 ms per frame at 548-6,600 diff lines,
 * with the Sanitize sweep alone about 60% of it. The sweep now rides the M12
 * house memo (the settled-renderDiff shared-process contract,
 * \SugarCraft\Code\Renderer::$diffMemo): scrubbed lines, gutter labels,
 * header flags and per-row languages are pure functions of the raw review
 * content, so they are keyed by exactly those bytes — and the highlighter
 * tokenizer instance (and its internal pattern/token caches) is hoisted out
 * of the per-frame rebuild.
 *
 * The pins are STRUCTURAL counters over the memo static, never wall-clock
 * elapsed time (standing E646 ban): cache membership, sentinel-served hits,
 * eviction order, and a source-shape census proving no ambient read ever
 * enters the key or the sweep. The warm/cold equality pins guarantee the
 * memo cannot change painted bytes.
 */
final class SettledDiffReviewMemoTest extends TestCase
{
    /** Marker poisoned into the memo to prove a later sweep rides the entry. */
    private const SENTINEL = 'M13D-SENTINEL';

    protected function setUp(): void {
        self::clearMemo();
    }

    protected function tearDown(): void {
        self::clearMemo();
    }

    public function testTheFirstSweepFillsTheMemoExactlyOneEntry(): void {
        $artifacts = self::sweep(self::reviewWithPatch('src/paypal.php', self::patch()));

        self::assertCount(1, self::memo());
        self::assertNotSame([], $artifacts['lines']);
    }

    public function testTheSecondSweepOfUnchangedContentIsServedFromTheMemo(): void {
        $view = self::reviewWithPatch('src/paypal.php', self::patch());

        // Cold sweep records the single content key...
        self::sweep($view);
        $key = (string) \array_key_first(self::memo());

        // ...poison that entry, and the next sweep must return the poison.
        // Serving it proves the hit path ran — no timers, no wall-clock.
        self::setMemo([$key => ['labels' => [self::SENTINEL], 'lines' => [], 'headers' => [], 'languages' => []]]);

        $served = self::sweep($view);
        self::assertSame([self::SENTINEL], $served['labels']);
    }

    public function testTheWarmSweepIsByteIdenticalToTheColdOne(): void {
        $cold = self::sweep(self::reviewWithPatch('src/paypal.php', self::patch()));

        self::clearMemo();
        $warm = self::sweep(self::reviewWithPatch('src/paypal.php', self::patch()));

        self::assertSame($cold, $warm);
    }

    public function testARenamedFileNeverRidesTheOldLabelsKey(): void {
        $before = self::sweep(self::reviewWithPatch('src/paypal.php', self::patch()));
        $after = self::sweep(self::reviewWithPatch('src/stripe.php', self::patch()));

        // Same patch bytes, DIFFERENT visible labels — the label channel rides
        // the key, so the renamed sweep can never be served paypal's stale
        // label row; the scrubbed body rides on unchanged and the sweep holds
        // one honest entry per label source.
        self::assertStringContainsString('src/stripe.php', $after['labels'][0]);
        self::assertStringNotContainsString('src/paypal.php', $after['labels'][0]);
        self::assertSame($before['lines'], $after['lines']);
        self::assertCount(2, self::memo());
    }

    public function testScrollingTheSamePatchRidesTheSameEntry(): void {
        $review = self::reviewWithPatch('src/paypal.php', self::bigPatch(5));

        $first = self::sweep($review);

        // Page down (scroll moves to the VO's clamp, the selected file's
        // bytes do not), then back up: two motion frames, ZERO new keys —
        // the per-frame resweep this lane kills.
        $paged = $review->update(new KeyMsg(KeyType::PageDown))[0];
        self::assertGreaterThan(0, $paged->scroll());
        self::assertSame($first, self::sweep($paged));
        self::assertCount(1, self::memo());

        self::assertSame($first, self::sweep($paged->update(new KeyMsg(KeyType::PageUp))[0]));
        self::assertCount(1, self::memo());
    }

    public function testSelectingASecondFileMintsItsOwnKeyAndRidesWarm(): void {
        $review = self::twoFileReview();

        $firstArtifacts = self::sweep($review);
        $firstKey = (string) \array_key_first(self::memo());

        // Move selection to the second file (the j/k motion the hints row
        // offers); a different patch is a different content key.
        $second = $review->update(new KeyMsg(KeyType::Down))[0];
        self::assertSame(1, $second->selectedIndex());
        $secondArtifacts = self::sweep($second);

        self::assertCount(2, self::memo());
        self::assertContains($firstKey, \array_keys(self::memo()));
        self::assertNotSame($firstKey, (string) \array_key_last(self::memo()));

        // Back to file one rides the ORIGINAL entry untouched.
        self::assertSame($firstArtifacts, self::sweep($second->update(new KeyMsg(KeyType::Up))[0]));
        self::assertCount(2, self::memo());
    }

    public function testTheMemoReInsertsAHitAsTheYoungestEntry(): void {
        $bound = self::memoBound();
        $reviews = [];
        for ($i = 0; $i < $bound; ++$i) {
            $reviews[$i] = self::reviewWithPatch('src/fill-' . $i . '.php', self::bigPatch(1));
        }

        foreach ($reviews as $review) {
            self::sweep($review);
        }
        self::assertCount($bound, self::memo());
        $order = \array_keys(self::memo());

        // Touching the FIRST entry must move it to the young end.
        self::sweep($reviews[0]);
        self::assertSame(\array_merge(\array_slice($order, 1), [$order[0]]), \array_keys(self::memo()));
    }

    public function testTheMemoEvictsTheOldestBeyondItsBound(): void {
        $bound = self::memoBound();
        $first = self::reviewWithPatch('src/paypal.php', self::bigPatch(1));
        self::sweep($first);
        $firstKey = (string) \array_key_first(self::memo());

        // Sweep one distinct content per key past the bound.
        for ($i = 0; $i <= $bound; ++$i) {
            self::sweep(self::reviewWithPatch('src/filler-' . $i . '.php', self::bigPatch(2 + $i)));
        }

        self::assertCount($bound, self::memo());
        self::assertArrayNotHasKey($firstKey, self::memo());

        // Re-sweeping the evicted content re-enters the same key — eviction
        // loses warmth, never correctness.
        self::sweep($first);
        self::assertArrayHasKey($firstKey, self::memo());
    }

    public function testUntrustedBytesAreSweptBeforeTheyAreStored(): void
    {
        $hostile = implode("\n", [
            '--- a/src/pawn.php',
            '+++ b/src/pawn.php',
            '@@ -1 +1 @@',
            '+<script>alert(1)</script>',
            "+\x1b[31mred\x1b[0m",
            '+PWN<script>x</script>',
        ]);

        $artifacts = self::sweep(self::reviewWithPatch('src/pawn.php', $hostile));

        // The M-13 contract is scrub-before-store: every stored value is byte-
        // equal to the exact scrub the pre-memo per-frame path applied to the
        // same raw line — never the raw bytes themselves, because every later
        // frame rides that entry. (The scrub strips C0/C1/escape bytes; markup
        // survives by design — display territory this sweep never owned.)
        self::assertSame(
            array_map(
                static fn(string $line): string => Sanitize::stripZoneSentinels(Sanitize::untrustedForDisplay($line)),
                explode("\n", $hostile),
            ),
            $artifacts['lines'],
            'the memo stores the swept form, exactly'
        );
        self::assertStringNotContainsString("\x1b", $artifacts['lines'][4], 'no raw escape survives the sweep');
        self::assertSame('+red', $artifacts['lines'][4]);
    }

    public function testTheSweepReadsNothingAmbient(): void {
        $code = self::methodBodyOfSource('reviewArtifacts');

        // The sweep's whole memo-safety argument: stored values are pure
        // functions of the review bytes. Every ambient the house memo folds
        // into ITS key (theme, width, locale, screen-reader mode) must be
        // ABSENT from this body — and a future direct read of one, without a
        // corresponding key change, must fail here rather than mis-serve a
        // warm frame.
        foreach (['UiSettings::', 'Settings::', 'getenv(', 'filter_var(', '$_SERVER[', '$_ENV[', 'Tty::', 'Theme', 'Width::', 'ScreenReaderMode', 'I18n\\', 'diffStyle', 'stream_isatty'] as $outlaw) {
            self::assertStringNotContainsString($outlaw, $code, "ambient read '{$outlaw}' entered reviewArtifacts(): the memo is content-addressed only, so this is a mis-serve hazard");
        }

        // The key material must stay honest: content-addressed hashing over
        // the raw review bytes, both channels length-prefixed like the house
        // memo, and over the RAW rows — scrubbing the key material itself
        // would let different hostile inputs collide.
        self::assertStringContainsString("hash('xxh128', \$patch)", $code);
        self::assertStringContainsString("hash('xxh128', \$labelSource)", $code);
        self::assertStringContainsString('strlen($patch)', $code);
        self::assertStringContainsString('strlen($labelSource)', $code);

        // Exactly the two scrub sites (labels + lines), reached only on a
        // cold sweep — a third scrub site, or any scrub outside this helper,
        // would mean per-frame work this memo was minted to kill.
        self::assertSame(2, \substr_count($code, 'self::reviewText('));
        self::assertStringNotContainsString('new RegexHighlighter', $code);
    }

    public function testTheHighlighterIsOneSharedInstanceNotAPerFrameRebuild(): void {
        $instance = self::highlighter();
        self::assertInstanceOf(RegexHighlighter::class, $instance);
        self::assertSame($instance, self::highlighter());
    }

    // ------------------------------------------------------------------
    // Fixtures and structural harness
    // ------------------------------------------------------------------

    /** @return array<string,mixed> the memo static */
    private static function memo(): array {
        $property = new ReflectionProperty(Renderer::class, 'reviewMemo');

        /** @var array<string,mixed> $memo */
        $memo = $property->getValue();

        return $memo;
    }

    /** @param array<string,mixed> $memo */
    private static function setMemo(array $memo): void {
        (new ReflectionProperty(Renderer::class, 'reviewMemo'))->setValue(null, $memo);
    }

    private static function clearMemo(): void {
        self::setMemo([]);
    }

    private static function memoBound(): int {
        return (int) (new ReflectionClass(Renderer::class))
            ->getReflectionConstant('REVIEW_MEMO_MAX')
            ->getValue();
    }

    /** Invoke the private sweep directly — the frames it serves are paint. */
    private static function sweep(DiffReview $review): array {
        return (new ReflectionMethod(Renderer::class, 'reviewArtifacts'))->invoke(null, $review);
    }

    private static function highlighter(): RegexHighlighter {
        return (new ReflectionMethod(Renderer::class, 'reviewHighlighter'))->invoke(null);
    }

    /** @param list<array{status:string,path:string,added:int,removed:int,patch:string}> $files */
    private static function plainReview(array $files): DiffReview {
        return DiffReview::open($files, 1, 3, false, 0);
    }

    private static function reviewWithPatch(string $path, string $patch): DiffReview {
        return self::plainReview([self::fileWithPatch($path, $patch)]);
    }

    /** @return array{status:string,path:string,added:int,removed:int,patch:string} */
    private static function fileWithPatch(string $path, string $patch): array {
        return ['status' => 'M', 'path' => $path, 'added' => 3, 'removed' => 1, 'patch' => $patch];
    }

    private static function twoFileReview(): DiffReview {
        return self::plainReview([
            self::fileWithPatch('src/a.php', self::patch()),
            self::fileWithPatch('src/b.php', self::bigPatch(5)),
        ]);
    }

    /** A realistic multi-file patch body (two diff sections). */
    private static function patch(): string {
        return implode("\n", [
            '--- a/src/paypal.php',
            '+++ b/src/paypal.php',
            '@@ -1,5 +1,7 @@',
            ' function charge() {',
            '-    return 1;',
            '+    return 2;',
            '+}',
            'diff --git a/src/stripe.php b/src/stripe.php',
            '--- a/src/stripe.php',
            '+++ b/src/stripe.php',
            '@@ -1,3 +1,4 @@',
            ' class Card {',
            '+    public int $brand = 0;',
            ' }',
        ]);
    }

    /** A deterministic synthetic diff of `repeat` 8-row blocks, > 1 page. */
    private static function bigPatch(int $repeat): string {
        $body = '';
        for ($i = 0; $i < $repeat; ++$i) {
            $body .= implode("\n", [
                '--- a/src/fill-' . $i . '.php',
                '+++ b/src/fill-' . $i . '.php',
                '@@ -1,' . (3 + $i) . ' +1,' . (4 + $i) . ' @@',
                ' line stable ' . $i,
                '-line removed ' . $i,
                '+line added ' . $i,
                '+extra ' . $i,
                ' trailing ' . $i,
            ]) . "\n";
        }

        return $body;
    }

    /**
     * The named method's source body with comments stripped via the token
     * walk — the house census shape documented in SettledDiffMemoTest for
     * the renderDiff ambient scan (byte-faithful stripper, own uniquely
     * named copy so the drift guard sees no divergent twin).
     */
    private static function methodBodyOfSource(string $name): string {
        $reflection = new ReflectionMethod(Renderer::class, $name);
        $file = (string) $reflection->getFileName();
        $start = (int) $reflection->getStartLine();
        $end = (int) $reflection->getEndLine();

        $lines = \explode("\n", (string) \file_get_contents($file));
        $body = \implode("\n", \array_slice($lines, $start - 1, $end - $start + 1));

        // Prefix so the body tokenizes as PHP at all (same trick as the M12
        // census): without it the slice lexes as one T_INLINE_HTML blob and
        // comments would never be stripped.
        $code = '';
        foreach (\token_get_all('<?php' . "\n" . $body) as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= \is_array($token) ? $token[1] : $token;
        }

        self::assertNotSame('', $code, 'the token walk produced nothing — the census would be vacuous');

        return $code;
    }
}
