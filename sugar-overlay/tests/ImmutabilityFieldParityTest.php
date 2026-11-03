<?php

declare(strict_types=1);

/*
 * Structural pin for the immutability contract (m11, round lib-fixes-1103).
 *
 * Veil grew fields one campaign at a time, and the failure mode of a
 * forgotten wire is silent state loss: a mutate() parameter never
 * forwarded resets the field on every with*() call, and a withoutSession()
 * line never carried SILENTLY DROPS the field on session swap — that is
 * how the scanner/lastRendered pair once lost hit-testing (round 62) and
 * how the marker/reducedMotion pair added in this round could regress.
 *
 * These pins re-derive the law from the class itself: every constructor
 * parameter except `session` must have a same-named mutate() parameter
 * (and vice versa, modulo the paired `*Set` presence flags) — mutate()
 * deliberately never swaps the session, since no with*() may detach one
 * implicitly. withoutSession() must name every constructor parameter in
 * its reconstruction. Comment out any single carry line and the suite
 * goes red.
 */

namespace SugarCraft\Overlay\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Overlay\Veil;

final class ImmutabilityFieldParityTest extends TestCase
{
    public function testMutateParametersMirrorTheConstructorParameters(): void
    {
        $ctor = array_values(array_diff($this->paramNames('__construct'), ['session']));
        $mutate = [];
        foreach ($this->paramNames('mutate') as $name) {
            if (preg_match('/^(.+)Set$/', $name, $m) === 1) {
                continue; // presence flag for the nullable paired field
            }
            $mutate[] = $name;
        }

        sort($ctor);
        sort($mutate);
        $this->assertSame(
            $ctor,
            $mutate,
            'Every Veil field must be settable through mutate() and only mutate fields must exist in the constructor'
        );
    }

    public function testWithoutSessionCarriesEveryField(): void
    {
        $method = new ReflectionMethod(Veil::class, 'withoutSession');
        $file = (string) $method->getFileName();
        $lines = explode("\n", file_get_contents($file) ?: '');
        $body = implode("\n", array_slice($lines, (int) $method->getStartLine(), (int) $method->getEndLine() - (int) $method->getStartLine() + 1));

        preg_match_all('/^\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*:/m', $body, $m);
        $carried = $m[1];

        $ctor = $this->paramNames('__construct');
        $missing = array_values(array_diff($ctor, $carried));
        $this->assertSame(
            [],
            $missing,
            'withoutSession() drops fields that the constructor knows — every field must be re-carried explicitly'
        );
    }

    public function testSessionIsFreshNotSharedInWithoutSession(): void
    {
        $method = new ReflectionMethod(Veil::class, 'withoutSession');
        $file = (string) $method->getFileName();
        $lines = explode("\n", file_get_contents($file) ?: '');
        $body = implode("\n", array_slice($lines, (int) $method->getStartLine(), (int) $method->getEndLine() - (int) $method->getStartLine() + 1));

        $this->assertMatchesRegularExpression(
            '/session:\s*new RenderSession\(\)/',
            $body,
            'withoutSession() must mint a fresh session, never carry the old one'
        );
    }

    /**
     * @return list<string>
     */
    private function paramNames(string $method): array
    {
        $names = [];
        foreach ((new ReflectionMethod(Veil::class, $method))->getParameters() as $parameter) {
            $names[] = $parameter->getName();
        }

        return $names;
    }
}
