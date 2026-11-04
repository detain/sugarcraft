<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Chat;

/**
 * Guard minted by reviews-1103 G2-L6 (OD-18 program prerequisite).
 *
 * SessionHost is the served-side twin of Chat, and its prose carries parity
 * claims in the shape `Chat::method()`: citations naming the Chat method a
 * host step mirrors. Until this file existed, nothing enforced them — the
 * contract "SessionHost mirrors Chat, method for method" was unverifiable
 * narrative, and a Chat refactor could silently strand a citation naming a
 * method that no longer exists. That is precisely the hazard every static
 * extraction lane is about to walk into, which is why the guard lands before
 * any extraction claims parity rather than after.
 *
 * The law pinned here: every lowercase `Chat::name(` citation in
 * src/Host/SessionHost.php must resolve via method_exists() on Chat.
 * Constant citations (`Chat::CANCELLED_TOOL_CALL`) are excluded by the
 * lowercase anchor — a bare `::CONSTANT` reference is code, not prose, and
 * cannot be a method claim. Citations without an open paren are likewise not
 * method claims; the paren is what makes the sentence "Chat has a method
 * called X".
 *
 * Vacuity control: testTheScanSeesADeadCitationPlantedInAFixture proves the
 * walker names a citation that does not resolve, so a broken regex cannot
 * turn this guard green by matching nothing.
 */
final class ChatParityCitationTest extends TestCase
{
    /**
     * The distinct lowercase `Chat::method(` citations found when this guard
     * was minted. Growth past the floor is fine (new parity prose); a shrink
     * through it would mean the scan lost the file, and the non-empty floor
     * turns that into a loud failure instead of a quietly vacuous pass.
     */
    private const CITATION_FLOOR = 13;

    /**
     * @return list<string> the cited method names, de-duplicated in file order
     */
    private static function citedMethods(string $source): array
    {
        $pattern = '/Chat::([a-z]\w*)\s*\(/';
        if (preg_match_all($pattern, $source, $matches) === false) {
            self::fail('the citation pattern failed to compile');
        }

        return array_values(array_unique($matches[1]));
    }

    private static function sessionHostSource(): string
    {
        $path = \dirname(__DIR__, 2) . '/src/Host/SessionHost.php';
        $source = file_get_contents($path);
        self::assertIsString($source, 'SessionHost.php must be readable for the parity scan');

        return $source;
    }

    public function testEveryChatCitationInSessionHostNamesALiveMethod(): void
    {
        $citations = self::citedMethods(self::sessionHostSource());

        self::assertGreaterThanOrEqual(
            self::CITATION_FLOOR,
            count($citations),
            'the scan found fewer distinct Chat citations than the mint-time roster; '
                . 'if SessionHost really lost parity prose, lower CITATION_FLOOR deliberately',
        );

        $dead = [];
        foreach ($citations as $method) {
            if (!method_exists(Chat::class, $method)) {
                $dead[] = $method;
            }
        }

        self::assertSame(
            [],
            $dead,
            'SessionHost prose cites ' . implode(', ', array_map(
                static fn (string $m): string => 'Chat::' . $m . '()',
                $dead,
            )) . ' but Chat has no such method — the parity claim is stale; '
                . 'fix the citation (or restore the method), never re-word the guard',
        );
    }

    public function testTheScanSeesADeadCitationPlantedInAFixture(): void
    {
        $fixture = " * Mirrors Chat::submit() exactly, unlike Chat::thisMethodNeverExisted() which rots.\n";

        self::assertSame(
            ['submit', 'thisMethodNeverExisted'],
            self::citedMethods($fixture),
            'the walker must name both the live and the planted citation',
        );

        $dead = array_values(array_filter(
            self::citedMethods($fixture),
            static fn (string $m): bool => !method_exists(Chat::class, $m),
        ));
        self::assertSame(['thisMethodNeverExisted'], $dead, 'a planted dead citation must be the thing the guard reports');
    }

    public function testConstantReferencesAreNotMistakenForMethodCitations(): void
    {
        $source = self::sessionHostSource();

        // The real file carries Chat::CANCELLED_TOOL_CALL as a code reference.
        // The guard must not accuse it: uppercase anchors are excluded by design.
        self::assertStringContainsString('Chat::CANCELLED_TOOL_CALL', $source);
        self::assertNotContains('CANCELLED_TOOL_CALL', self::citedMethods($source));
    }
}
