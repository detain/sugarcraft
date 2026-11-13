<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Config\Settings\Definitions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Config\Settings\Definitions\ServerSettings;
use SugarCraft\Code\Config\Settings\SettingCategory;
use SugarCraft\Code\Config\Settings\SettingDefinition;
use SugarCraft\Code\Config\Settings\SettingDefinitionSet;
use SugarCraft\Code\Config\Settings\SettingsSchema;
use SugarCraft\Code\Config\Settings\SettingType;

/**
 * Pins the serve/ACP/daemon keys that ServerSettings contributes to the schema: the exact
 * key roster (order is the settings-UI order), each row's type, risk class and
 * tiering, and that every row is registered in {@see SettingsSchema}.
 */
final class ServerSettingsTest extends TestCase
{
    /**
     * key => [type, risk class, project-settable, layered]
     *
     * @var array<string, array{string, string, bool, bool}>
     */
    private const ROWS = [
            'server.host' => ['String', 'Security', false, false],
            'server.port' => ['Int', 'Security', false, false],
            'server.allowedOrigins' => ['StringList', 'Security', false, false],
            'server.allowedHosts' => ['StringList', 'Security', false, false],
            'server.allowedIps' => ['StringList', 'Security', false, false],
            'server.trustedProxies' => ['StringList', 'Security', false, false],
            'server.maxOpenSessions' => ['Int', 'Security', false, false],
            'server.maxConcurrentTurns' => ['Int', 'Security', false, false],
            'server.askTimeoutSeconds' => ['Float', 'Security', false, false],
            'server.drainSeconds' => ['Float', 'Security', false, false],
            'server.allowBypass' => ['Bool', 'Security', false, false],
            'server.dirBrowse' => ['Bool', 'Security', false, false],
            'server.clientTools' => ['Bool', 'Security', false, false],
            'server.browseRoot' => ['Path', 'Security', false, false],
            'webhooks' => ['Map', 'Security', false, false],
            'webhooksOut' => ['Map', 'Security', false, false],
            'channels.discord' => ['Map', 'Security', false, false],
            'channels.msteams' => ['Map', 'Security', false, false],
    ];

    public function testItIsASettingDefinitionSetForItsCategory(): void
    {
        self::assertInstanceOf(SettingDefinitionSet::class, new class() implements SettingDefinitionSet {
            public static function category(): SettingCategory
            {
                return ServerSettings::category();
            }

            public static function definitions(): array
            {
                return ServerSettings::definitions();
            }
        });
        self::assertTrue(is_subclass_of(ServerSettings::class, SettingDefinitionSet::class));
        self::assertSame(SettingCategory::Server, ServerSettings::category());
    }

    public function testItOwnsExactlyTheDocumentedKeysInOrder(): void
    {
        $keys = array_map(static fn (SettingDefinition $d): string => $d->key, ServerSettings::definitions());

        self::assertSame(array_keys(self::ROWS), $keys);
        self::assertSame($keys, array_values(array_unique($keys)), 'no duplicate keys');
    }

    public function testEveryRowKeepsItsTypeRiskAndTiering(): void
    {
        foreach (ServerSettings::definitions() as $d) {
            self::assertSame(
                self::ROWS[$d->key],
                [$d->type->name, $d->riskClass->name, $d->projectSettable, $d->layered],
                $d->key,
            );
        }
    }

    public function testEveryRowIsFiledUnderTheSetsCategoryWithResolvedCopy(): void
    {
        foreach (ServerSettings::definitions() as $d) {
            self::assertSame(ServerSettings::category(), $d->category, $d->key);
            self::assertNotSame('', trim($d->label), $d->key . ' label');
            self::assertNotSame('', trim($d->help), $d->key . ' help');
            self::assertStringStartsNotWith('settings.', $d->label, $d->key . ' label is an unresolved lang key');
        }
    }

    public function testEnumRowsDeclareTheirValuesAndDefaultsAreMembers(): void
    {
        $enums = [];
        foreach (ServerSettings::definitions() as $d) {
            if ($d->type !== SettingType::Enum) {
                continue;
            }
            $enums[] = $d->key;
            self::assertNotSame([], $d->enumValues, $d->key . ' is an Enum with no values');
            if ($d->default !== null) {
                self::assertContains($d->default, $d->enumValues, $d->key . ' default');
            }
        }
        self::assertSame([], $enums);
    }

    public function testEveryRowIsRegisteredInTheSchema(): void
    {
        foreach (ServerSettings::definitions() as $d) {
            $registered = SettingsSchema::byKey($d->key);
            self::assertNotNull($registered, $d->key . ' is not in SettingsSchema');
            self::assertSame($d->type, $registered->type, $d->key);
            self::assertSame(ServerSettings::category(), $registered->category, $d->key);
        }
    }
}
