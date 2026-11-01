<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Media\Voice;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Config\LayeredSettings;
use SugarCraft\Code\Config\Settings\Definitions\MediaSettings;
use SugarCraft\Code\Config\Settings\SettingDefinition;
use SugarCraft\Code\Media\Voice\VoiceConfig;

/**
 * C-TUI-6.l config laws: the adopted ruling says voice stays OFF until a
 * USER-tier voice.baseUrl exists — so the off-shape, the URL join that
 * keeps a versioned base path, and the clamp bounds are the whole class's
 * behavior, and the tier roster is the security boundary the docblocks
 * promise. No env binding exists on these keys by law (an EnvRosterDrift
 * red would follow a hatch added here); this test pins its absence too.
 */
final class VoiceConfigTest extends TestCase
{
    public function testVoiceStaysOffUntilABaseUrlExists(): void
    {
        self::assertFalse(VoiceConfig::fromSettings([])->enabled());
        self::assertFalse(VoiceConfig::fromSettings(['voice.baseUrl' => '   '])->enabled(),
            'whitespace is not an endpoint');
        self::assertFalse(VoiceConfig::fromSettings(['voice.baseUrl' => 'https://x.invalid'])->apiKey !== '',
            'an unset key is empty, not a default credential');
        self::assertTrue(VoiceConfig::fromSettings(['voice.baseUrl' => 'https://x.invalid'])->enabled());
        self::assertFalse(VoiceConfig::off()->enabled());
    }

    public function testTheTranscriptionsUrlKeepsAVersionedBasePath(): void
    {
        $withSlash = VoiceConfig::fromSettings(['voice.baseUrl' => 'https://api.example.com/v1/']);
        $bare = VoiceConfig::fromSettings(['voice.baseUrl' => 'https://api.example.com/v1']);

        self::assertSame('https://api.example.com/v1/audio/transcriptions', $withSlash->transcriptionsUrl());
        self::assertSame('https://api.example.com/v1/audio/transcriptions', $bare->transcriptionsUrl(),
            'the join is rtrim(base) + path, NOT base_uri + a leading-slash path — that shape drops /v1');
    }

    public function testTheRecordingCapClampsToTheDeclaredRangeAndAcceptsNumericStrings(): void
    {
        self::assertSame(120, VoiceConfig::fromSettings([])->maxSeconds, 'the default cap is the design number');
        self::assertSame(600, VoiceConfig::fromSettings(['voice.maxSeconds' => '9999'])->maxSeconds);
        self::assertSame(600, VoiceConfig::fromSettings(['voice.maxSeconds' => 601])->maxSeconds);
        self::assertSame(5, VoiceConfig::fromSettings(['voice.maxSeconds' => '1'])->maxSeconds);
        self::assertSame(45, VoiceConfig::fromSettings(['voice.maxSeconds' => 45])->maxSeconds);
        self::assertSame(120, VoiceConfig::fromSettings(['voice.maxSeconds' => 'not-a-number'])->maxSeconds,
            'nonsense answers the default rather than zero — a zero-second cap is a broken microphone');
        self::assertSame(VoiceConfig::MIN_SECONDS, 5);
        self::assertSame(VoiceConfig::MAX_SECONDS, 600);
    }

    public function testTheModelDefaultsAndTheLanguageStaysNullable(): void
    {
        $plain = VoiceConfig::fromSettings(['voice.baseUrl' => 'https://x.invalid']);
        self::assertSame('whisper-1', $plain->model);
        self::assertNull($plain->language, 'unset language means the endpoint detects it');

        $tuned = VoiceConfig::fromSettings([
            'voice.baseUrl' => 'https://x.invalid',
            'voice.model' => ' whisper-large-v3 ',
            'voice.language' => 'de',
        ]);
        self::assertSame('whisper-large-v3', $tuned->model);
        self::assertSame('de', $tuned->language);
    }

    public function testTheWholeVoiceFamilyIsUserTierOnly(): void
    {
        $userTier = LayeredSettings::userTierOnlyKeys();

        foreach (self::voiceKeys() as $key) {
            self::assertContains($key, $userTier,
                'voice keys ride userTierOnlyKeys: a project checkout can never point the microphone at itself');
            $definition = self::voiceDefinition($key);
            self::assertTrue($definition->layered);
            self::assertFalse($definition->projectSettable,
                $key . ' must not be project-settable');
            self::assertNull($definition->envVar, $key . ' has no environment hatch to widen the tier door');
        }
    }

    public function testNoVoiceCodeReadsAnEnvironmentVariable(): void
    {
        $source = (string) \file_get_contents(\dirname(__DIR__, 3) . '/src/Media/Voice/VoiceConfig.php');

        self::assertStringNotContainsString('getenv', $source,
            'voice has no env hatch by design; the endpoint tier and the key expansion live behind the settings file');
    }

    /** @return list<string> */
    private static function voiceKeys(): array
    {
        return ['voice.baseUrl', 'voice.apiKey', 'voice.model', 'voice.language', 'voice.maxSeconds'];
    }

    private static function voiceDefinition(string $key): SettingDefinition
    {
        $wanted = null;

        foreach (MediaSettings::definitions() as $definition) {
            if ($definition->key === $key) {
                $wanted = $definition;
            }
        }

        self::assertNotNull($wanted, $key . ' must exist as a media setting');

        return $wanted;
    }
}
