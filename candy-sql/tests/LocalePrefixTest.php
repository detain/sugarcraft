<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests;

use PHPUnit\Framework\TestCase;

/**
 * client-020: the launcher printer owns the "candy-sql: " framing
 * (bin/candy-sql sprintf("candy-sql: %s\n", …)), so no locale VALUE may
 * embed it — every embedded prefix produced the observed double
 * "candy-sql: candy-sql: no such SQLite file". G3's 16-lock: this pin
 * iterates the whole lang/ set, so a future value re-gaining a prefix
 * goes red in every locale at once.
 */
final class LocalePrefixTest extends TestCase
{
    /** @return array<string,array<string,string>> */
    private function allLocales(): array
    {
        $files = glob(__DIR__ . '/../lang/*.php');
        $this->assertIsArray($files);
        $this->assertCount(16, $files, 'G3: the locale set is 16 files');

        $out = [];
        foreach ($files as $file) {
            $code = basename($file, '.php');
            $values = require $file;
            $this->assertIsArray($values, "lang/{$code}.php must return an array");
            $out[$code] = $values;
        }
        return $out;
    }

    public function testNoLocaleValueEmbedsTheLauncherPrefix(): void
    {
        foreach ($this->allLocales() as $code => $values) {
            foreach ($values as $key => $value) {
                $this->assertStringNotContainsString(
                    'candy-sql:',
                    (string) $value,
                    "lang/{$code}.php value for '{$key}' embeds the launcher prefix",
                );
                $this->assertStringNotContainsString(
                    'candy-sql：',
                    (string) $value,
                    "lang/{$code}.php value for '{$key}' embeds the fullwidth launcher prefix",
                );
                $this->assertStringNotContainsString(
                    'candy-sql :',
                    (string) $value,
                    "lang/{$code}.php value for '{$key}' embeds the spaced launcher prefix",
                );
            }
        }
    }

    public function testEveryLocaleKeepsTheNoFileKeyWithPlaceholder(): void
    {
        foreach ($this->allLocales() as $code => $values) {
            $this->assertArrayHasKey('database.no_file', $values, "lang/{$code}.php lost its key");
            $this->assertStringContainsString(
                '{path}',
                (string) $values['database.no_file'],
                "lang/{$code}.php no_file must keep the {path} placeholder",
            );
        }
    }
}
