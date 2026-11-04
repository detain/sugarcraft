<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui;

use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

/**
 * CTSP-F-3: the Tui renderer's size seam must stay test-only.
 *
 * Tui\Renderer::setSize() and ::resetSizeCache() write the process-global
 * frame box the fallback probe reads. Production never needs them: every
 * live frame threads its size through the explicit renderView() arguments
 * (the model's own rows/cols, kept fresh by WindowSizeMsg), and the probe
 * answers exactly once per process by design. A production call site would
 * re-introduce the stale-size trap this whole seam drifted into once
 * already — a pinned box consulted forever after the terminal changed.
 *
 * So the census walks src/ and bin/ and demands ZERO call sites. It skips
 * tests/ on purpose: pinning frames from a test is the seam's actual
 * purpose (see the setSize docblock). This is also the standing answer to
 * the TUIARCH-06 meta-finding — renderers here are statics-by-construction,
 * and where a static cannot be removed yet, at least its mutation surface
 * is fenced. If the API ever becomes real, thread the size explicitly
 * instead and retire this walk.
 */
final class RendererSizeSeamCensusTest extends TestCase
{
    /** The seam methods the census polices. */
    private const SEAM_METHODS = ['setSize', 'resetSizeCache'];

    /** Directories holding production code, relative to the lib root. */
    private const PRODUCTION_DIRS = ['src', 'bin'];

    public function testTheSizeSeamHasNoProductionCallers(): void
    {
        $root = \dirname(__DIR__, 2);
        $seamFile = $root . '/src/Tui/Renderer.php';
        $hits = [];

        foreach (self::PRODUCTION_DIRS as $dir) {
            $path = $root . '/' . $dir;
            if (!\is_dir($path)) {
                throw new RuntimeException('Census root vanished: ' . $path);
            }

            $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
            foreach ($walk as $entry) {
                if (!$entry instanceof \SplFileInfo || $entry->getExtension() !== 'php') {
                    continue;
                }

                $text = \file_get_contents($entry->getPathname());
                if ($text === false || $text === '') {
                    continue;
                }

                $tokens = PhpToken::tokenize($text);
                $count = \count($tokens);
                for ($i = 0; $i < $count; $i++) {
                    $token = $tokens[$i];
                    if ($token->id !== T_STRING || !\in_array($token->text, self::SEAM_METHODS, true)) {
                        continue;
                    }

                    $previous = self::previousSignificant($tokens, $i);
                    if ($previous?->is(T_FUNCTION)) {
                        continue; // The definition itself, wherever it lives.
                    }

                    $next = self::nextSignificant($tokens, $i);
                    if ($next?->text !== '(') {
                        continue; // A doc comment word or a first-class callable name, not a call.
                    }

                    if ($entry->getRealPath() === $seamFile) {
                        self::fail('The seam file itself must not call its own seam: ' . self::relative($root, $entry) . ':' . $token->line);
                    }

                    $hits[] = self::relative($root, $entry) . ':' . $token->line;
                }
            }
        }

        self::assertSame(
            [],
            $hits,
            'Tui\Renderer::setSize()/::resetSizeCache() are the TEST seam; a production caller would freeze a stale terminal box, because live frames thread their size through renderView() instead (CTSP-F-3). If the API is becoming real, thread the size explicitly and retire this census.'
        );
    }

    /**
     * @param array<int, PhpToken> $tokens
     */
    private static function previousSignificant(array $tokens, int $index): ?PhpToken
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            if ($tokens[$i]->id !== T_WHITESPACE) {
                return $tokens[$i];
            }
        }

        return null;
    }

    /**
     * @param array<int, PhpToken> $tokens
     */
    private static function nextSignificant(array $tokens, int $index): ?PhpToken
    {
        for ($i = $index + 1, $count = \count($tokens); $i < $count; $i++) {
            if ($tokens[$i]->id !== T_WHITESPACE) {
                return $tokens[$i];
            }
        }

        return null;
    }

    private static function relative(string $root, \SplFileInfo $entry): string
    {
        $real = $entry->getRealPath();
        if ($real === false) {
            throw new RuntimeException('Census entry unreadable: ' . $entry->getPathname());
        }

        return \substr($real, \strlen($root) + 1);
    }
}
