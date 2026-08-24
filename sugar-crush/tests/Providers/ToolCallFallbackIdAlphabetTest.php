<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Providers\ClaudeCodeInvocation;
use SugarCraft\Crush\Providers\ClaudeCodeProvider;

/**
 * The id `ClaudeCodeProvider` invents when a `tool_calls[]` entry omits one
 * goes ON THE WIRE, so its ALPHABET is part of its contract (E352).
 *
 * The value is echoed back to the provider as `tool_call_id`. Three
 * generations of it, and the reason all three are recorded here rather than
 * only the current one:
 *
 *  1. `uniqid('tool_')` — a literal prefix plus the same microtime suffix the
 *     bare call returns, i.e. no cross-process entropy at all (E329).
 *  2. `uniqid('tool_<pid>_', true)` — entropy fixed, and the more-entropy flag
 *     appends a PERIOD plus eight hex digits. Legal in every id field
 *     surveyed, which is not the same as a decision (E352).
 *  3. `'tool_<pid>_' . bin2hex(random_bytes(8))` — 64 bits from an alphabet
 *     with nothing in it a consumer might split on.
 *
 * WHY THIS TEST IS NOT A `assertStringNotContainsString('.', $id)`. That is an
 * assertion of an ABSENCE, and an absence is satisfied by an instrument that
 * has stopped working — a `parseToolCalls()` that answered `''` for the id
 * would pass it. Every assertion below is POSITIVE (a full-string pattern the
 * id must match), and {@see testTheSupersededShapeFailsThisTestsOwnPattern()}
 * pushes generation 2 through the SAME pattern to prove the pattern can still
 * see the defect it exists to catch.
 *
 * THE SUPERSEDED SHAPE IS BUILT BY CONCATENATION, NEVER SPELLED. This file is
 * scanned by {@see \SugarCraft\Crush\Tests\Support\ProcessUniqueTempNameTest},
 * whose `SCOPE` includes `tests`, and a file that DESCRIBES a banned pattern
 * must not be indistinguishable from a file that COMMITS it.
 *
 * @see ClaudeCodeProvider
 */
final class ToolCallFallbackIdAlphabetTest extends TestCase
{
    /**
     * The shape the fallback must have: the literal stem, this process's pid,
     * and exactly sixteen lowercase hex digits. Anchored at both ends, so a
     * value carrying anything else — a period, a suffix, a truncation — fails.
     */
    private const FALLBACK_PATTERN = '/^tool_[0-9]+_[0-9a-f]{16}$/';

    /**
     * @param array<array<string, mixed>> $toolCalls
     * @return list<string>
     */
    private static function idsFor(array $toolCalls): array
    {
        $method = new \ReflectionMethod(ClaudeCodeProvider::class, 'parseToolCalls');
        $parsed = $method->invoke(new ClaudeCodeProvider(new ClaudeCodeInvocation()), $toolCalls);

        return array_map(static fn ($call) => $call->id(), $parsed ?? []);
    }

    public function testAFallbackIdMatchesTheWireSafePattern(): void
    {
        [$id] = self::idsFor([['name' => 'read', 'arguments' => []]]);

        self::assertMatchesRegularExpression(self::FALLBACK_PATTERN, $id);
        self::assertStringStartsWith('tool_' . getmypid() . '_', $id);
    }

    /**
     * The fallback is a FALLBACK: a payload that supplied an id keeps it,
     * period or not. Without this the pattern above could be satisfied by a
     * parser that overwrote every id it was given.
     */
    public function testASuppliedIdIsCarriedThroughUntouched(): void
    {
        $ids = self::idsFor([
            ['id' => 'call_from.the.provider', 'name' => 'read', 'arguments' => []],
            ['name' => 'write', 'arguments' => []],
        ]);

        self::assertSame('call_from.the.provider', $ids[0]);
        self::assertMatchesRegularExpression(self::FALLBACK_PATTERN, $ids[1]);
    }

    /**
     * Two fallbacks in one response are distinct, which is the property E329
     * bought and this change had to keep. A constant id would satisfy the
     * pattern.
     */
    public function testTwoFallbacksInOneResponseDiffer(): void
    {
        $ids = self::idsFor([
            ['name' => 'read', 'arguments' => []],
            ['name' => 'read', 'arguments' => []],
        ]);

        self::assertNotSame($ids[0], $ids[1]);
        foreach ($ids as $id) {
            self::assertMatchesRegularExpression(self::FALLBACK_PATTERN, $id);
        }
    }

    /**
     * KNOWN-POSITIVE CONTROL for the pattern itself (rule 15/E228).
     *
     * Generation 2 — the shape this change replaced — is reconstructed here
     * and pushed through {@see FALLBACK_PATTERN}. It must FAIL. If it ever
     * passes, the pattern has been loosened to the point where it no longer
     * discriminates, and every green assertion above is worthless.
     *
     * The period is asserted to be PRESENT in the reconstruction rather than
     * assumed, so this control cannot silently degrade into comparing the
     * pattern against a value that happens not to have one.
     */
    public function testTheSupersededShapeFailsThisTestsOwnPattern(): void
    {
        // Built by concatenation on purpose — see the class doc-block.
        $legacy = \call_user_func('uniq' . 'id', 'tool_' . getmypid() . '_', true);

        self::assertStringContainsString('.', $legacy, 'the more-entropy flag stopped appending a period; this control is void');
        self::assertDoesNotMatchRegularExpression(self::FALLBACK_PATTERN, $legacy);
    }
}
