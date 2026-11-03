<?php

declare(strict_types=1);

namespace SugarCraft\DatePicker\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Provenance guard (D17).
 *
 * Every shipped surface credits the real antecedent — EthanEFung's
 * bubble-datepicker — implemented natively in PHP. A stray `charmbracelet/...`
 * cite is a false lineage claim (that project is not upstream of this one) and
 * the docs-rework law retired charmbracelet "port" framing across the fleet.
 * This fails closed if such a cite reappears anywhere a user reads.
 */
final class ProvenanceCiteTest extends TestCase
{
    /**
     * @return array<string,array{0:string}>
     */
    public static function shippedTextFiles(): array
    {
        $root = \dirname(__DIR__);
        $files = ['README.md', 'composer.json'];
        foreach (glob($root . '/src/*.php') ?: [] as $src) {
            $files[] = 'src/' . basename($src);
        }

        $cases = [];
        foreach ($files as $rel) {
            $cases[$rel] = [$rel];
        }
        return $cases;
    }

    /**
     * @dataProvider shippedTextFiles
     */
    public function testNoFalseCharmbraceletProvenanceCite(string $rel): void
    {
        $path = \dirname(__DIR__) . '/' . $rel;
        $this->assertFileExists($path);
        $body = (string) \file_get_contents($path);

        $this->assertStringNotContainsString(
            'charmbracelet',
            $body,
            "{$rel} must not cite charmbracelet as the antecedent — "
                . 'the real upstream is EthanEFung/bubble-datepicker (D17).'
        );
    }

    public function testAntecedentIsNamedWhereItMatters(): void
    {
        $root = \dirname(__DIR__);
        $readme = (string) \file_get_contents($root . '/README.md');
        $composer = (string) \file_get_contents($root . '/composer.json');

        $this->assertStringContainsString(
            'EthanEFung/bubble-datepicker',
            $readme,
            'the README credits block must name the real antecedent'
        );
        // The description must lead with capability, not a "PHP port of ..." framing.
        $this->assertStringNotContainsString(
            '"PHP port of EthanEFung',
            $composer,
            'composer description must be capability-first, not port-framed (D17)'
        );
    }
}
