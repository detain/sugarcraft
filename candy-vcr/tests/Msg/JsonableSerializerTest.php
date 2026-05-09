<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Msg;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg;
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
        $s = new JsonableSerializer();

        $envelope = $s->encode($original);
        $decoded = $s->decode($envelope);

        $this->assertInstanceOf(UserJsonableMsg::class, $decoded);
        $this->assertSame('bar', $decoded->name);
        $this->assertSame(7, $decoded->count);
    }

    public function testCanDecodeRequiresExistingMsgClass(): void
    {
        $s = new JsonableSerializer();
        $this->assertTrue($s->canDecode(['@type' => UserJsonableMsg::class]));
        $this->assertFalse($s->canDecode(['@type' => 'NoSuch\\Class']));
        $this->assertFalse($s->canDecode([]));
    }

    public function testCanDecodeRejectsClassesThatDoNotImplementMsg(): void
    {
        $s = new JsonableSerializer();
        // \stdClass exists but isn't a Msg.
        $this->assertFalse($s->canDecode(['@type' => \stdClass::class]));
    }

    public function testCanDecodeRejectsClassesThatDoNotImplementJsonSerializable(): void
    {
        $s = new JsonableSerializer();
        // KeyMsg is a Msg but doesn't implement JsonSerializable.
        $this->assertFalse($s->canDecode(['@type' => KeyMsg::class]));
    }

    public function testEncodeRejectsNonJsonSerializableMsg(): void
    {
        $this->expectException(\LogicException::class);
        (new JsonableSerializer())->encode(new QuitMsg());
    }

    public function testDecodeRejectsNonExistentClass(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot resolve class');
        (new JsonableSerializer())->decode(['@type' => 'No\\Such\\Class', 'data' => []]);
    }

    public function testDecodeRejectsClassThatIsNotAMsg(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not implement');
        (new JsonableSerializer())->decode(['@type' => \stdClass::class, 'data' => []]);
    }

    public function testEncodeRejectsNonArrayJsonSerializeReturn(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('jsonSerialize() to return an array');
        (new JsonableSerializer())->encode(new BadJsonableMsg());
    }
}

