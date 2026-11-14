<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Drift guard for examples/ fixtures (candy-git-003, critical).
 *
 * The hero-GIF fatal shipped silently because nothing class-loads
 * examples/*.php: play.php's anonymous GitDriver kept an old diff()
 * signature and lacked the newer interface members, so `php examples/play.php`
 * died at declaration while every unit suite stayed green. This test extracts
 * each example's fixture declaration (token-aware, so strings and comments
 * can't fool the brace walk), compiles it in a subprocess against the LIVE
 * interface, and boots App::start() on it — declaration drift is now a red
 * test, not a red demo gif.
 *
 * Mirrors the LangCoverageTest law: what the product runs must be touched by
 * the suite.
 */
final class ExamplesSmokeTest extends TestCase
{
    private static function libRoot(): string
    {
        return dirname(__DIR__);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function exampleFiles(): array
    {
        $files = glob(self::libRoot() . '/examples/*.php') ?: [];
        sort($files);
        $cases = [];
        foreach ($files as $file) {
            $cases[basename($file)] = [$file];
        }
        self::assertNotEmpty($cases, 'the guard must never go vacuous: examples/ must ship at least one fixture');
        return $cases;
    }

    #[DataProvider('exampleFiles')]
    public function testFixtureCompilesAgainstTheLiveDriverAndBootsTheApp(string $file): void
    {
        $declaration = self::extractDriverDeclaration((string) file_get_contents($file), $file);

        $tmp = tempnam(sys_get_temp_dir(), 'cgexample') . '.php';
        try {
            file_put_contents($tmp, implode("\n", [
                '<?php',
                'require ' . var_export(self::libRoot() . '/vendor/autoload.php', true) . ';',
                'use SugarCraft\Git\GitDriver;',
                '$fixture = ' . $declaration . ';',
                'if (!$fixture instanceof \SugarCraft\Git\GitDriver) { fwrite(STDERR, "NOT-A-DRIVER"); exit(3); }',
                'if (!\SugarCraft\Git\App::start($fixture) instanceof \SugarCraft\Git\App) { exit(4); }',
                'echo "BOOT-OK";',
            ]));

            exec(\escapeshellarg(PHP_BINARY) . ' ' . \escapeshellarg($tmp) . ' 2>&1', $lines, $rc);
            $out = implode("\n", $lines);

            self::assertSame(
                0,
                $rc,
                'examples fixture in ' . basename($file) . ' no longer compiles/boots against the live interface '
                . "(rc={$rc}):\n" . $out,
            );
            self::assertStringContainsString('BOOT-OK', $out, 'silent non-boot of ' . basename($file) . ': ' . $out);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Hermeticity law for the tapes (candy-git-087): a .tape drives
     * `php examples/<demo>.php`, never a live `./bin/<app>` against whatever
     * the render host happens to have checked out.
     */
    public function testTapesDriveExamplesNotTheLiveBinary(): void
    {
        $tapes = glob(self::libRoot() . '/.vhs/*.tape') ?: [];
        self::assertNotEmpty($tapes, 'the tape census must never go vacuous');
        foreach ($tapes as $tape) {
            $body = (string) file_get_contents($tape);
            self::assertStringNotContainsString(
                './bin/',
                $body,
                basename($tape) . ' drives the live binary — the render is environment-dependent (finding 087)',
            );
        }
    }

    /**
     * Pull the `new class ... implements GitDriver { ... }` expression out of
     * an example file using the real tokenizer: `new` followed by `class`,
     * then the first balanced brace group. Only STRUCTURAL brace tokens move
     * the depth (literal '{'/'}' chars, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES)
     * — braces inside strings like 'stash@{0}' are single
     * T_CONSTANT_ENCAPSED_STRING tokens and can never skew the count.
     */
    private static function extractDriverDeclaration(string $src, string $file): string
    {
        $tokens = token_get_all($src);
        $count  = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_NEW) {
                continue;
            }
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if ($j >= $count || !is_array($tokens[$j]) || $tokens[$j][0] !== T_CLASS) {
                continue;
            }

            $text  = '';
            $depth = 0;
            $seen  = false;
            for ($k = $i; $k < $count; $k++) {
                $token = $tokens[$k];
                $chunk = is_array($token) ? $token[1] : (string) $token;
                $text .= $chunk;

                $structural = false;
                if ($token === '{') {
                    $depth++;
                    $structural = true;
                } elseif ($token === '}') {
                    $depth--;
                    $structural = true;
                } elseif (is_array($token) && ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES)) {
                    $depth++;
                    $structural = true;
                }
                if (!$structural) {
                    continue;
                }
                self::assertGreaterThanOrEqual(0, $depth, "brace walk underflowed in {$file}");
                if ($depth === 0 && $seen) {
                    self::assertStringContainsString(
                        'GitDriver',
                        $text,
                        "a `new class` in {$file} was extracted but does not implement GitDriver — the guard's contract drifted",
                    );
                    return $text;
                }
                if ($depth > 0) {
                    $seen = true;
                }
            }
            self::fail("unbalanced class body in {$file}");
        }

        self::fail("no `new class ... implements GitDriver` fixture declaration found in {$file} — "
            . 'examples must keep this shape or the guard must be extended to match it deliberately');
    }
}
