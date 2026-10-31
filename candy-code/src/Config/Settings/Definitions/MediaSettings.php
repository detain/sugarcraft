<?php

declare(strict_types=1);

namespace SugarCraft\Code\Config\Settings\Definitions;

use SugarCraft\Code\Config\Settings\ApplyMode;
use SugarCraft\Code\Config\Settings\RiskClass;
use SugarCraft\Code\Config\Settings\SettingCategory;
use SugarCraft\Code\Config\Settings\SettingDefinition;
use SugarCraft\Code\Config\Settings\SettingDefinitionSet;
use SugarCraft\Code\Config\Settings\SettingsResolver;
use SugarCraft\Code\Config\Settings\SettingType;
use SugarCraft\Code\Config\Settings\UiEditability;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Media\Voice\VoiceConfig;

/**
 * The "Media" category's keys (plan_crush_media W1.8). One file per category
 * so a step adding a key edits only its category's file (DH-KEYS);
 * {@see \SugarCraft\Code\Config\Settings\SettingsSchema} collects every set.
 *
 * THE STABLE-DIFFUSION FAMILY. Every row is user-tier only: `sd.baseUrl` and
 * `sd.apiKey` decide where prompts and the bearer credential EGRESS to, which
 * is exactly the direction a checked-out repository must not steer; the rest
 * hang off that endpoint choice. The rows are wiring, not yet behaviour — the
 * consuming code lands with the media pipeline itself (W1.9+), so each names
 * the launch resolve ({@see SettingsResolver::resolve()}) as its reader:
 * today that is what actually reads the row, env layer included; a media
 * reader replacing it is a later wave's edit, not this file's claim.
 *
 * THE RENDER-MODE VOCABULARY (`ui.imageRenderMode`) is `sugar-image`'s own
 * accepted word set plus `auto`, quoted from
 * {@see \SugarCraft\Image\Mosaic::fromModeString()} (match arms, that file's
 * :600-612) — the plan's Q-7 ruling keeps the aliases (`half`/`ansi`/`quarter`)
 * alongside the canonical names rather than inventing a settings-only spelling.
 * {@see \SugarCraft\Code\Tests\Config\Settings\MediaSettingsTest} re-derives
 * the list against the live `fromModeString()` oracle, so a mosaic word added,
 * renamed or dropped reddens the pin instead of quietly desyncing the panel.
 */
final class MediaSettings implements SettingDefinitionSet
{
    /**
     * `ui.imageRenderMode`'s vocabulary: `auto` plus every word
     * {@see \SugarCraft\Image\Mosaic::fromModeString()} answers non-null for,
     * spelled as that match spells them (already lowercase — the comparator
     * trims and lowercases on its side, the house EnumValidator does not).
     *
     * @var list<string>
     */
    public const RENDER_MODES = [
        'auto',
        'sixel',
        'kitty',
        'iterm2',
        'halfblock',
        'half',
        'ansi',
        'quarterblock',
        'quarter',
        'ascii',
        'ansi256',
        'truecolor',
        'chafa',
        'symbols',
    ];

    public static function category(): SettingCategory
    {
        return SettingCategory::Media;
    }

    /** @return list<SettingDefinition> */
    public static function definitions(): array
    {
        return [
            // Egress, so user tier only: the server receives every prompt and
            // every reference image. No default host, by the WebSearch
            // endpoint's design (audit F-W3(b)) — an unset URL refuses, it
            // never silently dials somewhere.
            SettingDefinition::new('sd.baseUrl', SettingType::Url)
                ->withCategory(SettingCategory::Media)
                ->withRiskClass(RiskClass::Egress)
                ->withLayered()
                ->withEnvVar('CANDY_CODE_SD_BASE_URL')
                ->withDefaultText('unset: no default; media generation refuses until one is set')
                ->withLabel(Lang::t('settings.sd.baseUrl.label'))
                ->withHelp(Lang::t('settings.sd.baseUrl.help'))
                ->withReaderSymbol(SettingsResolver::class . '::resolve')
                ->withReadBy('`SettingsResolver::resolve()` at the launch resolve; the media pipeline reads it per request'),
            SettingDefinition::new('sd.apiKey', SettingType::Secret)
                ->withCategory(SettingCategory::Media)
                ->withRiskClass(RiskClass::Security)
                ->withLayered()
                ->withDefaultText('${VAR} honored: `${SD_API_KEY}` reads the env var, and an unset one resolves to empty (no key sent)')
                ->withLabel(Lang::t('settings.sd.apiKey.label'))
                ->withHelp(Lang::t('settings.sd.apiKey.help'))
                ->withReaderSymbol(SettingsResolver::class . '::resolve')
                ->withReadBy('`SettingsResolver::resolve()` at the launch resolve; the media pipeline sends it per request'),
            SettingDefinition::new('sd.timeoutSeconds', SettingType::Int, 300)
                ->withCategory(SettingCategory::Media)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(1, 3600)
                ->withLabel(Lang::t('settings.sd.timeoutSeconds.label'))
                ->withHelp(Lang::t('settings.sd.timeoutSeconds.help'))
                ->withReaderSymbol(SettingsResolver::class . '::resolve')
                ->withReadBy('`SettingsResolver::resolve()` at the launch resolve; the media pipeline bounds each request with it'),
            SettingDefinition::new('sd.defaultModel', SettingType::String)
                ->withCategory(SettingCategory::Media)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withDefaultText('unset: the model the server already has loaded answers')
                ->withLabel(Lang::t('settings.sd.defaultModel.label'))
                ->withHelp(Lang::t('settings.sd.defaultModel.help'))
                ->withReaderSymbol(SettingsResolver::class . '::resolve')
                ->withReadBy('`SettingsResolver::resolve()` at the launch resolve; the media form preselects it'),
            // Cosmetic: the word picks how a finished image is PAINTED, never
            // what is requested or spent. `auto` asks the terminal.
            SettingDefinition::new('ui.imageRenderMode', SettingType::Enum, 'auto')
                ->withCategory(SettingCategory::Media)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withEnvVar('CANDY_CODE_MEDIA_RENDER_MODE')
                ->withEnumValues(self::RENDER_MODES)
                ->withLabel(Lang::t('settings.ui.imageRenderMode.label'))
                ->withHelp(Lang::t('settings.ui.imageRenderMode.help'))
                ->withReaderSymbol(SettingsResolver::class . '::resolve')
                ->withReadBy('`SettingsResolver::resolve()` at the launch resolve; the painter picks the mode per frame'),
            SettingDefinition::new('sd.presets', SettingType::Map, [])
                ->withCategory(SettingCategory::Media)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withUi(UiEditability::Complex)
                ->withDefaultText('{}: the media form offers only its built-in defaults')
                ->withLabel(Lang::t('settings.sd.presets.label'))
                ->withHelp(Lang::t('settings.sd.presets.help'))
                ->withReaderSymbol(SettingsResolver::class . '::resolve')
                ->withReadBy('`SettingsResolver::resolve()` at the launch resolve; the media pipeline names the presets'),
            SettingDefinition::new('ui.mediaDisplayOverrides', SettingType::Map, [])
                ->withCategory(SettingCategory::Media)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withUi(UiEditability::Complex)
                ->withDefaultText('{}: every render mode displays at its built-in size')
                ->withLabel(Lang::t('settings.ui.mediaDisplayOverrides.label'))
                ->withHelp(Lang::t('settings.ui.mediaDisplayOverrides.help'))
                ->withReaderSymbol(SettingsResolver::class . '::resolve')
                ->withReadBy('`SettingsResolver::resolve()` at the launch resolve; the painter applies them'),
            SettingDefinition::new('sd.savePattern', SettingType::String, '[date]-[time]-[seed]-[number]')
                ->withCategory(SettingCategory::Media)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withLabel(Lang::t('settings.sd.savePattern.label'))
                ->withHelp(Lang::t('settings.sd.savePattern.help'))
                ->withReaderSymbol(SettingsResolver::class . '::resolve')
                ->withReadBy('`SettingsResolver::resolve()` at the launch resolve; the save path names files with it'),
            // THE VOICE FAMILY (C-TUI-6.l, GAP-MOD-23). Same egress posture as
            // `sd.baseUrl`, same user-tier-only law: the recorded take leaves
            // the machine ONLY toward `voice.baseUrl`, and the ruling answered
            // 2026-10-28 keeps the whole feature OFF while that row is unset —
            // no default endpoint, ever. These rows have no env binding on
            // purpose (see VoiceConfig's docblock); `VoiceConfig::fromSettings`
            // reads the family at arm/transcribe time rather than at launch.
            SettingDefinition::new('voice.baseUrl', SettingType::Url)
                ->withCategory(SettingCategory::Media)
                ->withRiskClass(RiskClass::Egress)
                ->withLayered()
                ->withDefaultText('unset: no default; voice input stays off until one is set')
                ->withLabel(Lang::t('settings.voice.baseUrl.label'))
                ->withHelp(Lang::t('settings.voice.baseUrl.help'))
                ->withReaderSymbol(VoiceConfig::class . '::fromSettings')
                ->withReadBy('`VoiceConfig::fromSettings()` when the recorder arms and when a take is transcribed; the endpoint path appends `/audio/transcriptions` to it'),
            SettingDefinition::new('voice.apiKey', SettingType::Secret)
                ->withCategory(SettingCategory::Media)
                ->withRiskClass(RiskClass::Security)
                ->withLayered()
                ->withDefaultText('${VAR} honored: `${OPENAI_API_KEY}` reads the env var, and an unset one resolves to empty (no key sent)')
                ->withLabel(Lang::t('settings.voice.apiKey.label'))
                ->withHelp(Lang::t('settings.voice.apiKey.help'))
                ->withReaderSymbol(VoiceConfig::class . '::fromSettings')
                ->withReadBy('`VoiceConfig::fromSettings()` at the same two moments; only the transcribe request reads it, and no error path carries it'),
            SettingDefinition::new('voice.model', SettingType::String, 'whisper-1')
                ->withCategory(SettingCategory::Media)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withLabel(Lang::t('settings.voice.model.label'))
                ->withHelp(Lang::t('settings.voice.model.help'))
                ->withReaderSymbol(VoiceConfig::class . '::fromSettings')
                ->withReadBy('`VoiceConfig::fromSettings()` at the same two moments; the model field names the endpoint route'),
            SettingDefinition::new('voice.language', SettingType::String)
                ->withCategory(SettingCategory::Media)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withDefaultText('unset: the endpoint detects the language itself')
                ->withLabel(Lang::t('settings.voice.language.label'))
                ->withHelp(Lang::t('settings.voice.language.help'))
                ->withReaderSymbol(VoiceConfig::class . '::fromSettings')
                ->withReadBy('`VoiceConfig::fromSettings()` at the same two moments; when empty the language field is omitted from the request'),
            SettingDefinition::new('voice.maxSeconds', SettingType::Int, 120)
                ->withCategory(SettingCategory::Media)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withRange(VoiceConfig::MIN_SECONDS, VoiceConfig::MAX_SECONDS)
                ->withLabel(Lang::t('settings.voice.maxSeconds.label'))
                ->withHelp(Lang::t('settings.voice.maxSeconds.help'))
                ->withReaderSymbol(VoiceConfig::class . '::fromSettings')
                ->withReadBy('`VoiceConfig::fromSettings()` when the recorder arms; the cap rides the recorder argument so an orphan stops itself'),
        ];
    }
}
