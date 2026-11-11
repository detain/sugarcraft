<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\SgrState;
use SugarCraft\Core\Util\Parser;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Code\Attachment;
use SugarCraft\Code\AttachmentType;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Role;
use SugarCraft\Code\Message;
use SugarCraft\Code\Renderer;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Theme;
use SugarCraft\Code\ToolResult;
use SugarCraft\Markdown\Renderer as Markdown;

/**
 * Audit 15b-10: a frame must not cost time proportional to the whole
 * session.
 *
 * Every keystroke, token batch and tick repaints, and {@see Renderer} used
 * to re-render every assistant reply through SugarMarkdown on every frame,
 * tokenize every transcript row byte by byte, sanitize every hidden tool
 * body, and re-parse the whole streaming partial. MEASURED before the fix
 * (120x40): 706 ms per frame at 200 exchanges, 2.1 s per frame for a 200 KB
 * streaming partial.
 *
 * The memos that fixed it are only safe if a warm frame is byte-identical to
 * a cold one, so most of this file pins that, against the original algorithm
 * where there was one: a whole-partial render for streaming, and the shared
 * Parser loop for {@see Renderer::balanceSgr()}.
 */
final class RenderMemoTest extends TestCase
{
    use HomeSandboxTrait;

    private const PARA = "## Heading\n\nSome **bold** text and `code` and a list:\n\n- one\n- two\n\n```php\n"
        . "echo \$x;\necho \$y;\n```\n";

    private string $homeSandbox = '';

    protected function setUp(): void
    {
        $this->homeSandbox = $this->useHomeSandbox(
            sys_get_temp_dir() . '/render_memo_home_' . uniqid('', true),
        );
        putenv('CANDY_CODE_DISABLE_MOUSE_CLICKS');
        Renderer::clearZones();
        self::clearMemos();
    }

    protected function tearDown(): void
    {
        self::clearMemos();
        Renderer::clearZones();
        $this->restoreHomeSandbox();
        @rmdir($this->homeSandbox);

        parent::tearDown();
    }

    // =====================================================================
    // Settled history
    // =====================================================================

    public function testAnUnchangedSecondFrameReadsEveryReplyFromTheMemo(): void
    {
        $chat = self::markdownChat(300);
        Renderer::render($chat);

        $memo = new ReflectionProperty(Renderer::class, 'markdownMemo');
        $entries = $memo->getValue();
        self::assertCount(300, $entries, 'one memo entry per distinct reply');

        // Poisoned: if the second frame rendered any reply again instead of
        // reading it back, the newest rows would show SugarMarkdown output, not
        // the marker.
        $i = 0;
        foreach ($entries as $key => $_) {
            $entries[$key] = 'MEMO-HIT-' . $i++;
        }
        $memo->setValue(null, $entries);

        $frame = Renderer::render($chat);
        self::assertStringContainsString('MEMO-HIT-299', $frame);
        self::assertStringNotContainsString('Heading', $frame);
        self::assertCount(300, $memo->getValue(), 'a warm frame renders no reply again');
    }

    public function testAWarmFrameIsMuchCheaperThanAColdOne(): void
    {
        $chat = self::markdownChat(300);

        $start = hrtime(true);
        $cold = Renderer::render($chat);
        $coldNs = hrtime(true) - $start;

        $warmNs = PHP_INT_MAX;
        for ($i = 0; $i < 3; $i++) {
            $start = hrtime(true);
            $warm = Renderer::render($chat);
            $warmNs = min($warmNs, hrtime(true) - $start);
            self::assertSame($cold, $warm);
        }

        // MEASURED on the 8.3 dev host: cold 630 ms, warm 38 ms, a ratio of 16.
        // Before the fix the ratio was 1.1 (1068 ms cold, 965 ms warm). A
        // ratio leaves room for a slow or loaded runner where a fixed
        // millisecond budget would not.
        self::assertLessThan(
            $coldNs / 4,
            $warmNs,
            sprintf('warm frame %.1f ms vs cold %.1f ms', $warmNs / 1e6, $coldNs / 1e6),
        );
    }

    /** @return iterable<string, array{0: string, 1: int, 2: bool}> */
    public static function frameProvider(): iterable
    {
        foreach (['dark', 'light', 'dracula', 'ansi'] as $theme) {
            foreach ([40, 120] as $cols) {
                yield "{$theme} {$cols} collapsed" => [$theme, $cols, false];
                yield "{$theme} {$cols} expanded" => [$theme, $cols, true];
            }
        }
    }

    /** @dataProvider frameProvider */
    public function testAWarmFrameIsByteIdenticalToAColdOne(string $theme, int $cols, bool $expand): void
    {
        $chat = self::mixedChat($cols, 60)->withThemeName($theme);
        if ($expand) {
            $chat = $chat->toggleToolOutput('t1')->toggleToolOutput('t3');
        }

        $cold = Renderer::render($chat);
        $warm = Renderer::render($chat);
        self::assertSame($cold, $warm);

        self::clearMemos();
        self::assertSame($cold, Renderer::render($chat), 'a frame from cleared memos matches');
    }

    public function testAThemeSwitchDoesNotServeTheOldThemesBodies(): void
    {
        $dark = self::mixedChat(100, 40)->withThemeName('dark');
        $dracula = $dark->withThemeName('dracula');

        Renderer::render($dark);
        $afterDark = Renderer::render($dracula);

        self::clearMemos();
        self::assertSame(Renderer::render($dracula), $afterDark);
    }

    public function testAResizeDoesNotServeTheOldWidthsBodies(): void
    {
        $wide = self::mixedChat(140, 40);
        $narrow = $wide->withSize(44, 40);

        Renderer::render($wide);
        $afterWide = Renderer::render($narrow);

        self::clearMemos();
        self::assertSame(Renderer::render($narrow), $afterWide);
    }

    // =====================================================================
    // Hidden tool bodies
    // =====================================================================

    public function testAHiddenToolBodyCountsTheRowsOfItsSanitizedText(): void
    {
        // CRLF is one break and a lone CR becomes one, exactly as
        // Sanitize::untrustedForDisplay() maps them, so this body has 4 rows.
        $chat = (new Chat(history: [
            Message::assistant('')->withToolResults([ToolResult::ok('Bash', "a\r\nb\rc\x1b[31m\nd", 't1')]),
            // Nothing survives the sanitizer, so no hint row at all.
            Message::assistant('')->withToolResults([ToolResult::ok('Bash', "\x1b[2J\x07", 't2')]),
        ]))->withSize(100, 30);

        $frame = Renderer::render($chat);
        self::assertSame(1, substr_count($frame, '4 lines hidden'));
        self::assertSame(1, substr_count($frame, 'lines hidden') + substr_count($frame, 'line hidden'));
        self::assertSame($frame, Renderer::render($chat));

        $memo = (new ReflectionProperty(Renderer::class, 'hiddenBodyMemo'))->getValue();
        self::assertSame([4, 0], array_values($memo));
    }

    // =====================================================================
    // The streaming partial
    // =====================================================================

    public function testAStreamingPartialMatchesAWholeRenderAtEveryPrefix(): void
    {
        $doc = self::streamingCorpus();
        $theme = Theme::byName('dark');

        foreach ([40, 100] as $width) {
            self::clearMemos();
            // Odd step so cuts land mid-line, mid-fence, mid-escape-like text.
            for ($len = 1; $len <= strlen($doc); $len += 7) {
                $partial = substr($doc, 0, $len);
                self::assertSame(
                    self::wholeStreamingRender($partial, $theme, $width),
                    self::streamingTurn($partial, $theme, $width),
                    "width {$width}, prefix {$len}",
                );
            }
            self::assertSame(
                self::wholeStreamingRender($doc, $theme, $width),
                self::streamingTurn($doc, $theme, $width),
            );
        }

        // The incremental path really ran: closed sections were kept.
        $memo = (new ReflectionProperty(Renderer::class, 'streamMemo'))->getValue();
        self::assertIsArray($memo);
        self::assertNotSame('', $memo['bodies']);
    }

    public function testAPartialThatIsNotAContinuationStartsOver(): void
    {
        $theme = Theme::byName('dark');
        $a = self::streamingCorpus();
        $b = "# Another reply\n\nWith *other* text.\n\n## Part two\n\n- x\n";

        self::streamingTurn($a, $theme, 80);
        self::assertSame(self::wholeStreamingRender($b, $theme, 80), self::streamingTurn($b, $theme, 80));
        // A shorter partial (a retry) is not a continuation either.
        $short = substr($a, 0, 50);
        self::assertSame(self::wholeStreamingRender($short, $theme, 80), self::streamingTurn($short, $theme, 80));
        // Neither is the same text under another theme.
        $light = Theme::byName('light');
        self::assertSame(self::wholeStreamingRender($a, $light, 80), self::streamingTurn($a, $light, 80));
    }

    public function testALinkReferenceDefinitionResolvesWithoutAWholeRender(): void
    {
        // A definition applies to the whole document, so the `[foo]` before
        // it must still become a link. Audit 15b-30: sugar-markdown now carries
        // definitions across sections, so the partial is no longer rendered
        // whole to get that right.
        $theme = Theme::byName('dark');
        $partial = "see [foo] now\n\n# H\n\n[foo]: http://example.com/x\n\n# I\n\ntail\n";

        $turn = self::streamingTurn($partial, $theme, 80);
        self::assertSame(self::wholeStreamingRender($partial, $theme, 80), $turn);
        self::assertStringContainsString("\x1b]8;;http://example.com/x", $turn);

        $memo = (new ReflectionProperty(Renderer::class, 'streamMemo'))->getValue();
        self::assertIsArray($memo, 'the incremental path ran');
        self::assertStringContainsString("\x1b]8;;http://example.com/x", $memo['bodies']);
    }

    public function testHeadingsStraightAfterAFenceStillStreamIncrementally(): void
    {
        // Audit 15b-31: PARA puts its next "## Heading" right under the
        // closing fence. That was no section boundary, so this partial was
        // one open tail rendered whole on every frame.
        $theme = Theme::byName('dark');
        $partial = str_repeat(self::PARA, 20);

        self::assertSame(self::wholeStreamingRender($partial, $theme, 80), self::streamingTurn($partial, $theme, 80));

        $memo = (new ReflectionProperty(Renderer::class, 'streamMemo'))->getValue();
        self::assertIsArray($memo);
        self::assertGreaterThan(
            strlen($partial),
            strlen($memo['bodies']),
            'all but the last section are kept rendered',
        );
    }

    public function testAnUnterminatedHeadingKeepsTheTextBeforeIt(): void
    {
        $theme = Theme::byName('dark');
        $partial = "Intro paragraph\n\n# F";

        $turn = self::streamingTurn($partial, $theme, 80);
        self::assertSame(self::wholeStreamingRender($partial, $theme, 80), $turn);
        self::assertStringContainsString('Intro paragraph', $turn);
    }

    public function testTheStreamingFrameStaysTheSameAcrossRepaints(): void
    {
        $mutate = new ReflectionMethod(Chat::class, 'mutate');
        $chat = $mutate->invoke(
            (new Chat(history: [Message::user('go')]))->withSize(100, 40),
            ['inFlight' => true, 'streamingText' => self::streamingCorpus()],
        );

        $first = Renderer::render($chat);
        self::assertSame($first, Renderer::render($chat));
        self::clearMemos();
        self::assertSame($first, Renderer::render($chat));
    }

    /**
     * cl-4 FIX-B: between token batches the 30 fps repaint reaches
     * streamingMarkdown() with an UNCHANGED partial, and the open tail used
     * to be parsed and rendered again on every one of those idle frames
     * (34 ms each at a 32 KB code fence). The preview cache must answer the
     * idle frame from the bytes it already rendered - and must NOT answer a
     * grown one from the stale cache.
     */
    public function testAnIdleRepaintServesTheOpenTailFromThePreviewCache(): void
    {
        $theme = Theme::byName('dark');
        $partial = substr(self::streamingCorpus(), 0, 400);

        $first = self::streamingTurn($partial, $theme, 80);

        $memoProp = new ReflectionProperty(Renderer::class, 'streamMemo');
        $memo = $memoProp->getValue();
        self::assertIsArray($memo);
        self::assertNotNull($memo['preview'], 'a rendered frame caches its tail preview');
        self::assertSame($partial, $memo['preview']['src']);

        // Poisoned: an idle frame that re-rendered the tail instead of reading
        // the cache could not print the marker. The frame's contract is
        // label . "\n" . rtrim(bodies . tail), so that is what the poisoned
        // state must produce - and only from the cache, never a fresh render.
        $bodies = $memo['bodies'];
        $memo['preview']['out'] = 'PREVIEW-HIT';
        $memoProp->setValue(null, $memo);

        $labelLine = substr($first, 0, strpos($first, "\n") + 1);
        self::assertSame(
            $labelLine . rtrim($bodies . 'PREVIEW-HIT'),
            self::streamingTurn($partial, $theme, 80),
        );

        // A grown partial must NOT read the stale cache: byte-identical to a
        // whole render, exactly as an uncached frame would be.
        $grown = substr(self::streamingCorpus(), 0, 900);
        self::assertSame(self::wholeStreamingRender($grown, $theme, 80), self::streamingTurn($grown, $theme, 80));
        $memo = $memoProp->getValue();
        self::assertSame($grown, $memo['preview']['src'], 'the preview was recomputed and re-cached');
    }

    /**
     * The same scaling law the brief asked for (8x content, <=3x time),
     * applied where the memo puts it in force: idle repaint frames of an
     * open-tail reply. Growth frames of a single unclosed fence stay
     * whole-tail renders until SugarMarkdown stops padding every fenced line
     * to the block's widest row - a splice there would not be byte-identical
     * (cl-4 FIX-B probe: appending a longer line REWRITES the earlier rows'
     * padding bytes). min-of-N ratios, the house shape of
     * {@see self::testAWarmFrameIsMuchCheaperThanAColdOne()}.
     */
    public function testIdleRepaintCostStaysBoundedAsTheOpenFenceGrows(): void
    {
        $mutate = new ReflectionMethod(Chat::class, 'mutate');
        $line = "echo \$value; // padded line of code here\n";

        $cost = static function (int $bytes) use ($mutate, $line): float {
            self::clearMemos();
            $doc = substr("```php\n" . str_repeat($line, intdiv($bytes, strlen($line)) + 1), 0, $bytes);
            $chat = $mutate->invoke(
                (new Chat(history: [Message::user('go')]))->withSize(100, 40),
                ['inFlight' => true, 'streamingText' => $doc],
            );
            Renderer::render($chat); // fills the preview cache at this exact partial
            $min = PHP_FLOAT_MAX;
            for ($i = 0; $i < 12; $i++) {
                $start = hrtime(true);
                Renderer::render($chat);
                $min = min($min, (float) (hrtime(true) - $start));
            }

            return $min;
        };

        $small = $cost(2000);
        $large = $cost(16000);

        self::assertLessThan(
            $small * 3.0,
            $large,
            sprintf('idle frame 16 KB %.2f ms vs 2 KB %.2f ms', $large / 1e6, $small / 1e6),
        );
    }

    /**
     * m53 (B-42): the delta reaches the stream in ONE whole-chunk push, so
     * growth jumps whose single delta spans many newlines must still answer
     * byte-identically to a whole render — the shape the deleted per-line
     * preg_split() loop used to slice up before feeding the scanner.
     */
    public function testADeltaSpanningManyNewlinesStillMatchesTheWholeRender(): void
    {
        $doc = self::streamingCorpus();
        $full = strlen($doc);
        $theme = Theme::byName('dark');

        foreach ([40, 80, 100] as $width) {
            self::clearMemos();
            // Jumps, not steps: every delta after the first crosses several
            // newlines, and the last jump carries the document's tail, which
            // has no trailing newline.
            foreach ([intdiv($full, 4), intdiv($full, 2), intdiv(3 * $full, 4), $full] as $len) {
                $partial = substr($doc, 0, $len);
                self::assertSame(
                    self::wholeStreamingRender($partial, $theme, $width),
                    self::streamingTurn($partial, $theme, $width),
                    sprintf('width %d, jump to %d', $width, $len),
                );
            }
        }

        $memo = (new ReflectionProperty(Renderer::class, 'streamMemo'))->getValue();
        self::assertIsArray($memo);
        self::assertSame($doc, $memo['consumed'], 'the whole document arrived through whole-chunk pushes');
    }

    /**
     * m53 (B-42) source-shape pin, in the program's prose-drift genre:
     * streamingMarkdown() must never reintroduce the per-line preg_split()
     * workaround — it predated the linear scanner cursor (sugar-markdown
     * 3574957df, 2026-11-03) and re-slices every delta for no reason.
     */
    public function testStreamingMarkdownPushesEachDeltaWithoutSplittingItPerLine(): void
    {
        $method = new ReflectionMethod(Renderer::class, 'streamingMarkdown');
        $lines = file((string) $method->getFileName());
        self::assertIsArray($lines);
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        self::assertStringNotContainsString('preg_split', $body, 'the stale per-line split must not come back');
        self::assertSame(
            1,
            substr_count($body, "\$memo['stream']->push(\$delta)"),
            'the delta reaches the stream in exactly one whole-chunk push',
        );
    }

    // =====================================================================
    // balanceSgr()
    // =====================================================================

    public function testBalanceSgrMatchesTheSharedParserAlgorithm(): void
    {
        $pieces = [
            'plain text',
            '',
            "\x1b[1mbold opens and stays open",
            'continuation row',
            "\x1b[0m reset",
            "\x1b[38;2;1;2;3mfg\x1b[39m done",
            "\x1b]8;;https://example.com/x\x1b\\link opens",
            "still inside\x1b]8;;\x1b\\ closed",
            "\x1b[4m\x1b[58;5;3munderline colour",
            "\x1b[24m\x1b[59m off",
            "\x1b[3mitalic \x1b[",            // ends inside an unfinished CSI
            '31mred finished on the next row',
            "\x1b]8;;http://cut",            // ends inside an unfinished OSC
            'more url',
            "\x1b\\ terminated",
            "文字 \x1b[7mreverse",
            "\x1b[m",
        ];
        $balance = new ReflectionMethod(Renderer::class, 'balanceSgr');

        mt_srand(15010);
        for ($round = 0; $round < 200; $round++) {
            $rows = [];
            $n = mt_rand(1, 12);
            for ($i = 0; $i < $n; $i++) {
                $rows[] = $pieces[mt_rand(0, count($pieces) - 1)];
            }
            $expected = self::referenceBalanceSgr($rows);
            self::assertSame($expected, $balance->invoke(null, $rows), 'cold, round ' . $round);
            self::assertSame($expected, $balance->invoke(null, $rows), 'warm, round ' . $round);
        }
    }

    // =====================================================================
    // M-10 settled plain rows (user prompts, notices, system lines)
    // =====================================================================

    private static function plainRowChat(int $cols = 120, int $rows = 40): Chat
    {
        return (new Chat(history: [
            Message::user('hello there'),
            Message::assistant('a settled reply'),
            Message::system('a note'),
            Message::notice('a notice'),
        ]))->withSize($cols, $rows);
    }

    public function testASettledPlainRowIsRenderedOnceAndServedFromTheMemo(): void
    {
        $chat = self::plainRowChat();

        $first = Renderer::render($chat);
        self::assertSame($first, Renderer::render($chat), 'a warm frame is byte-identical');

        $memo = new ReflectionProperty(Renderer::class, 'historyBlockMemo');
        $entries = $memo->getValue();
        self::assertCount(3, $entries, 'one entry per plain row; the assistant reply stays out');
        $kinds = array_map(static fn(string $key): string => explode(':', $key)[0], array_keys($entries));
        sort($kinds);
        self::assertSame(['0', '1', '2'], array_values(array_unique($kinds)), 'user, notice and system kinds are each keyed');

        // Poisoned: if the next frame re-rendered any plain row instead of
        // reading it back, its bytes would show the sanitizer's output, not
        // the marker.
        $i = 0;
        foreach ($entries as $key => $_) {
            $entries[$key] = 'PLAIN-HIT-' . $i++;
        }
        $memo->setValue(null, $entries);

        $warm = Renderer::render($chat);
        foreach ([0, 1, 2] as $n) {
            self::assertStringContainsString('PLAIN-HIT-' . $n, $warm, 'plain row ' . $n . ' was served, not re-rendered');
        }
        self::assertCount(3, $memo->getValue(), 'a warm frame renders no plain row again');
        self::assertStringContainsString('a settled reply', $warm, 'the assistant row still renders every frame');
    }

    public function testThePlainMemoBustsOnThemeObjectIdentity(): void
    {
        $chat = self::plainRowChat();
        Renderer::render($chat);

        $memo = new ReflectionProperty(Renderer::class, 'historyBlockMemo');
        $entries = $memo->getValue();
        self::assertCount(3, $entries);
        foreach ($entries as $key => $_) {
            $entries[$key] = 'PLAIN-HIT';
        }
        $memo->setValue(null, $entries);

        $frame = Renderer::render($chat->withThemeName('dracula'));
        self::assertStringNotContainsString('PLAIN-HIT', $frame, 'a theme switch must not serve the old theme rows');
        self::assertCount(3, $memo->getValue(), 'the memo was emptied and refilled for the new theme');
        self::assertStringNotContainsString('PLAIN-HIT', Renderer::render($chat));
    }

    public function testThePlainMemoKeyCarriesThePaneWidth(): void
    {
        Renderer::render(self::plainRowChat(120, 40));
        Renderer::render(self::plainRowChat(44, 40));

        $keys = array_keys((new ReflectionProperty(Renderer::class, 'historyBlockMemo'))->getValue());
        self::assertCount(3, array_filter($keys, static fn(string $k): bool => str_contains($k, ':120:')));
        self::assertCount(3, array_filter($keys, static fn(string $k): bool => str_contains($k, ':44:')),
            'a resize costs fresh keys instead of serving another width\'s bytes');
    }

    public function testThePlainMemoKeyCarriesTheCompactedFlag(): void
    {
        $block = new ReflectionMethod(Renderer::class, 'settledHistoryBlock');
        $theme = Theme::byName('dark');
        $msg = Message::user('above the boundary');

        $block->invoke(null, 0, $msg, $theme, 120, false, 'you');
        $condensed = $block->invoke(null, 0, $msg, $theme, 120, true, 'YOU-COMPACTED');

        self::assertStringContainsString('YOU-COMPACTED', $condensed,
            'a row that crosses a compaction boundary re-renders under the condensed label');
        $keys = array_keys((new ReflectionProperty(Renderer::class, 'historyBlockMemo'))->getValue());
        self::assertCount(2, $keys, 'the flag keeps its own entry');
        $bits = array_map(static fn(string $k): string => explode(':', $k)[2], $keys);
        sort($bits);
        self::assertSame(['0', '1'], $bits);
    }

    public function testThePlainMemoKeyCarriesTheLocale(): void
    {
        $en = T::locale();
        try {
            Renderer::render(self::plainRowChat());
            T::setLocale('de');
            Renderer::render(self::plainRowChat());
        } finally {
            T::setLocale($en);
        }

        $keys = array_keys((new ReflectionProperty(Renderer::class, 'historyBlockMemo'))->getValue());
        self::assertCount(3, array_filter($keys, static fn(string $k): bool => str_ends_with($k, ':de')),
            'the re-labelled rows cost their own entries');
        self::assertCount(6, $keys, 'the English entries stay, but are never served under another locale');
    }

    public function testAUserRowKeysApartByItsAttachments(): void
    {
        $png = new Message(Role::User, 'ship it', 1700000000, [new Attachment('/tmp/deep/a.png', AttachmentType::Image)]);
        $doc = new Message(Role::User, 'ship it', 1700000001, [new Attachment('/tmp/deep/b.txt', AttachmentType::File)]);
        $none = Message::user('ship it');
        $chat = (new Chat(history: [$png, $doc, $none]))->withSize(120, 40);

        $frame = Renderer::render($chat);
        self::assertStringContainsString('🖼 a.png', $frame);
        self::assertStringContainsString('b.txt', $frame);
        $memo = (new ReflectionProperty(Renderer::class, 'historyBlockMemo'))->getValue();
        self::assertCount(3, $memo, 'same bytes, three digests: two chips and a bare row');

        // A warm frame keeps each row's own chip - a collapsed key would
        // serve the first attachment's chip on every row.
        self::assertSame($frame, Renderer::render($chat));
    }

    public function testContextRowsNeverEnterThePlainMemo(): void
    {
        $chat = self::mixedChat(120, 24);
        $cold = Renderer::render($chat);
        self::assertSame($cold, Renderer::render($chat));

        $memo = new ReflectionProperty(Renderer::class, 'historyBlockMemo');
        $entries = $memo->getValue();
        // mixedChat repeats one user prompt and one system note four times
        // each: two settled keys. Its assistant, tool and diff rows stay
        // rebuilt every frame.
        self::assertCount(2, $entries);
        $kinds = array_map(static fn(string $key): string => explode(':', $key)[0], array_keys($entries));
        sort($kinds);
        self::assertSame(['0', '2'], $kinds, 'only the user and system kinds appear');
        self::assertSame($cold, Renderer::render($chat), 'a warm mixed frame is byte-identical');
        self::assertCount(2, $memo->getValue(), 'a warm frame never grew the memo with tool rows');
    }

    public function testARepeatedPlainRowIsServedFromTheOneEntry(): void
    {
        $chat = (new Chat(history: [
            Message::user('same prompt'),
            Message::system('same note'),
            Message::user('same prompt'),
            Message::system('same note'),
        ]))->withSize(120, 40);

        Renderer::render($chat);
        $memo = new ReflectionProperty(Renderer::class, 'historyBlockMemo');
        $entries = $memo->getValue();
        self::assertCount(2, $entries, 'identical repeats share one entry each');

        $keys = array_keys($entries);
        $entries[$keys[0]] = 'PLAIN-A';
        $entries[$keys[1]] = 'PLAIN-B';
        $memo->setValue(null, $entries);

        $warm = Renderer::render($chat);
        self::assertSame(2, substr_count($warm, 'PLAIN-A'), 'both repeats are served from the one entry');
        self::assertSame(2, substr_count($warm, 'PLAIN-B'));
        self::assertCount(2, $memo->getValue());
    }

    public function testThePlainMemoStaysBounded(): void
    {
        $cap = (new ReflectionClassConstant(Renderer::class, 'MARKDOWN_MEMO_MAX'))->getValue();
        $block = new ReflectionMethod(Renderer::class, 'settledHistoryBlock');
        $theme = Theme::byName('dark');
        $memo = new ReflectionProperty(Renderer::class, 'historyBlockMemo');

        // Warm the theme reference first, so the injected junk is not emptied
        // by the guard on the next call.
        $block->invoke(null, 2, Message::system('warm the theme ref'), $theme, 120, false);
        $junk = [];
        for ($i = 0; $i < $cap; $i++) {
            $junk['junk-' . $i] = 'x';
        }
        $memo->setValue(null, $junk);

        $block->invoke(null, 2, Message::system('one past the cap'), $theme, 120, false);
        self::assertCount($cap, $memo->getValue(), 'storing past the bound evicts least-recently-used first');
        self::assertArrayNotHasKey('junk-0', $memo->getValue(), 'the oldest entry made room');
    }

    public function testThePlainArmsReadNothingLive(): void
    {
        // Structural census behind the narrow-memo claim: the settled helper's
        // own body must never read live context state. The ambient reads it
        // DOES make (Lang::t, T::locale, the attachment digest) are all
        // pinned into the key.
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Renderer.php');
        $start = strpos($source, 'private static function settledHistoryBlock(');
        self::assertIsInt($start);
        $body = substr($source, $start, (int) strpos($source, "\n    }\n", $start) - $start);
        foreach (['getenv', 'ScreenReaderMode', '$expanded', '$frame', '$ledger', '$agents', '$images', '$mosaic', 'UiSettings'] as $live) {
            self::assertStringNotContainsString($live, $body, 'the settled plain arm must stay context-free: ' . $live);
        }
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private static function clearMemos(): void
    {
        foreach (['markdownMemo' => [], 'markdownMemoTheme' => null, 'streamMemo' => null, 'styleTokenMemo' => [], 'hiddenBodyMemo' => [], 'historyBlockMemo' => [], 'historyBlockMemoTheme' => null] as $name => $empty) {
            (new ReflectionProperty(Renderer::class, $name))->setValue(null, $empty);
        }
    }

    private static function markdownChat(int $exchanges): Chat
    {
        $history = [];
        for ($i = 0; $i < $exchanges; $i++) {
            $history[] = Message::user("question {$i}");
            $history[] = Message::assistant("Answer {$i}\n\n" . self::PARA);
        }

        return (new Chat(history: $history))->withSize(120, 40);
    }

    /**
     * Every kind of row the transcript has, repeated so the transcript
     * overflows the viewport and the scroll window matters.
     */
    private static function mixedChat(int $cols, int $rows): Chat
    {
        $wide = str_repeat('a **bold run that spans the wrap** and `code` ', 8);
        $history = [
            Message::user("first \x1b[31mquestion\x1b[0m"),
            Message::assistant("# Title\n\n" . $wide . "\n\n| a | b |\n|---|---|\n| 文字 | 👍🏽 |\n\n> quoted\n\n[link](https://example.com/x)"),
            Message::assistant('reply with a thought', reasoning: "step one\n\nstep two"),
            Message::assistant('')->withToolResults([ToolResult::ok('Bash', str_repeat("out line\n", 30), 't1')->withDescription('ls -la')]),
            Message::assistant('')->withToolResults([ToolResult::error('Read', "no such file\r\nsecond", 't2')]),
            Message::assistant('')->withToolResults([new ToolResult(
                name: 'Edit',
                result: 'edited',
                id: 't3',
                diff: "--- a/x\n+++ b/x\n@@ -1,2 +1,2 @@\n-old\n+new\n same\n",
            )]),
            Message::system('note'),
        ];
        $all = [];
        for ($i = 0; $i < 4; $i++) {
            foreach ($history as $msg) {
                $all[] = $msg;
            }
            $all[] = Message::assistant("Reply {$i}\n\n" . self::PARA);
        }

        return (new Chat(history: $all))->withSize($cols, $rows);
    }

    private static function streamingCorpus(): string
    {
        return "Intro paragraph with **bold** and a [link](https://example.com/a).\n\n"
            . "# First\n\nSome text\nwrapped over lines.\n\n"
            . "```php\n# not a heading inside a fence\n\n# still not\n```\n\n"
            . "## Second\n\n- item one\n- item two\n\n  continued item\n\n"
            . "# After a list\n\n> quote line\n> more\n\n"
            . "# After a quote\n\n| a | b |\n|---|---|\n| 1 | 2 |\n\n"
            . "<div>\n\n# inside html?\n\n</div>\n\n"
            . "# CJK 文字\n\n文字文字文字 " . str_repeat('word ', 30) . "\n\n"
            . "    indented code\n\n# Refs\n\nSee [the ref] and ![an image][ref] and [missing].\n\n"
            . "```\nfenced\n```\n## Glued under a fence\n\n[the ref]: https://example.com/r\n[REF]: https://example.com/i\n\n"
            . "# Last\n\n~~~\nfence ~~~ with tildes\n~~~\n\nTail text";
    }

    private static function streamingTurn(string $partial, Theme $theme, int $width): string
    {
        return (new ReflectionMethod(Renderer::class, 'renderStreamingTurn'))->invoke(null, $partial, $theme, $width);
    }

    /** What renderStreamingTurn() produced before 15b-10: one whole render per frame. */
    private static function wholeStreamingRender(string $partial, Theme $theme, int $width): string
    {
        $label = \SugarCraft\Style\Style::new()->foreground($theme->assistantLabel)->bold()->render('assistant');
        $raw = Sanitize::stripZoneSentinels($partial);

        try {
            $body = rtrim((new Markdown($theme->markdown, wrapWidth: $width))->render($raw));
        } catch (\Throwable) {
            // A partial cut inside a UTF-8 sequence: CommonMark rejects it.
            $body = (new ReflectionMethod(Renderer::class, 'untrusted'))->invoke(null, $raw);
        }

        return $label . "\n" . $body;
    }

    /**
     * {@see Renderer::balanceSgr()} as it was before 15b-10.
     *
     * @param list<string> $rows
     * @return list<string>
     */
    private static function referenceBalanceSgr(array $rows): array
    {
        $parser = new Parser();
        $state = SgrState::initial();
        $out = [];
        foreach ($rows as $row) {
            $prefix = $state->rowOpen();
            foreach ($parser->parse($row) as $token) {
                $state->apply($token);
            }
            $out[] = $prefix . $row . $state->rowClose();
        }

        return $out;
    }
}
