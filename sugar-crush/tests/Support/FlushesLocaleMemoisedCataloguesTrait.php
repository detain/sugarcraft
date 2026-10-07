<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use SugarCraft\Crush\Commands\Specs\BuiltInCommands;
use SugarCraft\Crush\Config\Settings\SettingsSchema;

/**
 * The one disposal for the process-global, LOCALE-KEYED catalogue memos a test
 * poisons the moment it fabricates a pseudo-catalogue.
 *
 * WHY. {@see SettingsSchema::all()} and {@see BuiltInCommands::all()} build
 * their rows once per locale NAME and keep them for the life of the process —
 * "CACHED PER LOCALE: one build answers one locale" is sound only while the
 * catalogue directory behind that name is stable. A test that points the
 * `crush` namespace at a throwaway pseudo-locale (`xx` fullwidth in
 * TuiLangTest, `xx` bracketed in RegistryTranslationTest, a stub `de` in
 * LangLaunchLocaleTest) breaks that premise: `T::overrideNamespace()` flushes
 * the translations themselves, but a schema/spec list already MEMOISED under
 * the locale code survives with the previous test's wording baked into its
 * rows. Co-sharded, the neighbour then reads stale fullwidth labels where it
 * built its own pseudo-catalogue — the RegistryTranslation/TuiLang pair red at
 * shard-context only (pair: TuiLang first, RegistryTranslation's
 * testSettingLabelsAreTranslated sees Ｐｒｏｖｉｄｅｒ instead of ⟦Provider⟧).
 *
 * So every test that overrides the `crush` namespace flushes the memos at BOTH
 * ends: on activation, so it reads its own catalogue regardless of what ran
 * before it, and on teardown, so it leaves nothing for whoever runs next.
 *
 * These src memos are production perf caches and deliberately carry no reset
 * seam — the disposal therefore lives here, by reflection, mirroring the
 * house static-reset hygiene of the sibling tests/Support traits.
 */
trait FlushesLocaleMemoisedCataloguesTrait
{
    /**
     * Drop every cached per-locale build so the next read re-resolves through
     * whichever catalogue directory the `crush` namespace points at now.
     */
    protected static function flushLocaleMemoisedCatalogues(): void
    {
        self::clearStaticMemo(SettingsSchema::class, 'all');
        self::clearStaticMemo(BuiltInCommands::class, 'all');
        self::clearStaticMemo(BuiltInCommands::class, 'bySpelling');
    }

    private static function clearStaticMemo(string $class, string $property): void
    {
        (new \ReflectionProperty($class, $property))->setValue(null, []);
    }
}
