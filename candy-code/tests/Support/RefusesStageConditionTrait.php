<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use SugarCraft\Code\Workflows\StageCondition;

/**
 * The message {@see StageCondition::parse()} refuses a clause with — one copy
 * for the wf-impl P1-5 suites (`StageConditionPointerTest`,
 * `StageConditionLoopLeafTest`), which had each written it and drifted by the
 * loop-leaf flag alone ({@see DuplicatedTestHelperDriftTest}'s two-token run).
 * `$allowLoopLeaves` defaults to parse()'s own default, so a pointer-suite call
 * parses exactly as it did.
 */
trait RefusesStageConditionTrait
{
    /** @param array<string, mixed> $clause */
    private function refusal(array $clause, bool $allowLoopLeaves = false): string
    {
        try {
            StageCondition::parse($clause, $allowLoopLeaves);
        } catch (\InvalidArgumentException $error) {
            return $error->getMessage();
        }

        $this->fail('parse() must refuse ' . json_encode($clause));
    }
}
