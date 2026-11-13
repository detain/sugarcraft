<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests;

use PHPUnit\Framework\TestCase;

/**
 * SQL-O05: every locale carries exactly the key set of en, with the same
 * {placeholders} per key, and every Lang::t() key referenced in src/ and bin/
 * exists — so a missing translation is a red test, not a raw key on screen.
 */
final class LocaleParityTest extends TestCase
{
    /** @return array<string,array<string,string>> */
    private static function locales(): array
    {
        $out = [];
        foreach (glob(__DIR__ . '/../lang/*.php') ?: [] as $file) {
            $out[basename($file, '.php')] = require $file;
        }
        return $out;
    }

    /** @return list<string> */
    private static function placeholders(string $value): array
    {
        preg_match_all('/\{(\w+)\}/', $value, $m);
        $p = array_unique($m[1]);
        sort($p);
        return array_values($p);
    }

    public function testEveryLocaleHasEnglishsKeySet(): void
    {
        $all = self::locales();
        $this->assertCount(16, $all);
        $en = array_keys($all['en']);
        sort($en);
        foreach ($all as $code => $values) {
            $keys = array_keys($values);
            sort($keys);
            $this->assertSame([], array_values(array_diff($en, $keys)), "lang/{$code}.php is missing keys");
            $this->assertSame([], array_values(array_diff($keys, $en)), "lang/{$code}.php has keys en lacks");
        }
    }

    public function testPlaceholdersMatchEnglishInEveryLocale(): void
    {
        $all = self::locales();
        foreach ($all['en'] as $key => $value) {
            $want = self::placeholders((string) $value);
            foreach ($all as $code => $values) {
                $this->assertSame($want, self::placeholders((string) $values[$key]), "lang/{$code}.php '{$key}' placeholders drifted from en");
            }
        }
    }

    public function testEveryReferencedKeyExistsInEnglish(): void
    {
        $en = self::locales()['en'];
        $roots = [__DIR__ . '/../src', __DIR__ . '/../bin'];
        $missing = [];
        foreach ($roots as $root) {
            $files = is_dir($root)
                ? iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)))
                : [];
            foreach ($files as $f) {
                if (!$f->isFile()) {
                    continue;
                }
                preg_match_all("/Lang::t\(\s*'([a-z0-9_.]+)'/", (string) file_get_contents($f->getPathname()), $m);
                foreach ($m[1] as $key) {
                    if (!array_key_exists($key, $en)) {
                        $missing[] = $f->getFilename() . ':' . $key;
                    }
                }
            }
        }
        $this->assertSame([], array_values(array_unique($missing)), 'Lang::t() keys absent from lang/en.php');
    }
}
