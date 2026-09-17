<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Field;

use PHPUnit\Framework\Attributes\DataProvider;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\Text;
use PHPUnit\Framework\TestCase;

/**
 * Plan 5.2 (round 89 lane y3) — async validation on Input/Text.
 *
 * Hermetic by construction: every promise is produced from a Deferred the
 * test settles by hand (react/promise v3 invokes settled callbacks
 * synchronously), so no timers and no shared-loop coupling (E646).
 */
final class AsyncValidationTest extends TestCase
{
    private static function settled(?string $error): PromiseInterface
    {
        $d = new Deferred();
        $d->resolve($error);
        return $d->promise();
    }

    /** Focus + type one char so the sync validators run (Input validates per keystroke). */
    private static function focused(Input $f, string $value = 'a'): Input
    {
        [$f, ] = $f->focus();
        foreach (str_split($value) as $r) {
            [$f, ] = $f->update(new KeyMsg(KeyType::Char, $r));
        }
        return $f;
    }

    public function testSyncErrorShortCircuitsTheAsyncLeg(): void
    {
        $calls = 0;
        $f = Input::new('k')
            ->withValidator(static fn (string $v): ?string => 'boom')
            ->withAsyncValidator(static function (mixed $v) use (&$calls): PromiseInterface {
                $calls++;
                return self::settled(null);
            });
        $resolved = null;
        $this->focused($f)->validateAsync()->then(static function (?string $e) use (&$resolved): void {
            $resolved = $e;
        });
        $this->assertSame('boom', $resolved, 'sync gate must answer without a round trip');
        $this->assertSame(0, $calls, 'async leg must not be consulted when sync fails');
    }

    public function testBothGatesPassResolvesNull(): void
    {
        $f = Input::new('k')
            ->withValidator(static fn (string $v): ?string => null)
            ->withAsyncValidator(static fn (mixed $v): PromiseInterface => self::settled(null));
        $resolved = 'untouched';
        $this->focused($f)->validateAsync()->then(static function (?string $e) use (&$resolved): void {
            $resolved = $e;
        });
        $this->assertNull($resolved);
    }

    public function testAsyncErrorSurfaces(): void
    {
        $f = Input::new('k')->withAsyncValidator(
            static fn (mixed $v): PromiseInterface => self::settled("name '$v' is taken")
        );
        $resolved = null;
        $this->focused($f, 'ada')->validateAsync()->then(static function (?string $e) use (&$resolved): void {
            $resolved = $e;
        });
        $this->assertSame("name 'ada' is taken", $resolved);
    }

    public function testFieldWithoutAsyncLegAnswersFromTheSyncGate(): void
    {
        $f = Input::new('k')->withValidator(static fn (string $v): ?string => 'boom');
        $resolved = 'untouched';
        $this->focused($f)->validateAsync()->then(static function (?string $e) use (&$resolved): void {
            $resolved = $e;
        });
        $this->assertSame('boom', $resolved);
        $this->assertNull($f->asyncValidator(), 'the wither is the only arming path');
    }

    public function testNonPromiseReturnRejectsNamingTheKey(): void
    {
        $f = Input::new('email')->withAsyncValidator(static fn (mixed $v): string => 'definitely not a promise');
        $error  = null;
        $this->focused($f)->validateAsync()->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });
        $this->assertInstanceOf(\InvalidArgumentException::class, $error);
        $this->assertStringContainsString('email', $error->getMessage());
        $this->assertStringContainsString('must return a promise, got string', $error->getMessage());
    }

    public function testNonStringResolutionRejects(): void
    {
        // A promise that RESOLVES 42 must trip the result-shape guard, so
        // the raw value bypasses settled()'s typed helper on purpose.
        $fortyTwo = new Deferred();
        $fortyTwo->resolve(42);
        $f = Input::new('k')->withAsyncValidator(
            static fn (mixed $v): PromiseInterface => $fortyTwo->promise()
        );
        $error = null;
        $this->focused($f)->validateAsync()->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });
        $this->assertInstanceOf(\InvalidArgumentException::class, $error);
        $this->assertStringContainsString('resolve to an error string or null, got int', $error->getMessage());
    }

    public function testValidatorThrowingSynchronouslyRejectsWithThatThrowable(): void
    {
        $boom   = new \RuntimeException('db down');
        $f = Input::new('k')->withAsyncValidator(static function (mixed $v) use ($boom): PromiseInterface {
            throw $boom;
        });
        $error = null;
        $this->focused($f)->validateAsync()->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });
        $this->assertSame($boom, $error, 'rejection means could-not-answer, never a swallowed pass');
    }

    public function testFetcherPromiseRejectionRidesThroughUntouched(): void
    {
        $boom  = new \RuntimeException('fetch failed');
        $bad   = new Deferred();
        $bad->reject($boom);
        $f = Input::new('k')->withAsyncValidator(static fn (mixed $v): PromiseInterface => $bad->promise());
        $error = null;
        $this->focused($f)->validateAsync()->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });
        $this->assertSame($boom, $error);
    }

    public function testWitherIsImmutableAndClearsAreImpossible(): void
    {
        $f = Input::new('k');
        $g = $f->withAsyncValidator(static fn (mixed $v): PromiseInterface => self::settled(null));
        $this->assertNotSame($f, $g);
        $this->assertNull($f->asyncValidator(), 'wither must not mutate the source');
        $this->assertInstanceOf(\Closure::class, $g->asyncValidator());
    }

    /**
     * E741: the slot rides the shared carrier — title mutate, focus/blur and
     * a real keystroke must all preserve the armed validator.
     *
     * @return array<string, array{0: object}>
     */
    public static function asyncCarrierProvider(): array
    {
        $armed = static fn (object $f): object => $f->withAsyncValidator(
            static fn (mixed $v): PromiseInterface => self::settled(null)
        );
        return [
            'Input' => [$armed(Input::new('k'))],
            'Text'  => [$armed(Text::new('k'))],
        ];
    }

    #[DataProvider('asyncCarrierProvider')]
    public function testAsyncValidatorSurvivesEveryRebuildPath(object $field): void
    {
        $armed = $field->asyncValidator();
        $this->assertInstanceOf(\Closure::class, $armed);

        $rebuilt = $field->withTitle('T');
        $this->assertSame($armed, $rebuilt->asyncValidator(), 'mutate dropped asyncValidator');

        [$focused, ] = $field->focus();
        $this->assertSame($armed, $focused->asyncValidator(), 'focus dropped asyncValidator');

        [$typed, ] = $focused->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertSame($armed, $typed->asyncValidator(), 'keystroke dropped asyncValidator');
    }

    public function testTextRunsSyncGateThenAsyncLeg(): void
    {
        $f = Text::new('k')
            ->withValidator(static fn (string $v): ?string => $v === '' ? 'empty' : null)
            ->withAsyncValidator(static fn (mixed $v): PromiseInterface => self::settled(str_contains((string) $v, 'bad') ? 'bad word' : null));
        $out = [];
        $f->validateAsync()->then(static function (?string $e) use (&$out): void {
            $out[] = $e;
        });
        $this->assertSame(['empty'], $out, 'empty Text value must fail the sync gate');
        $good = $f->withValue('fine');
        $good->validateAsync()->then(static function (?string $e) use (&$out): void {
            $out[] = $e;
        });
        $this->assertSame(['empty', null], $out);
        $bad = $f->withValue('a bad word');
        $bad->validateAsync()->then(static function (?string $e) use (&$out): void {
            $out[] = $e;
        });
        $this->assertSame(['empty', null, 'bad word'], $out);
    }
}
