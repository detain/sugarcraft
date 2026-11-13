<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Tests\Support\LoopHygiene;

/**
 * SF-49 (sugar-forms-078) — verdict identity on the FIELD-level
 * `validateAsync()` surface: the promise judges the bytes dispatched at call
 * time, while the host's immutable copy can drift mid-flight. These pins
 * codify the DISCARD LAW — capture `value()` before dispatch, and drop any
 * resolution (including a clean null, the stale-submit hazard the finding
 * names) whose judged bytes are no longer the live bytes. The pending round
 * inside the form enforces this duty for Enter-driven flows; a direct
 * validateAsync() caller carries it by hand. Hand-settled Deferreds only —
 * hermetic like FormAsyncValidateTest (E646, zero timers).
 */
final class AsyncValidationVerdictIdentityTest extends TestCase
{
    use LoopHygiene;

    /** Focus and type 'a' so the field carries real bytes to judge. */
    private static function typed(Input $f): Input
    {
        [$f, ] = $f->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'a'));
        return $f;
    }

    /**
     * @return array{0: Input, 1: Deferred}
     */
    private static function fieldHangingOn(): array
    {
        $pending = new Deferred();
        $field   = self::typed(Input::new('name')->withAsyncValidator(
            static fn (mixed $v): PromiseInterface => $pending->promise()
        ));
        return [$field, $pending];
    }

    public function testMidFlightEditLeavesTheVerdictJudgedAgainstDispatchedBytes(): void
    {
        [$field, $pending] = self::fieldHangingOn();
        $judged  = $field->value();
        $promise = $field->validateAsync();

        // A consumer edits while the check is in flight: immutability means
        // the live copy is a NEW instance; the dispatched one never moved.
        $edited = $field->withValue('a-and-more');
        $pending->resolve('name is taken');

        $error = 'unsentinel';
        $promise->then(static function (?string $e) use (&$error): void {
            $error = $e;
        });

        // The verdict arrived — and it speaks about 'a', not the live bytes.
        $this->assertSame('name is taken', $error);
        $this->assertSame('a', $judged);
        $this->assertSame('a-and-more', $edited->value());
        // DISCARD LAW trigger: judged bytes are no longer live bytes.
        $this->assertNotSame($judged, $edited->value());
        // No side channel: the copy never re-lands the verdict on itself.
        $this->assertNull($field->error());
        $this->assertNull($edited->error());
    }

    public function testStaleCleanVerdictIsDiscardedNotSubmitted(): void
    {
        // The hazard named in the finding: a mid-flight edit racing a CLEAN
        // resolution must not wave the new unvalidated bytes through.
        [$field, $pending] = self::fieldHangingOn();
        $judged  = $field->value();
        $promise = $field->validateAsync();

        $edited = $field->withValue('a-and-more');
        $pending->resolve(null);

        $error = 'unsentinel';
        $promise->then(static function (?string $e) use (&$error): void {
            $error = $e;
        });

        $this->assertNull($error, 'the clean verdict lands for the dispatched bytes');
        // Applying it would submit 'a-and-more' unvalidated: the identity
        // check is the ONLY thing standing between, and it must fire.
        $this->assertNotSame($judged, $edited->value());
    }

    public function testUnchangedBytesMakeTheVerdictApply(): void
    {
        [$field, $pending] = self::fieldHangingOn();
        $judged  = $field->value();
        $promise = $field->validateAsync();

        $pending->resolve(null);

        $error = 'unsentinel';
        $promise->then(static function (?string $e) use (&$error): void {
            $error = $e;
        });

        // No edit intervened: judged bytes still live — the host may act on
        // the verdict (submit on null, paint on string).
        $this->assertNull($error);
        $this->assertSame($judged, $field->value());
    }
}
