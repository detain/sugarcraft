<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Msg;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Vcr\Msg\JsonableSerializer;

final class JsonableSerializerTest extends TestCase
{
    public function testCanEncodeJsonSerializableMsg(): void
    {
        $s = new JsonableSerializer();
        $this->assertTrue($s->canEncode(new UserJsonableMsg(name: 'foo', count: 3)));
    }

    public function testCannotEncodeNonJsonSerializableMsg(): void
    {
        $s = new JsonableSerializer();
        $this->assertFalse($s->canEncode(new QuitMsg()));
        $this->assertFalse($s->canEncode(new KeyMsg(\SugarCraft\Core\KeyType::Tab)));
    }

    public function testEncodeUsesFqcnAndJsonSerializeOutput(): void
    {
        $msg = new UserJsonableMsg(name: 'foo', count: 3);
        $envelope = (new JsonableSerializer())->encode($msg);

        $this->assertSame(UserJsonableMsg::class, $envelope['@type']);
        $this->assertSame(['name' => 'foo', 'count' => 3], $envelope['data']);
    }

    public function testRoundTripViaNamedConstructorArgs(): void
    {
        $original = new UserJsonableMsg(name: 'bar', count: 7);
        $s = (new JsonableSerializer())->allow(UserJsonableMsg::class);

        $envelope = $s->encode($original);
        $decoded = $s->decode($envelope);

        $this->assertInstanceOf(UserJsonableMsg::class, $decoded);
        $this->assertSame('bar', $decoded->name);
        $this->assertSame(7, $decoded->count);
    }

    /**
     * The fail-closed default (finding 034): encoding needs no opt-in,
     * decoding EVERY class does.
     */
    public function testFreshCatchAllDecodesNothing(): void
    {
        $s = new JsonableSerializer();
        $envelope = $s->encode(new UserJsonableMsg(name: 'foo', count: 3));

        $this->assertFalse($s->canDecode($envelope));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not on the allow list');
        $s->decode($envelope);
    }

    public function testCanDecodeRequiresExistingMsgClass(): void
    {
        $s = (new JsonableSerializer())->allow(UserJsonableMsg::class);
        $this->assertTrue($s->canDecode(['@type' => UserJsonableMsg::class]));
        $this->assertFalse($s->canDecode(['@type' => 'NoSuch\\Class']));
        $this->assertFalse($s->canDecode([]));
    }

    public function testCanDecodeRejectsClassesThatDoNotImplementMsg(): void
    {
        $s = new JsonableSerializer();
        // \stdClass exists but isn't a Msg — it can never even join the
        // allow list, which is where the shape validation lives now.
        $this->assertFalse($s->canDecode(['@type' => \stdClass::class]));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not implement SugarCraft\\Core\\Msg');
        $s->allow(\stdClass::class);
    }

    public function testCanDecodeRejectsClassesThatDoNotImplementJsonSerializable(): void
    {
        $s = new JsonableSerializer();
        // KeyMsg is a Msg but doesn't implement JsonSerializable.
        $this->assertFalse($s->canDecode(['@type' => KeyMsg::class]));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not implement \\JsonSerializable');
        $s->allow(KeyMsg::class);
    }

    public function testAllowRefusesUnknownClassAtTheDoor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot allow unknown class');
        /** @phpstan-ignore-next-line — intentional bad type */
        (new JsonableSerializer())->allow('No\\Such\\Class');
    }

    public function testAllowedClassesIsTheInspectionSeam(): void
    {
        $s = (new JsonableSerializer())->allow(UserJsonableMsg::class, OtherJsonableMsg::class);
        $this->assertSame([OtherJsonableMsg::class, UserJsonableMsg::class], $s->allowedClasses());
    }

    public function testEncodeRejectsNonJsonSerializableMsg(): void
    {
        $this->expectException(\LogicException::class);
        (new JsonableSerializer())->encode(new QuitMsg());
    }

    public function testDecodeRejectsNonExistentClass(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not on the allow list');
        (new JsonableSerializer())->decode(['@type' => 'No\\Such\\Class', 'data' => []]);
    }

    /**
     * The constructor mirror (finding 034): unknown named keys, positional
     * keys and missing required args are all NAMED refusals — never a
     * TypeError escaping through the argument spread.
     */
    public function testDecodeRejectsUnknownDataKey(): void
    {
        $s = (new JsonableSerializer())->allow(UserJsonableMsg::class);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('has no parameter $evil');
        $s->decode(['@type' => UserJsonableMsg::class, 'data' => ['name' => 'a', 'count' => 1, 'evil' => true]]);
    }

    public function testDecodeRejectsPositionalDataKey(): void
    {
        $s = (new JsonableSerializer())->allow(UserJsonableMsg::class);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('positional data key');
        $s->decode(['@type' => UserJsonableMsg::class, 'data' => [0 => 'a']]);
    }

    public function testDecodeRejectsMissingRequiredArgument(): void
    {
        $s = (new JsonableSerializer())->allow(UserJsonableMsg::class);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing required constructor argument $count');
        $s->decode(['@type' => UserJsonableMsg::class, 'data' => ['name' => 'a']]);
    }

    public function testDecodeAcceptsOptionalOmissions(): void
    {
        $s = (new JsonableSerializer())->allow(OptionalJsonableMsg::class);
        $decoded = $s->decode(['@type' => OptionalJsonableMsg::class, 'data' => []]);
        $this->assertInstanceOf(OptionalJsonableMsg::class, $decoded);
    }

    public function testEncodeRejectsNonArrayJsonSerializeReturn(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('jsonSerialize() to return an array');
        (new JsonableSerializer())->encode(new BadJsonableMsg());
    }
}
