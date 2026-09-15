<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Messages;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Usage;

/**
 * E707 (round 81): `lengthStopped` is a seventh carried field on the root
 * Message, and every copy method in this class is documented as having to
 * carry it - the same maintenance contract the image and usage fields signed.
 * A copy method that quietly drops the flag is invisible everywhere except in
 * the one transcript that needed the notice, so the whole family is pinned
 * from one provider rather than trusting the reader to notice.
 */
final class LengthStoppedFieldTest extends TestCase
{
    public function testTheFactoriesStartClean(): void
    {
        $this->assertFalse(Message::assistant('hi')->lengthStopped, 'default false: every existing construction site keeps its meaning untouched');
        $this->assertFalse(Message::user('hi')->lengthStopped);
        $this->assertFalse(Message::system('hi')->lengthStopped);
    }

    public function testWithLengthStoppedIsTheOnlyWayOn(): void
    {
        $marked = Message::assistant('cut')->withLengthStopped(true);

        $this->assertTrue($marked->lengthStopped);
        $this->assertFalse(Message::assistant('cut')->lengthStopped, 'and back off is the same door - the setter round-trips both ways');
        $this->assertTrue($marked->withLengthStopped(false)->lengthStopped === false);
    }

    /**
     * @param \Closure(Message): Message $copy
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('fieldCarryingCopies')]
    public function testEveryCopyMethodCarriesTheFlagForward(\Closure $copy): void
    {
        $marked = Message::assistant('cut')->withLengthStopped(true);
        $after = $copy($marked);

        $this->assertTrue($after->lengthStopped, get_class($after) . ' copy dropped the flag — the E707 copy-list has to stay complete');
        $this->assertSame(Role::Assistant, $after->role, 'fixture sanity: the copy really rebuilt the same message');
    }

    /** @return iterable<string, array{\Closure(Message): Message}> */
    public static function fieldCarryingCopies(): iterable
    {
        yield 'attachFile' => [
            static fn (Message $m): Message => $m->attachFile('/tmp/e707-note.md'),
        ];
        yield 'attachImage' => [
            static fn (Message $m): Message => $m->attachImage('/tmp/e707.png'),
        ];
        yield 'withToolCalls' => [
            static fn (Message $m): Message => $m->withToolCalls([]),
        ];
        yield 'withToolResults' => [
            static fn (Message $m): Message => $m->withToolResults([]),
        ];
        yield 'withReasoning' => [
            static fn (Message $m): Message => $m->withReasoning('thought'),
        ];
        yield 'withImage' => [
            static fn (Message $m): Message => $m->withImage("\x89bytes", 'kitty'),
        ];
        yield 'withUsage' => [
            static fn (Message $m): Message => $m->withUsage(Usage::new(10, 0.1)),
        ];
    }

    public function testTheFlagSurvivesAlongsideTheOtherCarriedFields(): void
    {
        $marked = Message::assistant('cut')
            ->withReasoning('thinking')
            ->withLengthStopped(true)
            ->withUsage(Usage::new(4, 0.02))
            ->withImage("\x89png", 'kitty');

        $this->assertTrue($marked->lengthStopped, 'ordering of the builder chain must not lose the verdict');
        $this->assertSame('thinking', $marked->reasoning);
        $this->assertTrue($marked->hasImage());
        $this->assertNotNull($marked->usage);
    }
}
