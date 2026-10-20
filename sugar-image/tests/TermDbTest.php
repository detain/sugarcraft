<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\TermDb;
use SugarCraft\Image\TermInfo;

/**
 * F-01: golden chains for the declarative terminal database.
 *
 * Every case here is a pure string-in / TermInfo-out evaluation of
 * {@see TermDb::matchChain()} — no getenv, no exec, no tty. The table is
 * data, the matcher is arithmetic on that data, and this file is the
 * evidence: chicle's own rows, transcribed with line citations and the
 * SugarCraft divergences named inline (chafa/chafa-term-db.c term_def[]).
 *
 * The chains worth pinning:
 * - dot-split $TERM heads: "screen.xterm-256color" resolves the mux AND the
 *   outer tail from one string (chicle:1003-1012);
 * - version-gate EXCL rows: konsole 22.03.70 sixel, tmux 3.4 native sixel,
 *   xterm sixel behind its version advertisement — the EXCL rule doing real
 *   exclusion work, not decoration;
 * - the mux passthrough maps: tmux 3.4's sixel entry FALSE (tmux decodes
 *   sixels itself) vs tmux-base and screen's all-TRUE (chicle:474-490,
 *   :512-521);
 * - SC union semantics + precedence, which reproduce the old hardcoded
 *   Detect ladder's block order for every claim it made.
 */
final class TermDbTest extends TestCase
{
    // ------------------------------------------------------- single-row hits

    public function testXtermKittyTermClaimsKittyAlone(): void
    {
        $info = TermDb::matchChain(['TERM' => 'xterm-kitty']);

        self::assertTrue($info->supports(TermInfo::PROTOCOL_KITTY));
        self::assertFalse($info->supports(TermInfo::PROTOCOL_SIXEL));
        self::assertFalse($info->supports(TermInfo::PROTOCOL_ITERM2));
        self::assertSame('kitty', $info->chain);
        self::assertNull($info->mux);
    }

    public function testKittyWindowIdAloneClaimsKittyWithoutAnyTerm(): void
    {
        // chicle keys kitty off KITTY_PID; SC also carries the old ladder's
        // KITTY_WINDOW_ID advertisement.
        $info = TermDb::matchChain(['KITTY_WINDOW_ID' => '12']);

        self::assertTrue($info->supports(TermInfo::PROTOCOL_KITTY));
        self::assertFalse($info->needsPassthrough(TermInfo::PROTOCOL_KITTY), 'a bare emulator never needs a wrapper');
    }

    public function testWezTermClaimsKittyFamilyPerScPrecedence(): void
    {
        // chicle would claim sixel+iterm2 here; the SC row is the documented
        // divergence (wezterm speaks the Kitty graphics protocol; SC keeps
        // its classification single-family at the claim level).
        $info = TermDb::matchChain(['TERM_PROGRAM' => 'WezTerm']);

        self::assertSame([TermInfo::PROTOCOL_KITTY], $info->claims);
    }

    public function testGhosttyViaBinDirAdvertisement(): void
    {
        $info = TermDb::matchChain(['GHOSTTY_BIN_DIR' => '/opt/ghostty/bin']);

        self::assertTrue($info->supports(TermInfo::PROTOCOL_KITTY));
        self::assertSame('ghostty', $info->chain);
    }

    public function testWarpClaimsKittyPerChicleSeqs(): void
    {
        $info = TermDb::matchChain(['TERM_PROGRAM' => 'WarpTerminal']);

        self::assertSame([TermInfo::PROTOCOL_KITTY], $info->claims);
    }

    public function testIterm2ViaLcTerminalAndViaBothTermProgramSpellings(): void
    {
        foreach ([['LC_TERMINAL' => 'iTerm2'], ['TERM_PROGRAM' => 'iTerm.app'], ['TERM_PROGRAM' => 'iTerm2']] as $env) {
            $info = TermDb::matchChain($env);
            self::assertSame([TermInfo::PROTOCOL_ITERM2], $info->claims, 'spelling: ' . json_encode($env));
            self::assertSame('iterm', $info->chain);
        }
    }

    public function testMinttyClaimsBothIterM2AndSixelForPrecedenceToResolve(): void
    {
        $info = TermDb::matchChain(['TERM_PROGRAM' => 'mintty']);

        self::assertSame([TermInfo::PROTOCOL_ITERM2, TermInfo::PROTOCOL_SIXEL], $info->claims, 'union sorted by precedence');
    }

    public function testRioClaimsBothFamiliesPerChicle(): void
    {
        $info = TermDb::matchChain(['TERM' => 'rio']);

        self::assertSame([TermInfo::PROTOCOL_ITERM2, TermInfo::PROTOCOL_SIXEL], $info->claims);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sixelTermHeads(): iterable
    {
        yield 'mlterm'          => ['mlterm'];
        yield 'mlterm-256color' => ['mlterm-256color'];
        yield 'foot'            => ['foot'];
        yield 'foot-extra'      => ['foot-extra'];
        yield 'foot-direct'     => ['foot-direct'];
        yield 'yaft'            => ['yaft'];
        yield 'yaft-256color'   => ['yaft-256color'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sixelTermHeads')]
    public function testSixelHeadsClaimSixelWithoutPassthrough(string $term): void
    {
        $info = TermDb::matchChain(['TERM' => $term]);

        self::assertSame([TermInfo::PROTOCOL_SIXEL], $info->claims, "TERM={$term}");
        self::assertFalse($info->needsPassthrough(TermInfo::PROTOCOL_SIXEL));
    }

    public function testMltermAdvertisementKeyIsTheSetAndNonEmptyVariant(): void
    {
        // SC refinement over chicle: an EMPTY env value is no advertisement.
        self::assertSame([TermInfo::PROTOCOL_SIXEL], TermDb::matchChain(['MLTERM' => '1'])->claims);
        self::assertFalse(TermDb::matchChain(['MLTERM' => ''])->hasPixelProtocol());
    }

    public function testContourClaimsSixelViaTerminalName(): void
    {
        $info = TermDb::matchChain(['TERMINAL_NAME' => 'contour']);

        self::assertSame([TermInfo::PROTOCOL_SIXEL], $info->claims);
    }

    // ------------------------------------------------------- xterm AND-gate

    public function testXterm256ColorNeedsTheVersionAdvertisement(): void
    {
        // The EXCL ISSET gate: nearly every emulator claims an xterm* $TERM,
        // so only real xterm — which exports $XTERM_VERSION — gets the sixel
        // row (the old hasSixelEnvHints AND, now table data).
        $without = TermDb::matchChain(['TERM' => 'xterm-256color']);
        self::assertFalse($without->supports(TermInfo::PROTOCOL_SIXEL), 'bare xterm-256color must NOT claim sixel');
        self::assertSame('xterm-256color', $without->chain);

        $with = TermDb::matchChain(['TERM' => 'xterm-256color', 'XTERM_VERSION' => 'XTerm(388)']);
        self::assertTrue($with->supports(TermInfo::PROTOCOL_SIXEL));
    }

    public function testXtermDirectDoesNotClaimSixelEvenWithVersion(): void
    {
        // SC keeps the old ladder's exact head set {xterm, xterm-256color};
        // the direct variants stay color-only rows (chicle:883-887).
        $info = TermDb::matchChain(['TERM' => 'xterm-direct', 'XTERM_VERSION' => 'XTerm(388)']);

        self::assertFalse($info->supports(TermInfo::PROTOCOL_SIXEL));
    }

    // ------------------------------------------------------- version gates

    public function testKonsoleSixlOnlyFromTwentyTwoOhThreeSeventy(): void
    {
        $early = TermDb::matchChain(['KONSOLE_VERSION' => '220100']);
        self::assertFalse($early->supports(TermInfo::PROTOCOL_SIXEL), 'pre-22.03.70 konsole is color-only');
        self::assertSame('konsole', $early->chain);

        $late = TermDb::matchChain(['KONSOLE_VERSION' => '230420']);
        self::assertTrue($late->supports(TermInfo::PROTOCOL_SIXEL));
        self::assertSame('konsole(220370)', $late->chain, 'equal priority, same name: the versioned row labels the chain (chicle:1114-1121)');
    }

    public function testTmuxNativeSixlOnlyFromThreeFourAndOnlyViaAdvertisement(): void
    {
        // tmux 3.4 decodes sixels into its own grid → Sixel claimed, no
        // passthrough (chicle tmux_3_4_pixel_pt :482-488).
        $threeFour = TermDb::matchChain([
            'TMUX' => '/tmp/tmux.sock,1,0',
            'TERM_PROGRAM' => 'tmux',
            'TERM_PROGRAM_VERSION' => '3.4',
            'TERM' => 'tmux-256color',
        ]);
        self::assertTrue($threeFour->supports(TermInfo::PROTOCOL_SIXEL));
        self::assertFalse($threeFour->needsPassthrough(TermInfo::PROTOCOL_SIXEL), 'native rendering needs no wrapper');
        self::assertSame('tmux(3.4):vt220', $threeFour->chain);

        // Same server version but no self-advertisement: SC divergence — the
        // base row (no pixel claims) stands, the native claim does not fire.
        $unadvertised = TermDb::matchChain([
            'TMUX' => '/tmp/tmux.sock,1,0',
            'TERM_PROGRAM_VERSION' => '3.4',
            'TERM' => 'tmux-256color',
        ]);
        self::assertFalse($unadvertised->supports(TermInfo::PROTOCOL_SIXEL));
        self::assertSame('tmux:vt220', $unadvertised->chain);

        // Advertisement + old version: the EXCL VER_GE gate excludes the 3.4
        // row; the base row still labels the chain.
        $threeThree = TermDb::matchChain([
            'TMUX' => '/tmp/tmux.sock,1,0',
            'TERM_PROGRAM' => 'tmux',
            'TERM_PROGRAM_VERSION' => '3.3',
            'TERM' => 'tmux-256color',
        ]);
        self::assertFalse($threeThree->supports(TermInfo::PROTOCOL_SIXEL), 'pre-3.4 tmux renders sixels by passthrough only — F-04 decides');
        self::assertSame('tmux:vt220', $threeThree->chain);
    }

    // ------------------------------------------------------- chained heads

    public function testScreenPseudoTermChainsThroughTmuxWithPassthrough(): void
    {
        // The survey golden: an outer xterm-256color whose version leaked
        // into a tmux pane, pane $TERM spelling the outer head. Sixel is
        // claimed (outer row); under a pre-3.4 mux it still needs tmux's
        // passthrough wrapper; under 3.4+ tmux decodes it natively.
        $pane = [
            'TMUX' => '/tmp/tmux.sock,1,0',
            'TERM_PROGRAM' => 'tmux',
            'TERM' => 'screen.xterm-256color',
            'XTERM_VERSION' => 'XTerm(388)',
        ];

        $pre = TermDb::matchChain($pane + ['TERM_PROGRAM_VERSION' => '3.3']);
        self::assertTrue($pre->supports(TermInfo::PROTOCOL_SIXEL));
        self::assertTrue($pre->needsPassthrough(TermInfo::PROTOCOL_SIXEL), 'inherited claim rides the mux wrapper');
        self::assertSame('tmux:xterm', $pre->chain);

        $post = TermDb::matchChain($pane + ['TERM_PROGRAM_VERSION' => '3.4']);
        self::assertTrue($post->supports(TermInfo::PROTOCOL_SIXEL));
        self::assertFalse($post->needsPassthrough(TermInfo::PROTOCOL_SIXEL), 'tmux ≥ 3.4 decodes sixel itself');

        $screen = TermDb::matchChain(['TERM' => 'screen.xterm-256color', 'XTERM_VERSION' => 'XTerm(388)']);
        self::assertTrue($screen->supports(TermInfo::PROTOCOL_SIXEL));
        self::assertTrue($screen->needsPassthrough(TermInfo::PROTOCOL_SIXEL), 'GNU screen always wraps');
        self::assertSame('screen:xterm', $screen->chain);
        self::assertSame('screen', $screen->mux);
    }

    public function testKittyInsideTmuxNeedsPassthroughForKittyEvenAtThreeFour(): void
    {
        // chicle keeps KITTY at TRUE in tmux_3_4_pixel_pt — tmux 3.4 gained
        // sixel decoding but kitty still rides the passthrough.
        $info = TermDb::matchChain([
            'TERM_PROGRAM' => 'tmux',
            'TERM_PROGRAM_VERSION' => '3.4',
            'TERM' => 'xterm-kitty',
        ]);

        self::assertTrue($info->supports(TermInfo::PROTOCOL_KITTY));
        self::assertTrue($info->needsPassthrough(TermInfo::PROTOCOL_KITTY));
    }

    public function testMltermThroughScreenNeedsPassthrough(): void
    {
        $info = TermDb::matchChain(['TERM' => 'screen.mlterm']);

        // dot-split: the screen head hits the mux row, the mlterm tail hits
        // its own row through the subterm loop; the mux map forces the
        // wrapper. (A real screen never speaks to mlterm like this — it is
        // the chain mechanics pinned on a synthetic but legal spelling.)
        self::assertTrue($info->supports(TermInfo::PROTOCOL_SIXEL));
        self::assertTrue($info->needsPassthrough(TermInfo::PROTOCOL_SIXEL));
    }

    // ------------------------------------------------------- union semantics

    public function testAdvertisementAndTermRowUnionThenPrecedenceResolves(): void
    {
        // A leaked $TERM=mlterm while running inside kitty: both rows match
        // (different keys), the union carries both claims, and the sorted
        // order is what Detect maps first — reproducing the ladder exactly.
        $info = TermDb::matchChain(['TERM' => 'mlterm', 'KITTY_WINDOW_ID' => '9']);

        self::assertSame([TermInfo::PROTOCOL_KITTY, TermInfo::PROTOCOL_SIXEL], $info->claims, 'union sorted into precedence order');
        // Precedence order lives in TermInfo::PROTOCOLS for Detect's mapping.
        self::assertSame([TermInfo::PROTOCOL_KITTY, TermInfo::PROTOCOL_ITERM2, TermInfo::PROTOCOL_SIXEL], TermInfo::PROTOCOLS);
        self::assertSame('mlterm', $info->chain, 'priority labels the chain; union decides the claim set');
    }

    // ------------------------------------------------------- no-claim rows

    public function testColorOnlyRowsLabelWithoutClaiming(): void
    {
        foreach (['alacritty' => 'alacritty', 'st-256color' => 'st', 'linux' => 'linux'] as $term => $label) {
            $info = TermDb::matchChain(['TERM' => $term]);
            self::assertSame([$label], [$info->chain], $term);
            self::assertFalse($info->hasPixelProtocol(), "$term must claim no pixel protocol (chicle carries no seq lists for it)");
            self::assertTrue($info->supports('nothing') === false); // sanity: unknown protocol never supported
        }
    }

    public function testAppleTerminalAndWindowsConsoleRowsLabel(): void
    {
        $apple = TermDb::matchChain(['TERM_PROGRAM' => 'Apple_Terminal']);
        self::assertSame('apple', $apple->chain);
        self::assertFalse($apple->hasPixelProtocol());

        $win = TermDb::matchChain(['TERM' => 'xterm-256color', 'ComSpec' => 'C:\\Windows\\System32\\cmd.exe']);
        self::assertSame('windows-console', $win->chain, 'the -5 ComSpec row labels over the -10 xterm-256color row (chicle priorities)');
        self::assertFalse($win->hasPixelProtocol());
    }

    // ------------------------------------------------------- negatives

    public function testEmptyEnvironmentIsUnknown(): void
    {
        $info = TermDb::matchChain([]);

        self::assertSame('unknown', $info->chain);
        self::assertFalse($info->hasPixelProtocol());
        self::assertNull($info->mux);
    }

    public function testBareVt100FallsThroughToTheCatchAllWithoutClaims(): void
    {
        $info = TermDb::matchChain(['TERM' => 'vt100']);

        self::assertSame('vt220', $info->chain);
        self::assertFalse($info->hasPixelProtocol());
    }

    public function testCaseInsensitiveExactMatch(): void
    {
        // chicle EXACT/SUFFIX compare case-insensitively (strcasecmp).
        $info = TermDb::matchChain(['TERM' => 'XTERM-KITTY']);

        self::assertTrue($info->supports(TermInfo::PROTOCOL_KITTY));
    }

    // ------------------------------------------------------- version fold

    public function testParseVersionFoldsToDigitsOnly(): void
    {
        // chicle parse_version (:907-954): concat digits, no per-field math.
        self::assertSame(123, TermDb::parseVersion('1.2.3'));
        self::assertSame(388, TermDb::parseVersion('XTerm(388)'));
        self::assertSame(34, TermDb::parseVersion('3.4'));
        self::assertSame(310, TermDb::parseVersion('3.10'), 'more digits compare higher — 3.10 > 3.4 without fieldwise code');
        self::assertSame(36, TermDb::parseVersion('next-3.6'));
        self::assertSame(-1, TermDb::parseVersion(''), 'a digitless string can never clear a VER_GE gate');
    }

    // ------------------------------------------------------- table hygiene

    public function testEveryEnvRuleConsultsOnlySnapshotKeys(): void
    {
        // A row keyed on a var outside ENV_KEYS would be invisible to
        // snapshotEnv() — matchChain would then lie on production while
        // looking green in the pure tests.
        foreach (TermDb::ROWS as $row) {
            foreach ($row['env'] as $rule) {
                self::assertContains($rule['key'], TermDb::ENV_KEYS, "{$row['name']} rule key {$rule['key']}");
            }
        }
    }

    public function testRowsAreWellFormed(): void
    {
        // Fail-loud parsing of the table's own shape.
        foreach (TermDb::ROWS as $row) {
            self::assertNotEmpty($row['name']);
            self::assertContains($row['type'], [TermDb::TYPE_TERM, TermDb::TYPE_MUX]);
            foreach ($row['claims'] as $protocol) {
                self::assertContains($protocol, TermInfo::PROTOCOLS);
            }
            foreach ($row['env'] as $rule) {
                self::assertContains($rule['op'], [TermDb::ENV_OP_INCL, TermDb::ENV_OP_EXCL]);
                self::assertContains($rule['cmp'], [TermDb::ENV_CMP_ISSET, TermDb::ENV_CMP_EXACT, TermDb::ENV_CMP_PREFIX, TermDb::ENV_CMP_SUFFIX, TermDb::ENV_CMP_VER_GE]);
                self::assertLessThanOrEqual(8, count($row['env']), "chicle caps rules per def at 8 ({$row['name']})");
                if ($rule['op'] === TermDb::ENV_OP_INCL && $rule['cmp'] !== TermDb::ENV_CMP_ISSET) {
                    self::assertNotNull($rule['value'], "{$row['name']} non-isset INCL needs a value");
                }
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        new TermInfo('x', ['not-a-protocol'], [], null);
    }

    public function testTermDbCarriesNoExecSurface(): void
    {
        // Hermetic law as code: the table files must never spawn or query.
        $src = file_get_contents(__DIR__ . '/../src/TermDb.php');
        self::assertIsString($src);
        foreach (['proc_open', 'exec(', 'shell_exec', 'popen', 'passthru', 'pcntl', 'fopen', 'STDIN'] as $banned) {
            self::assertStringNotContainsString($banned, $src, "TermDb must stay a pure data consult ({$banned})");
        }
    }
}
