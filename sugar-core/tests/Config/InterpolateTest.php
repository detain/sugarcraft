<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Config\Interpolate;

final class InterpolateTest extends TestCase
{
    private const ENV_NAME = 'SUGAR_CORE_INTERPOLATE_TEST';

    /** @var array<string, string|false> */
    private array $vars = [];

    /** A lookup over the fixture map, so no test depends on the real environment. */
    private function lookup(): \Closure
    {
        return fn (string $name): string|false => $this->vars[$name] ?? false;
    }

    protected function tearDown(): void
    {
        putenv(self::ENV_NAME);
    }

    public function testAValueWithNoReferenceComesBackByteForByte(): void
    {
        $value = "plain \$5 and 100\${} and a lone \$ sign";

        $this->assertSame($value, Interpolate::expand($value, $this->lookup()));
    }

    public function testASetVariableIsReplaced(): void
    {
        $this->vars['HOST'] = 'example.test';

        $this->assertSame('example.test', Interpolate::expand('${HOST}', $this->lookup()));
    }

    public function testAReferenceInsideAStringIsReplaced(): void
    {
        $this->vars['HOST'] = 'example.test';

        $this->assertSame(
            'https://example.test/mcp',
            Interpolate::expand('https://${HOST}/mcp', $this->lookup()),
        );
    }

    public function testMultipleReferencesAreAllReplaced(): void
    {
        $this->vars['A'] = '1';
        $this->vars['B'] = '2';

        $this->assertSame('1-2', Interpolate::expand('${A}-${B}', $this->lookup()));
    }

    public function testAnUnsetVariableWithoutDefaultBecomesEmpty(): void
    {
        $this->assertSame('x--y', Interpolate::expand('x-${MISSING}-y', $this->lookup()));
    }

    public function testAnUnsetVariableTakesItsDefault(): void
    {
        $this->assertSame('fallback', Interpolate::expand('${MISSING:-fallback}', $this->lookup()));
    }

    public function testASetVariableIgnoresItsDefault(): void
    {
        $this->vars['SET'] = 'real';

        $this->assertSame('real', Interpolate::expand('${SET:-fallback}', $this->lookup()));
    }

    public function testAnEmptyVariableTakesItsDefault(): void
    {
        $this->vars['EMPTY'] = '';

        $this->assertSame('fallback', Interpolate::expand('${EMPTY:-fallback}', $this->lookup()));
    }

    public function testAnEmptyDefaultIsAllowed(): void
    {
        $this->assertSame('', Interpolate::expand('${MISSING:-}', $this->lookup()));
    }

    public function testZeroIsAValueByDefault(): void
    {
        // The provider reader keeps `0` as a real value.
        $this->vars['N'] = '0';

        $this->assertSame('0', Interpolate::expand('${N:-9}', $this->lookup()));
    }

    public function testZeroIsUnsetWhenAskedFor(): void
    {
        // The MCP reader treats `0` as unset, because it reads with `?:`.
        $this->vars['N'] = '0';

        $this->assertSame('9', Interpolate::expand('${N:-9}', $this->lookup(), zeroIsUnset: true));
    }

    public function testZeroIsUnsetWithoutADefaultBecomesEmpty(): void
    {
        $this->vars['N'] = '0';

        $this->assertSame('', Interpolate::expand('${N}', $this->lookup(), zeroIsUnset: true));
    }

    public function testADecimalZeroIsStillAValueWhenZeroIsUnset(): void
    {
        // Only the exact string `0` is falsy under `?:`; `0.0` is truthy.
        $this->vars['N'] = '0.0';

        $this->assertSame('0.0', Interpolate::expand('${N:-9}', $this->lookup(), zeroIsUnset: true));
    }

    public function testLowercaseNamesExpandByDefault(): void
    {
        $this->vars['lower'] = 'ok';

        $this->assertSame('ok', Interpolate::expand('${lower}', $this->lookup()));
    }

    public function testUpperCaseOnlyLeavesLowercaseNamesLiteral(): void
    {
        // The provider reader's `[A-Z_][A-Z0-9_]*` pattern: a lowercase name
        // is not a variable, so it stays in the config exactly as written.
        $this->vars['lower'] = 'ok';

        $this->assertSame('${lower}', Interpolate::expand('${lower}', $this->lookup(), upperCaseNamesOnly: true));
    }

    public function testUpperCaseOnlyStillExpandsUpperCaseNames(): void
    {
        $this->vars['API_KEY'] = 'sk-1';

        $this->assertSame('sk-1', Interpolate::expand('${API_KEY}', $this->lookup(), upperCaseNamesOnly: true));
    }

    public function testANameOutsideTheShellAlphabetStaysLiteral(): void
    {
        $this->assertSame('${BAD NAME}', Interpolate::expand('${BAD NAME}', $this->lookup()));
        $this->assertSame('${1ABC}', Interpolate::expand('${1ABC}', $this->lookup()));
    }

    public function testDefaultsAreNotRecursivelyExpanded(): void
    {
        $this->vars['OUTER'] = '';
        $this->vars['INNER'] = 'inner';

        $this->assertSame('${INNER}', Interpolate::expand('${OUTER:-${INNER}}', $this->lookup()));
    }

    public function testTheLookupReceivesTheBareName(): void
    {
        $seen = [];
        Interpolate::expand('${ALPHA}-${BETA:-x}', function (string $name) use (&$seen): string {
            $seen[] = $name;

            return 'v';
        });

        $this->assertSame(['ALPHA', 'BETA'], $seen);
    }

    public function testTheLookupMayReturnNull(): void
    {
        $this->assertSame('d', Interpolate::expand('${X:-d}', static fn (string $name): ?string => null));
    }

    public function testWithoutALookupTheProcessEnvironmentIsRead(): void
    {
        putenv(self::ENV_NAME . '=from-env');

        $this->assertSame('from-env', Interpolate::expand('${' . self::ENV_NAME . '}'));
    }

    public function testWithoutALookupAnUnsetEnvironmentVariableTakesTheDefault(): void
    {
        putenv(self::ENV_NAME);

        $this->assertSame('dflt', Interpolate::expand('${' . self::ENV_NAME . ':-dflt}'));
    }
}
