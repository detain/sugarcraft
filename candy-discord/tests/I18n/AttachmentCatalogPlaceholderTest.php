<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Tests\I18n;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The attachment skip notices are built from `channels.discord.attachments.*`
 * catalog entries, and each `Lang::t` call in DiscordAccount.php must pass
 * exactly the `{placeholder}` names the English entry declares. A renamed
 * parameter on either side would otherwise ship a literal `{limit}` or `{mb}`
 * to every locale without a single red test. This reads the call sites
 * straight from the source, so it also covers the reasons no runtime path
 * reaches (for example over_cap_after_download, which the media fetch's
 * buffer limit rejects before the size check can see it).
 */
final class AttachmentCatalogPlaceholderTest extends TestCase
{
    private const SRC = __DIR__ . '/../../src/Discord/DiscordAccount.php';
    private const EN = __DIR__ . '/../../lang/en.php';
    private const PREFIX = 'channels.discord.attachments.';
    private const CALL = '/Lang::t\(\s*\'(channels\.discord\.attachments\.[a-z_.]+)\'\s*(?:,\s*\[(.*?)\]\s*)?\)/s';

    /**
     * @return iterable<string, array{string, list<string>}> each attachment Lang::t call site:
     *         its key and the sorted parameter names it passes
     */
    public static function callSiteProvider(): iterable
    {
        $source = (string) \file_get_contents(self::SRC);
        \preg_match_all(self::CALL, $source, $hits, \PREG_SET_ORDER);
        self::assertNotSame([], $hits, 'the attachment Lang::t call sites must be found in DiscordAccount.php');
        foreach ($hits as $i => $hit) {
            $params = [];
            if (($hit[2] ?? '') !== '') {
                \preg_match_all('/\'([a-z_]+)\'\s*=>/', $hit[2], $names);
                $params = $names[1];
            }
            \sort($params);
            yield $i . ' ' . $hit[1] => [$hit[1], $params];
        }
    }

    /** @param list<string> $params */
    #[DataProvider('callSiteProvider')]
    public function testACallSitePassesExactlyTheCatalogPlaceholders(string $key, array $params): void
    {
        $en = require self::EN;
        self::assertArrayHasKey($key, $en, 'every attachment Lang::t key must exist in lang/en.php');
        \preg_match_all('/\{([a-z_]+)\}/', (string) $en[$key], $placeholders);
        $expected = $placeholders[1];
        \sort($expected);

        self::assertSame($expected, $params, "Lang::t('{$key}') must pass the placeholder names its English entry declares");
    }

    public function testEveryCatalogAttachmentKeyIsReferencedByTheCode(): void
    {
        $en = require self::EN;
        $catalog = \array_values(\array_filter(\array_keys($en), static fn (string $k): bool => \str_starts_with($k, self::PREFIX)));
        $source = (string) \file_get_contents(self::SRC);
        \preg_match_all(self::CALL, $source, $hits);
        $referenced = \array_values(\array_unique($hits[1]));
        \sort($catalog);
        \sort($referenced);

        self::assertSame($catalog, $referenced, 'every attachment catalog entry must be reachable from DiscordAccount.php');
    }
}
