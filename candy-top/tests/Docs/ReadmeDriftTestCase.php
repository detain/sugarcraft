<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Docs;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;

/**
 * Shared assertion for the README drift guards: the generated block named
 * $name must equal $expected byte for byte. With CANDY_TOP_UPDATE_DOCS=1
 * the block is rewritten instead and the test marked incomplete.
 */
abstract class ReadmeDriftTestCase extends TestCase
{
    protected function setUp(): void
    {
        // The README is English: render the rosters in the default locale.
        T::reset();
    }

    protected function assertReadmeBlock(string $name, string $expected): void
    {
        $path = ReadmeTables::readmePath();
        $readme = (string) file_get_contents($path);

        if (getenv(ReadmeTables::UPDATE_ENV) === '1') {
            $updated = ReadmeTables::replace($readme, $name, $expected);
            if ($updated !== $readme) {
                file_put_contents($path, $updated);
                $this->markTestIncomplete("README block generated:{$name} rewritten — review the diff.");
            }
        }

        $actual = ReadmeTables::block($readme, $name);
        $this->assertNotNull($actual, "README.md is missing the <!-- BEGIN generated:{$name} --> / <!-- END generated:{$name} --> markers");
        $this->assertSame(
            $this->rows($expected),
            $this->rows((string) $actual),
            "README block generated:{$name} drifted from the source roster; regenerate with "
            . ReadmeTables::UPDATE_ENV . '=1 vendor/bin/phpunit tests/Docs and review the diff.',
        );
        $this->assertSame($expected, $actual, "README block generated:{$name} differs in whitespace only");
    }

    /** @return list<string> */
    private function rows(string $block): array
    {
        return array_values(array_filter(explode("\n", $block), static fn (string $l): bool => $l !== ''));
    }
}
