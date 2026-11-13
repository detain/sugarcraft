<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use SugarCraft\Toast\{Action, Alert, ToastType};
use PHPUnit\Framework\TestCase;

final class AlertTest extends TestCase
{
    // ─── withProgress clamping ────────────────────────────────────────────

    public function testWithProgressClampsToZero(): void
    {
        $alert = (new Alert(ToastType::Info, 'msg'))->withProgress(-5.0);
        $this->assertSame(0.0, $alert->progress);
    }

    public function testWithProgressClampsToOne(): void
    {
        $alert = (new Alert(ToastType::Info, 'msg'))->withProgress(3.0);
        $this->assertSame(1.0, $alert->progress);
    }

    public function testWithProgressAcceptsValidValue(): void
    {
        $alert = (new Alert(ToastType::Info, 'msg'))->withProgress(0.5);
        $this->assertSame(0.5, $alert->progress);
    }

    public function testWithProgressAcceptsZero(): void
    {
        $alert = (new Alert(ToastType::Info, 'msg'))->withProgress(0.0);
        $this->assertSame(0.0, $alert->progress);
    }

    public function testWithProgressAcceptsOne(): void
    {
        $alert = (new Alert(ToastType::Info, 'msg'))->withProgress(1.0);
        $this->assertSame(1.0, $alert->progress);
    }

    // ─── withCancelledExpiry / withoutExpiry ──────────────────────────────

    public function testWithoutExpiryMakesAlertNonExpired(): void
    {
        // Start with an alert that would expire soon
        $alert = (new Alert(ToastType::Info, 'msg'))->withExpiry(0.001);
        // Wait for it to nearly expire
        \usleep(2000);
        $this->assertTrue($alert->isExpired());

        // withoutExpiry cancels the expiry
        $persistent = $alert->withoutExpiry();
        $this->assertFalse($persistent->isExpired());
    }

    public function testWithCancelledExpiryReturnsNewInstance(): void
    {
        $alert = new Alert(ToastType::Info, 'msg', \microtime(true) + 10);
        $cancelled = $alert->withCancelledExpiry();

        $this->assertNotSame($alert, $cancelled);
        $this->assertNull($cancelled->expiresAt);
        $this->assertNotNull($alert->expiresAt);  // original unchanged
    }

    public function testWithoutExpiryIsAliasForWithCancelledExpiry(): void
    {
        $alert = new Alert(ToastType::Info, 'msg', \microtime(true) + 10);
        $viaAlias = $alert->withoutExpiry();
        $viaExplicit = $alert->withCancelledExpiry();

        $this->assertSame($viaExplicit->expiresAt, $viaAlias->expiresAt);
    }

    public function testWithoutExpiryPreservesOtherFields(): void
    {
        $alert = new Alert(
            ToastType::Error,
            'important message',
            \microtime(true) + 10,
            0.75,
            [new Action('OK', static function () {})],
        );

        $persistent = $alert->withoutExpiry();

        $this->assertSame(ToastType::Error, $persistent->type);
        $this->assertSame('important message', $persistent->message);
        $this->assertNull($persistent->expiresAt);
        $this->assertSame(0.75, $persistent->progress);
        $this->assertCount(1, $persistent->actions);
    }

    // ─── withExtendedExpiry ───────────────────────────────────────────────

    public function testWithExtendedExpiryExtendsFromNow(): void
    {
        $originalExpiry = \microtime(true) + 10.0;
        $alert = new Alert(ToastType::Info, 'msg', $originalExpiry);

        \usleep(1000);  // 1ms later

        $now = \microtime(true);
        $extended = $alert->withExtendedExpiry(5.0);
        // withExtendedExpiry sets expiry to NOW + N seconds, not originalExpiry + N
        $this->assertEqualsWithDelta($now + 5.0, $extended->expiresAt, 1.0);
    }

    public function testWithExtendedExpiryReturnsNewInstance(): void
    {
        $alert = new Alert(ToastType::Info, 'msg', \microtime(true) + 10);
        $extended = $alert->withExtendedExpiry(5.0);

        $this->assertNotSame($alert, $extended);
        $this->assertNotNull($alert->expiresAt);  // original unchanged
    }

    public function testWithExtendedExpiryPreservesOtherFields(): void
    {
        $alert = new Alert(
            ToastType::Warning,
            'warn message',
            \microtime(true) + 10,
            0.5,
            [],
        );

        $extended = $alert->withExtendedExpiry(3.0);

        $this->assertSame(ToastType::Warning, $extended->type);
        $this->assertSame('warn message', $extended->message);
        $this->assertSame(0.5, $extended->progress);
    }

    // ─── withActions ───────────────────────────────────────────────────────

    public function testWithActionsAddsActions(): void
    {
        $alert = new Alert(ToastType::Info, 'msg');
        $actions = [
            new Action('Confirm', static function () {}),
            new Action('Cancel', static function () {}),
        ];

        $withActions = $alert->withActions($actions);

        $this->assertCount(2, $withActions->actions);
        $this->assertSame('Confirm', $withActions->actions[0]->label);
        $this->assertSame('Cancel', $withActions->actions[1]->label);
    }

    public function testWithActionsReturnsNewInstance(): void
    {
        $alert = new Alert(ToastType::Info, 'msg');
        $withActions = $alert->withActions([new Action('OK', static function () {})]);

        $this->assertNotSame($alert, $withActions);
        $this->assertSame([], $alert->actions);  // original unchanged
    }

    public function testWithActionsPreservesOtherFields(): void
    {
        $alert = (new Alert(ToastType::Success, 'done', \microtime(true) + 5))
            ->withProgress(0.8);

        $newActions = [new Action('View', static function () {})];
        $withActions = $alert->withActions($newActions);

        $this->assertSame(ToastType::Success, $withActions->type);
        $this->assertSame('done', $withActions->message);
        $this->assertSame(0.8, $withActions->progress);
        $this->assertCount(1, $withActions->actions);
    }

    public function testWithActionsCanReplaceActions(): void
    {
        $alert = (new Alert(ToastType::Info, 'msg'))
            ->withActions([new Action('Old', static function () {})]);

        $replaced = $alert->withActions([new Action('New', static function () {})]);

        $this->assertCount(1, $replaced->actions);
        $this->assertSame('New', $replaced->actions[0]->label);
    }

    // ─── Immutability of colour withers already in ToastStyledRenderTest ──

    public function testAllWithersReturnNewInstance(): void
    {
        $original = new Alert(ToastType::Info, 'msg');

        $this->assertNotSame($original, $original->withBackgroundColor(\SugarCraft\Core\Util\Color::hex('#000')));
        $this->assertNotSame($original, $original->withForegroundColor(\SugarCraft\Core\Util\Color::hex('#000')));
        $this->assertNotSame($original, $original->withBorderColor(\SugarCraft\Core\Util\Color::hex('#000')));
        $this->assertNotSame($original, $original->withProgress(0.5));
        $this->assertNotSame($original, $original->withActions([]));
        $this->assertNotSame($original, $original->withExpiry(10.0));
        $this->assertNotSame($original, $original->withCancelledExpiry());
    }

    public function testOriginalAlertUnchangedAfterWithers(): void
    {
        $original = new Alert(ToastType::Info, 'msg');

        $original->withBackgroundColor(\SugarCraft\Core\Util\Color::hex('#f00'));
        $original->withForegroundColor(\SugarCraft\Core\Util\Color::hex('#0f0'));
        $original->withBorderColor(\SugarCraft\Core\Util\Color::hex('#00f'));
        $original->withProgress(0.5);
        $original->withActions([]);
        $original->withExpiry(10.0);

        $this->assertNull($original->backgroundColor);
        $this->assertNull($original->foregroundColor);
        $this->assertNull($original->borderColor);
        $this->assertNull($original->progress);
        $this->assertSame([], $original->actions);
        $this->assertNull($original->expiresAt);
    }

    // ─── TST-CONV: single reconstruction site + explicit-null override ───

    public function testAlertPhpCarriesOnePositionalTranscriptionSite(): void
    {
        // House law (root AGENTS.md "Immutable + fluent"): every with*()
        // rebuilds via the private mutate(). The whole file may spell
        // `new self(` exactly twice — the base construction inside the
        // ::new() factory and the named-argument rebuild inside mutate().
        // A third spelling means a positional chain crept back in, and a
        // transposed ?float/?Color pair there would compile silently.
        $src = (string) \file_get_contents(\dirname(__DIR__) . '/src/Alert.php');
        $this->assertSame(
            2,
            \substr_count($src, 'new self('),
            'Alert.php must route reconstruction through mutate()'
        );
    }

    public function testExplicitNullOverrideClearsTheFieldNotFallsThrough(): void
    {
        // mutate() tests key PRESENCE, not nullness: with* setters must be
        // able to SET null (clear the colour / cancel the expiry). A
        // `?? $this->field` shortcut would read "no override" here and
        // leave the old value riding along.
        $alert = Alert::new(ToastType::Warning, 'x')
            ->withForegroundColor(\SugarCraft\Core\Util\Color::hex('#0f0'))
            ->withExpiry(60.0);
        $this->assertNotNull($alert->foregroundColor);
        $this->assertNotNull($alert->expiresAt);

        $cleared = $alert->withForegroundColor(null)->withCancelledExpiry();
        $this->assertNull($cleared->foregroundColor, 'explicit null override must clear the colour');
        $this->assertNull($cleared->expiresAt, 'cancelling expiry must write null through the override');
        $this->assertTrue($cleared->persistent);
    }

    public function testFactoryFillsEveryFieldExactlyLikeTheConstructor(): void
    {
        // ::new() now seeds type/message directly and rides mutate() for the
        // rest — a dropped or misnamed override key would silently default
        // the field to null while the ctor spelled it positionally.
        $actions = [new Action('Go', static function (): void {})];
        $bg = \SugarCraft\Core\Util\Color::hex('#111');
        $fg = \SugarCraft\Core\Util\Color::hex('#222');
        $border = \SugarCraft\Core\Util\Color::hex('#333');
        $args = [
            ToastType::Success, 'built', 123.5, 0.5, $actions, $bg, $fg, $border, true, 'id-1',
        ];

        $viaCtor = new Alert(...$args);
        $viaFactory = Alert::new(...$args);

        foreach (['type', 'message', 'expiresAt', 'progress', 'actions', 'backgroundColor', 'foregroundColor', 'borderColor', 'persistent', 'id'] as $field) {
            $this->assertSame($viaCtor->{$field}, $viaFactory->{$field}, "Alert::new() lost the {$field} override");
        }
    }
}
