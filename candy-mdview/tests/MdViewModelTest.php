<?php

declare(strict_types=1);

namespace SugarCraft\MdView\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\MdView\MdViewModel;
use SugarCraft\MdView\ReloadTickMsg;
use PHPUnit\Framework\TestCase;

final class MdViewModelTest extends TestCase
{
    private function content(int $n): string
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = "line $i";
        }
        return implode("\n", $out);
    }

    public function testInitialView(): void
    {
        $m = MdViewModel::fromContent($this->content(3), 80, 5);
        $this->assertStringContainsString('line 1', $m->view());
    }

    public function testQExits(): void
    {
        $m = MdViewModel::fromContent($this->content(3));
        [$m, $cmd] = $m->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertTrue($m->isExited());
        $this->assertNotNull($cmd);
    }

    public function testEscExits(): void
    {
        $m = MdViewModel::fromContent($this->content(3));
        [$m, ] = $m->update(new KeyMsg(KeyType::Escape));
        $this->assertTrue($m->isExited());
    }

    public function testCtrlCExits(): void
    {
        $m = MdViewModel::fromContent($this->content(3));
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));
        $this->assertTrue($m->isExited());
    }

    public function testKeymapRemapMovesQuitAwayFromPlainQ(): void
    {
        // The config file's keymap door (finding 036): `q` -> `x` means x
        // quits and plain q belongs to the reader again.
        $m = MdViewModel::fromContent($this->content(3))->withKeymap(['quit' => 'x']);
        [($still), ] = $m->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertFalse($still->isExited(), 'remapped away: plain q must NOT quit');

        [$x, $cmd] = $m->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertTrue($x->isExited());
        $this->assertNotNull($cmd);

        // Structural legs survive any remap: Esc and Ctrl+C still quit.
        [$esc, ] = $m->update(new KeyMsg(KeyType::Escape));
        $this->assertTrue($esc->isExited());
        [$ctrlC, ] = $m->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));
        $this->assertTrue($ctrlC->isExited());
    }

    public function testKeymapChordsAndPartialRemaps(): void
    {
        $base = MdViewModel::fromContent($this->content(3));

        // ctrl-/alt- chords name deliberate modifier pairs and nothing else.
        [$altQ, ] = $base->withKeymap(['quit' => 'alt-q'])->update(new KeyMsg(KeyType::Char, 'q', alt: true));
        $this->assertTrue($altQ->isExited());
        [$plainQ, ] = $base->withKeymap(['quit' => 'alt-q'])->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertFalse($plainQ->isExited(), 'an alt-q map must not answer to unmodified q');
        [$shiftX, ] = $base->withKeymap(['quit' => 'x'])->update(new KeyMsg(KeyType::Char, 'x', shift: true));
        $this->assertFalse($shiftX->isExited(), 'a bare-rune map stays an UNMODIFIED-key map');

        // Partial remaps keep every untouched binding (merge, not replace).
        [$qStill, ] = $base->withKeymap(['reload' => 'ctrl-r'])->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertTrue($qStill->isExited());
    }

    public function testKeymapMovesTheForceReloadChord(): void
    {
        // The reload arm is keymap-driven too: with `reload` on ctrl-r, an
        // unmodified `r` no longer queues anything while ctrl-r does.
        $path = sys_get_temp_dir() . '/mdview-keymap-reload-' . uniqid('', true) . '.md';
        file_put_contents($path, "# live\n");

        $model = MdViewModel::fromContent('RENDERED', 40, 6)
            ->withWatch($path, static fn (string $raw): string => 'RENDERED:' . $raw)
            ->withKeymap(['reload' => 'ctrl-r']);
        [$withR, ] = $model->update(new KeyMsg(KeyType::Char, 'r'));
        $this->assertFalse($withR->pendingReload, 'plain r is no longer the reload chord');
        [$withCtrl, ] = $model->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        $this->assertTrue($withCtrl->pendingReload, 'ctrl-r took the chord over');
        // The map must SURVIVE derived states: after an unrelated scroll
        // copy, plain r still answers to nothing (a keymap dropped by the
        // clone would silently resurrect the default chord here).
        [$afterNav, ] = $model->update(new KeyMsg(KeyType::Down));
        [$stillSafe, ] = $afterNav->update(new KeyMsg(KeyType::Char, 'r'));
        $this->assertFalse($stillSafe->pendingReload, 'the remap rode the copy');

        // Default map: plain r still forces the reload.
        [$defaultR, ] = MdViewModel::fromContent('RENDERED', 40, 6)
            ->withWatch($path, static fn (string $raw): string => 'RENDERED:' . $raw)
            ->update(new KeyMsg(KeyType::Char, 'r'));
        $this->assertTrue($defaultR->pendingReload);
        unlink($path);
    }

    public function testDownScrollsViewport(): void
    {
        $m = MdViewModel::fromContent($this->content(20), 80, 3);
        [$m, ] = $m->update(new KeyMsg(KeyType::Down));
        $this->assertSame(1, $m->viewport->yOffset);
    }

    public function testIgnoresKeysAfterExit(): void
    {
        $m = MdViewModel::fromContent($this->content(20));
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'q'));
        [$m2, $cmd] = $m->update(new KeyMsg(KeyType::Down));
        $this->assertSame($m, $m2);
        $this->assertNull($cmd);
    }

    /**
     * @see plan_candy-mdview.md — Item 2.1 (Item 5.8 in findings_resume_plan)
     */
    public function testPageUpScrollsViewport(): void
    {
        $m = MdViewModel::fromContent($this->content(20), 80, 3);
        // Scroll down first
        [$m, ] = $m->update(new KeyMsg(KeyType::Down));
        [$m, ] = $m->update(new KeyMsg(KeyType::Down));
        // PageUp should go back one viewport height
        [$m, ] = $m->update(new KeyMsg(KeyType::PageUp));
        $this->assertLessThan(2, $m->viewport->yOffset);
    }

    /**
     * @see plan_candy-mdview.md — Item 2.1 (Item 5.8 in findings_resume_plan)
     */
    public function testPageDownScrollsViewport(): void
    {
        $m = MdViewModel::fromContent($this->content(20), 80, 3);
        [$m, ] = $m->update(new KeyMsg(KeyType::PageDown));
        $this->assertGreaterThan(0, $m->viewport->yOffset);
    }

    /**
     * @see plan_candy-mdview.md — Item 2.1 (Item 5.8 in findings_resume_plan)
     */
    public function testCtrlUHalfPageUp(): void
    {
        $m = MdViewModel::fromContent($this->content(20), 80, 3);
        [$m, ] = $m->update(new KeyMsg(KeyType::Down));
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'u', ctrl: true));
        $this->assertLessThan(1, $m->viewport->yOffset);
    }

    /**
     * @see plan_candy-mdview.md — Item 2.1 (Item 5.8 in findings_resume_plan)
     */
    public function testHomeGoesToTop(): void
    {
        $m = MdViewModel::fromContent($this->content(20), 80, 3);
        [$m, ] = $m->update(new KeyMsg(KeyType::Down));
        [$m, ] = $m->update(new KeyMsg(KeyType::Home));
        $this->assertSame(0, $m->viewport->yOffset);
    }

    /**
     * @see plan_candy-mdview.md — Item 2.1 (Item 5.8 in findings_resume_plan)
     */
    public function testEndGoesToBottom(): void
    {
        $m = MdViewModel::fromContent($this->content(20), 80, 3);
        [$m, ] = $m->update(new KeyMsg(KeyType::End));
        $this->assertTrue($m->viewport->atBottom());
    }

    /**
     * @see plan_candy-mdview.md — Item 2.1 (Item 5.8 in findings_resume_plan)
     */
    public function testContentFitsViewportNoScrollNeeded(): void
    {
        $m = MdViewModel::fromContent($this->content(2), 80, 5);
        $this->assertSame(0, $m->viewport->yOffset);
        // Press Down - should clamp to max offset (0)
        [$m, ] = $m->update(new KeyMsg(KeyType::Down));
        $this->assertSame(0, $m->viewport->yOffset);
    }

    /**
     * @see plan_candy-mdview.md — Item 2.1 (Item 5.8 in findings_resume_plan)
     */
    public function testAtBottomWhenContentFits(): void
    {
        $m = MdViewModel::fromContent($this->content(2), 80, 5);
        $this->assertTrue($m->viewport->atBottom());
    }

    public function testInitReturnsNull(): void
    {
        $m = MdViewModel::fromContent($this->content(3));
        $this->assertNull($m->init());
    }

    public function testSubscriptionsReturnsNull(): void
    {
        $m = MdViewModel::fromContent($this->content(3));
        $this->assertNull($m->subscriptions());
    }

    public function testFromContentWithDefaultDimensions(): void
    {
        $m = MdViewModel::fromContent("line1\nline2");
        $this->assertStringContainsString('line1', $m->view());
        $this->assertFalse($m->isExited());
    }

    public function testStatusHeaderExactBytesAndNarrowClip(): void
    {
        // Finding 048: the header was pinned only via contains('%') plus the
        // ↻/'·' markers — dropping '%' into a separate segment or swapping
        // the divide stayed green, and the mid-number clip at narrow width
        // was unpinned. Exact literals now.
        $frame = MdViewModel::fromContent($this->content(5), 80, 4)
            ->withDocument('name')
            ->view();
        // 'name · 0%' spans 9 CELLS ('·' is one cell but two bytes), so the
        // pad is 80 − 9 spaces — a byte-counting str_pad would be one short
        // and this is precisely the cell-vs-byte seam the header keeps.
        $this->assertSame('name · 0%' . str_repeat(' ', 71), explode("\n", $frame)[0]);

        // Narrow clip: at width 6 the truncation lands after the separator,
        // before any digit — a regression that reorders segments shows here.
        $narrow = MdViewModel::fromContent($this->content(5), 6, 4)
            ->withDocument('name')
            ->view();
        $this->assertSame('name ·', explode("\n", $narrow)[0]);
    }

    /**
     * Run the observation with the locale chain pinned to $vars (null value
     * unsets). Both layers the readers consult — $_SERVER and putenv — are
     * driven, and the previous values are restored afterwards.
     *
     * @param array<string,string|null> $vars
     */
    private function withLocaleEnv(array $vars, \Closure $observe): void
    {
        // The whole chain is pinned every time: a developer box with an
        // unrelated LC_MESSAGES must not leak into the observation.
        $vars = array_merge(['LC_ALL' => null, 'LC_MESSAGES' => null, 'LANG' => null], $vars);
        $restore = [];
        foreach ($vars as $key => $value) {
            $restore[$key] = [getenv($key), $_SERVER[$key] ?? null];
            if ($value === null) {
                putenv($key);
                unset($_SERVER[$key]);
            } else {
                putenv($key . '=' . $value);
                $_SERVER[$key] = $value;
            }
        }
        try {
            $observe();
        } finally {
            foreach ($restore as $key => [$envValue, $serverValue]) {
                if ($envValue === false || $envValue === null) {
                    putenv($key);
                } else {
                    putenv($key . '=' . $envValue);
                }
                if ($serverValue === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $serverValue;
                }
            }
        }
    }

    public function testStatusHeaderAsciiFallbackLegsOnCAndLegacyCharsets(): void
    {
        // Finding 034: the header glyphs are Unicode; a C/POSIX or explicit
        // non-UTF-8 locale turned `name · 42% · ↻N` into mojibake. The row
        // now answers in pure ASCII there, and stays Unicode everywhere the
        // environment declares UTF-8 — including a declared C.UTF-8.
        $model = MdViewModel::fromContent($this->content(5), 80, 4)->withDocument('name');
        $asciiHeader = 'name 0%' . str_repeat(' ', 73);
        $unicodeHeader = 'name · 0%' . str_repeat(' ', 71);

        $this->withLocaleEnv(['LC_ALL' => 'C'], function () use ($model, $asciiHeader): void {
            $this->assertSame($asciiHeader, explode("\n", $model->view())[0]);
        });
        $this->withLocaleEnv(['LC_ALL' => 'de_DE.ISO-8859-1'], function () use ($model, $asciiHeader): void {
            $this->assertSame($asciiHeader, explode("\n", $model->view())[0]);
        });
        // The chain: LC_ALL takes no position, LANG does.
        $this->withLocaleEnv(['LC_ALL' => null, 'LANG' => 'POSIX'], function () use ($model, $asciiHeader): void {
            $this->assertSame($asciiHeader, explode("\n", $model->view())[0]);
        });
        // Precedence is first-value-takes-position: an overriding UTF-8
        // LC_ALL outranks LANG=C.
        $this->withLocaleEnv(['LC_ALL' => 'en_US.UTF-8', 'LANG' => 'C'], function () use ($model, $unicodeHeader): void {
            $this->assertSame($unicodeHeader, explode("\n", $model->view())[0]);
        });
        $this->withLocaleEnv(['LC_ALL' => 'C.UTF-8'], function () use ($model, $unicodeHeader): void {
            $this->assertSame($unicodeHeader, explode("\n", $model->view())[0]);
        });
        // An empty environment (no locale variable at all) stays Unicode —
        // the byte-exact control for the un-pinned S03 literal.
        $this->withLocaleEnv(['LC_ALL' => null, 'LC_MESSAGES' => null, 'LANG' => null], function () use ($model, $unicodeHeader): void {
            $this->assertSame($unicodeHeader, explode("\n", $model->view())[0]);
        });
    }

    public function testReloadMarkersHaveAsciiShapesUnderCLocale(): void
    {
        // The ↻N / ↻! markers share the header's charset gate: (rN)/(r!) in
        // ASCII mode, nothing non-ASCII ever reaches the row.
        $path = sys_get_temp_dir() . '/mdview-ascii-header-' . uniqid() . '.md';
        file_put_contents($path, "# live\n");
        $model = MdViewModel::fromContent('RENDERED-LIVE', 40, 6)
            ->withDocument('doc.md')
            ->withWatch($path, static fn (string $raw): string => 'RENDERED:' . $raw);

        unlink($path);
        $handle = fopen($path, 'wb');
        $this->assertNotFalse($handle);
        $this->assertTrue(ftruncate($handle, 67_108_865));
        fclose($handle);
        [$detect] = $model->update(new ReloadTickMsg());
        [$blocked] = $detect->update(new ReloadTickMsg());
        $this->assertTrue($blocked->reloadBlocked);

        $this->withLocaleEnv(['LC_ALL' => 'C'], function () use ($blocked): void {
            $line = explode("\n", $blocked->view())[0];
            $this->assertMatchesRegularExpression('/^doc\.md \d+% \(r!\) *$/', $line, 'ASCII shapes only, padded to the viewport width');
            $this->assertSame(0, preg_match('/[\x80-\xFF]/', $line), 'no non-ASCII byte may survive into the C-locale header');
        });
        // UTF-8 control: the same state renders the ↻! form.
        $this->withLocaleEnv(['LC_ALL' => 'C.UTF-8'], function () use ($blocked): void {
            $this->assertMatchesRegularExpression('/^doc\.md · \d+% · ↻! *$/', explode("\n", $blocked->view())[0]);
        });

        file_put_contents($path, "# newer\n");
        clearstatcache(true, $path);
        [$redetect] = $blocked->update(new ReloadTickMsg());
        [$fixed] = $redetect->update(new ReloadTickMsg());
        $this->assertSame(1, $fixed->reloadCount);
        $this->withLocaleEnv(['LC_ALL' => 'C'], function () use ($fixed): void {
            $this->assertMatchesRegularExpression('/^doc\.md \d+% \(r1\) *$/', explode("\n", $fixed->view())[0]);
        });
        unlink($path);
    }

    public function testReloadSweepRefusesOversizeReplacementKeepsFrameAndMarks(): void
    {
        // Finding 003's gate leg: the reload sweep re-read the watched file
        // through a guard-free @file_get_contents, so a grow-past-cap swap
        // OOM'd the LIVE pager while the launch-time "64 MiB contract" sat
        // unused. The sweep now goes through the same DocumentGate: the
        // refused replacement keeps the frame, consumes the tuple, and the
        // header marks ↻! until a later edit is accepted.
        $path = sys_get_temp_dir() . '/mdview-sweep-cap-' . uniqid() . '.md';
        file_put_contents($path, "# live\n");
        $model = MdViewModel::fromContent('RENDERED-LIVE', 40, 5)
            ->withDocument('doc.md')
            ->withWatch($path, static fn (string $raw): string => 'RENDERED:' . $raw);

        // Replace with a file past the cap — metadata-only sparse write,
        // and the gate refuses on fstat before any slurp can happen.
        unlink($path);
        $handle = fopen($path, 'wb');
        $this->assertNotFalse($handle);
        $this->assertTrue(ftruncate($handle, 67_108_865));
        fclose($handle);

        [$detect] = $model->update(new ReloadTickMsg());
        $this->assertTrue($detect->pendingReload, 'the detect pass queues the read for the next tick');
        $this->assertFalse($detect->reloadBlocked, 'nothing is refused until the read happens');

        [$blocked] = $detect->update(new ReloadTickMsg());
        $this->assertTrue($blocked->reloadBlocked);
        $this->assertFalse($blocked->pendingReload);
        $this->assertSame(0, $blocked->reloadCount, 'a refusal is not a reload');
        $frame = $blocked->view();
        $this->assertStringContainsString('↻!', $frame);
        $this->assertStringContainsString('RENDERED-LIVE', $frame, 'the refused frame must never blank the pager');

        // Tuple consumed: an identical next tick re-judges nothing, marker holds.
        [$held] = $blocked->update(new ReloadTickMsg());
        $this->assertTrue($held->reloadBlocked);

        // A later real edit recovers: accepted, counter moves, ↻! clears.
        file_put_contents($path, "# newer\n");
        clearstatcache(true, $path);
        [$redetect] = $held->update(new ReloadTickMsg());
        [$fixed] = $redetect->update(new ReloadTickMsg());
        $this->assertFalse($fixed->reloadBlocked);
        $this->assertSame(1, $fixed->reloadCount);
        $this->assertStringContainsString('↻1', $fixed->view());
        unlink($path);
    }

    public function testReloadSweepRefusesBinarySwapAndKeepsLastFrame(): void
    {
        // Finding 003's second probe leg: an ELF payload swapped onto the
        // watched path rendered WITH A SUCCESS FRAME. The shared gate now
        // refuses it; the header tells the user the frame is stale.
        $path = sys_get_temp_dir() . '/mdview-sweep-bin-' . uniqid() . '.md';
        file_put_contents($path, "# live\n");
        $model = MdViewModel::fromContent('RENDERED-LIVE', 40, 5)
            ->withDocument('doc.md')
            ->withWatch($path, static fn (string $raw): string => 'RENDERED:' . $raw);

        file_put_contents($path, "# ELF-ish\x00hostile");
        clearstatcache(true, $path);

        [$detect] = $model->update(new ReloadTickMsg());
        [$blocked] = $detect->update(new ReloadTickMsg());
        $this->assertTrue($blocked->reloadBlocked);
        $this->assertSame(0, $blocked->reloadCount);
        $this->assertStringContainsString('↻!', $blocked->view());
        $this->assertStringContainsString('RENDERED-LIVE', $blocked->view());
        unlink($path);
    }

    public function testFromContentClampsHeightToAtLeastOne(): void
    {
        // height of 0 should be clamped to 1
        $m = MdViewModel::fromContent("line1\nline2", 80, 0);
        $this->assertStringContainsString('line1', $m->view());
    }

    public function testScrolledFrameLinesNeverExceedViewportWidth(): void
    {
        // Finding 001 (the alt-screen tear class): Viewport::view() left-
        // shifts without right-clipping when xOffset > 0, and sugar-core's
        // Renderer maps frame lines 1:1 to rows without clipping — one
        // over-viewport row auto-wraps and smears every row below it.
        // Every frame line at EVERY non-zero scroll position must satisfy
        // Width::of(line) <= width.
        $rows = [str_repeat('abcdefghij', 30), 'short', str_repeat('x', 250)];
        $m    = MdViewModel::fromContent(implode("\n", $rows), 40, 5);
        for ($step = 0; $step < 12; $step++) {
            [$m] = $m->update(new KeyMsg(KeyType::Right));
            $this->assertGreaterThan(0, $m->viewport->xOffset);
            foreach (explode("\n", $m->view()) as $line) {
                $this->assertLessThanOrEqual(40, Width::of($line), 'scrolled frame row exceeds the viewport width');
            }
        }
    }

    public function testScrolledDocumentFrameKeepsHeaderAndBodyInsideWidth(): void
    {
        // The header leg of the same tear: document mode prepends the
        // status line; body rows are clipped, the header is exact-width.
        $m = MdViewModel::fromContent(str_repeat('long ', 60) . "\ntail", 30, 4)
            ->withDocument('doc.md');
        [$m] = $m->update(new KeyMsg(KeyType::Right));
        $frame = explode("\n", $m->view());
        $this->assertCount(3, $frame, 'header + two content rows');
        $this->assertSame(30, Width::of($frame[0]), 'header is padded to exactly the width');
        $this->assertLessThanOrEqual(30, Width::of($frame[1]));
        // dropAnsi-then-clip is the plain column window [6, 36) at one step.
        $this->assertSame(substr(str_repeat('long ', 60), 6, 30), $frame[1], 'visible window is the exact scrolled slice');
    }
}
