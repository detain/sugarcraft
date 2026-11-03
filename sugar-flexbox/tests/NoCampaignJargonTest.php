<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Fail-closed guard: campaign-internal identifiers must never ship in
 * user-facing prose. Findings ledgers, planning-doc citations and ruling
 * labels mean nothing to a library reader; the substance they witnessed
 * belongs stated inline in its own words.
 *
 * The detector is proven, not trusted: a planted token in an isolated
 * fixture tree MUST be accused, so a broken walk can never turn this
 * guard silently green.
 */
final class NoCampaignJargonTest extends TestCase
{
    /** @var list<string> substrings that must not appear in shipped prose */
    private const BANNED = [
        'docs-fix-1023',
        'step 10.12',
        'ruling 3b',
        'docs/research/',
    ];

    /** @var list<string> package-relative surfaces scanned in full */
    private const SCANNED = ['src', 'tests'];

    private const SCANNED_FILES = ['README.md'];

    /** @var list<string> this guard itself has to name the banned tokens */
    private const EXEMPT = ['NoCampaignJargonTest.php'];

    public function testShippedProseCarriesNoCampaignJargon(): void
    {
        $root = \dirname(__DIR__);
        $offenders = self::scan($root);
        $this->assertSame([], $offenders, 'campaign jargon in shipped prose: ' . implode(', ', $offenders));
    }

    public function testTheDetectorAccusesAPlantedToken(): void
    {
        // Reverse parity: the whole-tree pass above is only meaningful if
        // the detector actually sees files. Plant every banned token in a
        // throwaway tree and demand the accusation.
        $dir = \sys_get_temp_dir() . '/flexbox-jargon-probe-' . \uniqid('', true);
        \mkdir($dir . '/src', 0o777, true);
        \mkdir($dir . '/tests', 0o777, true);
        try {
            $expected = [];
            foreach (self::BANNED as $i => $token) {
                $file = ($i % 2 === 0 ? 'src' : 'tests') . "/probe{$i}.php";
                \file_put_contents(
                    $dir . '/' . $file,
                    "<?php // reviewed under " . $token . " OK\n",
                );
                $expected[] = $file;
            }
            $offenders = self::scan($dir);
            \sort($offenders);
            \sort($expected);
            $this->assertSame($expected, $offenders, 'detector missed planted tokens');
        } finally {
            foreach (\glob($dir . '/*/*') as $f) {
                @\unlink($f);
            }
            @\rmdir($dir . '/src');
            @\rmdir($dir . '/tests');
            @\rmdir($dir);
        }
    }

    /** @return list<string> package-relative paths of offending files */
    private static function scan(string $root): array
    {
        $offenders = [];
        $files = [];
        foreach (self::SCANNED as $sub) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $sub, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($it as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = \substr($file->getPathname(), \strlen($root) + 1);
                }
            }
        }
        foreach (self::SCANNED_FILES as $rel) {
            $files[] = $rel;
        }
        \sort($files);
        foreach ($files as $rel) {
            if (\in_array(\basename($rel), self::EXEMPT, true)) {
                continue;
            }
            $path = $root . '/' . $rel;
            if (!\is_file($path)) {
                // The planted-token probe runs scan() against a throwaway
                // tree that ships no README; the live scan always finds one.
                continue;
            }
            $body = (string) \file_get_contents($path);
            foreach (self::BANNED as $token) {
                if (\str_contains($body, $token)) {
                    $offenders[] = $rel;
                    break;
                }
            }
        }
        return $offenders;
    }
}
