<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Messages;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\Usage;

/**
 * {@see Message::$loopGuardStoppedBy}: the repeat-call loop guard's "this turn
 * was ended" verdict, carried from EngineBackend to Chat's settle arm so the
 * transcript can say the turn was stopped and why. Like its two siblings
 * ($lengthStopped, $stepsTruncated) it must survive every copy and a
 * persist/resume round trip, and never reach the wire.
 */
final class LoopGuardStoppedFieldTest extends TestCase
{
    public function testTheFactoriesStartUnset(): void
    {
        $this->assertNull(Message::assistant('hi')->loopGuardStoppedBy);
        $this->assertNull(Message::user('hi')->loopGuardStoppedBy);
        $this->assertNull(Message::notice('hi')->loopGuardStoppedBy);
    }

    public function testTheSetterRoundTripsAndAnEmptyNameIsNoVerdict(): void
    {
        $marked = Message::assistant('stopped')->withLoopGuardStoppedBy('Bash');

        $this->assertSame('Bash', $marked->loopGuardStoppedBy);
        $this->assertNull($marked->withLoopGuardStoppedBy(null)->loopGuardStoppedBy);
        $this->assertNull(Message::assistant('x')->withLoopGuardStoppedBy('')->loopGuardStoppedBy);
    }

    /** @return array<string, array{\Closure(Message): Message}> */
    public static function copies(): array
    {
        return [
            'withToolCalls' => [static fn(Message $m): Message => $m->withToolCalls([new ToolCall('probe', [], 'c1')])],
            'withToolResults' => [static fn(Message $m): Message => $m->withToolResults([])],
            'withReasoning' => [static fn(Message $m): Message => $m->withReasoning('thought')],
            'withImage' => [static fn(Message $m): Message => $m->withImage(null, null)],
            'withUsage' => [static fn(Message $m): Message => $m->withUsage(Usage::new(5))],
            'withLengthStopped' => [static fn(Message $m): Message => $m->withLengthStopped(true)],
            'withStepsTruncated' => [static fn(Message $m): Message => $m->withStepsTruncated(false)],
            'withUiOnly' => [static fn(Message $m): Message => $m->withUiOnly(false)],
        ];
    }

    /** @param \Closure(Message): Message $copy */
    #[DataProvider('copies')]
    public function testEveryCopyMethodCarriesTheVerdict(\Closure $copy): void
    {
        $after = $copy(Message::assistant('stopped')->withLoopGuardStoppedBy('probe'));

        $this->assertSame('probe', $after->loopGuardStoppedBy, 'a copy dropped the verdict — the copy list has to stay complete');
    }

    public function testThePersistedRowRoundTripsTheVerdict(): void
    {
        $row = json_decode((string) json_encode(Message::assistant('stopped')->withLoopGuardStoppedBy('probe')), true);
        $this->assertIsArray($row);

        $this->assertSame('probe', Message::fromArray($row)->loopGuardStoppedBy);

        unset($row['loopGuardStoppedBy']);
        $this->assertNull(Message::fromArray($row)->loopGuardStoppedBy, 'a row persisted before the field existed reads as no verdict');

        $row['loopGuardStoppedBy'] = ['not' => 'a name'];
        $this->assertNull(Message::fromArray($row)->loopGuardStoppedBy, 'garbage is not a verdict');
    }

    public function testTheVerdictNeverReachesTheWire(): void
    {
        $wire = Message::assistant('stopped')->withLoopGuardStoppedBy('probe')->toWire();

        $this->assertArrayNotHasKey('loopGuardStoppedBy', $wire);
        $this->assertStringNotContainsString('probe', (string) json_encode($wire));
    }
}
