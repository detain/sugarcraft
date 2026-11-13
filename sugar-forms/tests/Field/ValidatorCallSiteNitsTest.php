<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Field;

use SugarCraft\Core\Msg\Msg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Core\Msg\SuggestionsReadyMsg;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\TextArea\TextArea;
use SugarCraft\Forms\TextInput\TextInput;
use SugarCraft\Forms\Validator\Validator;
use PHPUnit\Framework\TestCase;

/**
 * SF-19 (sugar-forms-018/-055/-056/-091): the validator/async call-site
 * bundle — one validate() pass per judgment, the async-resolution claim
 * spoken honestly by consumes(), one definition of the unreachable-override
 * fault, no vestigial clone in the programmatic write, and the stale
 * error() contract pinned as verdict-authority law.
 */
final class ValidatorCallSiteNitsTest extends TestCase
{
    /** counting stand-in for the 018 spy pin */
    private static function spyValidator(int &$calls): Validator
    {
        return new class ($calls) implements Validator {
            /** @param int<0, max> $calls reference cell */
            public function __construct(private int &$hits) {}

            public function validate(string $input): true|string
            {
                $this->hits++;

                return $input === '' ? 'required' : true;
            }
        };
    }

    public function testFailingJudgmentRunsTheValidatorExactlyOnce(): void
    {
        $calls = 0;
        $field = Input::new('f')->withValidator(self::spyValidator($calls));
        $field = $field->withValue('')->revalidate();
        $this->assertSame('required', $field->error());
        $this->assertSame(1, $calls, 'one validate() per judgment — no match(true) double fire');

        $calls = 0;
        $ok = Input::new('g')->withValidator(self::spyValidator($calls))->withValue('x')->revalidate();
        $this->assertNull($ok->error());
        $this->assertSame(1, $calls, 'the PASS path judges once too');
    }

    public function testConsumesDeclaresTheAsyncResolutionEnvelope(): void
    {
        $mine = new SuggestionsReadyMsg('mine', ['a']);
        $theirs = new SuggestionsReadyMsg('other', ['b']);

        $input = Input::new('mine');
        $this->assertTrue($input->consumes($mine));
        $this->assertFalse($input->consumes($theirs), 'a foreign key is not our claim');
        $this->assertFalse($input->consumes(new PasteMsg('x')), 'a plain msg is not our claim');

        [$sel, ] = Select::new('mine', ['opt' => 'Opt'])->focus();
        $this->assertTrue($sel->consumes($mine));
        $this->assertFalse($sel->consumes($theirs));
    }

    public function testUnreachableOverrideFaultHasOneDefinitionSite(): void
    {
        foreach ([TextInput::new(), TextArea::new()] as $widget) {
            $thrown = null;
            try {
                (function () {
                    return $this->applyKeyOverride('not-a-shipped-constant');
                })->call($widget);
            } catch (\InvalidArgumentException $e) {
                $thrown = $e->getMessage();
            }
            $this->assertNotNull($thrown, $widget::class);
            $this->assertSame('unreachable override action: not-a-shipped-constant', $thrown);
        }

        $sources = 0;
        foreach (['src/TextInput/TextInput.php', 'src/TextArea/TextArea.php', 'src/HasKeyOverrides.php'] as $file) {
            $path = dirname(__DIR__, 2) . '/' . $file;
            $this->assertLessThan(
                2,
                substr_count(file_get_contents($path), 'unreachable override action:'),
                $file . ' — message literal only in the trait, once'
            );
            $sources += substr_count(file_get_contents($path), 'unreachable override action:');
        }
        $this->assertSame(1, $sources, 'exactly one definition of the fault string');
    }

    public function testProgrammaticWriteMutatesOnceNoVestigialClone(): void
    {
        $t = TextInput::new()->setValue('hello');
        $this->assertSame('hello', $t->value);
        $this->assertSame(5, $t->cursorPos);
        $src = file_get_contents(dirname(__DIR__, 2) . '/src/TextInput/TextInput.php');
        $this->assertStringNotContainsString('$clone = clone $this', $src, 'mutate() is the only rebuild site');
    }

    public function testVerdictAuthorityStaleInstanceContract(): void
    {
        $field = Input::new('k')
            ->withValue('')
            ->withValidator(static fn (string $v): ?string => $v === '' ? 'sync-required' : null)
            ->withAsyncValidator(static fn (string $v): \React\Promise\PromiseInterface
                => \React\Promise\resolve(null));

        $this->assertNull($field->error(), 'no judgment has run on the held copy yet');

        $verdict = null;
        $field->validateAsync()->then(static function (?string $err) use (&$verdict): void {
            $verdict = $err;
        });

        $this->assertSame('sync-required', $verdict, 'the resolved string is the authoritative verdict');
        $this->assertNull($field->error(), 'the held copy stays pre-call until a revalidating path lands it');
    }
}
