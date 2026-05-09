<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Msg;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Vcr\Msg\BuiltinSerializer;
use SugarCraft\Vcr\Msg\JsonableSerializer;
use SugarCraft\Vcr\Msg\MsgSerializer;
use SugarCraft\Vcr\Msg\Registry;

final class RegistryTest extends TestCase
{
    public function testEmptyRegistryReturnsNull(): void
    {
        $r = new Registry();
        $this->assertNull($r->encode(new KeyMsg(KeyType::Tab)));
        $this->assertNull($r->decode(['@type' => 'KeyMsg']));
    }

    public function testRegisterIsFluent(): void
    {
        $r = new Registry();
        $returned = $r->register(new BuiltinSerializer());
        $this->assertSame($r, $returned);
    }

    public function testEncodeFirstMatchWins(): void
    {
        $custom = new class implements MsgSerializer {
            public function canEncode(Msg $msg): bool { return $msg instanceof KeyMsg; }
            public function canDecode(array $envelope): bool { return ($envelope['@type'] ?? null) === 'CustomKey'; }
            public function encode(Msg $msg): array { return ['@type' => 'CustomKey']; }
            public function decode(array $envelope): Msg { return new KeyMsg(KeyType::Char, 'X'); }
        };

        $r = (new Registry())->register($custom)->register(new BuiltinSerializer());
        $envelope = $r->encode(new KeyMsg(KeyType::Char, 'q'));
        $this->assertSame('CustomKey', $envelope['@type']);
    }

    public function testDecodeFirstMatchWins(): void
    {
        $custom = new class implements MsgSerializer {
            public function canEncode(Msg $msg): bool { return false; }
            public function canDecode(array $envelope): bool { return ($envelope['@type'] ?? null) === 'KeyMsg'; }
            public function encode(Msg $msg): array { throw new \LogicException(); }
            public function decode(array $envelope): Msg { return new KeyMsg(KeyType::Char, 'CUSTOM'); }
        };

        $r = (new Registry())->register($custom)->register(new BuiltinSerializer());
        $decoded = $r->decode(['@type' => 'KeyMsg', 'type' => 'char', 'rune' => 'q']);
        $this->assertInstanceOf(KeyMsg::class, $decoded);
        $this->assertSame('CUSTOM', $decoded->rune);
    }

    public function testDefaultRegistryHandlesBuiltinAndJsonable(): void
    {
        $r = Registry::default();

        // Builtin: KeyMsg
        $msg = new KeyMsg(KeyType::Char, 'q');
        $envelope = $r->encode($msg);
        $this->assertNotNull($envelope);
        $this->assertSame('KeyMsg', $envelope['@type']);
        $decoded = $r->decode($envelope);
        $this->assertInstanceOf(KeyMsg::class, $decoded);
        $this->assertSame('q', $decoded->rune);

        // Jsonable: user-defined Msg
        $user = new UserJsonableMsg(name: 'r', count: 1);
        $envelope = $r->encode($user);
        $this->assertNotNull($envelope);
        $this->assertSame(UserJsonableMsg::class, $envelope['@type']);
        $decoded = $r->decode($envelope);
        $this->assertInstanceOf(UserJsonableMsg::class, $decoded);
        $this->assertSame('r', $decoded->name);
        $this->assertSame(1, $decoded->count);
    }

    public function testDefaultRegistryDoesNotHandleQuitMsg(): void
    {
        $r = Registry::default();
        // QuitMsg isn't in BuiltinSerializer's set and doesn't implement
        // JsonSerializable, so the default registry has no encoder.
        $this->assertNull($r->encode(new QuitMsg()));
    }

    public function testDecodeReturnsNullWhenNoSerializerClaims(): void
    {
        $r = Registry::default();
        $this->assertNull($r->decode(['@type' => 'NoSuchMsg']));
    }
}
