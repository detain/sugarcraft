<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Tape;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Tape\Compiler;

/**
 * VCR-S54: `Set Shell` carries upstream vhs's nine-name validation. Before
 * this door, any string a tape named rode straight into exec mode — the
 * "Mirrors charmbracelet/vhs" docblock lacked the guard rail half of the
 * mirror, and CI render-matrices consume committed tapes unattended.
 */
final class SetShellValidationTest extends TestCase
{
    public function testRosterIsUpstreamsNineNamesInItsOwnOrder(): void
    {
        // Evidence: candy-code/tests/VhsTapeContractTest.php::VALID_SHELLS —
        // the map measured off the real vhs binary (it refuses `sh`). The
        // roster travels as a delimited string so phpstan cannot narrow the
        // constant's literal type against itself.
        self::assertSame(
            'bash,zsh,fish,powershell,pwsh,cmd,nu,osh,xonsh',
            implode(',', Compiler::VALID_SHELLS),
        );
    }

    public function testKnownShellSurfacesOnTheAccessor(): void
    {
        $compiler = new Compiler();
        $result = Compiler::parseSource("Set Shell \"zsh\"\nType \"hi\"");
        $compiler->compile($result['ast'], '/shell-ok.tape');

        self::assertSame('zsh', $compiler->shell());
        self::assertSame([], $compiler->warnings());
    }

    public function testUnknownShellIsRefusedAndTheTapeStaysOnTheEchoPath(): void
    {
        $compiler = new Compiler();
        $result = Compiler::parseSource("Set Shell rm\nType \"hi\"");
        $compiler->compile($result['ast'], '/shell-rm.tape');

        self::assertNull($compiler->shell(), 'the refused directive must not enable exec mode');
        self::assertCount(1, $compiler->warnings());
        self::assertStringContainsString("Invalid shell 'rm'", $compiler->warnings()[0]);
        self::assertStringContainsString('bash, zsh, fish', $compiler->warnings()[0], 'the warning names the roster');
        self::assertStringContainsString('the directive is ignored', $compiler->warnings()[0]);
    }

    public function testPlainShIsRefusedLikeUpstreamRefusesIt(): void
    {
        // The surprising one: upstream's measured map does NOT contain `sh`.
        $compiler = new Compiler();
        $result = Compiler::parseSource('Set Shell "sh"');
        $compiler->compile($result['ast'], '/shell-sh.tape');

        self::assertNull($compiler->shell());
        self::assertStringContainsString("Invalid shell 'sh'", $compiler->warnings()[0]);
    }

    public function testRefusedShellAfterAValidOneKeepsTheValidOne(): void
    {
        $compiler = new Compiler();
        $result = Compiler::parseSource("Set Shell \"bash\"\nSet Shell /evil/prog\nType \"hi\"");
        $compiler->compile($result['ast'], '/shell-two.tape');

        self::assertSame('bash', $compiler->shell());
        self::assertStringContainsString("Invalid shell '/evil/prog'", $compiler->warnings()[0]);
    }

    public function testEmptyShellNameIsRefused(): void
    {
        $compiler = new Compiler();
        $result = Compiler::parseSource('Set Shell ""');
        $compiler->compile($result['ast'], '/shell-empty.tape');

        self::assertNull($compiler->shell());
        self::assertStringContainsString('needs a shell name', $compiler->warnings()[0]);
    }

    public function testStrictModeMakesAnInvalidShellFatal(): void
    {
        $compiler = new Compiler();
        $result = Compiler::parseSource('Set Shell rm');

        try {
            $compiler->compile($result['ast'], '/shell-strict.tape', true);
            self::fail('strict mode must refuse an unknown shell outright');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString("Invalid shell 'rm'", $e->getMessage());
        }
    }
}
