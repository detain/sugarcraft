<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Kv\Lang;
use SugarCraft\Kv\Support\PluralChooser;

final class PluralChooserTest extends TestCase
{
    private string $prior = 'en';

    protected function setUp(): void
    {
        $this->prior = T::locale();
    }

    protected function tearDown(): void
    {
        T::setLocale($this->prior);
    }

    /** @return array<string, array{0: string, 1: int, 2: string}> */
    public static function categories(): array
    {
        return [
            'en 1' => ['en', 1, 'one'], 'en 0' => ['en', 0, 'other'], 'en 2' => ['en', 2, 'other'],
            'ru 1' => ['ru', 1, 'one'], 'ru 21' => ['ru', 21, 'one'], 'ru 11' => ['ru', 11, 'many'],
            'ru 2' => ['ru', 2, 'few'], 'ru 24' => ['ru', 24, 'few'], 'ru 12' => ['ru', 12, 'many'],
            'ru 14' => ['ru', 14, 'many'], 'ru 5' => ['ru', 5, 'many'], 'ru 0' => ['ru', 0, 'many'],
            'ru 101' => ['ru', 101, 'one'], 'ru 111' => ['ru', 111, 'many'], 'ru_RU 3' => ['ru_RU', 3, 'few'],
            'pl 1' => ['pl', 1, 'one'], 'pl 21' => ['pl', 21, 'many'], 'pl 22' => ['pl', 22, 'few'],
            'pl 12' => ['pl', 12, 'many'], 'pl 5' => ['pl', 5, 'many'], 'pl 112' => ['pl', 112, 'many'],
            'cs 1' => ['cs', 1, 'one'], 'cs 4' => ['cs', 4, 'few'], 'cs 5' => ['cs', 5, 'other'], 'cs 0' => ['cs', 0, 'other'],
            'ar 0' => ['ar', 0, 'zero'], 'ar 1' => ['ar', 1, 'one'], 'ar 2' => ['ar', 2, 'two'],
            'ar 3' => ['ar', 3, 'few'], 'ar 10' => ['ar', 10, 'few'], 'ar 11' => ['ar', 11, 'many'],
            'ar 99' => ['ar', 99, 'many'], 'ar 100' => ['ar', 100, 'other'], 'ar 102' => ['ar', 102, 'other'],
            'ar 103' => ['ar', 103, 'few'], 'ar 111' => ['ar', 111, 'many'],
            'ja 5' => ['ja', 5, 'other'],
        ];
    }

    #[DataProvider('categories')]
    public function testCldrCategory(string $locale, int $n, string $expected): void
    {
        $this->assertSame($expected, PluralChooser::category($locale, $n));
    }

    public function testKeyFallsBackToTheHistoricNSuffix(): void
    {
        $this->assertSame('cli.deleted_one', PluralChooser::key('deleted', 1, 'en'));
        $this->assertSame('cli.deleted_n', PluralChooser::key('deleted', 3, 'en'));
        $this->assertSame('cli.deleted_few', PluralChooser::key('deleted', 3, 'ru'));
        $this->assertSame('cli.deleted_n', PluralChooser::key('deleted', 5, 'ru'), 'ru many folds into _n');
        $this->assertSame('cli.pruned_zero', PluralChooser::key('pruned', 0, 'ar'));
        $this->assertSame('cli.pruned_many', PluralChooser::key('pruned', 11, 'ar'));
        $this->assertSame('cli.pruned_n', PluralChooser::key('pruned', 100, 'ar'));
        $this->assertSame('cli.pruned_n', PluralChooser::key('pruned', 0, 'de'), 'a locale without extras never reaches them');
    }

    public function testEveryCountOfEveryLocaleResolvesToAShippedKeyThatNamesTheNumber(): void
    {
        foreach (\glob(__DIR__ . '/../lang/*.php') ?: [] as $file) {
            $code = \basename($file, '.php');
            $catalog = require $file;
            foreach (PluralChooser::BASES as $base) {
                foreach ([0, 1, 2, 3, 4, 5, 10, 11, 12, 14, 21, 22, 25, 100, 101, 102, 111] as $n) {
                    $key = PluralChooser::key($base, $n, $code);
                    $this->assertArrayHasKey($key, $catalog, "{$code} {$base} {$n} -> {$key}");
                }
            }
        }
    }

    public function testRussianSingularFormTakesTheNumberSoTwentyOneReadsRight(): void
    {
        T::setLocale('ru');
        $this->assertSame('Удалена 21 запись.', PluralChooser::t('deleted', 21));
        $this->assertSame('Удалено 3 записи.', PluralChooser::t('deleted', 3));
        $this->assertSame('Удалено 5 записей.', PluralChooser::t('deleted', 5));
        $this->assertSame('Удалена 1 запись.', PluralChooser::t('deleted', 1));
    }

    public function testEnglishWordingIsUnchanged(): void
    {
        T::setLocale('en');
        $this->assertSame('Deleted 1 entry.', PluralChooser::t('deleted', 1));
        $this->assertSame('Deleted 0 entries.', PluralChooser::t('deleted', 0));
        $this->assertSame('Imported 7 entries.', PluralChooser::t('imported', 7));
        $this->assertSame(Lang::t('cli.would_delete_n', ['count' => 2]), PluralChooser::t('would_delete', 2));
    }

    public function testPolishCzechAndArabicSpotChecks(): void
    {
        T::setLocale('pl');
        $this->assertSame('Usunięto 3 wpisy.', PluralChooser::t('deleted', 3));
        $this->assertSame('Usunięto 5 wpisów.', PluralChooser::t('deleted', 5));
        T::setLocale('cs');
        $this->assertSame('Smazány 3 položky.', PluralChooser::t('deleted', 3));
        $this->assertSame('Smazáno 5 položek.', PluralChooser::t('deleted', 5));
        T::setLocale('ar');
        $this->assertSame('تم حذف مدخلين.', PluralChooser::t('deleted', 2));
        $this->assertSame('تم حذف 5 مدخلات.', PluralChooser::t('deleted', 5));
    }
}
