<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Msg;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Msg\BuiltinSerializer;
use SugarCraft\Vt\Msg\FocusInMsg;
use SugarCraft\Vt\Msg\FocusOutMsg;

/**
 * Round-trip tests for FocusInMsg and FocusOutMsg serialization.
 * These msg types originate in candy-vt (step 07.06) and must round-trip
 * correctly through BuiltinSerializer for VCR cassette recording/replay.
 */
final class BuiltinSerializerFocusTest extends TestCase
{
    public function testFocusInMsgRoundTrip(): void
    {
        $msg = new FocusInMsg();
        $envelope = (new BuiltinSerializer())->encode($msg);

        $this->assertSame('FocusInMsg', $envelope['@type']);

        $decoded = (new BuiltinSerializer())->decode($envelope);
        $this->assertInstanceOf(FocusInMsg::class, $decoded);
    }

    public function testFocusOutMsgRoundTrip(): void
    {
        $msg = new FocusOutMsg();
        $envelope = (new BuiltinSerializer())->encode($msg);

        $this->assertSame('FocusOutMsg', $envelope['@type']);

        $decoded = (new BuiltinSerializer())->decode($envelope);
        $this->assertInstanceOf(FocusOutMsg::class, $decoded);
    }

    public function testCanEncodeFocusInMsg(): void
    {
        $this->assertTrue((new BuiltinSerializer())->canEncode(new FocusInMsg()));
    }

    public function testCanEncodeFocusOutMsg(): void
    {
        $this->assertTrue((new BuiltinSerializer())->canEncode(new FocusOutMsg()));
    }

    public function testCanDecodeFocusInMsg(): void
    {
        $this->assertTrue((new BuiltinSerializer())->canDecode(['@type' => 'FocusInMsg']));
    }

    public function testCanDecodeFocusOutMsg(): void
    {
        $this->assertTrue((new BuiltinSerializer())->canDecode(['@type' => 'FocusOutMsg']));
    }

    public function testTagsListContainsFocusMsgs(): void
    {
        $tags = BuiltinSerializer::tags();
        $this->assertContains('FocusInMsg', $tags);
        $this->assertContains('FocusOutMsg', $tags);
    }
}
