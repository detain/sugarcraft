<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Shell\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Robustness pins for {@see Application::applyEnvVarFallbackToInput()}, which
 * reflects into Symfony's PRIVATE {@see ArgvInput}::$tokens property to prepend
 * env-backed value options onto the token stream.
 */
final class ApplicationEnvTokenInjectionTest extends TestCase
{
    /**
     * LOAD-BEARING ROBUSTNESS PIN.
     *
     * The env-var fallback reflects into ArgvInput's private $tokens property.
     * Symfony treats $tokens as an internal implementation detail, so a major
     * bump could rename or remove it — which would silently disable the
     * CANDYSHELL_* value-option fallback (the reflection now degrades to
     * best-effort setOption() instead of fataling, so the breakage would go
     * unnoticed at runtime).
     *
     * Asserting the property still exists turns a Symfony upgrade that moves it
     * into a RED CI run right here, forcing Application::ARGV_TOKENS_PROPERTY and
     * the reflection to be updated, rather than the fallback going dark.
     */
    public function testArgvInputStillExposesTokensProperty(): void
    {
        $this->assertTrue(
            property_exists(ArgvInput::class, 'tokens'),
            'Symfony\'s ArgvInput no longer has a private $tokens property. '
            . 'candy-shell Application reflects into it for the CANDYSHELL_* '
            . 'env-var value-option fallback — update Application::ARGV_TOKENS_PROPERTY '
            . 'to the new property name and the reflection in '
            . 'applyEnvVarFallbackToInput() accordingly.',
        );
    }

    /**
     * Happy path: when $tokens exists (every current Symfony release), an
     * env-backed VALUE option is injected onto the token stream and survives
     * Command::run()'s second bind(), reaching the command's output. Regresses
     * the injection mechanism itself — replacing it with a plain setOption()
     * would drop the value on the second bind and fail this test.
     */
    public function testEnvBackedValueOptionIsInjectedViaTokenStream(): void
    {
        $original = getenv('CANDYSHELL_PREFIX');
        putenv('CANDYSHELL_PREFIX=ENVPFX');
        try {
            $app = new Application();
            $out = new BufferedOutput();
            $status = $app->run(new ArgvInput(['candyshell', 'log', 'hello']), $out);

            $this->assertSame(0, $status);
            $display = $out->fetch();
            $this->assertStringContainsString('ENVPFX', $display);
            $this->assertStringContainsString('hello', $display);
        } finally {
            if ($original === false) {
                putenv('CANDYSHELL_PREFIX');
            } else {
                putenv('CANDYSHELL_PREFIX=' . $original);
            }
        }
    }
}
