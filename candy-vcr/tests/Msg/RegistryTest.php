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

    public function testRegisterFirstIsFluent(): void
    {
        $r = new Registry();
        $returned = $r->registerFirst(new BuiltinSerializer());
        $this->assertSame($r, $returned);
    }

    /**
     * The order trap: `default()` already ends in the JsonableSerializer
     * catch-all, so an appended codec for a `\JsonSerializable` Msg is
     * never consulted. Reds if `register()` ever starts prepending.
     */
    public function testAppendedSerializerStaysBehindTheCatchAll(): void
    {
        $r = Registry::new()->register($this->customUserCodec());

        $envelope = $r->encode(new UserJsonableMsg(name: 'r', count: 2));

        $this->assertNotNull($envelope);
        $this->assertSame(UserJsonableMsg::class, $envelope['@type']);
    }

    /**
     * `registerFirst()` is the documented way to outrank the built-ins:
     * the custom codec wins on both directions. Reds if the prepend is
     * neutered.
     */
    public function testRegisterFirstOutranksTheCatchAll(): void
    {
        $r = Registry::new()->registerFirst($this->customUserCodec());

        $envelope = $r->encode(new UserJsonableMsg(name: 'r', count: 2));
        $this->assertNotNull($envelope);
        $this->assertSame('CustomUser', $envelope['@type']);

        $decoded = $r->decode(['@type' => 'CustomUser', 'name' => 'z', 'count' => 7]);
        $this->assertInstanceOf(UserJsonableMsg::class, $decoded);
        $this->assertSame('z', $decoded->name);
        $this->assertSame(7, $decoded->count);
    }

    /**
     * A codec for the test fixture Msg, tagged differently from
     * JsonableSerializer's FQCN tag so the winner is identifiable.
     */
    private function customUserCodec(): MsgSerializer
    {
        return new class () implements MsgSerializer {
            public function canEncode(Msg $msg): bool
            {
                return $msg instanceof UserJsonableMsg;
            }

            public function canDecode(array $envelope): bool
            {
                return ($envelope['@type'] ?? null) === 'CustomUser';
            }

            public function encode(Msg $msg): array
            {
                /** @var UserJsonableMsg $msg */
                return ['@type' => 'CustomUser', 'name' => $msg->name, 'count' => $msg->count];
            }

            public function decode(array $envelope): Msg
            {
                return new UserJsonableMsg(
                    name: (string) ($envelope['name'] ?? ''),
                    count: (int) ($envelope['count'] ?? 0),
                );
            }
        };
    }

    public function testEncodeFirstMatchWins(): void
    {
        $custom = new class () implements MsgSerializer {
            public function canEncode(Msg $msg): bool
            {
                return $msg instanceof KeyMsg;
            }
            public function canDecode(array $envelope): bool
            {
                return ($envelope['@type'] ?? null) === 'CustomKey';
            }
            public function encode(Msg $msg): array
            {
                return ['@type' => 'CustomKey'];
            }
            public function decode(array $envelope): Msg
            {
                return new KeyMsg(KeyType::Char, 'X');
            }
        };

        $r = (new Registry())->register($custom)->register(new BuiltinSerializer());
        $envelope = $r->encode(new KeyMsg(KeyType::Char, 'q'));
        $this->assertNotNull($envelope);
        $this->assertSame('CustomKey', $envelope['@type']);
    }

    public function testDecodeFirstMatchWins(): void
    {
        $custom = new class () implements MsgSerializer {
            public function canEncode(Msg $msg): bool
            {
                return false;
            }
            public function canDecode(array $envelope): bool
            {
                return ($envelope['@type'] ?? null) === 'KeyMsg';
            }
            public function encode(Msg $msg): array
            {
                throw new \LogicException();
            }
            public function decode(array $envelope): Msg
            {
                return new KeyMsg(KeyType::Char, 'CUSTOM');
            }
        };

        $r = (new Registry())->register($custom)->register(new BuiltinSerializer());
        $decoded = $r->decode(['@type' => 'KeyMsg', 'type' => 'char', 'rune' => 'q']);
        $this->assertInstanceOf(KeyMsg::class, $decoded);
        $this->assertSame('CUSTOM', $decoded->rune);
    }

    public function testDefaultRegistryHandlesBuiltinAndJsonable(): void
    {
        $r = Registry::new();

        // Builtin: KeyMsg
        $msg = new KeyMsg(KeyType::Char, 'q');
        $envelope = $r->encode($msg);
        $this->assertNotNull($envelope);
        $this->assertSame('KeyMsg', $envelope['@type']);
        $decoded = $r->decode($envelope);
        $this->assertInstanceOf(KeyMsg::class, $decoded);
        $this->assertSame('q', $decoded->rune);

        // Jsonable: user-defined Msg — the decode side is allow-listed
        // (finding 034), so the host opts its class in through the registry.
        $r->allow(UserJsonableMsg::class);
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
        $r = Registry::new();
        // QuitMsg isn't in BuiltinSerializer's set and doesn't implement
        // JsonSerializable, so the default registry has no encoder.
        $this->assertNull($r->encode(new QuitMsg()));
    }

    public function testDecodeReturnsNullWhenNoSerializerClaims(): void
    {
        $r = Registry::new();
        $this->assertNull($r->decode(['@type' => 'NoSuchMsg']));
    }
}
