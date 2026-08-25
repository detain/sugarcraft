<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend;

/**
 * **E495 — the {@see Backend} contract family must not declare an
 * implicitly-nullable parameter.**
 *
 * `callable $onToken = null` and `?callable $onToken = null` mean the same
 * thing on PHP 8.3, which this box runs and which is the ONLY version anything
 * here was executed on. The first spelling is deprecated from PHP 8.4, and CI
 * runs 8.3 AND 8.4 — so on the 8.4 leg every one of these declarations emits
 * `Implicitly marking parameter $onToken as nullable is deprecated`. Nothing in
 * this test observes that deprecation and nothing here should be read as
 * claiming to: what it asserts is a SYNTACTIC property of the source, which is
 * the half of the problem a PHP 8.3 host can actually measure.
 *
 * ## Why this cannot be a behavioural test
 *
 * The two spellings compile to the same signature, and `ReflectionParameter`
 * cannot tell them apart either. MEASURED on PHP 8.3.6 rather than asserted:
 * for BOTH spellings `getType()` stringifies to `?callable`, both report
 * `allowsNull() === true` on the type AND on the parameter, and both have a
 * default of null. There is no runtime observation that separates them, so the
 * only honest instrument is the token stream and the only honest pin for the fix
 * is a scanner over it. {@see testTheTwoSpellingsAreIndistinguishableToReflection()}
 * keeps that claim from becoming folklore.
 *
 * ## Why the scanner has its own tests
 *
 * A guard that asserts an absence and nothing else passes just as green when it
 * is dead. {@see testTheScannerStillSeesAnImplicitlyNullableParameter()} and
 * {@see testTheScannerRejectsEveryNonOffendingSpelling()} push known answers
 * through the SAME scanner in the same file, in both polarities, so an empty
 * result from {@see testTheBackendContractFamilyHasNoImplicitlyNullableParameters()}
 * is evidence rather than a shrug.
 *
 * The fixtures build their offending declarations by CONCATENATION rather than
 * spelling them out. A future textual sweep of this pattern would otherwise
 * rewrite the very fixture that proves the sweep worked.
 *
 * ## Why the scope is the Backend family and not `src/`
 *
 * `src/Workflows/Workflow.php` carries two more of these and is not this lane's
 * file. Widening the scan is a one-line change once that is fixed; widening it
 * now would only make this file red for someone else's code.
 */
final class BackendSignatureNullabilityTest extends TestCase
{
    /**
     * The contract and every implementation of it that ships in this package.
     * Derived rather than listed, so a new backend cannot be added outside the
     * guard's view.
     *
     * @return list<string>
     */
    private static function contractFamily(): array
    {
        $root = dirname(__DIR__, 2) . '/src';
        $files = glob($root . '/Backend/*.php');
        self::assertIsArray($files, 'src/Backend/ did not glob — the guard is looking at nothing');

        return [$root . '/Backend.php', ...$files];
    }

    public function testTheBackendContractFamilyHasNoImplicitlyNullableParameters(): void
    {
        $family = self::contractFamily();
        // Rule 18: the count is DERIVED here, never written into prose. What
        // matters is that the list is non-trivial, not what its length is today.
        $this->assertGreaterThan(1, count($family), 'the family glob collapsed to the interface alone');

        $offenders = [];
        foreach ($family as $file) {
            $source = file_get_contents($file);
            $this->assertIsString($source, "unreadable: $file");
            foreach (self::implicitlyNullableParams($source) as $hit) {
                $offenders[] = basename($file) . ': ' . $hit;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Implicitly-nullable parameter(s) in the Backend contract family.\n"
            . "Spell the type nullable (a leading question mark) instead of relying on the\n"
            . "null default to do it; the two are identical on PHP 8.3 and the second is\n"
            . "deprecated from 8.4, which CI runs.",
        );
    }

    /**
     * Rule 15's known-positive: the same scanner, in the same test file, on a
     * source it MUST flag. Without this, a scanner mutated to return `[]`
     * unconditionally leaves the assertion above green.
     */
    public function testTheScannerStillSeesAnImplicitlyNullableParameter(): void
    {
        // Built by concatenation on purpose - see the class docblock.
        $offending = 'callable $onToken = ' . 'null';
        $source = "<?php\nfinal class F { public function m(array \$h, {$offending}): void {} }\n";

        $this->assertSame(
            ['$onToken (callable)'],
            self::implicitlyNullableParams($source),
            'the scanner is dead: it cannot see the very shape it exists to find',
        );
    }

    /**
     * The other polarity (rule 33): every spelling that is NOT an offender must
     * come back clean, or the guard is a licence to rewrite correct signatures.
     *
     * @dataProvider nonOffendingSpellings
     */
    public function testTheScannerRejectsEveryNonOffendingSpelling(string $label, string $param): void
    {
        $source = "<?php\nfinal class F { public function m({$param}): void {} }\n";

        $this->assertSame([], self::implicitlyNullableParams($source), $label);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function nonOffendingSpellings(): array
    {
        $null = 'null';

        return [
            'explicitly nullable'   => ['a leading ? is the fix, not the defect', '?callable $a = ' . $null],
            'union carrying null'   => ['a union with null is already explicit', 'callable|null $b = ' . $null],
            'union, null first'     => ['order in the union must not matter', 'null|string $c = ' . $null],
            'mixed'                 => ['mixed already includes null', 'mixed $d = ' . $null],
            'untyped'               => ['no type declaration, no deprecation', '$e = ' . $null],
            'non-null default'      => ['a non-null default is not this defect', 'string $f = \'x\''],
            'no default at all'     => ['a required parameter is not this defect', 'callable $g'],
            'constant default'      => ['a constant default is not a bare null', 'int $h = \PHP_INT_MAX'],
        ];
    }

    /**
     * Every parameter that has a non-nullable type declaration AND a default of
     * exactly `null`.
     *
     * Token-level rather than a regular expression: a signature wraps across
     * lines, carries attributes, promoted-property modifiers, by-reference
     * markers and doc-comments, and a line-oriented match reads all of those
     * wrong. Anything it cannot resolve to a variable is reported as an
     * offender-shaped `<unparsed>` entry rather than dropped (rule 14) — a
     * guard that silently ignores what it cannot read has a hole in exactly the
     * shape of the next defect.
     *
     * @return list<string>
     */
    private static function implicitlyNullableParams(string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $hits = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            $open = $i + 1;
            while ($open < $count && $tokens[$open] !== '(') {
                // A `function` keyword whose body starts before any '(' is not
                // a signature this scanner understands; stop rather than run on
                // into the next one.
                if ($tokens[$open] === '{' || $tokens[$open] === ';') {
                    break;
                }
                $open++;
            }
            if ($open >= $count || $tokens[$open] !== '(') {
                continue;
            }

            foreach (self::splitParams($tokens, $open, $count) as $param) {
                $hit = self::classifyParam($param);
                if ($hit !== null) {
                    $hits[] = $hit;
                }
            }
        }

        return $hits;
    }

    /**
     * Slice the balanced parameter list starting at the '(' at $open into one
     * token list per parameter.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return list<list<array{0:int,1:string,2:int}|string>>
     */
    private static function splitParams(array $tokens, int $open, int $count): array
    {
        $depth = 0;
        $params = [];
        $current = [];

        for ($k = $open; $k < $count; $k++) {
            $token = $tokens[$k];
            if ($token === '(' || $token === '[') {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif ($token === ')' || $token === ']') {
                $depth--;
                if ($depth === 0) {
                    $params[] = $current;
                    break;
                }
            } elseif ($token === ',' && $depth === 1) {
                $params[] = $current;
                $current = [];

                continue;
            }
            $current[] = $token;
        }

        return $params;
    }

    /**
     * @param list<array{0:int,1:string,2:int}|string> $param
     */
    private static function classifyParam(array $param): ?string
    {
        $param = array_values(array_filter(
            $param,
            static fn ($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        if ($param === []) {
            return null;
        }

        $varIndex = null;
        foreach ($param as $index => $token) {
            if (is_array($token) && $token[0] === T_VARIABLE) {
                $varIndex = $index;
                break;
            }
        }
        if ($varIndex === null) {
            // Rule 14: a parameter with no variable in it is something this
            // scanner does not understand, and saying so is the point.
            return '<unparsed> ' . self::flatten($param);
        }

        $default = array_slice($param, $varIndex + 1);
        if ($default === [] || $default[0] !== '=') {
            return null;
        }
        $default = array_slice($default, 1);
        if (count($default) !== 1) {
            return null;  // `= self::X`, `= [..]`, `= -1` — not a bare null
        }
        $literal = is_array($default[0]) ? $default[0][1] : $default[0];
        if (strtolower($literal) !== 'null') {
            return null;
        }

        $type = self::flatten(array_slice($param, 0, $varIndex));
        if ($type === '') {
            return null;  // untyped: no implicit nullability to mark
        }
        if (str_starts_with($type, '?') || strtolower($type) === 'mixed') {
            return null;
        }
        foreach (explode('|', $type) as $member) {
            if (strtolower(trim($member)) === 'null') {
                return null;
            }
        }

        return (is_array($param[$varIndex]) ? $param[$varIndex][1] : '?') . ' (' . $type . ')';
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function flatten(array $tokens): string
    {
        $out = '';
        foreach ($tokens as $token) {
            if (is_array($token)) {
                // Modifiers, variadics and by-reference markers are not part of
                // the TYPE, and leaving them in would make `?` detection and the
                // union split answer about the wrong string.
                if (in_array($token[0], [T_PUBLIC, T_PRIVATE, T_PROTECTED, T_READONLY, T_ELLIPSIS], true)) {
                    continue;
                }
                if (defined('T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG')
                    && $token[0] === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG) {
                    continue;
                }
                $out .= $token[1];

                continue;
            }
            if ($token === '&') {
                continue;
            }
            $out .= $token;
        }

        return trim($out);
    }

    /**
     * The premise of this whole file, measured rather than asserted: reflection
     * genuinely cannot separate the two spellings, so a reflection-based guard
     * would be a dead instrument no matter how it was written.
     *
     * The two declarations are built by CONCATENATION and evaluated, so neither
     * spelling appears literally in this file — a future textual sweep of the
     * deprecated form would otherwise rewrite the fixture that proves why the
     * sweep is needed.
     */
    public function testTheTwoSpellingsAreIndistinguishableToReflection(): void
    {
        $class = 'NullabilityProbe' . bin2hex(random_bytes(6));
        $implicit = 'callable $a = ' . 'null';
        $explicit = '?' . 'callable $b = ' . 'null';
        eval("final class {$class} { public function implicit({$implicit}): void {} "
            . "public function explicit({$explicit}): void {} }");

        $seen = [];
        foreach (['implicit', 'explicit'] as $method) {
            $parameter = (new \ReflectionMethod($class, $method))->getParameters()[0];
            $type = $parameter->getType();
            $seen[$method] = [
                'type' => (string) $type,
                'typeAllowsNull' => $type?->allowsNull(),
                'paramAllowsNull' => $parameter->allowsNull(),
                'defaultIsNull' => $parameter->isDefaultValueAvailable() && $parameter->getDefaultValue() === null,
            ];
        }

        $this->assertSame(
            $seen['explicit'],
            $seen['implicit'],
            'reflection CAN separate the two spellings on this PHP - if so, prefer it over the token scan',
        );
        // Positive component (rule 25): an all-null reading would also compare
        // equal, and would prove nothing.
        $this->assertSame('?callable', $seen['implicit']['type']);
        $this->assertTrue($seen['implicit']['typeAllowsNull']);
        $this->assertTrue($seen['implicit']['defaultIsNull']);
    }

    /**
     * The interface itself is reachable from this namespace — asserted so a
     * rename cannot leave the glob above scanning a directory whose contents no
     * longer implement anything.
     */
    public function testTheContractItselfIsStillThere(): void
    {
        $this->assertTrue(interface_exists(Backend::class));
    }
}
