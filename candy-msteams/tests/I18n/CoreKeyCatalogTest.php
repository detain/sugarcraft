<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\I18n;

use CandyCode\Bundles\MsTeams\Bridge\ConversationRouter;
use PHPUnit\Framework\TestCase;
use SugarCraft\Plugins\Bridge\ConversationRouterCore;

/**
 * Every catalog key the shared ConversationRouterCore asks for must resolve in
 * the bundle's own lang/ catalog, English and every shipped locale. The core
 * names its keys as core-relative literals ($this->t('approval.not_yours')) and
 * the router maps each one through catalogKey(); a key missing from that map
 * or from the catalog reaches the user as the raw key. LangParityTest only
 * sees Lang::t() literals in src/, so this guard covers the core's keys.
 */
final class CoreKeyCatalogTest extends TestCase
{
    private const LANG_DIR = __DIR__ . '/../../lang';

    /** @return list<string> catalog keys the core can ask this router for */
    private static function coreCatalogKeys(): array
    {
        $core = (string) \file_get_contents((string) (new \ReflectionClass(ConversationRouterCore::class))->getFileName());
        \preg_match_all('/\$this->t\(\s*\'([a-z][a-z0-9_.]*)\'/', $core, $coreKeys);
        self::assertNotEmpty($coreKeys[1], 'the core must name its catalog keys as literals');

        $catalogKey = new \ReflectionMethod(ConversationRouter::class, 'catalogKey');
        $keys = [];
        foreach (\array_unique($coreKeys[1]) as $coreKey) {
            $keys[] = (string) $catalogKey->invoke(null, $coreKey);
        }

        return \array_values(\array_unique($keys));
    }

    public function testEveryCoreKeyIsInEnglishAndEveryLocale(): void
    {
        $keys = self::coreCatalogKeys();
        self::assertContains('channels.msteams.approval.not_yours', $keys);
        self::assertContains('channels.msteams.prompt.failed', $keys);

        $missing = [];
        foreach (\glob(self::LANG_DIR . '/*.php') ?: [] as $catalog) {
            $table = require $catalog;
            foreach ($keys as $key) {
                if (!\array_key_exists($key, $table)) {
                    $missing[] = \basename($catalog) . ' ' . $key;
                }
            }
        }

        self::assertSame([], $missing, 'every key the shared core asks for must have an entry in every lang/ catalog');
    }
}
