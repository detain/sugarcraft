<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tests\Support\RunsWallClockBoundedChildTrait;

/**
 * C-SES-6.c: `tools/gen-homebrew-formula.php` rendering
 * `packaging/homebrew/candy-code.rb.tmpl` into a formula.
 *
 * The plan's §6.1 ruling declined a Homebrew tap: the formula ships as a
 * release asset only (C-SES-6.d attaches it), installed via
 * `brew install --formula <url>`. What this lane owns is the RENDERER, so
 * that is what is pinned: the three placeholders land with the validated
 * values, the fail-closed edges refuse garbage without writing a partial
 * file, and the rendered text carries the load-bearing lines (version,
 * sha256, url, the php dep, the phar-wrapping install and the --version
 * smoke test). Nothing here talks to Homebrew; `brew` is not on this host
 * and the formula is judged as text, exactly as the release workflow will
 * ship it.
 */
final class HomebrewFormulaTest extends TestCase
{
    use RunsWallClockBoundedChildTrait;

    private const SAMPLE_SHA256 = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private string $generatorPath;

    private string $templatePath;

    private string $root;

    protected function setUp(): void
    {
        $this->generatorPath = \dirname(__DIR__, 2) . '/tools/gen-homebrew-formula.php';
        $this->templatePath = \dirname(__DIR__, 2) . '/packaging/homebrew/candy-code.rb.tmpl';
        $this->assertIsReadable($this->generatorPath);
        $this->assertIsReadable($this->templatePath);

        $this->root = (string) \realpath(\sys_get_temp_dir()) . '/cc_ses6c_hb_' . \bin2hex(\random_bytes(6));
        \mkdir($this->root);
    }

    protected function tearDown(): void
    {
        foreach ((array) \glob($this->root . '/*') as $leftover) {
            \is_file((string) $leftover) ? \unlink((string) $leftover) : \rrmdir((string) $leftover);
        }
        \rmdir($this->root);
    }

    public function testTheTemplateCarriesEachPlaceholderExactlyOnce(): void
    {
        $template = (string) \file_get_contents($this->templatePath);

        foreach (['{{VERSION}}', '{{URL}}', '{{SHA256}}'] as $placeholder) {
            $this->assertSame(
                1,
                \substr_count($template, $placeholder),
                'the generator substitutes every occurrence, so a doubled placeholder in the '
                    . 'template would render twice and the "no placeholder survived" arm below '
                    . 'could never catch template/generator drift'
            );
        }
        $this->assertStringContainsString('class CandyCode < Formula', $template);
    }

    public function testTheRenderedFormulaCarriesVersionUrlAndSha(): void
    {
        [$status, $output] = $this->runGenerator([
            '--version=v9.8.7',
            '--sha256=' . self::SAMPLE_SHA256,
        ]);

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('class CandyCode < Formula', $output);
        $this->assertStringContainsString('version "9.8.7"', $output);
        $this->assertStringContainsString('sha256 "' . self::SAMPLE_SHA256 . '"', $output);
        $this->assertStringContainsString(
            'url "https://github.com/detain/sugarcraft/releases/download/candy-code/v9.8.7/candy-code.phar"',
            $output
        );
        $this->assertStringContainsString('license "MIT"', $output);
        $this->assertStringContainsString('depends_on "php"', $output);
        $this->assertStringContainsString('libexec.install "candy-code.phar"', $output);
        $this->assertStringContainsString('assert_match version.to_s', $output);
        $this->assertStringNotContainsString('{{', $output);
        $this->assertStringNotContainsString('}}', $output);
    }

    public function testTheVersionSpellingIsNormalisedFromEitherSourceForm(): void
    {
        // The release tooling speaks tags ("candy-code/v1.2.3") and the phar
        // build stamps speak "v1.2.3"; a formula needs the bare triple and a
        // URL that keeps the tag's leading v.
        [$status, $output] = $this->runGenerator([
            '--version=candy-code/v1.2.3',
            '--sha256=' . self::SAMPLE_SHA256,
        ]);

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('version "1.2.3"', $output);
        $this->assertStringContainsString('/candy-code/v1.2.3/candy-code.phar', $output);
    }

    public function testTheDigestMayBeHashedFromABuiltPhar(): void
    {
        $pharFile = $this->root . '/candy-code.phar';
        $bytes = 'STUB-PHAR-BYTES-' . \uniqid('', true);
        \file_put_contents($pharFile, $bytes);

        [$status, $output] = $this->runGenerator([
            '--version=0.1.1',
            '--phar=' . $pharFile,
        ]);

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('sha256 "' . \hash('sha256', $bytes) . '"', $output);
    }

    public function testAnExplicitUrlOverrideReplacesTheGitHubShape(): void
    {
        // --base-url in the mirror, this is --url in the generator: whatever
        // C-SES-6.d's release job needs for a non-default asset location.
        [$status, $output] = $this->runGenerator([
            '--version=0.1.1',
            '--sha256=' . self::SAMPLE_SHA256,
            '--url=https://example.invalid/pool/candy-code.phar',
        ]);

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('url "https://example.invalid/pool/candy-code.phar"', $output);
        $this->assertStringNotContainsString('github.com/detain/sugarcraft/releases/download', $output);
    }

    public function testGarbageInputIsRefusedWithoutWritingAnything(): void
    {
        $outFile = $this->root . '/should-not-exist.rb';
        $cases = [
            ['--version=1.2', '--sha256=' . self::SAMPLE_SHA256, '--out=' . $outFile],
            ['--version=one.two.three', '--sha256=' . self::SAMPLE_SHA256, '--out=' . $outFile],
            ['--version=0.1.1', '--out=' . $outFile], // neither digest source
            [
                '--version=0.1.1',
                '--sha256=' . \str_repeat('A', 64), // Homebrew digests are lowercase hex
                '--out=' . $outFile,
            ],
            ['--version=0.1.1', '--phar=' . $this->root . '/absent.phar', '--out=' . $outFile],
            ['--version=0.1.1', '--sha256=' . self::SAMPLE_SHA256, '--phar=' . $this->generatorPath, '--out=' . $outFile],
            ['--sha256=' . self::SAMPLE_SHA256, '--out=' . $outFile], // no version at all
            ['--bogus=1', '--out=' . $outFile],
        ];

        foreach ($cases as $flags) {
            [$status, $output] = $this->runGenerator($flags);
            $this->assertNotSame(0, $status, 'expected refusal for: ' . \implode(' ', $flags) . ' said: ' . $output);
            $this->assertFileDoesNotExist($outFile, 'a refused render must not leave a partial formula');
        }
    }

    public function testHelpExitsZeroAndOutMatchesStdout(): void
    {
        [$status, $output] = $this->runGenerator(['--help']);
        $this->assertSame(0, $status);
        $this->assertStringContainsString('gen-homebrew-formula', $output);

        $outFile = $this->root . '/candy-code.rb';
        [$status, $stdout] = $this->runGenerator([
            '--version=0.1.1',
            '--sha256=' . self::SAMPLE_SHA256,
            '--out=' . $outFile,
        ]);
        $this->assertSame(0, $status, $stdout);
        $this->assertSame('', $stdout, 'with --out the formula must not also be sprayed at stdout');
        $this->assertStringContainsString(
            'sha256 "' . self::SAMPLE_SHA256 . '"',
            (string) \file_get_contents($outFile)
        );

        // And the stdout shape matches the file shape (exec() strips the
        // child's final newline, so the file is compared rtrimmed), which is
        // what the release workflow captures.
        [$status, $sameAgain] = $this->runGenerator(['--version=0.1.1', '--sha256=' . self::SAMPLE_SHA256]);
        $this->assertSame(0, $status);
        $this->assertSame(\rtrim((string) \file_get_contents($outFile), "\n"), $sameAgain);
    }

    /**
     * @param list<string> $flags
     *
     * @return array{0: int, 1: string} exit status and merged stdout+stderr
     */
    private function runGenerator(array $flags): array
    {
        $argv = [\PHP_BINARY, $this->generatorPath, ...$flags];
        $command = \implode(' ', \array_map('escapeshellarg', $argv));
        [$status, $lines] = $this->runWallClockBoundedChild('', $command . ' 2>&1');

        return [$status, \implode("\n", $lines)];
    }
}
