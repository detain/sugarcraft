<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Config\Trust;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Config\Trust\ProjectTrustCensus;
use SugarCraft\Code\Config\Trust\TrustTarget;

/**
 * C-SEC-5.a: the census shape and the target vocabulary, asserted against
 * plain closures — the class doc-block's promise that the shape carries no
 * filesystem, so every rule here holds without a HOME or a project on disk.
 * The wiring of the closures to the real trust doors is pinned beside them,
 * in {@see \SugarCraft\Code\Tests\Cli\BootstrapProjectTrustGrantTest}.
 */
final class ProjectTrustCensusTest extends TestCase
{
    public function testNothingDeclaredIsNothingPending(): void
    {
        self::assertSame([], ProjectTrustCensus::pending(
            static fn (TrustTarget $t): bool => false,
            static fn (TrustTarget $t): bool => false,
        ));
    }

    public function testDeclaredAndUntrustedTargetsComeOutInCaseOrder(): void
    {
        // Declared in an order that is NOT the case order, to prove the walk
        // belongs to the enum and not to the probe.
        $declared = [TrustTarget::Settings, TrustTarget::Hooks, TrustTarget::Mcp];

        self::assertSame(
            [TrustTarget::Mcp, TrustTarget::Hooks, TrustTarget::Settings],
            ProjectTrustCensus::pending(
                static fn (TrustTarget $t): bool => in_array($t, $declared, true),
                static fn (TrustTarget $t): bool => false,
            ),
        );
    }

    public function testATrustedTargetIsNeverPendingEvenWhenDeclared(): void
    {
        self::assertSame(
            [TrustTarget::Hooks],
            ProjectTrustCensus::pending(
                static fn (TrustTarget $t): bool => true,
                static fn (TrustTarget $t): bool => $t !== TrustTarget::Hooks,
            ),
        );
    }

    public function testTheUndeclaredShortCircuitDoesNotAskTheTrustedDoor(): void
    {
        // A target with no content is skipped without consulting the grant
        // memo — the census must not force a trust read (and its frozen-list
        // consequences) for doors a project never declared.
        $trustedAsked = [];
        self::assertSame([], ProjectTrustCensus::pending(
            static fn (TrustTarget $t): bool => false,
            static function (TrustTarget $t) use (&$trustedAsked): bool {
                $trustedAsked[] = $t;

                return false;
            },
        ));
        self::assertSame([], $trustedAsked);
    }

    /**
     * The config-key map. `mcp-pin` shares the MCP list BY DESIGN — the pin
     * question is answered by re-recording through the same door — and the
     * four names are exactly the roster
     * {@see \SugarCraft\Code\Tests\Config\TrustKeyDocumentationDriftTest}
     * censuses. Pinned per-case, so renaming one here breaks next to the
     * sentence that says what it means.
     */
    public function testEachTargetNamesTheSettingsListThatRecordsIt(): void
    {
        self::assertSame('trustedProjectMcp', TrustTarget::Mcp->configKey());
        self::assertSame('trustedProjectMcp', TrustTarget::McpPin->configKey());
        self::assertSame('trustedProjectHooks', TrustTarget::Hooks->configKey());
        self::assertSame('trustedProjectCommands', TrustTarget::Commands->configKey());
        self::assertSame('trustedProjectSettings', TrustTarget::Settings->configKey());
    }

    /**
     * Every ask row exists in the catalogue — the census hands these keys to
     * prompt surfaces, and a missing row degrades to the raw key IN THE
     * USER'S PROMPT. LangParityTest only watches literal `Lang::t()` calls,
     * and these keys reach it assembled, so the pin lives here.
     */
    public function testEachTargetHasItsReasonInTheEnglishCatalogue(): void
    {
        $catalogue = require \dirname(__DIR__, 3) . '/lang/en.php';
        self::assertIsArray($catalogue);

        foreach (TrustTarget::cases() as $target) {
            self::assertArrayHasKey($target->askKey(), $catalogue);
            $text = (string) $catalogue[$target->askKey()];
            self::assertNotSame($target->askKey(), $text, 'a reason must be prose, not a key echo');
            self::assertStringContainsString('{root}', $text, 'each reason names the root it speaks about');
        }
    }

    public function testAskKeysAreDistinctPerTarget(): void
    {
        $keys = array_map(static fn (TrustTarget $t): string => $t->askKey(), TrustTarget::cases());

        self::assertSame($keys, array_unique($keys));
    }

    /**
     * Only the MCP door records pins with its grant. The pin case rides it —
     * a pin cannot be approved apart from the list it keys on — and nothing
     * else may, because the other doors are plain list grants
     * {@see \SugarCraft\Code\Config\Settings\SettingsWriter::grantTrust()}
     * already writes.
     */
    public function testOnlyTheMcpDoorGrantsThroughThePinRecordingPath(): void
    {
        foreach (TrustTarget::cases() as $target) {
            self::assertSame(
                $target === TrustTarget::Mcp || $target === TrustTarget::McpPin,
                $target->grantsThroughMcpDoor(),
            );
        }
    }
}
