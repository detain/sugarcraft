<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Diagnostics;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;
use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Crush\Tests\Support\RuntimeNoticeSinkResetExtension;

/**
 * Pins for E194: the suite resets {@see RuntimeNoticeSink} before EVERY test,
 * from the PHPUnit extension registered in `phpunit.xml` rather than from the
 * good behaviour of whoever happens to run next.
 *
 * THE FILE IS THREE PINS IN THE ORDER THE FAILURE MODES NEED THEM. The first
 * is static and answers "is it configured": a registration nobody loads is
 * worse than none, because it reads as a guarantee. The second and third are
 * one pair — a test that leaves a notice behind and the test that follows it,
 * which asserts the inbox is empty — and they answer "does it actually fire",
 * which no config file can prove on its own.
 *
 * WHY THE PAIR IS DECLARED-ADJACENT AND WHAT THAT COSTS: PHPUnit runs methods
 * in declaration order under every gate this repo uses, so the clean-half sees
 * exactly the state the leaver-half left. Under `--order-by=random` the pair
 * can run clean-before-leaving and go vacuously green; it cannot go falsely
 * red, and random order is not a shape any gate or CI leg runs. A false-green
 * there is a known bound, written down rather than discovered.
 *
 * WHY THE STATIC PIN READS THE XML AND NOT JUST THE CLASS: PHPUnit 10's
 * loader (vendor `TextUI/Configuration/Xml/Loader.php`, `extensions/bootstrap`)
 * silently IGNORES a child element it does not know — MEASURED on this tree,
 * an `<extension class=...>` entry produced a green run that had loaded
 * nothing, and only a throw-probe inside `bootstrap()` distinguished the two.
 * The spelling IS the registration, so the pin asserts the spelled shape the
 * loader queries for, not a plausible one.
 */
final class RuntimeNoticeSinkResetExtensionTest extends TestCase
{
    public function testPhpunitConfigBootsTheResetExtensionByTheNameTheLoaderReads(): void
    {
        $configFile = \dirname(__DIR__, 2) . '/phpunit.xml';
        self::assertFileExists($configFile);

        $xml = new SimpleXMLElement(file_get_contents($configFile) ?: '');
        $nodes = $xml->xpath('/phpunit/extensions/bootstrap');
        self::assertNotFalse($nodes, 'the extensions block is gone from phpunit.xml');

        // The loader takes EVERY `extensions/bootstrap` child; the config owes
        // exactly this one. A second entry is not this test's subject, but a
        // zero entry while the guarantee still READS as installed — a rename,
        // a merge that dropped the node — is precisely the silent-config-loss
        // the throw-probe above measured as invisible.
        $classes = [];
        foreach ($nodes as $node) {
            $classes[] = (string) $node['class'];
        }
        self::assertSame(
            [RuntimeNoticeSinkResetExtension::class],
            $classes,
            'phpunit.xml does not boot the reset extension under the exact element name '
            . '(`extensions/bootstrap`) and class FQN PHPUnit 10.5 actually loads — a child '
            . 'of any other name is parsed and dropped without a word',
        );

        self::assertTrue(
            \is_subclass_of(RuntimeNoticeSinkResetExtension::class, Extension::class),
            'the registered class no longer implements PHPUnit\Runner\Extension\Extension, so '
            . 'the bootstrapper it names cannot bootstrap',
        );
        self::assertTrue(method_exists(RuntimeNoticeSinkResetExtension::class, 'bootstrap'));
    }

    /**
     * FIRST HALF OF THE PAIR — leave the process-wide sink armed with a
     * notice, the exact shape round 47 found leaking between cases. Arm on the
     * array backend (`crossFork: false`): the transport socket pair would work
     * too, but the array keeps this pair free of fds and loop watchers, so a
     * failure here names the leak and nothing else.
     */
    public function testThisRunLeavesAnArmedSinkWithANoticeInTheInbox(): void
    {
        RuntimeNoticeSink::arm(false);
        self::assertTrue(RuntimeNoticeSink::isArmed());
        self::assertTrue(RuntimeNoticeSink::record('fd-lane probe notice'), 'the record was '
            . 'dropped rather than queued — the pair below would then pass for the wrong reason');
        self::assertTrue(RuntimeNoticeSink::hasPending());
    }

    /**
     * SECOND HALF OF THE PAIR — nothing in this process reset the sink by
     * hand; the only thing standing between the notice above and this
     * assertion is the extension firing on `PreparationStarted`. Delete the
     * `<extensions>` block (or mis-spell it, which loads nothing — see the
     * class doc-block) and this goes red, not silently green.
     */
    public function testTheNextPreparationStartedWithTheSinkWiped(): void
    {
        self::assertFalse(RuntimeNoticeSink::isArmed(), 'the sink carried state from the previous '
            . 'test — the E194 extension did not run, so "clean at your start" is again a property '
            . 'of who happens to drain');
        self::assertFalse(RuntimeNoticeSink::hasPending());
    }
}
