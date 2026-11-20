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
    /* Either quote style: a double-quoted call must not silently drop out of the guard. */
    private const CALL = '/Lang::t\(\s*([\'"])(channels\.discord\.attachments\.[a-z_.]+)\1\s*(?:,\s*\[(.*?)\]\s*)?\)/s';

    /**
     * @return list<array{string, list<string>}> each attachment Lang::t call site in $source:
     *         its key and the sorted parameter names it passes
     */
    private static function callSites(string $source): array
    {
        \preg_match_all(self::CALL, $source, $hits, \PREG_SET_ORDER);
        $sites = [];
        foreach ($hits as $hit) {
            $params = [];
            if (($hit[3] ?? '') !== '') {
                \preg_match_all('/([\'"])([a-z_]+)\1\s*=>/', $hit[3], $names);
                $params = $names[2];
            }
            \sort($params);
            $sites[] = [$hit[2], $params];
        }

        return $sites;
    }

    /**
     * @return iterable<string, array{string, list<string>}> each attachment Lang::t call site:
     *         its key and the sorted parameter names it passes
     */
    public static function callSiteProvider(): iterable
    {
        $hits = self::callSites((string) \file_get_contents(self::SRC));
        self::assertNotSame([], $hits, 'the attachment Lang::t call sites must be found in DiscordAccount.php');
        foreach ($hits as $i => [$key, $params]) {
            yield $i . ' ' . $key => [$key, $params];
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
        $referenced = \array_values(\array_unique(\array_column(self::callSites((string) \file_get_contents(self::SRC)), 0)));
        \sort($catalog);
        \sort($referenced);

        self::assertSame($catalog, $referenced, 'every attachment catalog entry must be reachable from DiscordAccount.php');
    }

    /**
     * Regression: a double-quoted Lang::t call used to match nothing, so its placeholder list was
     * never compared. The fixture is a double-quoted batch_budget call that omits {mb}.
     */
    public function testADoubleQuotedCallSiteIsExtractedWithItsParameters(): void
    {
        $source = '<?php $notes[] = Lang::t("channels.discord.attachments.reason.batch_budget", ["name" => $name]);';

        self::assertSame(
            [['channels.discord.attachments.reason.batch_budget', ['name']]],
            self::callSites($source),
            'a double-quoted attachment call must be found, not skipped'
        );
    }

    public function testADoubleQuotedCallWithWrongPlaceholdersDiffersFromTheCatalog(): void
    {
        $en = require self::EN;
        $key = 'channels.discord.attachments.reason.batch_budget';
        \preg_match_all('/\{([a-z_]+)\}/', (string) $en[$key], $placeholders);
        $expected = $placeholders[1];
        \sort($expected);

        $sites = self::callSites('<?php Lang::t("' . $key . '", ["name" => $name]);');

        self::assertCount(1, $sites);
        self::assertNotSame($expected, $sites[0][1], 'the omitted {mb} placeholder must be visible to the guard');
    }

    public function testSingleQuotedCallSitesAreStillExtracted(): void
    {
        $source = "<?php Lang::t('channels.discord.attachments.reason.batch_budget', ['name' => \$n, 'mb' => \$m]);";

        self::assertSame(
            [['channels.discord.attachments.reason.batch_budget', ['mb', 'name']]],
            self::callSites($source)
        );
    }
}
