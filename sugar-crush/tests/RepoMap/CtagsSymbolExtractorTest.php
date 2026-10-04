<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\RepoMap;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\RepoMap\CtagsSymbolExtractor;
use SugarCraft\Crush\RepoMap\Tag;
use SugarCraft\Crush\Tools\BuiltIn\Doctor;

/**
 * Roadmap 5.5-2: the universal-ctags producer. The mapping and the probe are
 * driven through a scripted stand-in binary, so they run on every host; the
 * one live test runs only where a real Universal Ctags with JSON is installed
 * (a GNU Emacs `ctags` on PATH, which Debian ships, is correctly not one).
 */
final class CtagsSymbolExtractorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/sc_ctags_' . \bin2hex(\random_bytes(6));
        \mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->dir . '/{,.}*', \GLOB_BRACE) ?: [] as $file) {
            if (\is_file($file)) {
                @\unlink($file);
            }
        }
        @\rmdir($this->dir);
    }

    // ---- the probe -------------------------------------------------------

    public function testOnlyAUniversalCtagsBannerWithJsonIsAccepted(): void
    {
        self::assertTrue(CtagsSymbolExtractor::isUniversalWithJson(
            "Universal Ctags 6.0.0, Copyright (C) 2015-2022 Universal Ctags Team\n"
            . "  Optional compiled features: +wildcards, +regex, +iconv, +option-directory, +xpath, +json, +interactive\n",
        ));
        self::assertFalse(CtagsSymbolExtractor::isUniversalWithJson(
            "Universal Ctags 5.9.0\n  Optional compiled features: +wildcards, +regex, +iconv\n",
        ), 'built without JSON');
        self::assertFalse(CtagsSymbolExtractor::isUniversalWithJson('ctags (GNU Emacs 29.3)'));
        self::assertFalse(CtagsSymbolExtractor::isUniversalWithJson('Exuberant Ctags 5.8, Copyright (C) 1996-2009'));
    }

    public function testAMissingBinaryIsUnavailableAndExtractsNothing(): void
    {
        $extractor = CtagsSymbolExtractor::new()->withBinary($this->dir . '/no-such-ctags');
        \file_put_contents($this->dir . '/a.py', "def a():\n    pass\n");

        self::assertFalse($extractor->available());
        self::assertSame([], $extractor->extractFiles(['a.py' => $this->dir . '/a.py']));
    }

    public function testANonUniversalCtagsIsUnavailable(): void
    {
        $extractor = CtagsSymbolExtractor::new()->withBinary($this->fakeCtags('ctags (GNU Emacs 29.3)', ''));

        self::assertFalse($extractor->available());
    }

    public function testDoctorReportsWhetherTheRepoMapHasCtags(): void
    {
        $with = (new Doctor(CtagsSymbolExtractor::new()->withBinary($this->fakeCtags(self::UNIVERSAL_BANNER, ''))))->execute([]);
        $without = (new Doctor(CtagsSymbolExtractor::new()->withBinary($this->dir . '/absent')))->execute([]);

        self::assertStringContainsString('Universal Ctags with JSON output is installed', $with->content());
        self::assertStringContainsString('Universal Ctags (with +json) was not found', $without->content());
    }

    // ---- the run, through a scripted binary --------------------------------

    public function testDefinitionsComeFromCtagsAndReferencesFromTheScan(): void
    {
        \file_put_contents($this->dir . '/shapes.py', "class Shape:\n    def area(self):\n        return compute_area(self)\n");
        $abs = $this->dir . '/shapes.py';
        $json = \implode("\n", [
            '{"_type": "ptag", "name": "JSON_OUTPUT_VERSION", "path": "0.0"}',
            \json_encode(['_type' => 'tag', 'name' => 'Shape', 'path' => $abs, 'line' => 1, 'kind' => 'class']),
            // Real universal-ctags 5.9 output for a Python method: kind `member`, with a signature.
            \json_encode(['_type' => 'tag', 'name' => 'area', 'path' => $abs, 'line' => 2, 'kind' => 'member', 'signature' => '(self)', 'scope' => 'Shape', 'scopeKind' => 'class']),
            \json_encode(['_type' => 'tag', 'name' => 'self', 'path' => $abs, 'line' => 2, 'kind' => 'parameter']),
        ]);
        $extractor = CtagsSymbolExtractor::new()->withBinary($this->fakeCtags(self::UNIVERSAL_BANNER, $json));

        $tags = $extractor->extractFiles(['src/shapes.py' => $abs]);

        self::assertSame(['src/shapes.py'], \array_keys($tags));
        $defs = \array_values(\array_filter($tags['src/shapes.py'], static fn (Tag $t): bool => $t->isDefinition()));
        self::assertSame(
            [['src/shapes.py', 1, 'Shape', Tag::TYPE_CLASS, ''], ['src/shapes.py', 2, 'area', Tag::TYPE_METHOD, 'Shape']],
            \array_map(static fn (Tag $t): array => [$t->relPath, $t->line, $t->name, $t->type, $t->scope], $defs),
            'a parameter is not a definition, and a function scoped to a class is a method',
        );

        $refs = \array_map(
            static fn (Tag $t): string => $t->line . ':' . $t->name,
            \array_values(\array_filter($tags['src/shapes.py'], static fn (Tag $t): bool => !$t->isDefinition())),
        );
        self::assertSame(['3:compute_area'], $refs, 'definitions, keywords and `self` are not references');
    }

    public function testTheRunDisablesOptionFilesAndPassesOnlyAbsolutePaths(): void
    {
        $command = CtagsSymbolExtractor::new()->commandFor(['/r/a b.py', '/r/-x.c']);

        self::assertMatchesRegularExpression("/^'ctags' --options=NONE /", $command, '--options=NONE must come first');
        self::assertStringContainsString('--output-format=json', $command);
        self::assertStringContainsString("'/r/a b.py' '/r/-x.c'", $command);
    }

    public function testAHungCtagsIsCutOffAtTheDeadline(): void
    {
        $extractor = CtagsSymbolExtractor::new()
            ->withBinary($this->fakeCtags(self::UNIVERSAL_BANNER, '', sleepSeconds: 30))
            ->withTimeout(0.5);
        \file_put_contents($this->dir . '/a.go', "package a\n");

        $start = \microtime(true);
        $tags = $extractor->extractFiles(['a.go' => $this->dir . '/a.go']);

        self::assertLessThan(10.0, \microtime(true) - $start, 'the batch deadline did not bound the child');
        self::assertSame([], $tags, 'a cut-off batch answers for none of its files, so nothing partial is cached');
    }

    public function testAnOversizedFileIsSkipped(): void
    {
        $big = $this->dir . '/big.js';
        \file_put_contents($big, \str_repeat('a', CtagsSymbolExtractor::MAX_FILE_BYTES + 1));
        $extractor = CtagsSymbolExtractor::new()->withBinary($this->fakeCtags(self::UNIVERSAL_BANNER, ''));

        self::assertSame([], $extractor->extractFiles(['big.js' => $big]));
    }

    // ---- the pure halves ---------------------------------------------------

    public function testParseIgnoresForeignPathsBrokenLinesAndUnknownKinds(): void
    {
        $lines = \implode("\n", [
            '{"_type":"tag","name":"Kept","path":"/r/a.rs","line":4,"kind":"struct"}',
            '{"_type":"tag","name":"Elsewhere","path":"/other/b.rs","line":1,"kind":"struct"}',
            '{"_type":"tag","name":"local_x","path":"/r/a.rs","line":5,"kind":"local"}',
            '{"_type":"tag","name":"Half","path":"/r/a.rs","li',
            '{"_type":"tag","name":"Inner","path":"/r/a.rs","line":9,"kind":"enumerator","scope":"crate::Color","scopeKind":"enum"}',
            '{"_type":"tag","name":"W","path":"/r/a.rs","line":10,"kind":"member","scope":"main.Shape","scopeKind":"struct"}',
            '{"_type":"tag","name":"Area","path":"/r/a.rs","line":11,"kind":"func","signature":"()","scope":"main.Shape","scopeKind":"struct"}',
        ]);

        $tags = CtagsSymbolExtractor::parse($lines, ['/r/a.rs' => 'a.rs']);

        self::assertSame(
            [
                ['Kept', 4, Tag::TYPE_CLASS, ''],
                ['Inner', 9, Tag::TYPE_ENUM_CASE, 'Color'],
                ['W', 10, Tag::TYPE_PROPERTY, 'Shape'],
                ['Area', 11, Tag::TYPE_METHOD, 'Shape'],
            ],
            \array_map(static fn (Tag $t): array => [$t->name, $t->line, $t->type, $t->scope], $tags['a.rs']),
        );
    }

    public function testReferencesAreCapped(): void
    {
        $source = \str_repeat("alpha beta gamma\n", CtagsSymbolExtractor::MAX_REFERENCES_PER_FILE);

        self::assertCount(CtagsSymbolExtractor::MAX_REFERENCES_PER_FILE, CtagsSymbolExtractor::references($source, 'x.txt'));
    }

    // ---- live, only with the real binary ---------------------------------

    /**
     * Not a skip on a host without the binary — the suite's skip roster is a
     * fixed floor — but the other branch asserted instead: without Universal
     * Ctags the producer degrades to nothing rather than failing.
     */
    public function testTheHostsCtagsEitherParsesRealSourceOrYieldsNothing(): void
    {
        $extractor = CtagsSymbolExtractor::new();
        \file_put_contents($this->dir . '/geo.py', "class Point:\n    def norm(self):\n        return 0\n\ndef origin():\n    return Point()\n");
        $tags = $extractor->extractFile($this->dir . '/geo.py', 'geo.py');

        if (!$extractor->available()) {
            self::assertSame([], $tags, 'an unavailable ctags must extract nothing');

            return;
        }

        $defs = [];
        foreach ($tags as $tag) {
            if ($tag->isDefinition()) {
                $defs[] = $tag->name . ':' . $tag->type;
            }
        }
        self::assertContains('Point:class', $defs);
        self::assertContains('norm:method', $defs);
        self::assertContains('origin:function', $defs);
    }

    // ---- fixtures --------------------------------------------------------

    private const UNIVERSAL_BANNER = "Universal Ctags 6.0.0, Copyright (C) 2015-2022 Universal Ctags Team\n"
        . "  Optional compiled features: +wildcards, +regex, +json\n";

    /** An executable stand-in: `--version` prints $banner, anything else prints $json. */
    private function fakeCtags(string $banner, string $json, int $sleepSeconds = 0): string
    {
        $path = $this->dir . '/fake-ctags-' . \bin2hex(\random_bytes(3));
        \file_put_contents($path, '#!' . \PHP_BINARY . "\n<?php\n"
            . 'if (($argv[1] ?? "") === "--version") { echo ' . \var_export($banner, true) . "; exit(0); }\n"
            . 'if (($argv[1] ?? "") !== "--options=NONE") { fwrite(STDERR, "option files not disabled first\n"); exit(2); }' . "\n"
            . ($sleepSeconds > 0 ? "sleep({$sleepSeconds});\n" : '')
            . 'echo ' . \var_export($json, true) . ";\n");
        \chmod($path, 0o700);

        return $path;
    }
}
