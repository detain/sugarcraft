<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * S-X06 (G-16 hybrid, owner ruling): the `with*()` immutability law of this
 * package, made checkable.
 *
 * THE RULING, dated 2026-11-13:
 *
 *  1. A `with*()` that has seven or more constructor inputs to carry goes
 *     through ONE `mutate()` (the shared {@see \SugarCraft\Core\Concerns\Mutable}
 *     splat, or a class-local `mutate()` where an input is a closure or a
 *     lazily-filled memo) and names only what it changes. Re-listing the whole
 *     constructor in every wither is how a dropped field goes unnoticed
 *     (an omitted `modifiedAt` would silently freeze timestamps).
 *  2. Value objects with fewer than seven inputs MAY keep hand-carried
 *     `new self(...)` withers; each carries a dated exemption line in its
 *     class docblock saying so. Below seven the list is short enough to read at
 *     a glance and a dropped field is a compile-visible arity error far more
 *     often than not.
 *  3. A method named `with*` NEVER mutates `$this`. A service-side setter that
 *     must keep object identity (a live PDO store, the launch-owned hook
 *     chain) is named `set*`/`attach*` instead.
 *  4. Where a class's `mutate()` is the generic `get_object_vars` splat, the
 *     declared properties and the constructor parameters must be the same
 *     set: a property added without a constructor input would be silently
 *     dropped by the first wither.
 *
 * The root AGENTS.md sentence that states this law is out of this package's
 * remit and is not touched here.
 */
final class MutateDriftGuardTest extends TestCase
{
    /** Hand-carry arity at which a wither must use mutate(). */
    private const SWEEP_ARITY = 7;

    /**
     * Classes whose declared properties legitimately differ from their
     * constructor parameters, with the reason. Their class-local `mutate()`
     * carries the extra state by hand.
     *
     * @var array<class-string, string>
     */
    private const PARITY_EXEMPT = [
        \SugarCraft\Code\Context\EnvironmentBlock::class => 'lazy enclosingRepo memo carried by its mutate()',
        \SugarCraft\Code\Providers\AnthropicProvider::class => 'httpClient/seam flags are derived from the http* inputs; named mutate()',
        \SugarCraft\Code\Providers\GeminiProvider::class => 'httpClient is derived from the http* inputs; named mutate()',
        \SugarCraft\Code\Tools\BuiltIn\WebFetch::class => 'closures are stored, not promoted; named mutate()',
        \SugarCraft\Code\Hooks\HttpHook::class => 'closures are stored, not promoted; named mutate()',
        \SugarCraft\Code\Hooks\ScriptHook::class => 'clock is stored as a closure; named mutate()',
    ];

    public function testNoWitherHandCarriesSevenOrMoreConstructorInputs(): void
    {
        $offenders = [];
        foreach ($this->withMethods() as [$file, $class, $name, $body]) {
            if (preg_match_all('/new\s+(?:self|static)\s*\(/', $body, $m, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }
            foreach ($m[0] as [$text, $offset]) {
                $args = $this->topLevelArgCount($body, $offset + strlen($text) - 1);
                if ($args >= self::SWEEP_ARITY) {
                    $offenders[] = sprintf('%s::%s() re-lists %d constructor inputs by hand', $class, $name, $args);
                }
            }
        }

        $this->assertSame([], $offenders, "S-X06: route these through mutate():\n" . implode("\n", $offenders));
    }

    public function testNoWitherMutatesItsOwnObject(): void
    {
        $offenders = [];
        foreach ($this->withMethods() as [$file, $class, $name, $body]) {
            if (preg_match('/\$this->\w+(?:\[[^\]]*\])?\s*(?:\?\?)?=(?!=)/', $body) === 1
                && !preg_match('/\bclone\b|new\s+(?:self|static)|mutate\s*\(/', $body)) {
                $offenders[] = sprintf('%s::%s() assigns $this state yet is named with*', $class, $name);
            }
        }

        $this->assertSame([], $offenders, "S-X06: a with*() must return a new instance; rename the mutator set*/attach*:\n" . implode("\n", $offenders));
    }

    public function testEveryGenericMutateClassHasPropertyConstructorParity(): void
    {
        $checked = 0;
        $offenders = [];
        foreach ($this->classFiles() as $file => $class) {
            $src = (string) file_get_contents($file);
            if (!preg_match('/use\s+Mutable\s*;/', $src)) {
                continue;
            }
            $rc = new \ReflectionClass($class);
            $ctor = $rc->getConstructor();
            if ($ctor === null) {
                $offenders[] = $class . ' uses Mutable but has no constructor';
                continue;
            }
            $params = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $ctor->getParameters());
            $props = [];
            foreach ($rc->getProperties() as $p) {
                if (!$p->isStatic() && $p->getDeclaringClass()->getName() === $class) {
                    $props[] = $p->getName();
                }
            }
            sort($params);
            sort($props);
            if ($params !== $props) {
                $offenders[] = sprintf('%s: constructor [%s] vs properties [%s]', $class, implode(',', $params), implode(',', $props));
            }
            ++$checked;
        }

        $this->assertGreaterThan(15, $checked, 'the census should see every Mutable user');
        $this->assertSame([], $offenders, "S-X06: a property outside the constructor is dropped by mutate():\n" . implode("\n", $offenders));
    }

    public function testEveryClassThatCallsMutateDefinesOrUsesIt(): void
    {
        $offenders = [];
        foreach ($this->classFiles() as $file => $class) {
            $src = (string) file_get_contents($file);
            if (!str_contains($src, '$this->mutate(')) {
                continue;
            }
            // The class-body form: an import line at column 0 is NOT the trait use.
            if (!preg_match('/^    use [^;]*\bMutable\b[^;]*;/m', $src) && !preg_match('/function\s+mutate\s*\(/', $src)) {
                $offenders[] = $class;
            }
        }

        $this->assertSame([], $offenders, "S-X06: these call \$this->mutate() but neither `use Mutable;` nor declare it:\n" . implode("\n", $offenders));
    }

    public function testParityExemptionsAreStillNeeded(): void
    {
        foreach (self::PARITY_EXEMPT as $class => $why) {
            $this->assertTrue(class_exists($class), "exempt class $class is gone — drop its row ($why)");
            $src = (string) file_get_contents((new \ReflectionClass($class))->getFileName());
            $this->assertMatchesRegularExpression('/private function mutate\(/', $src, "$class has a class-local mutate() ($why)");
        }
    }

    /**
     * Every `with*()` declared in a class of src/, body sliced by the line
     * range reflection reports (no brace walking: see
     * {@see InterpolationOpenerTokenTest}).
     *
     * @return \Generator<int, array{0: string, 1: string, 2: string, 3: string}>
     */
    private function withMethods(): \Generator
    {
        foreach ($this->classFiles() as $file => $class) {
            if (!class_exists($class)) {
                continue;
            }
            $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
            foreach ((new \ReflectionClass($class))->getMethods() as $method) {
                if (!preg_match('/^with[A-Z]/', $method->getName()) || $method->isAbstract() || $method->getFileName() !== $file) {
                    continue;
                }
                $body = implode("\n", array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
                yield [$file, $class, $method->getName(), $body];
            }
        }
    }

    private function topLevelArgCount(string $body, int $openParen): int
    {
        $depth = 0;
        $commas = 0;
        $seen = false;
        $quote = null;
        $len = strlen($body);
        for ($i = $openParen; $i < $len; ++$i) {
            $c = $body[$i];
            if ($quote !== null) {
                if ($c === '\\') {
                    ++$i;
                } elseif ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === "'" || $c === '"') {
                $quote = $c;
                continue;
            }
            if ($c === '(' || $c === '[') {
                ++$depth;
                continue;
            }
            if ($c === ')' || $c === ']') {
                if (--$depth === 0) {
                    break;
                }
                continue;
            }
            if ($depth === 1) {
                if ($c === ',') {
                    ++$commas;
                    $seen = false;
                } elseif (!ctype_space($c)) {
                    $seen = true;
                }
            }
        }

        // A trailing comma opens no extra argument.
        return $commas + ($seen ? 1 : 0);
    }

    /**
     * @return array<string, class-string> file => FQCN for every class under src/
     */
    private function classFiles(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        $root = dirname(__DIR__, 2) . '/src';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $f) {
            if ($f->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($f->getPathname());
            if (preg_match('/^namespace\s+([^;]+);/m', $src, $ns) && preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $src, $c)) {
                $cache[$f->getPathname()] = $ns[1] . '\\' . $c[1];
            }
        }

        return $cache;
    }
}
