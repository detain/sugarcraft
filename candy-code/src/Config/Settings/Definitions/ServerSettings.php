<?php

declare(strict_types=1);

namespace SugarCraft\Code\Config\Settings\Definitions;

use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\Settings\RiskClass;
use SugarCraft\Code\Config\Settings\SettingCategory;
use SugarCraft\Code\Config\Settings\SettingDefinition;
use SugarCraft\Code\Config\Settings\SettingDefinitionSet;
use SugarCraft\Code\Config\Settings\SettingType;
use SugarCraft\Code\Config\Settings\UiEditability;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Server\ServerConfig;

/**
 * The "Server" category's keys: what `candy-code serve` binds and admits
 * (Appendix O §4.7, §8.3, §8.4). One file per category (DH-KEYS);
 * {@see \SugarCraft\Code\Config\Settings\SettingsSchema} collects every set.
 *
 * ALL USER-CONFIG ONLY — never layered, never project-settable — and every one
 * is classed Security: a cloned repository must not be able to open a port,
 * widen the origins a browser may drive the agent from, or hand clients the
 * bypass modes. A non-loopback `server.host` still needs `--allow-remote` on
 * the command line each launch.
 *
 * The session caps, the answer timeout and the drain time are the
 * `candy-code.v1` protocol's (roadmap O-3b): how many sessions a server keeps
 * open, how many turns run at once, how long a permission question waits, and
 * how long a stopping server waits for the turns still running.
 */
final class ServerSettings implements SettingDefinitionSet
{
    public static function category(): SettingCategory
    {
        return SettingCategory::Server;
    }

    public static function definitions(): array
    {
        return [
            SettingDefinition::new('server.host', SettingType::String, ServerConfig::DEFAULT_HOST)
                ->withCategory(SettingCategory::Server)
                ->withRiskClass(RiskClass::Security)
                ->withEnvVar('CANDY_CODE_SERVER_HOST')
                ->withLabel(Lang::t('settings.server.host.label'))
                ->withHelp(Lang::t('settings.server.host.help'))
                ->withReaderSymbol(ServerConfig::class . '::resolve')
                ->withReadBy('`Cli\Serve::config()` → `ServerConfig::resolve()`'),
            SettingDefinition::new('server.port', SettingType::Int, ServerConfig::DEFAULT_PORT)
                ->withCategory(SettingCategory::Server)
                ->withRiskClass(RiskClass::Security)
                ->withEnvVar('CANDY_CODE_SERVER_PORT')
                ->withRange(0, 65535)
                ->withLabel(Lang::t('settings.server.port.label'))
                ->withHelp(Lang::t('settings.server.port.help'))
                ->withReaderSymbol(ServerConfig::class . '::resolve')
                ->withReadBy('`Cli\Serve::config()` → `ServerConfig::resolve()`'),
            SettingDefinition::new('server.allowedOrigins', SettingType::StringList, [])
                ->withCategory(SettingCategory::Server)
                ->withRiskClass(RiskClass::Security)
                ->withEnvVar('CANDY_CODE_SERVER_ALLOWED_ORIGINS')
                ->withUi(UiEditability::List)
                ->withLabel(Lang::t('settings.server.allowedOrigins.label'))
                ->withHelp(Lang::t('settings.server.allowedOrigins.help'))
                ->withReaderSymbol(ServerConfig::class . '::resolve')
                ->withReadBy('`Cli\Serve::config()` → `ServerConfig::resolve()`'),
            SettingDefinition::new('server.allowedHosts', SettingType::StringList, [])
                ->withCategory(SettingCategory::Server)
                ->withRiskClass(RiskClass::Security)
                ->withEnvVar('CANDY_CODE_SERVER_ALLOWED_HOSTS')
                ->withUi(UiEditability::List)
                ->withLabel(Lang::t('settings.server.allowedHosts.label'))
                ->withHelp(Lang::t('settings.server.allowedHosts.help'))
                ->withReaderSymbol(ServerConfig::class . '::resolve')
                ->withReadBy('`Cli\Serve::config()` → `ServerConfig::resolve()`'),
            SettingDefinition::new('server.allowedIps', SettingType::StringList, [])
                ->withCategory(SettingCategory::Server)
                ->withRiskClass(RiskClass::Security)
                ->withEnvVar('CANDY_CODE_SERVER_ALLOWED_IPS')
                ->withUi(UiEditability::List)
                ->withLabel(Lang::t('settings.server.allowedIps.label'))
                ->withHelp(Lang::t('settings.server.allowedIps.help'))
                ->withReaderSymbol(ServerConfig::class . '::resolve')
                ->withReadBy('`Cli\Serve::config()` → `ServerConfig::resolve()`'),
            SettingDefinition::new('server.trustedProxies', SettingType::StringList, [])
                ->withCategory(SettingCategory::Server)
                ->withRiskClass(RiskClass::Security)
                ->withUi(UiEditability::List)
                ->withLabel(Lang::t('settings.server.trustedProxies.label'))
                ->withHelp(Lang::t('settings.server.trustedProxies.help'))
                ->withReaderSymbol(ServerConfig::class . '::resolve')
                ->withReadBy('`Cli\Serve::config()` → `ServerConfig::resolve()`'),
            SettingDefinition::new('server.maxOpenSessions', SettingType::Int, ServerConfig::DEFAULT_MAX_OPEN_SESSIONS)
                ->withCategory(SettingCategory::Server)
                ->withRiskClass(RiskClass::Security)
                ->withRange(1, null)
                ->withLabel(Lang::t('settings.server.maxOpenSessions.label'))
                ->withHelp(Lang::t('settings.server.maxOpenSessions.help'))
                ->withReaderSymbol(ServerConfig::class . '::resolve')
                ->withReadBy('`Cli\Serve::config()` → `ServerConfig::resolve()`'),
            SettingDefinition::new('server.maxConcurrentTurns', SettingType::Int, ServerConfig::DEFAULT_MAX_CONCURRENT_TURNS)
                ->withCategory(SettingCategory::Server)
                ->withRiskClass(RiskClass::Security)
                ->withRange(1, null)
                ->withLabel(Lang::t('settings.server.maxConcurrentTurns.label'))
                ->withHelp(Lang::t('settings.server.maxConcurrentTurns.help'))
                ->withReaderSymbol(ServerConfig::class . '::resolve')
                ->withReadBy('`Cli\Serve::config()` → `ServerConfig::resolve()`'),
            SettingDefinition::new('server.askTimeoutSeconds', SettingType::Float, ServerConfig::DEFAULT_ASK_TIMEOUT_SECONDS)
                ->withCategory(SettingCategory::Server)
                ->withRiskClass(RiskClass::Security)
                ->withRange(0, null)
                ->withLabel(Lang::t('settings.server.askTimeoutSeconds.label'))
                ->withHelp(Lang::t('settings.server.askTimeoutSeconds.help'))
                ->withReaderSymbol(ServerConfig::class . '::resolve')
                ->withReadBy('`Cli\Serve::config()` → `ServerConfig::resolve()`'),
            SettingDefinition::new('server.drainSeconds', SettingType::Float, ServerConfig::DEFAULT_DRAIN_SECONDS)
                ->withCategory(SettingCategory::Server)
                ->withRiskClass(RiskClass::Security)
                ->withRange(0, null)
                ->withLabel(Lang::t('settings.server.drainSeconds.label'))
                ->withHelp(Lang::t('settings.server.drainSeconds.help'))
                ->withReaderSymbol(ServerConfig::class . '::resolve')
                ->withReadBy('`Cli\Serve::config()` → `ServerConfig::resolve()`'),
            SettingDefinition::new('server.allowBypass', SettingType::Bool, false)
                ->withCategory(SettingCategory::Server)
                ->withRiskClass(RiskClass::Security)
                ->withLabel(Lang::t('settings.server.allowBypass.label'))
                ->withHelp(Lang::t('settings.server.allowBypass.help'))
                ->withReaderSymbol(ServerConfig::class . '::resolve')
                ->withReadBy('`Cli\Serve::config()` → `ServerConfig::resolve()`'),
            SettingDefinition::new('server.dirBrowse', SettingType::Bool, false)
                ->withCategory(SettingCategory::Server)
                ->withRiskClass(RiskClass::Security)
                ->withLabel(Lang::t('settings.server.dirBrowse.label'))
                ->withHelp(Lang::t('settings.server.dirBrowse.help'))
                ->withReaderSymbol(ServerConfig::class . '::resolve')
                ->withReadBy('`Cli\Serve::config()` → `ServerConfig::resolve()`'),
            SettingDefinition::new('server.browseRoot', SettingType::Path)
                ->withCategory(SettingCategory::Server)
                ->withRiskClass(RiskClass::Security)
                ->withLabel(Lang::t('settings.server.browseRoot.label'))
                ->withHelp(Lang::t('settings.server.browseRoot.help'))
                ->withReaderSymbol(ServerConfig::class . '::resolve')
                ->withReadBy('`Cli\Serve::config()` → `ServerConfig::resolve()`'),
            // AUT-6: top-level name (the key maps hooks, not server knobs),
            // Server category (only `serve` reads it), user-config only like
            // every sibling above — a cloned repository must not be able to
            // mount an inbound door.
            SettingDefinition::new('webhooks', SettingType::Map, [])
                ->withCategory(SettingCategory::Server)
                ->withRiskClass(RiskClass::Security)
                ->withUi(UiEditability::Complex)
                ->withLabel(Lang::t('settings.webhooks.label'))
                ->withHelp(Lang::t('settings.webhooks.help'))
                ->withReaderSymbol(ServerConfig::class . '::webhooksValue')
                ->withReadBy('`Cli\Serve::config()` → `ServerConfig::resolve()`'),
            // C-AGT-5.f: the OUTBOUND sibling. Same top-level naming as the
            // inbound hook map above (it names endpoints, not server knobs)
            // and the same user-config-only law — a cloned repository must
            // not be able to point the machine's events at attacker-chosen
            // URLs. Read once at launch by `Bootstrap::workspace()`, which
            // parses via `Automation\OutboundWebhookConfig::parse()` and
            // refuses the launch (exit 2) on any malformed endpoint, so a
            // typo costs a restart message and never a silent no-webhooks.
            SettingDefinition::new('webhooksOut', SettingType::Map, [])
                ->withCategory(SettingCategory::Server)
                ->withRiskClass(RiskClass::Security)
                ->withUi(UiEditability::Complex)
                ->withLabel(Lang::t('settings.webhooksOut.label'))
                ->withHelp(Lang::t('settings.webhooksOut.help'))
                ->withReaderSymbol(Bootstrap::class . '::outboundWebhookEndpoints')
                ->withReadBy('`Cli\Bootstrap::workspace()` → `Automation\WebhookDispatcher` service'),
        ];
    }
}
