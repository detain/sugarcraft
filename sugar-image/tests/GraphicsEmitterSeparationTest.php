<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A-01: the pen-delta emitter is scoped to character-mosaic output, and the graphics protocols stay out of it.
 *
 * {@see \SugarCraft\Image\Support\OutputOptimizer} tracks the six SGR pen channels a cell stream can move
 * (foreground, background, bright, underline, inverse, blink). The pixel-graphics renderers (Sixel, Kitty,
 * iTerm2) and the chafa subprocess bridge emit protocol blobs, not SGR cell runs — per the Renderer
 * contract's `isInline()` law, their bytes carry no per-row pen state for a delta optimizer to diff.
 * Chafa keeps the same separation between its printer-side `emit_attributes_truecolor` and its graphics
 * paths. Nothing in the suite pinned that boundary, so a refactor that routed graphics bytes through the
 * emitter (or a document that later CLAIMED such coverage) would have shipped silently. This census is the
 * tripwire: a new reference in any non-mosaic renderer file reddens here and forces the scope decision to
 * be made in the roster, not by accident.
 */
final class GraphicsEmitterSeparationTest extends TestCase
{
    /**
     * The character-mosaic emitters that legitimately stream through the optimizer (A-01/A-13/A-14).
     * A new mosaic renderer must be added here deliberately — the completeness arm below reddens otherwise.
     */
    private const MOSAIC_EMITTERS = ['AsciiRenderer.php', 'HalfBlockRenderer.php', 'QuarterBlockRenderer.php'];

    /**
     * Every other file in src/Renderer must carry zero optimizer references: the four protocol/bridge
     * renderers (graphics blobs or subprocess bytes), plus the interface, the colour-mode enum and the
     * fingerprint contract that share the directory.
     */
    private const MUST_STAY_APART = [
        'SixelRenderer.php',
        'KittyRenderer.php',
        'Iterm2Renderer.php',
        'ChafaRenderer.php',
        'OrderedTexture.php',
        'Renderer.php',
        'AsciiColorMode.php',
        'ConfigFingerprintProvider.php',
    ];

    public function testTheTwoRostersCoverEveryRendererFile(): void
    {
        $discovered = array_values(array_diff(
            scandir(\dirname(__DIR__) . '/src/Renderer') ?: [],
            ['.', '..'],
        ));
        sort($discovered);

        $expected = array_merge(self::MOSAIC_EMITTERS, self::MUST_STAY_APART);
        sort($expected);

        self::assertSame(
            $expected,
            $discovered,
            'a renderer appeared in src/Renderer without a judged roster row in this census; '
                . 'adding it to MOSAIC_EMITTERS or MUST_STAY_APART is the deliberate act this test demands',
        );
    }

    /** @return array<string, array{0: string}> */
    public static function apartFiles(): array
    {
        $cases = [];
        foreach (self::MUST_STAY_APART as $file) {
            $cases[$file] = [$file];
        }

        return $cases;
    }

    /**
     * The census itself: graphics/bridge/contract files name the optimizer nowhere in code.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('apartFiles')]
    public function testSeparateFilesCarryNoOptimizerReference(string $file): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__) . '/src/Renderer/' . $file);

        self::assertSame(
            0,
            self::optimizerReferences($source),
            $file . ' must not touch the mosaic emitter — graphics protocols emit blobs the pen cannot diff',
        );
    }

    /** @return array<string, array{0: string}> */
    public static function mosaicFiles(): array
    {
        $cases = [];
        foreach (self::MOSAIC_EMITTERS as $file) {
            $cases[$file] = [$file];
        }

        return $cases;
    }

    /**
     * Non-vacuity for the census above: the walk actually sees the references that DO exist, so the
     * zero-assertions are not green because of a blind scanner (or a deleted emitter).
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('mosaicFiles')]
    public function testMosaicEmittersDoReferenceTheOptimizer(string $file): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__) . '/src/Renderer/' . $file);

        self::assertGreaterThan(
            0,
            self::optimizerReferences($source),
            $file . ' is a character-mosaic emitter and must stream through the optimizer — if this went '
                . 'green-to-red because the wiring moved, update the roster and this census together',
        );
    }

    public function testTheWalkerSeesCodeNotProse(): void
    {
        self::assertSame(1, self::optimizerReferences('<?php $p = new OutputOptimizer();'), 'bare T_STRING use counts');
        self::assertSame(1, self::optimizerReferences('<?php $p = OutputOptimizer::trueColor(0, 0, 0);'), 'static call counts');
        self::assertSame(
            1,
            self::optimizerReferences('<?php use SugarCraft\Image\Support\OutputOptimizer;'),
            'a qualified use statement is one reference (PHP 8 lexes the whole path as one T_NAME_QUALIFIED)',
        );
        self::assertSame(
            1,
            self::optimizerReferences('<?php $p = \\SugarCraft\\Image\\Support\\OutputOptimizer::new();'),
            'a fully-qualified call counts too',
        );
        self::assertSame(0, self::optimizerReferences('<?php // OutputOptimizer mentioned in prose'), 'comments are not code');
        self::assertSame(0, self::optimizerReferences('<?php $x = \'OutputOptimizer\';'), 'string payloads are not code');
        self::assertSame(
            0,
            self::optimizerReferences('<?php class OutputOptimizerProbe {}'),
            'a longer name that merely starts with the class is not a reference',
        );
    }

    /**
     * Occurrences of the OutputOptimizer symbol as a real code reference; comments and strings excluded
     * because PhpToken keeps them out of the T_STRING / T_NAME_* alphabet (DetectClosedStdinTest idiom).
     */
    private static function optimizerReferences(string $source): int
    {
        $needle = 'OutputOptimizer';
        $count = 0;
        foreach (token_get_all($source) as $token) {
            if (!\is_array($token)) {
                continue;
            }
            if ($token[0] !== \T_STRING && $token[0] !== \T_NAME_QUALIFIED && $token[0] !== \T_NAME_FULLY_QUALIFIED) {
                continue;
            }
            $text = $token[1];
            if ($text === $needle) {
                $count++;
                continue;
            }
            if (strlen($text) > strlen($needle)
                && str_ends_with($text, $needle)
                && substr($text, -strlen($needle) - 1, 1) === '\\'
            ) {
                $count++;
            }
        }

        return $count;
    }
}
