<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Providers\HttpSettingsSnapshot;
use SugarCraft\Code\Providers\HttpSettingsTurn;

/**
 * The scope {@see HttpSettingsSnapshot::forTurn()} hands back: it ends the
 * turn's snapshot when released (how EngineBackend::runTurn() uses it) or
 * when {@see HttpSettingsTurn::end()} is called, and only once.
 */
final class HttpSettingsTurnTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        HttpSettingsSnapshot::resetForTesting();
    }

    protected function tearDown(): void
    {
        HttpSettingsSnapshot::resetForTesting();
        parent::tearDown();
    }

    public function testReleasingTheScopeEndsTheSnapshot(): void
    {
        $scope = HttpSettingsSnapshot::forTurn(['k' => 'turn']);
        self::assertInstanceOf(HttpSettingsTurn::class, $scope);
        self::assertSame('turn', HttpSettingsSnapshot::config()['k'] ?? null);

        unset($scope);

        self::assertFalse(HttpSettingsSnapshot::active());
    }

    public function testEndEndsTheSnapshotOnceAndLeavesANewerTurnAlone(): void
    {
        $scope = HttpSettingsSnapshot::forTurn(['k' => 'outer']);
        $scope->end();
        self::assertFalse(HttpSettingsSnapshot::active());

        $newer = HttpSettingsSnapshot::forTurn(['k' => 'newer']);
        $scope->end();
        unset($scope);

        self::assertSame('newer', HttpSettingsSnapshot::config()['k'] ?? null, 'a second end() or the release after end() touches no other turn');
        $newer->end();
    }
}
