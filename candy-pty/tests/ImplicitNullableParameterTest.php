<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;

/**
 * No parameter in src/ is implicitly nullable (`Foo $x = null`).
 *
 * PHP 8.4 deprecates the implicit form; the package targets 8.3+ and
 * 8.4 for Windows FFI, so every such signature turns into a deprecation
 * notice the moment a host moves up. `PosixPump::run()` carried one
 * (`PumpOptions $opts = null`). Reflection cannot tell the implicit form
 * from `?Foo $x = null` -- both report a nullable type -- so the guard
 * reads tokens.
 */
final class ImplicitNullableParameterTest extends TestCase
{
    public function testNoSourceSignatureUsesAnImplicitlyNullableType(): void
    {
        $root = \dirname(__DIR__) . '/src';
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            foreach (self::implicitNullables((string) \file_get_contents($file->getPathname())) as $hit) {
                $offenders[] = \substr($file->getPathname(), \strlen($root) + 1) . ':' . $hit;
            }
        }

        $this->assertSame([], $offenders, 'declare these parameters `?Type $x = null` (or `Type|null`)');
    }

    /** The scanner sees the deprecated form and accepts every explicit one. */
    public function testScannerFlagsOnlyTheImplicitForm(): void
    {
        $src = '<?php function f(Foo $a = null, ?Foo $b = null, Foo|null $c = null, $d = null, '
            . 'mixed $e = null, \\A\\B $f = NULL, int &$g = null, Foo $h = new Foo()) { $i = null; static $j = null; }';

        $this->assertSame(['1 $a', '1 $f', '1 $g'], self::implicitNullables($src));
    }

    /** @return list<string> "line $name" for every implicitly nullable parameter */
    private static function implicitNullables(string $code): array
    {
        $tokens = \array_values(\array_filter(
            \PhpToken::tokenize($code),
            static fn (\PhpToken $t): bool => !$t->isIgnorable(),
        ));
        $typeTokens = [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_NAME_RELATIVE, \T_ARRAY, \T_CALLABLE];
        $hits = [];
        $count = \count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!$tokens[$i]->is(\T_VARIABLE)) {
                continue;
            }
            if (!isset($tokens[$i + 2]) || $tokens[$i + 1]->text !== '='
                || \strtolower($tokens[$i + 2]->text) !== 'null') {
                continue;
            }

            $j = $i - 1;
            if ($j >= 0 && ($tokens[$j]->text === '&' || $tokens[$j]->text === '...')) {
                $j--;
            }
            // Walk the (possibly union) type back to its start.
            $types = [];
            while ($j >= 0 && $tokens[$j]->is($typeTokens)) {
                $types[] = \strtolower(\ltrim($tokens[$j]->text, '\\'));
                $j--;
                if ($j >= 0 && $tokens[$j]->text === '|') {
                    $j--;
                    continue;
                }
                break;
            }
            if ($types === []) {
                continue; // untyped, or not a parameter at all
            }
            if (($j >= 0 && $tokens[$j]->text === '?') || \in_array('null', $types, true) || \in_array('mixed', $types, true)) {
                continue;
            }
            $hits[] = $tokens[$i]->line . ' ' . $tokens[$i]->text;
        }

        return $hits;
    }
}
