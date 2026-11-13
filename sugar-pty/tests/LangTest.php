<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Pty\Lang;

final class LangTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        T::reset();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        T::reset();
    }

    public function testNamespaceIsPty(): void
    {
        $reflection = new \ReflectionClass(Lang::class);
        $const = $reflection->getConstant('NAMESPACE');
        $this->assertSame('pty', $const);
    }

    public function testDirPointsToLangFolderInSugarPty(): void
    {
        $reflection = new \ReflectionClass(Lang::class);
        $dirConst = $reflection->getConstant('DIR');

        // DIR is defined as __DIR__ . '/../lang' relative to src/Lang.php
        // When Lang.php is at src/Lang.php, __DIR__ is src/, so src/../lang = lang/
        $expectedResolved = dirname($reflection->getFileName()) . '/../lang';
        $this->assertSame($expectedResolved, $dirConst);
        $this->assertFileExists($dirConst . '/en.php');
    }

    public function testTReturnsTranslationFromLangFile(): void
    {
        T::reset();
        // Lang::t() internally registers the 'pty' namespace using Lang::DIR
        // which points to the sugar-pty/lang directory
        $result = Lang::t('open.posix_openpt_failed', ['rc' => -1, 'errno' => 5]);
        $this->assertStringContainsString('posix_openpt', $result);
        $this->assertStringContainsString('-1', $result);
        $this->assertStringContainsString('5', $result);
    }

    public function testTWithMissingKeyReturnsKey(): void
    {
        T::reset();
        // When key doesn't exist, T::translate returns the raw key
        $result = Lang::t('nonexistent.key', ['name' => 'World']);
        $this->assertSame('pty.nonexistent.key', $result);
    }

    public function testExtendsBaseLang(): void
    {
        $this->assertInstanceOf(\SugarCraft\Core\I18n\Lang::class, new Lang());
    }

    public function testEverySrcLangKeyExistsInEnglishCatalog(): void
    {
        // PTY-25: the exemption boundary is only honest if the keys are.
        // Every LITERAL Lang::t('key') call site in src/ must exist in
        // lang/en.php — a missing key renders as the bare key string in
        // front of a console operator (see testTWithMissingKeyReturnsKey).
        $root = \dirname(__DIR__) . '/src';
        $used = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            if (\preg_match_all("/Lang::t\\(\\s*'([a-z0-9_.]+)'/", (string) \file_get_contents($file->getPathname()), $m)) {
                $used = \array_merge($used, $m[1]);
            }
        }
        $used = \array_values(\array_unique($used));
        \sort($used);

        $this->assertNotEmpty($used, 'the src census found no Lang::t keys at all');

        $catalog = require \dirname(__DIR__) . '/lang/en.php';
        $missing = \array_values(\array_diff($used, \array_keys($catalog)));
        $this->assertSame(
            [],
            $missing,
            'src/ calls Lang::t() with keys absent from lang/en.php (they would render as bare keys): '
            . \implode(', ', $missing),
        );
    }

    public function testEveryWindowsThrowSiteSpeaksThroughLang(): void
    {
        // PTY-26 side B: src/Windows carries no hand-rolled operator
        // messages any more — every PtyException site routes through
        // Lang::t (programmer-guard InvalidArgumentException sites are
        // raw by the PTY-25 policy and out of this census). A fresh
        // literal message here goes red until it gets a windows.* key.
        $offenders = [];
        foreach (glob(\dirname(__DIR__) . '/src/Windows/*.php') ?: [] as $file) {
            $src = (string) \file_get_contents($file);
            if (\preg_match_all('/new PtyException\(\s*((?:[^;]|\n)*)\);/', $src, $m, \PREG_SET_ORDER)) {
                foreach ($m as $set) {
                    if (!\str_contains($set[1], 'Lang::t(')) {
                        $offenders[] = \basename($file) . ': ' . \substr(\trim($set[1]), 0, 60);
                    }
                }
            }
        }
        $this->assertSame([], $offenders, 'untranslated PtyException message(s) in src/Windows');
    }

    public function testTWithPlaceholderSubstitution(): void
    {
        T::reset();
        // The en.php file has placeholders like {rc} and {errno}
        $result = Lang::t('open.posix_openpt_failed', ['rc' => 42, 'errno' => 5]);
        $this->assertStringContainsString('42', $result);
        $this->assertStringContainsString('5', $result);
    }
}
