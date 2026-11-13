<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Tests\I18n;

use CandyCode\Bundles\Discord\Discord\Commands;
use PHPUnit\Framework\TestCase;

/**
 * Every string a Discord user or the operator reads at startup rides
 * `Lang::t` over the host's `channels.discord.*` catalogs. This guard fails
 * on a NEW bare literal in the user-facing slots (slash descriptions, message
 * `content`, thrown endpoint errors) and on a `Lang::t` key that the English
 * catalog does not carry. Diagnostic-only text (log lines, RPC error
 * messages, SQL) is deliberately outside it.
 */
final class UserFacingLiteralGuardTest extends TestCase
{
    private const SRC = __DIR__ . '/../../src';
    private const LANG = __DIR__ . '/../../../candy-code/lang';

    /** @return list<string> */
    private static function sources(): array
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SRC, \FilesystemIterator::SKIP_DOTS));
        $out = [];
        foreach ($it as $f) {
            if ($f->getExtension() === 'php') {
                $out[] = $f->getPathname();
            }
        }
        \sort($out);

        return $out;
    }

    public function testNoBareLiteralInAUserFacingSlot(): void
    {
        $bad = [];
        foreach (self::sources() as $file) {
            foreach (\file($file) as $n => $line) {
                $code = \trim($line);
                if (\str_starts_with($code, '*') || \str_starts_with($code, '//') || \str_starts_with($code, '/*')) {
                    continue;
                }
                // 'description' => '<text>' / 'content' => "<text>" with a letter-bearing literal.
                if (\preg_match('/[\'"](description|content)[\'"]\s*=>\s*([\'"])(?=[^\'"]*[A-Za-z])/', $code)) {
                    $bad[] = \basename($file) . ':' . ($n + 1) . ' ' . $code;
                }
                if (\basename($file) === 'Endpoint.php' && \preg_match('/new RuntimeException\(\s*[\'"]/', $code)) {
                    $bad[] = \basename($file) . ':' . ($n + 1) . ' ' . $code;
                }
            }
        }
        self::assertSame([], $bad, 'route user-facing text through Lang::t with a channels.discord.* key in every candy-code/lang catalog');
    }

    public function testEveryLangKeyUsedExistsInEnglishAndEveryLocale(): void
    {
        $keys = [];
        foreach (self::sources() as $file) {
            if (\preg_match_all('/Lang::t\(\s*\'(channels\.discord\.[A-Za-z0-9_.]+)\'/', (string) \file_get_contents($file), $m)) {
                \array_push($keys, ...$m[1]);
            }
        }
        $keys = \array_values(\array_unique($keys));
        self::assertNotEmpty($keys);
        $missing = [];
        foreach (\glob(self::LANG . '/*.php') as $catalog) {
            $table = require $catalog;
            foreach ($keys as $k) {
                if (!isset($table[$k])) {
                    $missing[] = \basename($catalog) . ' ' . $k;
                }
            }
        }
        self::assertSame([], $missing);
    }

    public function testSlashRosterTextComesFromTheCatalogNotRawKeys(): void
    {
        foreach (Commands::definitions() as $command) {
            self::assertStringNotContainsString('channels.discord.', $command['description'], $command['name']);
            self::assertLessThanOrEqual(100, \mb_strlen($command['description']), 'Discord caps descriptions at 100 chars');
            foreach ($command['options'] ?? [] as $option) {
                self::assertStringNotContainsString('channels.discord.', $option['description']);
                self::assertLessThanOrEqual(100, \mb_strlen($option['description']));
            }
        }
    }

    public function testPairActionOffersGrantAndNotTheDeprecatedApprove(): void
    {
        $pair = \array_values(\array_filter(Commands::definitions(), static fn (array $c): bool => $c['name'] === 'pair'))[0];
        $choices = \array_column($pair['options'][0]['choices'], 'value');
        self::assertSame(['grant', 'list'], $choices);
    }
}
