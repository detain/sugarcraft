<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\TextInput;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\TextInput\EchoMode;
use SugarCraft\Forms\TextInput\TextInput;

/**
 * E736 plan 5.5 (round 89): pattern-based masking on TextInput + the
 * Input::withPasswordMask() upgrade path. The fixed-echo Password mode keeps
 * its own pins elsewhere (EchoModeTest / Input password tests) — here only the
 * new Mask mode and the byte-identity of the untouched paths.
 */
final class PatternMaskTest extends TestCase
{
    private function widget(string $value): TextInput
    {
        return TextInput::new()->setValue($value);
    }

    /** Unfocused + blurred view is prompt + displayed value, no cursor styling. */
    private function render(TextInput $t): string
    {
        return $t->blur()->view();
    }

    // ---- render byte pins ---------------------------------------------------

    public function testDigitsMaskedSeparatorsVisible(): void
    {
        $t = $this->widget('4111 1234 5678 9012')->withMask('[0-9]');
        $this->assertSame('> **** **** **** ****', $this->render($t));
    }

    public function testCustomEchoCharAndCoercion(): void
    {
        $t = $this->widget('pin:1234')->withMask('[0-9]', '-');
        $this->assertSame('> pin:----', $this->render($t));
        // Empty echo char coerces to '*' — same house rule as withPassword.
        $coerced = $this->widget('ab')->withMask('[a-z]', '');
        $this->assertSame('> **', $this->render($coerced));
    }

    public function testMultibyteMaskingCountsCodepoints(): void
    {
        $t = $this->widget('café—café')->withMask('[é]');
        $this->assertSame('> caf*—caf*', $this->render($t));
    }

    public function testPasswordModeBytesUnchanged(): void
    {
        // The 5.5 upgrade must not disturb the fixed-echo path: same bytes as
        // str_repeat('*', length) exactly as before the Mask case existed.
        $t = $this->widget('secret')->withEchoMode(EchoMode::Password);
        $this->assertSame('> ******', $this->render($t));
    }

    // ---- door -----------------------------------------------------------------

    public function testBrokenPatternThrows(): void
    {
        try {
            $this->widget('x')->withMask('[0-9');
            $this->fail('unclosed class must throw at the door');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid mask pattern', $e->getMessage());
        }
    }

    public function testEmptyPatternThrows(): void
    {
        try {
            $this->widget('x')->withMask('');
            $this->fail('Mask without a pattern is ambiguous — must throw');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid mask pattern', $e->getMessage());
        }
    }

    public function testDoorRunsTheRenderersOwnFlags(): void
    {
        // "\xFF" compiles without /u but NOT with it. If the door and the
        // renderer ever disagree on flags, this widget would accept a pattern
        // that later fails at match time — the whole point of the shared probe.
        $this->assertNotFalse(
            @preg_match('/' . "\xFF" . '/', ''),
            'vacuity: the byte compiles without /u',
        );
        try {
            $this->widget('x')->withMask("\xFF");
            $this->fail('pattern invalid under /u must be refused up front');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid mask pattern', $e->getMessage());
        }
    }

    // ---- lifecycle ------------------------------------------------------------

    public function testModeAccessorsAndCarriage(): void
    {
        $t = $this->widget('4111')->withMask('[0-9]');
        $this->assertSame(EchoMode::Mask, $t->echoMode);
        $this->assertSame('[0-9]', $t->maskPattern);
        // The pattern rides every edit through the mutate funnel.
        $focused = $t->focus()[0];
        $typed   = $focused->update(new KeyMsg(KeyType::Char, '9'))[0];
        $this->assertInstanceOf(TextInput::class, $typed);
        $this->assertSame('[0-9]', $typed->maskPattern);
        $this->assertSame('41119', $typed->value);
        $this->assertSame('> *****', $this->render($typed));
    }

    public function testClearMaskRestoresPlainRendering(): void
    {
        $t = $this->widget('4111')->withMask('[0-9]');
        $cleared = $t->clearMask();
        $this->assertSame(EchoMode::Normal, $cleared->echoMode);
        $this->assertSame('', $cleared->maskPattern);
        $this->assertSame('> 4111', $this->render($cleared));
        // No-op identity on unmasked widgets.
        $this->assertSame($cleared, $cleared->clearMask());
    }

    public function testHostileSubjectFailsClosedToFullEcho(): void
    {
        // A lone surrogate byte can make preg_replace_callback refuse the
        // SUBJECT even though the pattern was door-proven. Secrets win: the
        // fallback echoes every cell rather than rendering raw bytes.
        // charLimit 0 skips setValue's mb_substr — the invalid bytes really
        // reach the renderer instead of being sanitised at the door.
        $t = TextInput::new()->withCharLimit(0)->setValue("\xB4\xA1\xE2\x28")->withMask('[0-9]');
        $rendered = $this->render($t);
        $this->assertStringNotContainsString("\xB4", $rendered);
        $this->assertStringNotContainsString("\xA1", $rendered);
        $this->assertStringNotContainsString("\xE2", $rendered);
        $this->assertStringNotContainsString("\x28", $rendered);
    }

    // ---- Input field pass-through ---------------------------------------------

    public function testFieldPasswordMaskUpgradePath(): void
    {
        $f = Input::new('card')->withValue('4111 1234');
        // Vacuity pair first: plain field renders the number.
        $this->assertStringContainsString('4111 1234', $f->blur()->view());

        $masked = $f->withPasswordMask('[0-9]');
        $this->assertSame(EchoMode::Mask, $masked->input->echoMode);
        $this->assertStringContainsString('**** ****', $masked->blur()->view());
        $this->assertStringNotContainsString('1234', $masked->blur()->view());

        $plain = $masked->withPasswordMask(null);
        $this->assertSame(EchoMode::Normal, $plain->input->echoMode);
        $this->assertStringContainsString('4111 1234', $plain->blur()->view());
    }

    public function testFieldPasswordStillUsesFixedEcho(): void
    {
        // The pre-existing Password door is byte-identical after 5.5 — a
        // withPassword(true) field never enters Mask.
        $f = Input::new('token')->withValue('abc')->withPassword();
        $this->assertSame(EchoMode::Password, $f->input->echoMode);
        $this->assertStringNotContainsString('abc', $f->blur()->view());
    }
}
