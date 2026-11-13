<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Form;
use SugarCraft\Forms\FormAsyncValidatedMsg;
use SugarCraft\Forms\Group;
use SugarCraft\Forms\Lang;
use PHPUnit\Framework\TestCase;

/**
 * SF-20 (sugar-forms-020, owner ruling WIRE — not document-a-host-duty):
 * Enter on the last field must not resolve the form while async validators
 * are still answering. The submit parks in a seq-guarded round; verdicts
 * ride back as {@see FormAsyncValidatedMsg}.
 */
final class FormAsyncValidateTest extends TestCase
{
    private function focused(Form $f): Form
    {
        $fields = $f->fieldsByGroup[count($f->groups) - 1];
        $last = array_key_last($fields);
        // Focus the final field directly (the submit chord only fires there).
        [$focused, ] = $f->focusField($fields[$last]->key());

        return $focused;
    }

    /** @return array{0:Form, 1:AsyncCmd|null} */
    private function pressEnter(Form $f): array
    {
        [$next, $cmd] = $f->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertInstanceOf(Form::class, $next);

        return [$next, $cmd === null ? null : $cmd()];
    }

    private function asyncInput(string $key, Deferred $d): Input
    {
        return Input::new($key)
            ->withValue('x')
            ->withAsyncValidator(static fn (string $v): PromiseInterface => $d->promise());
    }

    public function testPendingRoundParksSubmitAndIgnoresSecondEnter(): void
    {
        $d = new Deferred();
        $form = Form::groups(Group::new($this->asyncInput('name', $d)));
        [$f, $ac] = $this->pressEnter($this->focused($form));

        $this->assertFalse($f->submitted, 'submit held while the validator answers');
        $this->assertTrue($f->asyncValidating);
        $this->assertSame(1, $f->asyncValidationSeq);
        $this->assertInstanceOf(AsyncCmd::class, $ac);

        [$again, $cmd2] = $f->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame($f, $again, 'a second Enter while pending is ignored');
        $this->assertNull($cmd2);
        $this->assertStringContainsString('validating', $f->view());
    }

    public function testCleanVerdictFiresTheDeferredSubmit(): void
    {
        $d = new Deferred();
        $form = Form::groups(Group::new($this->asyncInput('name', $d)));
        [$f, $ac] = $this->pressEnter($this->focused($form));

        $settled = null;
        $ac->promise->then(static function (FormAsyncValidatedMsg $m) use (&$settled): void {
            $settled = $m;
        });
        $d->resolve(null);
        $this->assertNotNull($settled);
        $this->assertSame([], $settled->errors);

        [$done, $quit] = $f->update($settled);
        $this->assertTrue($done->submitted);
        $this->assertFalse($done->asyncValidating);
        $this->assertNotNull($quit);
        $this->assertInstanceOf(QuitMsg::class, $quit());
    }

    public function testRejectionKeepsFormOpenPaintsInlineAndMovesFocus(): void
    {
        $name = new Deferred();
        $email = new Deferred();
        $form = Form::groups(Group::new(
            $this->asyncInput('name', $name),
            $this->asyncInput('email', $email),
        ));
        $focusedForm = $this->focused($form);
        $this->assertSame(1, $focusedForm->focusedIndex);
        [$f, $ac] = $this->pressEnter($focusedForm);

        $settled = null;
        $ac->promise->then(static function (FormAsyncValidatedMsg $m) use (&$settled): void {
            $settled = $m;
        });
        $name->resolve('name taken');
        $email->resolve(null);

        [$blocked, ] = $f->update($settled);
        $this->assertInstanceOf(FormAsyncValidatedMsg::class, $settled);
        $this->assertFalse($blocked->submitted);
        $this->assertSame(['name' => 'name taken'], $blocked->asyncErrors);
        $this->assertSame(0, $blocked->focusedIndex, 'focus moved to the failing field');
        $this->assertStringContainsString('! name taken', $blocked->view());
        $this->assertStringNotContainsString('validating', $blocked->view(), 'round closed');
    }

    public function testValueEditClearsTheAsyncOverlay(): void
    {
        $d = new Deferred();
        $form = Form::groups(Group::new($this->asyncInput('name', $d)));
        [$f, $ac] = $this->pressEnter($this->focused($form));
        $settled = null;
        $ac->promise->then(static function ($m) use (&$settled): void {
            $settled = $m;
        });
        $d->resolve('nope');
        [$blocked, ] = $f->update($settled);
        $this->assertNotEmpty($blocked->asyncErrors);

        [$edited, ] = $blocked->update(new KeyMsg(KeyType::Char, 'z'));
        $this->assertSame([], $edited->asyncErrors, 'the verdict judged pre-edit bytes');
        $this->assertFalse($edited->submitted);
    }

    public function testStaleSeqVerdictsAreDropped(): void
    {
        $d = new Deferred();
        $form = Form::groups(Group::new($this->asyncInput('name', $d)));
        [$f, ] = $this->pressEnter($this->focused($form));

        [$same, $cmd] = $f->update(new FormAsyncValidatedMsg([], 99));
        $this->assertSame($f, $same);
        $this->assertNull($cmd);
        $this->assertFalse($same->submitted, 'a superseded round cannot submit the form');
    }

    public function testVerdictAfterResolveIsDiscarded(): void
    {
        $d = new Deferred();
        $form = Form::groups(Group::new($this->asyncInput('name', $d)));
        [$f, $ac] = $this->pressEnter($this->focused($form));
        $settled = null;
        $ac->promise->then(static function ($m) use (&$settled): void {
            $settled = $m;
        });
        $d->resolve(null);
        [$done, ] = $f->update($settled);
        $this->assertTrue($done->submitted);

        [$after, $cmd] = $done->update(new FormAsyncValidatedMsg(['name' => 'late'], 1));
        $this->assertSame($done, $after, 'post-submit verdict changes nothing');
        $this->assertNull($cmd);
    }

    public function testSyncErrorShortCircuitsBeforeAnyFlight(): void
    {
        $d = new Deferred();
        $input = Input::new('name')
            ->withValidator(static fn (string $v): ?string => 'always wrong')
            ->withAsyncValidator(static fn (string $v): PromiseInterface => $d->promise());
        $form = Form::groups(Group::new($input));
        [$f, $ac] = $this->pressEnter($this->focused($form));

        $this->assertNull($ac, 'a sync failure never dispatches the async batch');
        $this->assertFalse($f->asyncValidating);
        $this->assertFalse($f->submitted);
    }

    public function testZeroAsyncSubmitIsImmediateAndUnmoved(): void
    {
        $form = Form::groups(Group::new(Input::new('name')->withValue('x')));
        [$f, $ac] = $this->pressEnter($this->focused($form));

        $this->assertTrue($f->submitted, 'plain forms submit on the spot (byte pin)');
        $this->assertFalse($f->asyncValidating);
        $this->assertNotNull($ac);
        $this->assertInstanceOf(QuitMsg::class, $ac);
    }

    public function testRefusingValidatorBlocksClosedWithTypedVerdict(): void
    {
        $d = new Deferred();
        $form = Form::groups(Group::new($this->asyncInput('name', $d)));
        [$f, $ac] = $this->pressEnter($this->focused($form));

        $settled = null;
        $ac->promise->then(static function ($m) use (&$settled): void {
            $settled = $m;
        });
        $d->reject(new \RuntimeException('network down'));

        $this->assertNotNull($settled, 'a rejecting batch still settles the round');
        $this->assertArrayHasKey('name', $settled->errors, 'fail-closed: unanswered != passed');
        $this->assertStringContainsString('network down', $settled->errors['name']);
        $this->assertStringContainsString(
            Lang::t('form.async_unavailable', ['field' => 'name', 'reason' => 'network down']),
            $settled->errors['name'],
        );
    }

    public function testErrorSummarySurfacesAsyncVerdicts(): void
    {
        $d = new Deferred();
        $form = Form::groups(Group::new($this->asyncInput('name', $d)))->withErrorSummary(true);
        [$f, $ac] = $this->pressEnter($this->focused($form));
        $settled = null;
        $ac->promise->then(static function ($m) use (&$settled): void {
            $settled = $m;
        });
        $d->resolve('too loud');

        [$blocked, ] = $f->update($settled);
        $this->assertSame(['name' => 'too loud'], $blocked->errors());
        $this->assertTrue($blocked->hasErrors());
        $this->assertStringContainsString('too loud', $blocked->view());
    }
}
