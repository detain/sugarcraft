<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings\Definitions;

use SugarCraft\Crush\Config\Settings\ApplyMode;
use SugarCraft\Crush\Config\Settings\RiskClass;
use SugarCraft\Crush\Config\Settings\SettingCategory;
use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Crush\Config\Settings\SettingDefinitionSet;
use SugarCraft\Crush\Config\Settings\SettingsResolver;
use SugarCraft\Crush\Config\Settings\SettingType;
use SugarCraft\Crush\Config\Settings\UiEditability;
use SugarCraft\Crush\Lang;

/**
 * The "Media" category's keys (plan_crush_media W1.8). One file per category
 * so a step adding a key edits only its category's file (DH-KEYS);
 * {@see \SugarCraft\Crush\Config\Settings\SettingsSchema} collects every set.
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
 * THE RENDER-MODE VOCABULARY (`ui.imageRenderMode`) is `candy-mosaic`'s own
 * accepted word set plus `auto`, quoted from
 * {@see \SugarCraft\Mosaic\Mosaic::fromModeString()} (match arms, that file's
 * :600-612) — the plan's Q-7 ruling keeps the aliases (`half`/`ansi`/`quarter`)
 * alongside the canonical names rather than inventing a settings-only spelling.
 * {@see \SugarCraft\Crush\Tests\Config\Settings\MediaSettingsTest} re-derives
 * the list against the live `fromModeString()` oracle, so a mosaic word added,
 * renamed or dropped reddens the pin instead of quietly desyncing the panel.
 */
final class MediaSettings implements SettingDefinitionSet
{
    /**
     * `ui.imageRenderMode`'s vocabulary: `auto` plus every word
     * {@see \SugarCraft\Mosaic\Mosaic::fromModeString()} answers non-null for,
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
                ->withEnvVar('SUGARCRUSH_SD_BASE_URL')
                ->withDefaultText('unset: no default; media generation refuses until one is set')
                ->withLabel(Lang::t('settings.sd.baseUrl.label'))
                ->withHelp('HTTP base URL of an AUTOMATIC1111-compatible Stable Diffusion webui (`http://127.0.0.1:7860`); prompts, images and the API key all egress there.')
                ->withReaderSymbol(SettingsResolver::class . '::resolve')
                ->withReadBy('`SettingsResolver::resolve()` at the launch resolve; the media pipeline (W1.9+) reads it per request'),
            SettingDefinition::new('sd.apiKey', SettingType::Secret)
                ->withCategory(SettingCategory::Media)
                ->withRiskClass(RiskClass::Security)
                ->withLayered()
                ->withDefaultText('${VAR} honored: `${SD_API_KEY}` reads the env var, and an unset one resolves to empty (no key sent)')
                ->withLabel(Lang::t('settings.sd.apiKey.label'))
                ->withHelp('Bearer key sent to the Stable Diffusion server, if it demands one; prefer the `${VAR}` placeholder form over a literal.')
                ->withReaderSymbol(SettingsResolver::class . '::resolve')
                ->withReadBy('`SettingsResolver::resolve()` at the launch resolve; the media pipeline (W1.9+) sends it per request'),
            SettingDefinition::new('sd.timeoutSeconds', SettingType::Int, 300)
                ->withCategory(SettingCategory::Media)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(1, 3600)
                ->withLabel(Lang::t('settings.sd.timeoutSeconds.label'))
                ->withHelp('Seconds one generation request may run before it is abandoned; diffusion on a weak GPU routinely takes minutes.')
                ->withReaderSymbol(SettingsResolver::class . '::resolve')
                ->withReadBy('`SettingsResolver::resolve()` at the launch resolve; the media pipeline (W1.9+) bounds each request with it'),
            SettingDefinition::new('sd.defaultModel', SettingType::String)
                ->withCategory(SettingCategory::Media)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withDefaultText('unset: the model the server already has loaded answers')
                ->withLabel(Lang::t('settings.sd.defaultModel.label'))
                ->withHelp('Model id generation requests start with; the server keeps the pick to itself when unset.')
                ->withReaderSymbol(SettingsResolver::class . '::resolve')
                ->withReadBy('`SettingsResolver::resolve()` at the launch resolve; the media form (W1.9+) preselects it'),
            // Cosmetic: the word picks how a finished image is PAINTED, never
            // what is requested or spent. `auto` asks the terminal.
            SettingDefinition::new('ui.imageRenderMode', SettingType::Enum, 'auto')
                ->withCategory(SettingCategory::Media)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withEnvVar('SUGARCRUSH_MEDIA_RENDER_MODE')
                ->withEnumValues(self::RENDER_MODES)
                ->withLabel(Lang::t('settings.ui.imageRenderMode.label'))
                ->withHelp('How generated images are painted in the terminal; `auto` picks the best protocol the terminal reports.')
                ->withReaderSymbol(SettingsResolver::class . '::resolve')
                ->withReadBy('`SettingsResolver::resolve()` at the launch resolve; the painter (W2.2) picks the mode per frame'),
            SettingDefinition::new('sd.presets', SettingType::Map, [])
                ->withCategory(SettingCategory::Media)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withUi(UiEditability::Complex)
                ->withDefaultText('{}: the media form offers only its built-in defaults')
                ->withLabel(Lang::t('settings.sd.presets.label'))
                ->withHelp('Named parameter bundles the media form offers; the shape is the media pipeline\'s own, opaque to the settings layer.')
                ->withReaderSymbol(SettingsResolver::class . '::resolve')
                ->withReadBy('`SettingsResolver::resolve()` at the launch resolve; the media pipeline (W1.9+) names the presets'),
            SettingDefinition::new('ui.mediaDisplayOverrides', SettingType::Map, [])
                ->withCategory(SettingCategory::Media)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withUi(UiEditability::Complex)
                ->withDefaultText('{}: every render mode displays at its built-in size')
                ->withLabel(Lang::t('settings.ui.mediaDisplayOverrides.label'))
                ->withHelp('Per-render-mode display tweaks (size caps, fallbacks); keys are the render-mode words, the values the display knobs.')
                ->withReaderSymbol(SettingsResolver::class . '::resolve')
                ->withReadBy('`SettingsResolver::resolve()` at the launch resolve; the painter (W2.2) applies them'),
            SettingDefinition::new('sd.savePattern', SettingType::String, '[date]-[time]-[seed]-[number]')
                ->withCategory(SettingCategory::Media)
                ->withRiskClass(RiskClass::Cosmetic)
                ->withLayered()
                ->withLabel(Lang::t('settings.sd.savePattern.label'))
                ->withHelp('File name template for saved media; the `[date]`, `[time]`, `[seed]` and `[number]` tokens fill at save time.')
                ->withReaderSymbol(SettingsResolver::class . '::resolve')
                ->withReadBy('`SettingsResolver::resolve()` at the launch resolve; the save path (W1.9+) names files with it'),
        ];
    }
}
