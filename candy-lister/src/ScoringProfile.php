<?php

declare(strict_types=1);

namespace SugarCraft\Lister;

/**
 * Scoring constants for the FuzzyMatch algorithm.
 *
 * Back-compat re-export of the candy-fuzzy SSOT
 * {@see \SugarCraft\Fuzzy\ScoringProfile}, whose API (constructor named params +
 * default()/strict()/lenient()) is a superset of candy-lister's original copy
 * with identical default/strict/lenient values. Aliased rather than subclassed
 * so `new FuzzyMatch(ScoringProfile::strict())` yields an instance the SSOT
 * matcher accepts natively.
 *
 * @deprecated Use \SugarCraft\Fuzzy\ScoringProfile directly.
 */
class_alias(\SugarCraft\Fuzzy\ScoringProfile::class, ScoringProfile::class);
