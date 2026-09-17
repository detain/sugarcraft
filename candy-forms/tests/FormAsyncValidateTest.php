<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\Note;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Form;
use SugarCraft\Forms\Group;
use PHPUnit\Framework\TestCase;

/**
 * Plan 5.2 (round 89 lane y3) — Form::validateAsync(): the SAME progressive
 * walk as validateAll(), answers on the event loop. Parity with the
 * synchronous map is pinned; hermetic like AsyncValidationTest (hand-settled
 * Deferreds only, zero timers, E646).
 */
final class FormAsyncValidateTest extends TestCase
{
    private static function settled(?string $error): PromiseInterface
    {
        $d = new Deferred();
        $d->resolve($error);
        return $d->promise();
    }

    private static function inputWithError(string $key, ?string $syncError = null): Input
    {
        $f = Input::new($key);
        if ($syncError !== null) {
            $f = $f->withValidator(static fn (string $v): ?string => $syncError);
        }
        return self::typed($f);
    }

    private static function typed(Input $f): Input
    {
        [$f, ] = $f->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, 'a'));
        return $f;
    }

    public function testParityWithValidateAllOnPurelySyncFields(): void
    {
        $form = Form::new(
            self::inputWithError('name', 'boom'),
            Select::new('size')->withOptions('S', 'M'),
            Confirm::new('agree'),
            Note::new('readme'),
        );
        $async = null;
        $form->validateAsync()->then(static function (array $errors) use (&$async): void {
            $async = $errors;
        });
        // Byte-parity: same map, same key order, same everything.
        $this->assertSame($form->validateAll(), $async);
        $this->assertSame(['name' => 'boom'], $async);
    }

    public function testEmptyFormResolvesEmptyMap(): void
    {
        $async = 'untouched';
        Form::new()->validateAsync()->then(static function (array $errors) use (&$async): void {
            $async = $errors;
        });
        $this->assertSame([], $async);
    }

    public function testMixedSyncAndAsyncFieldsAggregatesBothInWalkOrder(): void
    {
        $pending = new Deferred();
        $form = Form::new(
            self::inputWithError('name', 'boom'),
            Input::new('handle')->withAsyncValidator(
                static fn (mixed $v): PromiseInterface => $pending->promise()
            ),
        );
        $async   = 'untouched';
        $form->validateAsync()->then(static function (array $errors) use (&$async): void {
            $async = $errors;
        });
        $this->assertSame('untouched', $async, 'must wait for the slowest field');
        $pending->resolve('handle is taken');
        $this->assertSame(['name' => 'boom', 'handle' => 'handle is taken'], $async);
    }

    public function testPassingAsyncLegIsSilentInTheMap(): void
    {
        $form = Form::new(
            Input::new('handle')->withAsyncValidator(
                static fn (mixed $v): PromiseInterface => self::settled(null)
            ),
        );
        $async = 'untouched';
        $form->validateAsync()->then(static function (array $errors) use (&$async): void {
            $async = $errors;
        });
        $this->assertSame([], $async);
        $this->assertSame([], $form->validateAll(), 'sync view stays untouched by an armed async leg');
    }

    public function testRejectionRidesTheWholeAggregate(): void
    {
        $boom = new \RuntimeException('service down');
        $form = Form::new(
            Input::new('handle')->withAsyncValidator(
                static fn (mixed $v): PromiseInterface => self::rejected($boom)
            ),
        );
        $error = null;
        $form->validateAsync()->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });
        $this->assertSame($boom, $error, 'could-not-answer never looks like valid or invalid');
    }

    private static function rejected(\Throwable $e): PromiseInterface
    {
        $d = new Deferred();
        $d->reject($e);
        return $d->promise();
    }

    public function testHiddenGroupsAndSkippablesAreInvisibleToBothWalks(): void
    {
        $page1 = Group::new(Input::new('mode')->withTitle('mode'));
        $page2 = Group::new(self::inputWithError('secret', 'boom'))->withHideFunc(
            static fn (array $values): bool => true
        );
        $form  = Form::groups($page1, $page2);
        $async = 'untouched';
        $form->validateAsync()->then(static function (array $errors) use (&$async): void {
            $async = $errors;
        });
        $this->assertSame([], $async);
        $this->assertSame($form->validateAll(), $async);
    }

    public function testSyncGateNeverFiresTheAsyncLegInsideTheFormWalk(): void
    {
        $calls = 0;
        $f = Input::new('name')
            ->withValidator(static fn (string $v): ?string => 'boom')
            ->withAsyncValidator(static function (mixed $v) use (&$calls): PromiseInterface {
                $calls++;
                return self::settled('unreachable');
            });
        $async = 'untouched';
        Form::new($f)->validateAsync()->then(static function (array $e) use (&$async): void {
            $async = $e;
        });
        $this->assertSame(['name' => 'boom'], $async);
        $this->assertSame(0, $calls);
    }
}
