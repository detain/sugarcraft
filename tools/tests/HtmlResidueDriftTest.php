<?php

declare(strict_types=1);

namespace SugarCraft\Tools\Tests;

require_once __DIR__ . '/_bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * RT-34 (repo-tooling-037): the published site embeds `docs/_data/*.body.html`
 * — and the narrative markdown converter's raw-HTML passthrough — VERBATIM.
 * Before this round anything merged to those sources reached
 * https://sugarcraft.github.io unfiltered: no auth or cookies are at stake on
 * the target, but the ORIGIN is trusted, and that is enough for phishing,
 * brand damage, and drive-by. Three enforcement points now cover it —
 * gen-docs.php (generation), gen-doc-pages.php (narrative build), and
 * check-docs-assets.php (the retroactive second point over committed bytes) —
 * each carrying its own copy of one deny-list function.
 *
 * THREE COPIES BECAUSE THE TOOLS/ SCRIPTS CANNOT INCLUDE EACH OTHER (every
 * top-level `tools/*.php` is a `--help`-answering script under the
 * ToolsEnvRosterTest census; an included library file would join that roster
 * and answer to nothing), and copies are only defensible while divergence is
 * LOUD: this file compares the bodies TOKEN BY TOKEN with the shared
 * extractor, so a comment or a wrap is free and a changed decision is a red
 * naming both sides. Same posture as the sugar-pty scanner copy pin.
 *
 * ONE ATTRIBUTE IS ALLOW-LISTED BY EXACT FULL LITERAL: the repo's own
 * canonical demo-GIF crutch (`gen-docs.php::DEMO_GIF_ONERROR`, demanded by
 * check-docs-assets' crutch leg, already carried inside two committed _data
 * bodies). Not the shape — the string. A single-quoted lookalike or any other
 * value still lands in findings, which is what the fourth fixture below pins:
 * the deny-list "expect zero residue" note in the plan carried exactly one
 * residue, and verify-not-assume is the reason the allow-list is this narrow.
 *
 * @internal
 */
final class HtmlResidueDriftTest extends TestCase
{
    use PhpFunctionBodyTokens;
    /** tool script => the deny-list function's name there. */
    private const COPIES = [
        '/tools/gen-docs.php' => 'html_residue_problems',
        '/tools/gen-doc-pages.php' => 'html_residue_problems',
        '/tools/check-docs-assets.php' => 'htmlResidueProblems',
    ];

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function residueShapes(): iterable
    {
        yield 'a script tag' => ['<p>ok</p><script>alert(1)</script>', 'disallowed tag <script>'];
        yield 'an iframe' => ['<iframe src="https://x">', 'disallowed tag <iframe>'];
        yield 'an event handler' => ['<img src=x onerror="fetch(1)">', 'event handler attribute onerror'];
        yield 'a single-quoted crutch lookalike is still residue' => [
            '<img src=x onerror=\'steal()\'>',
            'event handler attribute onerror',
        ];
        yield 'a javascript url' => ['<a href="javascript:void(0)">j</a>', 'scriptable URL scheme javascript:'];
        yield 'a data-html url' => ['<a href="data:text/html;base64,PHNjcmlwdD4=">d</a>', 'scriptable URL scheme data:text/html'];
        yield 'a meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://x">', 'meta http-equiv'];
        yield 'the canonical demo crutch passes' => [
            '<img src="https://raw.githubusercontent.com/detain/sugarcraft/master/sugar-x/.vhs/y.gif" alt="y" '
            . 'onerror="this.parentElement.style.display=\'none\'">',
            '',
        ];
    }

    /**
     * @dataProvider residueShapes
     */
    public function testTheDenyListSeparatesResidueFromCorpusChrome(string $html, string $expected): void
    {
        // The check-docs-assets copy is the EXECUTABLE one: gen-docs.php and
        // gen-doc-pages.php run their whole build at top level (no CLI main
        // guard), so requiring them is not an option and behaviour there is
        // pinned through THE TOKEN-IDENTITY ARM BELOW — three bodies that
        // compare equal by token cannot answer differently. Only the guarded
        // copy is called here.
        $root = \dirname(__DIR__, 2);
        require_once $root . '/tools/check-docs-assets.php';
        /** @var list<string> $found */
        $found = \CheckDocsAssets::htmlResidueProblems($html);
        if ($expected === '') {
            $this->assertSame([], $found, 'the deny-list flagged the repo\'s own canonical crutch — the allow-list must let the corpus it standardises pass');

            return;
        }
        $this->assertNotEmpty($found, 'the deny-list saw no residue in: ' . $html);
        $this->assertStringContainsString($expected, \implode('; ', $found), 'the deny-list missed [' . $expected . '] in: ' . $html);
    }

    public function testTheThreeCopiesCannotDriftApart(): void
    {
        $root = \dirname(__DIR__, 2);
        $sources = [];
        foreach (\array_keys(self::COPIES) as $file) {
            $sources[$file] = (string) \file_get_contents($root . $file);
        }
        $names = \array_values(self::COPIES);
        $paths = \array_keys(self::COPIES);
        for ($i = 1; $i < 3; $i++) {
            $a = self::functionBodyTokens($sources[$paths[0]], $names[0]);
            $b = self::functionBodyTokens($sources[$paths[$i]], $names[$i]);
            $this->assertNotSame([], $a, "no body was found for {$names[0]} in {$paths[0]}");
            $this->assertNotSame([], $b, "no body was found for {$names[$i]} in {$paths[$i]}");
            $this->assertSame(
                $a,
                $b,
                "the deny-list in {$paths[$i]} has drifted from {$paths[0]} — port the change across "
                . 'all three seats deliberately; a narrower filter at one enforcement point is the '
                . 'stored-XSS hole this guard exists to keep shut',
            );
        }
    }

    public function testTheCommittedCorpusCarriesNoResidueBeyondTheCanonicalCrutch(): void
    {
        $root = \dirname(__DIR__, 2);
        $bodies = \glob($root . '/docs/_data/*.body.html') ?: [];
        $this->assertGreaterThanOrEqual(40, \count($bodies), 'the _data corpus walk found almost nothing — this arm is scanning an empty tree');
        require_once $root . '/tools/check-docs-assets.php';
        $dirty = [];
        foreach ($bodies as $body) {
            foreach (\CheckDocsAssets::htmlResidueProblems((string) \file_get_contents($body)) as $problem) {
                $dirty[] = \basename($body) . ': ' . $problem;
            }
        }
        $this->assertSame(
            [],
            $dirty,
            'committed _data bodies carry residue — ' . \implode(' | ', $dirty) . '. The retroactive '
            . 'leg of check-docs-assets.php will red CI; scrub the source or extend the allow-list '
            . 'with the same exact-literal discipline as DEMO_GIF_ONERROR (and all three copies together)',
        );
    }

    public function testAllThreeEnforcementSeatsAreReal(): void
    {
        $root = \dirname(__DIR__, 2);
        $this->assertMatchesRegularExpression(
            '/html_residue_problems\(\$body\)/',
            (string) \file_get_contents($root . '/tools/gen-docs.php'),
            'gen-docs.php no longer calls the deny-list on the body it embeds verbatim',
        );
        $this->assertMatchesRegularExpression(
            '/html_residue_problems\(\$body\)/',
            (string) \file_get_contents($root . '/tools/gen-doc-pages.php'),
            'gen-doc-pages.php no longer calls the deny-list on the narrative body',
        );
        $this->assertMatchesRegularExpression(
            '/self::htmlResidue\(\$root\)/',
            (string) \file_get_contents($root . '/tools/check-docs-assets.php'),
            'check-docs-assets.php dropped the retroactive residue leg from derive()',
        );
    }

    public function testTheRetroactiveLegRedsAPlantedBodyEndToEnd(): void
    {
        $root = \sys_get_temp_dir() . '/residue-leg-' . \bin2hex(\random_bytes(6));
        try {
            \mkdir($root . '/docs/_data', 0777, true);
            \file_put_contents($root . '/docs/_data/planted.body.html', "<p>x</p><script>alert(1)</script>");
            $lines = [];
            $rc = 0;
            \exec(
                \escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg(\dirname(__DIR__) . '/check-docs-assets.php')
                . ' --root=' . \escapeshellarg($root) . ' 2>&1',
                $lines,
                $rc,
            );
            $out = \implode("\n", $lines);
            $this->assertSame(1, $rc, 'output: ' . $out);
            $this->assertStringContainsString('docs/_data/planted.body.html: disallowed tag <script>', $out);
        } finally {
            @unlink($root . '/docs/_data/planted.body.html');
            @rmdir($root . '/docs/_data');
            @rmdir($root . '/docs');
            @rmdir($root);
        }
    }

}
