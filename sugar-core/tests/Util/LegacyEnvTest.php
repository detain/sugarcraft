<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\LegacyEnv;

/**
 * Pins for the rename-campaign env shim (plan_rename.md step 0.5): old-prefix
 * exports ride into the new prefix without ever clobbering a var the user set
 * under the new name. Fixture lines spelling an old prefix carry @rename-keep
 * so the residue checker reads them as law, exactly like the app call sites.
 *
 * Every var a test touches is snapshotted on first use and restored in
 * tearDown — putenv state must never leak across tests.
 */
final class LegacyEnvTest extends TestCase
{
    /**
     * Pre-test state per var name: getenv value (false = unset) plus the
     * presence-and-value of each superglobal mirror.
     *
     * @var array<string, array{getenv: string|false, env: array{bool, mixed}, server: array{bool, mixed}}>
     */
    private array $snapshot = [];

    protected function tearDown(): void
    {
        foreach ($this->snapshot as $name => $original) {
            if ($original['getenv'] === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $original['getenv']);
            }
            [$envExisted, $envValue] = $original['env'];
            if ($envExisted) {
                $_ENV[$name] = $envValue;
            } else {
                unset($_ENV[$name]);
            }
            [$serverExisted, $serverValue] = $original['server'];
            if ($serverExisted) {
                $_SERVER[$name] = $serverValue;
            } else {
                unset($_SERVER[$name]);
            }
        }
        $this->snapshot = [];
    }

    public function testOnlyOldVarSetFallsBackIntoTheNewPrefix(): void
    {
        $this->clearEnv('CANDY_CODE_MODE');
        $this->setEnv('SUGARCRUSH_MODE', 'vim'); // @rename-keep

        $this->assertSame(['SUGARCRUSH_MODE'], LegacyEnv::import(['SUGARCRUSH_' => 'CANDY_CODE_'])); // @rename-keep
        $this->assertSame('vim', getenv('CANDY_CODE_MODE'));
        $this->assertSame('vim', $_ENV['CANDY_CODE_MODE']);
        $this->assertSame('vim', $_SERVER['CANDY_CODE_MODE']);
        // The import copies, it never deletes — the old var stays for the notice and for pre-rename tools.
        $this->assertSame('vim', getenv('SUGARCRUSH_MODE')); // @rename-keep
    }

    public function testNewVarWinsWhenBothPrefixesAreSet(): void
    {
        $this->setEnv('SUGARCRUSH_MODE', 'legacy'); // @rename-keep
        $this->setEnv('CANDY_CODE_MODE', 'modern');

        $this->assertSame([], LegacyEnv::import(['SUGARCRUSH_' => 'CANDY_CODE_'])); // @rename-keep
        $this->assertSame('modern', getenv('CANDY_CODE_MODE'));
    }

    public function testNeitherVarSetImportsNothingAndLeavesDefaultUntouched(): void
    {
        $this->clearEnv('SUGARCRUSH_MODE'); // @rename-keep
        $this->clearEnv('CANDY_CODE_MODE');

        $this->assertSame([], LegacyEnv::import(['SUGARCRUSH_' => 'CANDY_CODE_'])); // @rename-keep
        $this->assertFalse(getenv('CANDY_CODE_MODE'));
    }

    public function testEmptyNewVarStillWinsBecauseSetMeansSetEvenWhenBlank(): void
    {
        $this->setEnv('SUGARCRUSH_MODE', 'legacy'); // @rename-keep
        $this->setEnv('CANDY_CODE_MODE', '');

        $this->assertSame([], LegacyEnv::import(['SUGARCRUSH_' => 'CANDY_CODE_'])); // @rename-keep
        $this->assertSame('', getenv('CANDY_CODE_MODE'));
    }

    public function testEmptyOldVarIsImportedAsTheEmptyStringVerbatim(): void
    {
        $this->setEnv('SUGARCRUSH_MODE', ''); // @rename-keep
        $this->clearEnv('CANDY_CODE_MODE');

        $this->assertSame(['SUGARCRUSH_MODE'], LegacyEnv::import(['SUGARCRUSH_' => 'CANDY_CODE_'])); // @rename-keep
        $this->assertSame('', getenv('CANDY_CODE_MODE'));
    }

    public function testTwoOldPrefixesBothLandInTheSameNewPrefix(): void
    {
        $this->clearEnv('CANDY_CODE_ALPHA');
        $this->clearEnv('CANDY_CODE_BETA');
        $this->setEnv('SUGARCRUSH_ALPHA', 'one'); // @rename-keep
        $this->setEnv('SUGAR_CRUSH_BETA', 'two'); // @rename-keep

        $imported = LegacyEnv::import([ // @rename-keep
            'SUGARCRUSH_' => 'CANDY_CODE_', // @rename-keep
            'SUGAR_CRUSH_' => 'CANDY_CODE_', // @rename-keep
        ]);

        $this->assertSame(['SUGARCRUSH_ALPHA', 'SUGAR_CRUSH_BETA'], $imported); // @rename-keep
        $this->assertSame('one', getenv('CANDY_CODE_ALPHA'));
        $this->assertSame('two', getenv('CANDY_CODE_BETA'));
    }

    public function testCollidingOldNamesResolveToTheByteFirstOldVar(): void
    {
        $this->clearEnv('CANDY_CODE_ONE');
        $this->setEnv('SUGARCRUSH_ONE', 'plain'); // @rename-keep
        $this->setEnv('SUGAR_CRUSH_ONE', 'underscored'); // @rename-keep

        // Sorted scan: 'CANDY_CODE_ONE' < 'CANDY_CODE_ONE' ('S' 0x53 beats '_' 0x5F),
        // so the plain spelling wins the shared CANDY_CODE_ONE and the loser is not reported.
        $this->assertSame(['SUGARCRUSH_ONE'], LegacyEnv::import([ // @rename-keep
            'SUGARCRUSH_' => 'CANDY_CODE_', // @rename-keep
            'SUGAR_CRUSH_' => 'CANDY_CODE_', // @rename-keep
        ]));
        $this->assertSame('plain', getenv('CANDY_CODE_ONE'));
    }

    public function testUnrelatedVariablesAreNeverTouched(): void
    {
        $this->clearEnv('CANDY_CODE_UNRELATED');
        $this->setEnv('LEGACYENV_PROBE_UNRELATED', 'keep-me');

        $this->assertSame([], LegacyEnv::import(['SUGARCRUSH_' => 'CANDY_CODE_'])); // @rename-keep
        $this->assertFalse(getenv('CANDY_CODE_UNRELATED'));
        $this->assertSame('keep-me', getenv('LEGACYENV_PROBE_UNRELATED'));
    }

    public function testResultListIsSortedRegardlessOfEnvironmentOrder(): void
    {
        $this->clearEnv('CANDY_CODE_ABE');
        $this->clearEnv('CANDY_CODE_MID');
        $this->clearEnv('CANDY_CODE_ZET');
        $this->setEnv('SUGARCRUSH_ZET', 'z'); // @rename-keep
        $this->setEnv('SUGAR_CRUSH_MID', 'm'); // @rename-keep
        $this->setEnv('SUGARCRUSH_ABE', 'a'); // @rename-keep

        $this->assertSame( // @rename-keep
            ['SUGARCRUSH_ABE', 'SUGARCRUSH_ZET', 'SUGAR_CRUSH_MID'], // @rename-keep
            LegacyEnv::import([ // @rename-keep
                'SUGARCRUSH_' => 'CANDY_CODE_', // @rename-keep
                'SUGAR_CRUSH_' => 'CANDY_CODE_', // @rename-keep
            ])
        );
    }

    public function testEmptyOldPrefixIsRefusedFailFast(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        LegacyEnv::import(['' => 'CANDY_CODE_']);
    }

    private function setEnv(string $name, string $value): void
    {
        $this->record($name);
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    private function clearEnv(string $name): void
    {
        $this->record($name);
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }

    private function record(string $name): void
    {
        if (array_key_exists($name, $this->snapshot)) {
            return;
        }
        $this->snapshot[$name] = [
            'getenv' => getenv($name),
            'env' => [array_key_exists($name, $_ENV), $_ENV[$name] ?? null],
            'server' => [array_key_exists($name, $_SERVER), $_SERVER[$name] ?? null],
        ];
    }
}
