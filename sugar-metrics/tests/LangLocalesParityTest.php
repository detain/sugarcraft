<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Every locale must ship the exact message set of en.php.
 *
 * A missing key silently falls back to English at the worst moment (the
 * error path — jsonstream.write_failed was absent from all fifteen non-en
 * locales), and a stale key ships dead strings forever. This guard also
 * pins placeholder tokens per message so a translation can never rename or
 * drop an interpolation variable.
 */
final class LangLocalesParityTest extends TestCase
{
    public function testEveryLocaleShipsTheEnglishKeySetInOrder(): void
    {
        $dir = __DIR__ . '/../lang';
        $files = array_values(array_filter(
            (array) scandir($dir),
            static fn (string $f): bool => str_ends_with($f, '.php')
        ));
        self::assertGreaterThanOrEqual(16, count($files), 'all sixteen locales must be present');

        /** @var array<string,string> $en */
        $en = require $dir . '/en.php';

        foreach ($files as $file) {
            if ($file === 'en.php') {
                continue;
            }
            /** @var array<string,string> $locale */
            $locale = require $dir . '/' . $file;

            self::assertSame(
                array_keys($en),
                array_keys($locale),
                $file . ' must ship the same keys, in the same order, as en.php'
            );

            foreach ($en as $key => $english) {
                self::assertSame(
                    self::placeholders($english),
                    self::placeholders($locale[$key]),
                    $file . ':' . $key . ' must use exactly the en.php placeholder tokens'
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function placeholders(string $message): array
    {
        preg_match_all('/\{[a-z_]+\}/', $message, $matches);

        return $matches[0];
    }
}
