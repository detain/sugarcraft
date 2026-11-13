<?php

/**
 * English (default) translations for sugar-navstack.
 *
 * These are the REGISTRY COPY of the shipped glyph defaults. The live
 * single source is {@see \SugarCraft\NavStack\NavStack::SEPARATOR} /
 * {@see \SugarCraft\NavStack\NavStack::TRUNCATOR}; the values below must
 * stay byte-identical to those consts — LangCoverageTest pins the
 * agreement (NS-13). Nothing rendered consults this file; a host that
 * wants localized glyphs reads them here and feeds the setters.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Default breadcrumb separator (›)
    'separator' => ' › ',

    // Truncation ellipsis marker (…)
    'truncator' => '… ',
];
