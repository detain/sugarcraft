<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Config\Settings\RiskClass;
use SugarCraft\Crush\Config\Settings\SettingsSchema;
use SugarCraft\Crush\Config\Settings\SettingsWriter;
use SugarCraft\Crush\Protocol\ErrorCode;
use SugarCraft\Crush\Protocol\MethodRegistry;
use SugarCraft\Crush\Protocol\Methods\SettingsMethods;
use SugarCraft\Crush\Protocol\Schema\MethodSchemas;
use SugarCraft\Crush\Protocol\Schema\ProtocolSchema;
use SugarCraft\Crush\Protocol\Scope;
use SugarCraft\Crush\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Crush\Tests\Server\Support\WireClient;

/**
 * Roadmap O-6b: settings over the wire, as the web settings form uses them —
 * the schema the form is generated from (tiers, apply modes, env locks, why a
 * field is read-only), the effective values with their provenance, the save
 * preview that writes nothing, and the save itself, through the launch's
 * {@see SettingsWriter} and the allowlist (Appendix O §6.10 / §8.5 / §8.6).
 */
final class SettingsRemoteAllowlistTest extends TestCase
{
    private ProtocolFixture $fixture;

    private string|false $home = false;

    /** @var array<string, string|false> */
    private array $env = [];

    protected function setUp(): void
    {
        $this->fixture = ProtocolFixture::new();
        $this->home = \getenv('HOME');
        $home = $this->fixture->dir . '/home';
        \mkdir($home . '/' . LayeredSettings::dir(), 0o700, true);
        $this->setEnv('HOME', $home);
    }

    protected function tearDown(): void
    {
        foreach ($this->env as $name => $value) {
            \putenv($value === false ? $name : $name . '=' . $value);
        }
        $this->fixture->tearDown();
    }

    public function testTheSchemaSaysWhichFieldsAClientMayWriteAndWhyNot(): void
    {
        $result = $this->checked($this->client(), 'settings.schema');
        $rows = \array_column($result['items'], null, 'key');

        self::assertSame(\array_map(static fn ($d) => $d->key, SettingsSchema::all()), \array_keys($rows), 'one row per schema key, in schema order');
        foreach (['statusLine', 'permissionMode', 'permissionRules', 'instructions', 'trustedProjectSettings', 'server.allowBypass', 'theme', 'provider', 'maxToolSteps', 'layout'] as $key) {
            self::assertFalse($rows[$key]['writableRemotely'], $key);
            self::assertNotSame('', $rows[$key]['remoteRefusal'] ?? '', $key . ' says why it is read-only');
        }
        self::assertStringContainsString('/theme', $rows['theme']['remoteRefusal']);
        self::assertStringContainsString('runs a command', $rows['statusLine']['remoteRefusal']);
        self::assertStringContainsString('trust', $rows['trustedProjectSettings']['remoteRefusal']);

        foreach ($rows as $key => $row) {
            self::assertSame(!isset($row['remoteRefusal']), $row['writableRemotely'], $key);
            if ($row['writableRemotely']) {
                self::assertContains($row['riskClass'], [RiskClass::Cosmetic->value, RiskClass::Tuning->value, RiskClass::Narrowing->value], $key);
                self::assertFalse($row['sensitive'], $key);
            }
            self::assertArrayHasKey('appliesLabel', $row, $key);
            self::assertArrayHasKey('ui', $row, $key);
        }

        self::assertTrue($rows['parallelToolDeadlineSeconds']['writableRemotely']);
        self::assertSame('next-turn', $rows['parallelToolDeadlineSeconds']['applies']);
        self::assertSame('next turn', $rows['parallelToolDeadlineSeconds']['appliesLabel']);
        self::assertSame('SUGARCRUSH_CONNECT_TIMEOUT', $rows['connectTimeoutSeconds']['envVar']);
        self::assertSame(['off', 'bell', 'osc9'], $rows['notify']['options'] ?? null, 'a pick-one field names its choices');

        // A count is not a credential: the name match is anchored at the end.
        self::assertFalse($rows['maxOutputTokens']['sensitive']);
        self::assertFalse($rows['secretEnvAllowlist']['sensitive'], 'a list of variable NAMES holds no secret');

        $tiers = \array_column($result['tiers'], null, 'scope');
        self::assertTrue($tiers['user']['writable']);
        self::assertSame($this->fixture->dir . '/config.json', $tiers['user']['path']);
        self::assertFalse($tiers['project']['writable'], 'the fixture writer names no trusted project');
        self::assertStringContainsString('project', $tiers['project']['refusal']);
    }

    public function testEffectiveValuesCarryTheirProvenanceAndTheirEnvLock(): void
    {
        $client = $this->client();
        $client->call('settings.set', ['key' => 'parallelToolDeadlineSeconds', 'value' => 40]);
        $this->setEnv('SUGARCRUSH_CONNECT_TIMEOUT', '7');

        $result = $this->checked($client, 'settings.get', ['scope' => 'effective']);

        $deadline = $result['values']['parallelToolDeadlineSeconds'];
        self::assertSame(40, $deadline['value']);
        self::assertSame('user-config', $deadline['source']);
        self::assertSame('config.json', $deadline['sourceLabel']);
        self::assertSame($this->fixture->dir . '/config.json', $deadline['sourcePath'], 'layer 4 is the file the writer saved to');
        self::assertFalse($deadline['locked']);

        $connect = $result['values']['connectTimeoutSeconds'];
        self::assertSame('env', $connect['source']);
        self::assertTrue($connect['locked']);
        self::assertStringContainsString('SUGARCRUSH_CONNECT_TIMEOUT', (string) $connect['lockReason']);

        self::assertSame('default', $result['values']['parallelToolCalls']['source']);
        self::assertContains($this->fixture->dir . '/config.json', \array_column($result['files'], 'path'));
    }

    public function testTierFilesAndPreviewsMaskEveryCredentialAtAnyDepth(): void
    {
        \file_put_contents($this->fixture->dir . '/config.json', (string) \json_encode([
            'providers' => ['acme' => ['apiKey' => 'sk-live-123', 'baseUrl' => 'https://bob:hunter2@api.example/v1', 'headers' => ['Authorization' => 'Bearer zzz']]],
            'maxOutputTokens' => 4096,
        ]));
        $client = $this->client();

        $values = $this->checked($client, 'settings.get', ['scope' => 'user'])['values'];
        self::assertSame(SettingsMethods::MASK, $values['providers']['acme']['apiKey']);
        self::assertSame(SettingsMethods::MASK, $values['providers']['acme']['headers']['Authorization']);
        self::assertSame('https://' . SettingsMethods::MASK . '@api.example/v1', $values['providers']['acme']['baseUrl']);
        self::assertSame(4096, $values['maxOutputTokens'], 'a token COUNT is shown');

        $preview = $this->checked($client, 'settings.preview', ['set' => ['parallelToolCalls' => false]]);
        $text = \json_encode($preview);
        foreach (['sk-live-123', 'hunter2', 'zzz'] as $secret) {
            self::assertStringNotContainsString($secret, (string) $text);
        }
    }

    public function testAPreviewShowsTheDiffAndWhenEachChangeAppliesAndWritesNothing(): void
    {
        $client = $this->client();
        $client->call('settings.set', ['key' => 'disabledTools', 'value' => ['WebFetch']]);
        $path = $this->fixture->dir . '/config.json';
        $before = (string) \file_get_contents($path);
        $this->setEnv('SUGARCRUSH_CONNECT_TIMEOUT', '7');

        $preview = $this->checked($client, 'settings.preview', [
            'set' => ['parallelToolCalls' => false, 'connectTimeoutSeconds' => 9],
            'unset' => ['disabledTools'],
        ]);

        self::assertSame($before, (string) \file_get_contents($path), 'a preview writes nothing');
        self::assertTrue($preview['canSave']);
        self::assertSame($path, $preview['path']);
        self::assertStringContainsString('+    "parallelToolCalls": false', $preview['diff']);
        self::assertStringContainsString('-        "WebFetch"', $preview['diff']);
        $changes = \array_column($preview['changes'], null, 'key');
        self::assertSame('set', $changes['parallelToolCalls']['action']);
        self::assertSame('next-turn', $changes['parallelToolCalls']['applies']);
        self::assertSame('restart', $changes['connectTimeoutSeconds']['applies']);
        self::assertSame('reset', $changes['disabledTools']['action']);
        self::assertStringContainsString('next turn', $preview['applySummary']);
        self::assertContains('connectTimeoutSeconds: environment still sets it, and outranks this file', $preview['notes']);
    }

    public function testAPreviewNamesWhatBlocksTheSave(): void
    {
        $client = $this->client();

        $preview = $this->checked($client, 'settings.preview', ['set' => ['statusLine' => ['command' => 'rm -rf ~'], 'parallelToolDeadlineSeconds' => 'many']]);
        self::assertFalse($preview['canSave']);
        self::assertStringContainsString('runs a command', $preview['refusals']['statusLine']);
        self::assertArrayHasKey('parallelToolDeadlineSeconds', $preview['refusals']);

        $project = $this->checked($client, 'settings.preview', ['scope' => 'project', 'set' => ['parallelToolCalls' => false]]);
        self::assertFalse($project['canSave']);
        self::assertArrayHasKey('*', $project['refusals'], 'an unwritable tier blocks the whole save');

        self::assertSame('nothing_to_save', $client->request('settings.preview', [])['error']['data']['kind']);
    }

    public function testASaveWritesTheWholeChangeSetOrNothing(): void
    {
        $client = $this->client();
        $path = $this->fixture->dir . '/config.json';

        $saved = $this->checked($client, 'settings.set', ['set' => ['parallelToolCalls' => false, 'turnIdleTimeoutSeconds' => 90]]);
        self::assertSame(['parallelToolCalls', 'turnIdleTimeoutSeconds'], $saved['changed']);
        self::assertSame($path, $saved['written']);
        self::assertSame(['parallelToolCalls' => 'next-turn', 'turnIdleTimeoutSeconds' => 'next-turn'], $saved['appliesByKey']);
        self::assertSame(['parallelToolCalls' => false, 'turnIdleTimeoutSeconds' => 90], \json_decode((string) \file_get_contents($path), true));

        $refused = $client->request('settings.set', ['set' => ['parallelToolCalls' => true, 'statusLine' => ['command' => 'x']]]);
        self::assertSame(ErrorCode::Forbidden->value, $refused['error']['code']);
        self::assertSame(['statusLine'], $refused['error']['data']['keys']);
        $invalid = $client->request('settings.set', ['set' => ['parallelToolCalls' => true, 'turnIdleTimeoutSeconds' => 'soon']]);
        self::assertSame('setting_refused', $invalid['error']['data']['kind']);
        self::assertArrayHasKey('turnIdleTimeoutSeconds', $invalid['error']['data']['refusals']);
        self::assertFalse(\json_decode((string) \file_get_contents($path), true)['parallelToolCalls'], 'a refused change set writes none of itself');

        $reset = $this->checked($client, 'settings.set', ['key' => 'turnIdleTimeoutSeconds', 'reset' => true]);
        self::assertSame('turnIdleTimeoutSeconds', $reset['key']);
        self::assertSame('next-turn', $reset['applies']);
        self::assertSame(['parallelToolCalls' => false], \json_decode((string) \file_get_contents($path), true), 'a reset deletes the key');

        self::assertSame('setting_not_found', $client->request('settings.set', ['key' => 'noSuchKey', 'value' => 1])['error']['data']['kind']);
    }

    public function testReadingIsReadScopeAndOnlyTheSaveHasSideEffects(): void
    {
        $registry = MethodRegistry::new();
        SettingsMethods::register($registry);

        $shape = [];
        foreach ($registry->all() as $spec) {
            $shape[$spec->name] = [$spec->scope, $spec->sideEffects];
        }

        self::assertSame([
            'settings.get' => [Scope::Read, false],
            'settings.preview' => [Scope::Admin, false],
            'settings.schema' => [Scope::Read, false],
            'settings.set' => [Scope::Admin, true],
        ], $shape);
    }

    private function client(): WireClient
    {
        return $this->fixture->client();
    }

    /**
     * Call $method and assert its answer fits the method's result schema.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function checked(WireClient $client, string $method, array $params = []): array
    {
        $result = $client->call($method, $params);
        $schema = MethodSchemas::result($method);
        self::assertNotNull($schema, $method);
        self::assertSame([], ProtocolSchema::errors($result, $schema, $method), $method . ' answered outside its schema');

        return $result;
    }

    private function setEnv(string $name, string $value): void
    {
        if (!\array_key_exists($name, $this->env)) {
            $this->env[$name] = \getenv($name);
        }
        \putenv($name . '=' . $value);
    }
}
