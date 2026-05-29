<?php

declare(strict_types=1);

namespace SugarCraft\Prompt\Fuzzy;

/**
 * @deprecated Use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher instead. This re-export exists for backward compatibility.
 */
class_alias(\SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher::class, FuzzyMatcher::class);