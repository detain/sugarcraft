<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\ScriptUi\Application;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * scriptui-75 (finding 101) — doRun's pre-bind catch swallows only the
 * condition the env fallback can actually repair (an undeclared option);
 * every other bind-time throw propagates from the gate instead of coasting
 * into an accidental exit shape.
 */
final class BindErrorNarrowingTest extends TestCase
{
    public function testUndeclaredOptionStillContinuesIntoTheEnvFallback(): void
    {
        $app = new Application();
        $app->setAutoExit(false);
        $out = new BufferedOutput();

        $status = $app->run(new StringInput('style --nope hello'), $out);

        // The gate swallowed the option-existence throw and let the fallback
        // run; the parent re-binds and throws the same class from
        // Command::run, where Application::run's exception door renders it
        // as an ordinary usage error — rc 1, not a raw throw.
        self::assertSame(1, $status);
        self::assertStringContainsString('does not exist', $out->fetch());
    }

    public function testOptionMissingItsValuePropagatesFromTheGate(): void
    {
        $app = new Application();
        $app->setAutoExit(false);
        $out = new BufferedOutput();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires a value');

        $app->doRun(new StringInput('style --foreground'), $out);
    }

    public function testUnexpectedArgumentIsRepairedByTheAuthoritativeParentBind(): void
    {
        // The gate bind still carries the command-name token, so arity
        // throws are artifacts it must tolerate; the pin here is that the
        // fault is NOT laundered into a silent success — the parent's
        // re-bind (definition merged, token skipped) renders it as an
        // ordinary usage error with a non-zero exit.
        $app = new Application();
        $app->setAutoExit(false);
        $out = new BufferedOutput();

        $status = $app->run(new StringInput('version extra'), $out);

        self::assertSame(1, $status);
        self::assertStringContainsString('No arguments expected', $out->fetch());
    }

    public function testPropagatedThrowShortCircuitsTheEnvFallbackEntirely(): void
    {
        // The discriminator against the old catch-all: when the gate re-
        // throws, applyEnvVarFallbackToInput must NEVER have touched the
        // raw token stream. Under the swallow-everything shape, the env
        // value was injected first and only then the parent complained —
        // a half-applied fallback riding on a usage fault.
        putenv('CANDY_SCRIPTUI_BOLD=1');
        try {
            $input = new ArgvInput(['candy-scriptui', 'style', '--foreground']);
            $app   = new Application();
            $app->setAutoExit(false);

            try {
                $app->doRun($input, new BufferedOutput());
                self::fail('the gate must re-throw an option-without-value');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('requires a value', $error->getMessage());
            }

            $tokens = new \ReflectionProperty(ArgvInput::class, 'tokens');
            $tokens->setAccessible(true);
            self::assertSame(
                ['style', '--foreground'],
                $tokens->getValue($input),
                'a propagated bind fault must leave no injected env tokens behind',
            );
        } finally {
            putenv('CANDY_SCRIPTUI_BOLD');
        }
    }
}
