<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mouse\Lang;

/**
 * Smoke test for the per-library i18n facade.
 *
 * PHP fatals at class-compile time when a subclass narrows the visibility
 * of an inherited constant: Lang once declared `private const NAMESPACE`
 * / `DIR` against the base's `protected` ones, which made
 * SugarCraft\Mouse\Lang a hard fatal on load.  The rest of the suite kept
 * it green only because nothing referenced the class — these tests make
 * the reference (and therefore the compile) unavoidable.
 *
 * The facade is kept as house i18n skeleton: lang/en.php is an empty stub
 * shipped ahead of real strings, so lookups resolve via the documented
 * locale → en → raw-key fallback rather than throwing.
 */
final class LangTest extends TestCase
{
    public function testLangClassLoads(): void
    {
        // class_exists() triggers the autoloader, which compiles
        // src/Lang.php — a visibility narrowing would fatal right here.
        self::assertTrue(class_exists(Lang::class));
    }

    public function testTranslationKeyResolvesViaFallback(): void
    {
        // en.php ships empty, so every lookup misses all locale files and
        // T::translate returns the raw namespaced key — visibly, loudly,
        // never an exception.
        self::assertSame('mouse.zone.click', Lang::t('zone.click'));
        self::assertSame('mouse.missing.key', Lang::t('missing.key'));
    }
}
