<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests\Support;

use SugarCraft\Plugins\Lang;

/**
 * A plugin-shaped Lang subclass over the fixture catalog, so the lookup
 * order can be asserted without a real plugin's strings.
 */
final class FixtureLang extends Lang
{
    protected const NAMESPACE = 'fixture';
    protected const DIR = __DIR__ . '/../fixtures/lang';
}
