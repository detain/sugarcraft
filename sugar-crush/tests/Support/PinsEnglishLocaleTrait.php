<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use SugarCraft\Core\I18n\T;

/**
 * Runs each test under the `en` locale and restores the process locale after.
 *
 * For the documentation drift guards (audit 15b-14): the registries they read
 * now answer through `Lang::t()`, and the pages they compare against are
 * English, so the comparison is only meaningful in `en` — whatever an earlier
 * test, or a developer's `LANG` reaching a `T::detect()` call, left the
 * process-global locale set to.
 *
 * Hooked with `#[Before]`/`#[After]` rather than `setUp()`/`tearDown()`, so a
 * class that has its own fixtures can use it without a method clash.
 */
trait PinsEnglishLocaleTrait
{
    private ?string $localeBeforeEnglishPin = null;

    #[Before]
    protected function pinEnglishLocale(): void
    {
        $this->localeBeforeEnglishPin = T::locale();
        T::setLocale('en');
    }

    #[After]
    protected function restoreLocaleAfterEnglishPin(): void
    {
        if ($this->localeBeforeEnglishPin !== null) {
            T::setLocale($this->localeBeforeEnglishPin);
            $this->localeBeforeEnglishPin = null;
        }
    }
}
