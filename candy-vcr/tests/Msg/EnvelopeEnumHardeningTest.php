<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Msg;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Vcr\Msg\BuiltinSerializer;
use SugarCraft\Vcr\Msg\Registry;

/**
 * VCR-S41 (finding 035): enum fields in a message envelope are
 * validated before construction and refuse with a named
 * UnexpectedValueException. Before this fix `Enum::from()` let an
 * anonymous-looking \ValueError escape mid-cassette and kill whoever
 * was dispatching the tape — a cassette field is data, and data faults
 * must surface as data faults.
 */
final class EnvelopeEnumHardeningTest extends TestCase
{
    public function testBadKeyMsgTypeRefusesWithNamedExceptionNotValueError(): void
    {
        $serializer = new BuiltinSerializer();
        try {
            $serializer->decode(['@type' => 'KeyMsg', 'type' => 'bogus', 'rune' => 'x']);
            $this->fail('expected a named UnexpectedValueException for the bogus KeyType');
        } catch (\UnexpectedValueException $e) {
            $this->assertStringContainsString("KeyMsg type 'bogus'", $e->getMessage());
            $this->assertStringContainsString('not a valid KeyType case', $e->getMessage());
        }
    }

    public function testBadMouseButtonRefusesWithNamedException(): void
    {
        $serializer = new BuiltinSerializer();
        try {
            $serializer->decode([
                '@type' => 'MouseClickMsg',
                'x' => 3,
                'y' => 4,
                'button' => 'nope',
                'action' => 'press',
            ]);
            $this->fail('expected a named UnexpectedValueException for the bogus MouseButton');
        } catch (\UnexpectedValueException $e) {
            $this->assertStringContainsString("MouseClickMsg button 'nope'", $e->getMessage());
            $this->assertStringContainsString('not a valid MouseButton case', $e->getMessage());
        }
    }

    public function testValidEnumFieldsStillDecode(): void
    {
        $key = (new BuiltinSerializer())->decode(['@type' => 'KeyMsg', 'type' => 'char', 'rune' => 'j', 'alt' => true]);
        $this->assertInstanceOf(KeyMsg::class, $key);

        $mouse = (new BuiltinSerializer())->decode([
            '@type' => 'MouseClickMsg',
            'x' => 3,
            'y' => 4,
            'button' => 'left',
            'action' => 'press',
        ]);
        $this->assertInstanceOf(MouseClickMsg::class, $mouse);
    }

    public function testRegistryTagsTheRefusalWithTheEnvelopeType(): void
    {
        $registry = Registry::new();
        try {
            // A nested container where a scalar belongs: the serializer's
            // Scalars door throws InvalidArgumentException, the Registry
            // normalises it into the one named family the Player catches.
            $registry->decode(['@type' => 'KeyMsg', 'type' => ['nested']]);
            $this->fail('expected the registry to surface the refusal as UnexpectedValueException');
        } catch (\UnexpectedValueException $e) {
            $this->assertStringContainsString("envelope 'KeyMsg' refused:", $e->getMessage());
            $this->assertNotNull($e->getPrevious(), 'the original field fault rides along for diagnosis');
        }
    }

    public function testUnclaimedEnvelopeStillReturnsNull(): void
    {
        $this->assertNull(Registry::new()->decode(['@type' => 'NoSuchType']));
    }
}
