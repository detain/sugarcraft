<?php

declare(strict_types=1);

namespace SugarCraft\Cli\Tests;

use SugarCraft\Cli\Theme;
use SugarCraft\Style\Style;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Palettes;
use PHPUnit\Framework\TestCase;

final class ThemeTest extends TestCase
{
    public function testAnsiThemeStylesEachLevel(): void
    {
        $t = Theme::ansi();
        foreach (['success', 'error', 'warn', 'info', 'prompt', 'accent'] as $field) {
            $rendered = $t->{$field}->render('x');
            $this->assertStringContainsString("\x1b[", $rendered, "$field should emit SGR");
        }
    }

    public function testAnsiMutedIsFaint(): void
    {
        $rendered = Theme::ansi()->muted->render('x');
        $this->assertStringContainsString('2m', $rendered); // SGR 2 = faint
    }

    public function testPlainThemePassthrough(): void
    {
        $t = Theme::plain();
        foreach (['success', 'error', 'warn', 'info', 'prompt', 'accent', 'muted'] as $field) {
            $this->assertSame('text', $t->{$field}->render('text'));
        }
    }

    public function testCharmThemeEmitsColour(): void
    {
        $this->assertStringContainsString("\x1b[", Theme::charm()->prompt->render('x'));
    }

    public function testDraculaThemeEmitsColour(): void
    {
        $this->assertStringContainsString("\x1b[", Theme::dracula()->success->render('x'));
    }

    public function testDraculaAccentIsPinkFromPalettes(): void
    {
        // W5 fix: sugar-cli's dracula accent had diverged to purple #bd93f9.
        // It is now re-sourced from sugar-core's Palettes SSOT and bound to
        // Dracula's `pink` (#ff79c6 → SGR 38;2;255;121;198), matching the
        // canonical sugar-style accent slot.
        $this->assertSame('#ff79c6', Palettes::DRACULA['pink']);
        $this->assertStringContainsString(
            '38;2;255;121;198',
            Theme::dracula()->accent->render('x'),
            'dracula accent must be Palettes::DRACULA[pink] (#ff79c6), not purple #bd93f9',
        );
        // After the fix accent == prompt (both pink); the old purple #bd93f9
        // no longer appears anywhere in sugar-cli's dracula palette.
        $this->assertSame(
            Theme::dracula()->prompt->render('x'),
            Theme::dracula()->accent->render('x'),
        );
        $this->assertStringNotContainsString(
            '38;2;189;147;249', // purple #bd93f9 — the pre-W5 diverged accent
            Theme::dracula()->accent->render('x'),
        );
    }

    public function testDraculaSlotsSourcedFromPalettes(): void
    {
        // Parity: every migrated slot renders the truecolor SGR derived from
        // its sugar-core Palettes hex, so the two stay locked together.
        $p = Palettes::DRACULA;
        $map = [
            'success' => $p['green'],
            'error'   => $p['red'],
            'warn'    => $p['yellow'],
            'info'    => $p['cyan'],
            'prompt'  => $p['pink'],
            'accent'  => $p['pink'],
            'muted'   => $p['comment'],
        ];
        $theme = Theme::dracula();
        foreach ($map as $slot => $hex) {
            [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
            $this->assertStringContainsString(
                "38;2;{$r};{$g};{$b}",
                $theme->{$slot}->render('x'),
                "dracula {$slot} must render Palettes hex {$hex}",
            );
        }
    }

    public function testNordThemeEmitsColour(): void
    {
        $this->assertStringContainsString("\x1b[", Theme::nord()->info->render('x'));
    }

    public function testCatppuccinThemeEmitsColour(): void
    {
        $this->assertStringContainsString("\x1b[", Theme::catppuccin()->accent->render('x'));
    }

    public function testByNameResolvesPresets(): void
    {
        // Dracula's success color is #50fa7b → truecolor SGR 38;2;80;250;123
        $this->assertStringContainsString(
            '38;2;80;250;123',
            Theme::byName('dracula')->success->render('x'),
        );
        // byName is case-insensitive (strtolower normalises the lookup key)
        $this->assertEquals(
            Theme::byName('ANSI')->success->render('x'),
            Theme::byName('ansi')->success->render('x'),
        );
    }

    public function testByNameRejectsUnknown(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Theme::byName(): unknown preset "nonexistent" (expected one of: ansi, plain, charm, dracula, nord, catppuccin, auto).');
        Theme::byName('nonexistent');
    }

    /**
     * Security: the offending name is echoed into the error message, so it
     * is sanitised (escapes/control bytes stripped) and clipped — a hostile
     * config string must not smuggle an OSC-52 / clear-screen sequence out
     * through the exception text, nor a megabyte-long payload.
     */
    public function testByNameRejectionSanitizesAndClipsTheHostileName(): void
    {
        $evil = "drac\x1b]52;c;aGVsbG8=\x07ula" . str_repeat('x', 200);
        try {
            Theme::byName($evil);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $msg = $e->getMessage();
            $this->assertStringNotContainsString("\x1b", $msg, 'no ESC in the error');
            $this->assertStringNotContainsString("\x07", $msg, 'no BEL in the error');
            $this->assertStringNotContainsString('aGVsbG8=', $msg, 'OSC payload not echoed');
            $this->assertStringContainsString('dracula', $msg, 'the surviving visible text is shown');
            $this->assertLessThanOrEqual(160, strlen($msg), 'clipped: ' . strlen($msg) . ' bytes');
        }
    }

    /** The advertised preset roster matches the names byName() accepts. */
    public function testPresetNamesRosterMatchesResolution(): void
    {
        $this->assertSame(['ansi', 'plain', 'charm', 'dracula', 'nord', 'catppuccin', 'auto'], Theme::PRESET_NAMES);
        foreach (Theme::PRESET_NAMES as $preset) {
            $this->assertInstanceOf(Theme::class, Theme::byName($preset));
        }
    }

    public function testPlainThemeAllFieldsUseSameStyleInstance(): void
    {
        // Theme::plain() re-uses one Style::$s instance for all 7 fields.
        // All accessors must return the identical object reference.
        $t = Theme::plain();
        $this->assertSame($t->success, $t->error);
        $this->assertSame($t->error, $t->warn);
        $this->assertSame($t->warn, $t->info);
        $this->assertSame($t->info, $t->prompt);
        $this->assertSame($t->prompt, $t->accent);
        $this->assertSame($t->accent, $t->muted);
    }

    public function testAutoReturnsNordForDarkBackground(): void
    {
        $dark = new \SugarCraft\Core\Msg\BackgroundColorMsg(30, 30, 30);
        $this->assertEquals(Theme::nord(), Theme::auto($dark));
    }

    public function testAutoReturnsCatppuccinForLightBackground(): void
    {
        $light = new \SugarCraft\Core\Msg\BackgroundColorMsg(250, 250, 250);
        $this->assertEquals(Theme::catppuccin(), Theme::auto($light));
    }

    public function testAutoFallsBackToAnsiWhenNoBackgroundProvided(): void
    {
        $this->assertEquals(Theme::ansi(), Theme::auto());
    }

    public function testByNameAutoResolves(): void
    {
        $this->assertEquals(Theme::auto(), Theme::byName('auto'));
    }

    public function testBuildFluentBuilder(): void
    {
        $custom = Theme::build()
            ->success(Style::new()->foreground(Color::hex('#ff0000')))
            ->error(Style::new()->foreground(Color::hex('#00ff00')))
            ->warn(Style::new()->foreground(Color::hex('#0000ff')))
            ->info(Style::new()->foreground(Color::hex('#ffff00')))
            ->prompt(Style::new()->foreground(Color::hex('#ff00ff')))
            ->accent(Style::new()->foreground(Color::hex('#00ffff')))
            ->muted(Style::new()->foreground(Color::hex('#888888')))
            ->build();

        // The success style should have ANSI color for #ff0000 (38;2;255;0;0).
        $this->assertStringContainsString('38;2;255;0;0', $custom->success->render('x'));
        $this->assertNotSame(Theme::ansi()->success, $custom->success);
    }

    public function testBuildThrowsWhenFieldMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Theme::build()->success(Style::new())->build();
    }

    /** Parse-don't-validate: the non-nullable Style props enforce slot presence
     *  at the boundary — a null becomes a TypeError under strict_types, no
     *  hand-rolled constructor loop needed. */
    public function testConstructorRejectsNullStyle(): void
    {
        $this->expectException(\TypeError::class);
        new Theme(
            Style::new(), Style::new(), Style::new(),
            Style::new(), Style::new(), Style::new(), null,
        );
    }

    /** Directly exercise Theme::charm() — a preset not routed through byName. */
    public function testCharmThemeDirectly(): void
    {
        $t = Theme::charm();
        // charm pink #ff5fd2 → truecolor SGR 38;2;255;95;210
        $rendered = $t->prompt->render('x');
        $this->assertStringContainsString('38;2;255;95;210', $rendered, 'charm prompt must be #ff5fd2');
    }

    /** Directly exercise Theme::catppuccin() — a preset not routed through byName. */
    public function testCatppuccinThemeDirectly(): void
    {
        $t = Theme::catppuccin();
        // catppuccin green #a6e3a1 → truecolor SGR 38;2;166;227;161
        $rendered = $t->success->render('x');
        $this->assertStringContainsString('38;2;166;227;161', $rendered, 'catppuccin success must be #a6e3a1');
    }

    public function testByNameRejectsEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Theme::byName('');
    }

    /** ThemeBuilder individual fluent methods — cover each setter directly. */
    public function testThemeBuilderIndividualSetters(): void
    {
        $s = Style::new()->bold();
        $builder = Theme::build();
        $this->assertSame($builder, $builder->success($s));
        $this->assertSame($builder, $builder->error($s));
        $this->assertSame($builder, $builder->warn($s));
        $this->assertSame($builder, $builder->info($s));
        $this->assertSame($builder, $builder->prompt($s));
        $this->assertSame($builder, $builder->accent($s));
        $this->assertSame($builder, $builder->muted($s));
        $built = $builder->build();
        // All fields should reference the identical $s instance (same Style object).
        $this->assertSame($s, $built->success);
        $this->assertSame($s, $built->error);
        $this->assertSame($s, $built->warn);
        $this->assertSame($s, $built->info);
        $this->assertSame($s, $built->prompt);
        $this->assertSame($s, $built->accent);
        $this->assertSame($s, $built->muted);
    }

    /**
     * ThemeBuilder lives in its own file so PSR-4 can autoload it BY NAME. It
     * used to be a second class inside Theme.php, which resolved only if
     * something had loaded Theme first — so a fresh process asking for it
     * (a type check, a direct construction) got "class not found". Probed in a
     * child PHP process, because in this one Theme is already loaded and would
     * mask the defect.
     */
    public function testThemeBuilderAutoloadsWithoutThemeBeingLoadedFirst(): void
    {
        $autoload = \dirname(__DIR__) . '/vendor/autoload.php';
        $probe = 'require ' . var_export($autoload, true) . ';'
            . 'echo class_exists(\'SugarCraft\\Cli\\ThemeBuilder\') ? "found" : "missing",'
            . ' class_exists(\'SugarCraft\\Cli\\Theme\', false) ? " theme-loaded" : " theme-not-loaded";';

        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($probe), $output, $exit);

        $this->assertSame(0, $exit);
        $this->assertSame(['found theme-not-loaded'], $output);
    }

    /** Build throws on the first missing field (in declaration order). */
    public function testThemeBuilderBuildThrowsOnFirstMissingField(): void
    {
        $s = Style::new();
        // Only success is set; build() fails at error (the next required field).
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('error style is required');
        Theme::build()->success($s)->build();
    }

    /**
     * SC-TB1 (finding 037): build() clears the slate — a reused builder must
     * not silently inherit the previous theme's slots. Old behaviour: the
     * second build() happily returned theme #1's styling for every unset
     * slot; now it throws exactly like a fresh builder with one slot set.
     */
    public function testBuilderSlotsAreClearedAfterBuild(): void
    {
        $s = Style::new();
        $builder = Theme::build()
            ->success($s)->error($s)->warn($s)->info($s)->prompt($s)->accent($s)->muted($s);
        $this->assertInstanceOf(Theme::class, $builder->build());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('success style is required');
        $builder->build();
    }

    /** Full re-specification after a build works, and the first theme is untouched. */
    public function testBuilderIsReusableAfterFullRespec(): void
    {
        $s    = Style::new();
        $bold = Style::new()->bold();
        $builder = Theme::build()
            ->success($s)->error($s)->warn($s)->info($s)->prompt($s)->accent($s)->muted($s);
        $first = $builder->build();

        $second = $builder
            ->success($bold)->error($s)->warn($s)->info($s)->prompt($s)->accent($s)->muted($s)
            ->build();

        $this->assertSame($bold, $second->success, 're-set slot wins');
        $this->assertSame($s, $first->success, 'first theme not mutated');
    }

    /** Nord theme is exercised via auto() and byName('nord') in other tests — do a direct slot check. */
    public function testNordThemeDirectly(): void
    {
        $t = Theme::nord();
        // nord green #a3be8c → 38;2;163;190;140
        $this->assertStringContainsString('38;2;163;190;140', $t->success->render('x'));
        // nord red #bf616a → 38;2;191;97;106
        $this->assertStringContainsString('38;2;191;97;106', $t->error->render('x'));
    }

    // ------------------------------------------------------------------
    // SC-T2 · finding 002 (high): detect() memoization, never stale
    // ------------------------------------------------------------------

    /**
     * The environment roster the memo fingerprint covers. Kept in lockstep
     * with sugar-core's private ColorProfile::defaultEnv() roster by the
     * drift guard below — that is what makes "keyed on exactly what
     * detection consults" a checked fact rather than a hope.
     */
    private const FINGERPRINT_ENV_KEYS = [
        'NO_COLOR', 'CLICOLOR', 'CLICOLOR_FORCE', 'FORCE_COLOR',
        'TERM', 'COLORTERM', 'TERM_PROGRAM',
        'WT_SESSION',
        'CI', 'GITHUB_ACTIONS', 'GITLAB_CI', 'BUILDKITE',
    ];

    public function testDetectMemoizesAnUnchangedEnvironment(): void
    {
        $first = Theme::detect();
        $this->assertSame($first, Theme::detect(), 'identical environment must return the memoized instance');
    }

    public function testDetectChangingOneEnvKeyComputesAFreshPalette(): void
    {
        $restore = $this->isolateEnv(['NO_COLOR' => '1', 'CLICOLOR_FORCE' => '1']);
        try {
            // force + NO_COLOR outranks it → Ascii: decoration, no colour.
            $ascii = Theme::detect();
            $this->assertStringNotContainsString('38;2;', $ascii->prompt->render('p'));

            putenv('NO_COLOR=0'); // same stream, one changed value → fresh
            $full = Theme::detect();

            $this->assertNotSame($ascii, $full, 'a changed env key must never return the memoized answer');
            $this->assertStringContainsString('38;2;', $full->prompt->render('p'), 'force leg: TrueColor returns');
        } finally {
            $this->restoreEnv($restore);
        }
    }

    public function testDetectMemoIsFingerprintedNotFirstWriteWins(): void
    {
        $base = Theme::detect();
        // Flip NO_COLOR to a value the ambient env does not already carry.
        $probe = \getenv('NO_COLOR') === '0' ? '1' : '0';
        $restore = $this->isolateEnv(['NO_COLOR' => $probe]);
        try {
            $other = Theme::detect();
            $this->assertNotSame($base, $other);
        } finally {
            $this->restoreEnv($restore);
        }
        $this->assertSame($base, Theme::detect(), 'returning the env must return the original memo entry');
    }

    public function testDetectWithExplicitEnvArrayBypassesTheMemo(): void
    {
        $a = Theme::detect(['NO_COLOR' => '1']);
        $b = Theme::detect(['NO_COLOR' => '1']);
        $this->assertNotSame($a, $b, 'caller-owned arrays are never identity-shared');
        $this->assertStringNotContainsString('38;2;', $a->prompt->render('p'));
    }

    public function testFingerprintRosterMatchesSugarCoreDefaultEnv(): void
    {
        $restore = [];
        foreach (self::FINGERPRINT_ENV_KEYS as $k) {
            $restore[$k] = \getenv($k);
            putenv($k . '=sc-t2-drift-probe');
        }
        try {
            $method = new \ReflectionMethod(\SugarCraft\Core\Util\ColorProfile::class, 'defaultEnv');
            $collected = $method->invoke(null);

            $themeKeys = self::FINGERPRINT_ENV_KEYS;
            sort($themeKeys);
            $coreKeys = array_keys($collected);
            sort($coreKeys);
            $this->assertSame(
                $themeKeys,
                $coreKeys,
                'sugar-core changed its detection env roster — Theme::DETECT_ENV_KEYS (the memo fingerprint) must follow, or the memo can go stale',
            );
        } finally {
            $this->restoreEnv($restore);
        }
    }

    /**
     * @param array<string,string> $set
     * @return array<string,string|false>
     */
    private function isolateEnv(array $set): array
    {
        $restore = [];
        foreach ($set as $k => $v) {
            $restore[$k] = \getenv($k);
            putenv($k . '=' . $v);
        }
        return $restore;
    }

    /** @param array<string,string|false> $restore */
    private function restoreEnv(array $restore): void
    {
        foreach ($restore as $k => $v) {
            if ($v === false) {
                putenv($k);
            } else {
                putenv($k . '=' . $v);
            }
        }
    }

    // ------------------------------------------------------------------
    // SC-T3 · findings 017/023/036/053: detectFor() + message prefix
    // ------------------------------------------------------------------

    public function testErrorMessagesCarryTheMethodPrefix(): void
    {
        try {
            Theme::byName('nope');
            $this->fail('expected byName() to reject');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringStartsWith('Theme::byName(): ', $e->getMessage());
        }
        try {
            Theme::detectFor('not-a-stream');
            $this->fail('expected detectFor() to reject a non-stream');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringStartsWith('Theme::detectFor(): ', $e->getMessage());
            $this->assertStringContainsString('string', $e->getMessage());
        }
        // detect() keeps its own (already-prefixed-by-name) guard wording.
        try {
            Theme::detect(null, 42);
            $this->fail('expected detect() to reject a non-stream');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringStartsWith('Theme::detect() expects', $e->getMessage());
        }
    }

    public function testDetectForUsesTheGivenStreamNotTheDefault(): void
    {
        // A memory stream is never a tty; unforced, that resolves NoTty —
        // proving the stream argument is what decides, not STDOUT.
        $fh = fopen('php://memory', 'r');
        $this->assertIsResource($fh);
        try {
            $theme = Theme::detectFor($fh);
            $this->assertStringNotContainsString("\x1b[", $theme->success->render('x'));
        } finally {
            fclose($fh);
        }
    }
}
