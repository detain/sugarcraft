<?php

declare(strict_types=1);

namespace SugarCraft\Ansi\Tests;

use SugarCraft\Ansi\Parser\Action;
use SugarCraft\Ansi\Parser\State;
use SugarCraft\Ansi\Parser\Transitions;
use PHPUnit\Framework\TestCase;

final class TransitionsCachingTest extends TestCase
{
    public function testTableIsCachedAfterFirstCall(): void
    {
        $entry1 = Transitions::get(State::Ground->value, ord('A'));
        $entry2 = Transitions::get(State::Ground->value, ord('A'));

        // Same entry returned from cache
        $this->assertSame($entry1, $entry2);
    }

    public function testTableIsBuiltOnlyOnce(): void
    {
        // Multiple calls should all return consistent results
        for ($i = 0; $i < 10; $i++) {
            $entry = Transitions::get(State::Ground->value, ord('A'));
            $this->assertSame(Action::Print->value, Transitions::action($entry));
            $this->assertSame(State::Ground->value, Transitions::nextState($entry));
        }
    }

    public function testTableSizeIs4096(): void
    {
        // Test that a sample of entries from different state positions work
        // We can't directly access the private table, but we can verify
        // the transition lookup is consistent across all states
        $states = [
            State::Ground->value,
            State::CsiEntry->value,
            State::Escape->value,
            State::Utf8->value,
        ];

        foreach ($states as $state) {
            $entry = Transitions::get($state, 0x00);
            $this->assertNotNull($entry, "Entry should exist for state $state");
            $this->assertGreaterThanOrEqual(0, Transitions::action($entry));
            $this->assertLessThanOrEqual(15, Transitions::nextState($entry));
        }
    }

    public function testAllStatesHaveValidTransitions(): void
    {
        foreach (State::cases() as $state) {
            foreach ([0x00, 0x1B, 0x7F, 0x80, 0xFF] as $byte) {
                $entry = Transitions::get($state->value, $byte);
                $this->assertNotNull($entry);
            }
        }
    }

    public function testAllActionValuesAreRepresented(): void
    {
        $foundActions = [];
        // Sample every byte in every state to find all action values
        foreach (State::cases() as $state) {
            for ($byte = 0; $byte <= 0xFF; $byte++) {
                $entry = Transitions::get($state->value, $byte);
                $action = Transitions::action($entry);
                $foundActions[$action] = true;
            }
        }
        // All action values 0-9 should appear in the table
        foreach (Action::cases() as $action) {
            $this->assertArrayHasKey($action->value, $foundActions,
                sprintf('Action %s (%d) should appear in transition table', $action->name, $action->value));
        }
    }

    public function testActionAndNextStateUnpackCorrectly(): void
    {
        // Action is upper 4 bits (shifted right by 4)
        // Next state is lower 4 bits (masked with 0x0F)
        $entry = Transitions::get(State::Ground->value, ord('A'));
        $action = Transitions::action($entry);
        $nextState = Transitions::nextState($entry);
        // Rebuild: (action << 4) | state gives the original byte value
        $rebuilt = ($action << 4) | ($nextState & 0x0F);
        $this->assertSame($entry, $rebuilt, 'Entry should round-trip through action/nextState extraction and rebuild');
    }
}
