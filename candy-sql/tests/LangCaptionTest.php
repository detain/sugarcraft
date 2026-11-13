<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Sql\Admin\Dashboard\PostgresWidgetCatalog;
use SugarCraft\Sql\Admin\Dashboard\WidgetCatalog;
use SugarCraft\Sql\Lang;

/**
 * SQL-O05: widget captions stay English identity tokens in the catalogs while
 * what is drawn goes through Lang::caption().
 */
final class LangCaptionTest extends TestCase
{
    protected function tearDown(): void
    {
        T::setLocale('en');
    }

    public function testEnglishCaptionIsReturnedVerbatim(): void
    {
        T::setLocale('en');
        $this->assertSame('Bytes In', Lang::caption('Bytes In'));
    }

    public function testCaptionLocalizesWithoutTouchingTheIdentityToken(): void
    {
        T::setLocale('de');
        $this->assertSame('Eingehende Bytes', Lang::caption('Bytes In'));
        $this->assertSame('InnoDB-Datenträgerlesezugriffe', Lang::caption('InnoDB Disk Reads'));
    }

    public function testCaptionWithoutAnEntryRendersAsWritten(): void
    {
        T::setLocale('de');
        $this->assertSame('SELECT', Lang::caption('SELECT'));
    }

    public function testEveryCatalogCaptionThatIsProseHasATranslationKey(): void
    {
        $en = require __DIR__ . '/../lang/en.php';
        $sqlVerbs = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'DROP'];
        foreach ([new WidgetCatalog(), new PostgresWidgetCatalog()] as $catalog) {
            foreach ((new \ReflectionClass($catalog))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
                if ($m->isStatic() || $m->getNumberOfRequiredParameters() > 0) {
                    continue;
                }
                $entries = $m->invoke($catalog);
                if (!is_array($entries)) {
                    continue;
                }
                foreach ($entries as $entry) {
                    $caption = is_array($entry) && is_string($entry[0] ?? null) ? $entry[0] : null;
                    if ($caption === null || in_array($caption, $sqlVerbs, true)) {
                        continue;
                    }
                    $key = 'caption.' . strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $caption), '_'));
                    $this->assertArrayHasKey($key, $en, "caption \"{$caption}\" has no {$key} entry");
                    $this->assertSame($caption, $en[$key], "{$key} English must equal the identity caption");
                }
            }
        }
    }
}
