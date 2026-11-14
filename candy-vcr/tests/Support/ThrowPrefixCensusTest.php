<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Fails closed if any `throw` in src/ grows an embedded tool-name prefix.
 *
 * The whole CLI re-wraps escaped exceptions with its own label
 * (`candy-vcr record: `, `candy-vcr inspect: `, `candy-vcr render-tape: ` …),
 * so a prefix
 * baked into the thrown message reaches users doubled — the exact shape this
 * library's own boundary law (src/Cli/FpsOption.php) forbids. The census
 * walks throw statements only: direct writers (fwrite/error_log sites that
 * emit without any re-wrapping catch) legitimately carry their own label and
 * are out of scope here.
 *
 * Mirrors the fleet's census-guard genre (fail-closed: an unreadable source
 * file aborts the run rather than silently shrinking the corpus).
 */
final class ThrowPrefixCensusTest extends TestCase
{
    private const TOOL_NAME = 'candy-vcr';

    /**
     * Lower bound on throw sites the walk must observe; a drop means the
     * scanner went blind (or the tree lost a family of doors) and the guard
     * would otherwise pass vacuously.
     */
    private const THROW_SITE_FLOOR = 50;

    public function testNoThrowInTheLibraryEmbedsTheToolNamePrefix(): void
    {
        $violations = [];
        $throwSites = 0;
        foreach (self::sourceFiles() as $file) {
            $source = @file_get_contents($file);
            if ($source === false) {
                $this->fail('census could not read ' . $file);
            }
            [$sites, $bad] = self::scanThrowSites($source, $file);
            $throwSites += $sites;
            $violations = array_merge($violations, $bad);
        }

        self::assertGreaterThanOrEqual(
            self::THROW_SITE_FLOOR,
            $throwSites,
            'the throw-site census fell below its floor — the scanner lost coverage'
        );
        self::assertSame([], $violations, "throw sites must not embed the '" . self::TOOL_NAME . ": ' prefix (every CLI catch re-wraps): \n" . implode("\n", $violations));
    }

    /**
     * Positive control: the scanner must flag a planted violation, proving
     * the main assertion is not green by construction.
     */
    public function testTheScannerFlagsAPlantedPrefix(): void
    {
        $planted = "<?php\nthrow new \\RuntimeException('" . self::TOOL_NAME . ": planted');\n";
        [$sites, $bad] = self::scanThrowSites($planted, 'memory://planted');
        self::assertSame(1, $sites);
        self::assertCount(1, $bad);

        $clean = "<?php\nthrow new \\RuntimeException('planted without a prefix');\nthrow new \\LogicException(sprintf('dynamic %s', self::TOOL_NAME));\n";
        [$sites, $bad] = self::scanThrowSites($clean, 'memory://clean');
        self::assertSame(2, $sites);
        self::assertSame([], $bad, 'a sprintf-headed (non-literal-first) throw stays out of the literal census, and an unprefixed message passes');
    }

    /**
     * A dynamic first argument (function call, not a string literal) cannot
     * be judged lexically — the census skips it but still counts the site.
     * Interpolated double-quoted literals ARE one token and must be judged.
     */
    public function testInterpolatedAndConcatenatedLiteralsAreBothJudged(): void
    {
        // Line 1 uses a REAL interpolated variable (lexes as an encapsed
        // chunk, not one constant string); line 2 concatenates a leading
        // literal; line 3 is a dynamic call — only the first two flag.
        $dollar = chr(36);
        $src = "<?php\n"
            . 'throw new \RuntimeException("' . self::TOOL_NAME . ': file {' . $dollar . 'path}");' . "\n"
            . 'throw new \RuntimeException(' . chr(39) . self::TOOL_NAME . ': head' . chr(39) . ' . ' . $dollar . 'tail);' . "\n"
            . 'throw new \RuntimeException(buildMessage(' . $dollar . 'tail));' . "\n";
        [$sites, $bad] = self::scanThrowSites($src, 'memory://shapes');
        self::assertSame(3, $sites);
        self::assertCount(2, $bad, 'interpolated and concatenation-headed literals both land in the census; a non-literal first arg does not');
    }

    /**
     * @return list<string>
     */
    private static function sourceFiles(): array
    {
        $root = \dirname(__DIR__, 2) . '/src';
        $files = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iter as $entry) {
            if ($entry->isFile() && str_ends_with($entry->getFilename(), '.php')) {
                $files[] = $entry->getPathname();
            }
        }
        sort($files);
        self::assertNotEmpty($files, 'the src/ walk found no files');
        return $files;
    }

    /**
     * @return array{0:int, 1:list<string>} observed throw-site count and violation lines
     */
    private static function scanThrowSites(string $source, string $file): array
    {
        $tokens = \PhpToken::tokenize($source);
        $sites = 0;
        $violations = [];
        $count = count($tokens);
        foreach ($tokens as $i => $token) {
            if (!$token->is(T_THROW)) {
                continue;
            }
            $j = $i + 1;
            while ($j < $count && $tokens[$j]->is(T_WHITESPACE)) {
                ++$j;
            }
            if ($j >= $count || !$tokens[$j]->is(T_NEW)) {
                continue; // `throw $expr` shapes are outside the literal census
            }
            ++$j;
            // Walk the class reference to its argument list; a statement end
            // before a '(' means an unrecognised shape — skip, never accuse.
            while ($j < $count) {
                if ($tokens[$j]->is('(')) {
                    break;
                }
                if ($tokens[$j]->is(';') || $tokens[$j]->is('{') || $tokens[$j]->is('}')) {
                    $j = -$count;
                    break;
                }
                ++$j;
            }
            if ($j < 0 || $j >= $count || !$tokens[$j]->is('(')) {
                continue;
            }
            ++$j;
            while ($j < $count && $tokens[$j]->is(T_WHITESPACE)) {
                ++$j;
            }
            if ($j < $count && $tokens[$j]->is(')')) {
                $sites++; // throw new X() — no argument to judge
                continue;
            }
            if ($j >= $count) {
                continue;
            }
            $sites++;
            $first = $tokens[$j];
            if ($first->text === '"' || $first->text === "'") {
                // An interpolated double-quoted string lexes the opening
                // quote as its own token; the leading text follows as an
                // encapsed chunk.
                $k = $j + 1;
                while ($k < $count && $tokens[$k]->is(T_WHITESPACE)) {
                    ++$k;
                }
                if ($k < $count && $tokens[$k]->is(T_ENCAPSED_AND_WHITESPACE)) {
                    $first = $tokens[$k];
                }
            }
            if (!$first->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE])) {
                continue; // non-literal first argument — unjudgeable lexically
            }
            $value = trim($first->text, "'\"");
            if (str_starts_with($value, self::TOOL_NAME)) {
                $violations[] = $file . ':' . $first->line;
            }
        }
        return [$sites, $violations];
    }
}
