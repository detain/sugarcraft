<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\I18n;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Exception\ProgramException;
use SugarCraft\Core\Exception\RenderException;
use SugarCraft\Core\Exception\TerminalException;
use SugarCraft\Core\Lang;

/**
 * Guards the Lang::t() roster on disk (sc-20, finding sugar-core-022).
 *
 * Finding 022's headline was a 16-locale library whose new failure paths
 * threw raw English and whose `fromKey()` arms cited keys ABSENT from
 * `lang/en.php` (a missing key silently degrades to the raw key — even a
 * strong assertion would only pin the failure). The raw-English corpus is
 * now swept to `Lang::t('<area>.<snake>')`; this roster test is the machine
 * guard that keeps it swept: every literal key referenced by src MUST exist
 * in the English catalog, and the three named absents the finding owns
 * render real English instead of falling back to the raw key.
 *
 * Block comments are newline-blanked before scanning so doc-EXAMPLES (like
 * T.php's `heatmap.*` illustration for another library's namespace) do not
 * count as references, while every surviving line keeps its true number.
 */
final class LangTest extends TestCase
{
    /**
     * @return array<string, list<string>> key => non-empty list of `file:line` refs
     */
    private static function referencedKeys(): array
    {
        $refs = [];
        $srcRoot = realpath(\dirname(__DIR__, 2) . '/src');
        self::assertNotFalse($srcRoot);
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcRoot, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            $code = (string) preg_replace_callback(
                '!/\*.*?\*/!s',
                static fn(array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
                $code
            );
            foreach (explode("\n", $code) as $offset => $line) {
                if (preg_match_all('!(?:Lang::t|->fromKey|::fromKey)\(\s*([\'"])([a-z0-9_.]+)\1!', $line, $m, PREG_SET_ORDER) >= 1) {
                    foreach ($m as $hit) {
                        $refs[$hit[2]][] = substr($file->getPathname(), strlen($srcRoot) + 1) . ':' . ($offset + 1);
                    }
                }
            }
        }
        return $refs;
    }

    public function testEveryKeyReferencedBySrcExistsInEnglishCatalog(): void
    {
        /** @var array<string, string> $en */
        $en = require \dirname(__DIR__, 2) . '/lang/en.php';
        $refs = self::referencedKeys();
        self::assertNotEmpty($refs, 'the src scan found no Lang::t references at all — the guard is toothless');
        $missing = [];
        foreach ($refs as $key => $sites) {
            if (!isset($en[$key])) {
                $missing[] = $key . ' (referenced by ' . implode(', ', $sites) . ')';
            }
        }
        self::assertSame(
            [],
            $missing,
            'Lang::t/fromKey keys referenced by src but absent from lang/en.php — a missing key '
            . 'silently renders the raw key (finding sugar-core-022): ' . implode('; ', $missing)
        );
    }

    public function testTheGuardScansExecutableCodeNotDocExamples(): void
    {
        // Negative control for the scanner itself: the heatmap example lives
        // ONLY in I18n/T.php's docblock; a guard that counted it would name
        // a key no core call site uses.
        $refs = self::referencedKeys();
        self::assertArrayNotHasKey(
            'heatmap.invalid_dimensions',
            $refs,
            'the roster scan counted a docblock example — the guard would demand a key from another lib'
        );
        self::assertArrayHasKey('builder.framerate_positive', $refs, 'the swept builder arm must be counted');
    }

    public function testFinding022NamedAbsentsRenderRealEnglish(): void
    {
        // The trio 022 named (verified absent pre-sweep) must now resolve to
        // cataloged English through the fromKey arms — never the raw key.
        $cases = [
            [TerminalException::class, 'tty.open_failed'],
            [RenderException::class, 'render.buffer_overflow'],
            [ProgramException::class, 'program.init_failed'],
        ];
        foreach ($cases as [$class, $key]) {
            $message = $class::fromKey($key)->getMessage();
            self::assertNotSame($key, $message, "$key renders as the raw key — still absent from the catalog");
            self::assertNotSame('', $message, "$key renders empty");
        }
        // Exact English voice pinned once so translations stay answerable.
        self::assertSame('could not open the terminal', Lang::t('tty.open_failed'));
        self::assertSame(
            'render buffer overflow: the frame exceeded the byte ceiling',
            Lang::t('render.buffer_overflow')
        );
        self::assertSame('program initialization failed', Lang::t('program.init_failed'));
    }
}
