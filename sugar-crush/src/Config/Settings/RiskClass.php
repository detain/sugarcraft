<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings;

/**
 * What a hostile value of a key could cost, which is what decides whether a
 * checked-out repository may set it.
 *
 * This turns the prose rule `docs/SETTINGS.md` and
 * {@see \SugarCraft\Crush\Config\LayeredSettings::PROJECT_TIER_KEYS} argue key
 * by key — "no key whose meaningful direction is UP belongs to a checked-out
 * repository" — into one predicate a test can hold every definition to:
 * {@see projectSettableAllowed()}.
 *
 *  - Cosmetic  pixels and placement (`theme`).
 *  - Narrowing only ever removes capability or prompt text (`disabledTools`).
 *  - Tuning    throughput or a bound whose bad value costs time, not money.
 *  - Spend     raises what the operator's credential is billed.
 *  - Exec      names something this process RUNS (`statusLine`).
 *  - Security  widens or grants what the gate lets through.
 *  - Egress    decides where data is sent.
 *  - Prompt    decides which text becomes authoritative system prompt.
 */
enum RiskClass: string
{
    case Cosmetic = 'cosmetic';
    case Narrowing = 'narrowing';
    case Tuning = 'tuning';
    case Spend = 'spend';
    case Exec = 'exec';
    case Security = 'security';
    case Egress = 'egress';
    case Prompt = 'prompt';

    /**
     * The tier ceiling. A class outside this set may still be USER-only for a
     * key in it — `contextWindow` is Tuning and user-only — but a project-settable
     * key outside it is a contradiction the schema test refuses.
     */
    public function projectSettableAllowed(): bool
    {
        return match ($this) {
            self::Cosmetic, self::Narrowing, self::Tuning => true,
            self::Spend, self::Exec, self::Security, self::Egress, self::Prompt => false,
        };
    }
}
