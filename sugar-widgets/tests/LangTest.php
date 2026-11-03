<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Widgets\Lang;
use SugarCraft\Core\I18n\Lang as BaseLang;

final class LangTest extends TestCase
{
    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(Lang::class));
    }

    public function testLangIsFinal(): void
    {
        $reflection = new \ReflectionClass(Lang::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function testExtendsBaseLang(): void
    {
        $reflection = new \ReflectionClass(Lang::class);
        $this->assertSame(BaseLang::class, $reflection->getParentClass()->getName());
    }

    public function testNamespaceConstant(): void
    {
        $reflection = new \ReflectionClass(Lang::class);
        $this->assertSame('widgets', $reflection->getConstant('NAMESPACE'));
    }

    public function testDirConstant(): void
    {
        $reflection = new \ReflectionClass(Lang::class);
        $dir = $reflection->getConstant('DIR');
        $this->assertStringContainsString('lang', $dir);
        $this->assertStringContainsString('sugar-widgets', $dir);
    }

    public function testCanBeInstantiated(): void
    {
        $lang = new Lang();
        $this->assertInstanceOf(Lang::class, $lang);
    }

    public function testTranslateKnownKey(): void
    {
        $result = Lang::t('progress.width_nonneg');
        $this->assertSame('progress width must be >= 0', $result);
    }

    public function testTranslateAnotherKnownKey(): void
    {
        $result = Lang::t('timer.duration_nonneg');
        $this->assertSame('timer duration must be >= 0', $result);
    }

    public function testTranslateWithParameters(): void
    {
        $result = Lang::t('table.sort_unknown_column', ['column' => '42']);
        $this->assertStringContainsString('42', $result);
        $this->assertStringContainsString('sort column not found', $result);
    }

    public function testTranslateFallbackForUnknownKey(): void
    {
        $result = Lang::t('nonexistent.key');
        $this->assertSame('widgets.nonexistent.key', $result);
    }

    public function testTranslateTabsKey(): void
    {
        $result = Lang::t('tabs.bad_index');
        $this->assertSame('tab index out of range', $result);
    }

    public function testTranslateTableKey(): void
    {
        $result = Lang::t('table.dim_nonneg');
        $this->assertSame('table width/height must be >= 0', $result);
    }

    public function testTranslateHelpKey(): void
    {
        $result = Lang::t('help.width_nonneg');
        $this->assertSame('help width must be >= 0', $result);
    }

    public function testStaticFactoryReturnsSameInstance(): void
    {
        $lang1 = new Lang();
        $lang2 = new Lang();
        $this->assertInstanceOf(Lang::class, $lang1);
        $this->assertInstanceOf(Lang::class, $lang2);
    }

    /**
     * M25: every locale must ship exactly the canonical key set. Before this
     * arm existed, six keys were English-only (their throws were unreachable
     * for every other locale) and nine more were dead weight copied across
     * sixteen files with no producer.
     */
    public function testEveryLocaleCarriesTheSameKeySet(): void
    {
        $dir      = __DIR__ . '/../lang';
        $en       = require "{$dir}/en.php";
        $expected = array_keys($en);
        sort($expected);

        $codes = glob("{$dir}/*.php");
        $this->assertNotCount(0, $codes, 'no locale files found');

        foreach ($codes as $path) {
            $code = basename($path, '.php');
            $body = require $path;
            $keys = array_keys($body);
            sort($keys);
            $this->assertSame(
                $expected,
                $keys,
                "locale '{$code}' does not carry the canonical key set",
            );
        }
    }

    /**
     * M25: bidirectional liveness. Every key in en.php must be requested by
     * a literal Lang::t('key') call in src/, and every literal key src/
     * requests must exist in en.php. A key nobody throws (or a throw naming
     * a missing key) now reddens instead of drifting silently.
     */
    public function testCatalogueIsExactlyTheKeysSrcRequests(): void
    {
        $en = require __DIR__ . '/../lang/en.php';

        $requested = [];
        $srcRoot   = __DIR__ . '/../src';
        $iterator  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcRoot, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $text = (string) file_get_contents($file->getPathname());
                if (preg_match_all("/Lang::t\\('([a-z0-9_.]+)'/", $text, $hits)) {
                    foreach ($hits[1] as $key) {
                        $requested[$key] = true;
                    }
                }
            }
        }

        $catalogue = array_keys($en);
        $used      = array_keys($requested);
        sort($catalogue);
        sort($used);

        $this->assertSame(
            $catalogue,
            $used,
            'the lang catalogue and the src request sites have diverged',
        );
    }

    /**
     * M25: the {column} placeholder is interpolated into user-facing refusal
     * text; a locale that translates it away would silently drop the column
     * name from the error.
     */
    public function testPlaceholderSurvivesInEveryLocale(): void
    {
        $dir = __DIR__ . '/../lang';
        foreach (glob("{$dir}/*.php") as $path) {
            $body = require $path;
            $this->assertStringContainsString(
                '{column}',
                $body['table.sort_unknown_column'],
                'locale ' . basename($path, '.php') . ' dropped the {column} placeholder',
            );
        }
    }

    /** m11: withPageSize() refuses negatives with its own message, not the width/height one. */
    public function testPageSizeKeyIsLive(): void
    {
        $this->assertSame('page size must be >= 0', Lang::t('table.page_size_nonneg'));
    }
}
