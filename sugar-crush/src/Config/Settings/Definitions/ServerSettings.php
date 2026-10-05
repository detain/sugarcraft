<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings\Definitions;

use SugarCraft\Crush\Config\Settings\RiskClass;
use SugarCraft\Crush\Config\Settings\SettingCategory;
use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Crush\Config\Settings\SettingDefinitionSet;
use SugarCraft\Crush\Config\Settings\SettingType;
use SugarCraft\Crush\Config\Settings\UiEditability;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Server\ServerConfig;

/**
 * The "Server" category's keys: what `sugarcrush serve` binds and admits
 * (Appendix O §4.7, §8.3, §8.4). One file per category (DH-KEYS);
 * {@see \SugarCraft\Crush\Config\Settings\SettingsSchema} collects every set.
 *
 * ALL USER-CONFIG ONLY — never layered, never project-settable — and every one
 * is classed Security: a cloned repository must not be able to open a port,
 * widen the origins a browser may drive the agent from, or hand clients the
 * bypass modes. A non-loopback `server.host` still needs `--allow-remote` on
 * the command line each launch.
 *
 * The session caps, the answer timeout and the drain time are the
 * `sugarcrush.v1` protocol's (roadmap O-3b): how many sessions a server keeps
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
                ->withEnvVar('SUGARCRUSH_SERVER_HOST')
                ->withLabel(Lang::t('settings.server.host.label'))
                ->withHelp('The address `serve` binds. Anything but loopback also needs --allow-remote at launch.')
                ->withReaderSymbol(ServerConfig::class . '::resolve')
                ->withReadBy('`Cli\Serve::config()` → `ServerConfig::resolve()`'),
            SettingDefinition::new('server.port', SettingType::Int, ServerConfig::DEFAULT_PORT)
                ->withCategory(SettingCategory::Server)
                ->withRiskClass(RiskClass::Security)
                ->withEnvVar('SUGARCRUSH_SERVER_PORT')
                ->withRange(0, 65535)
                ->withLabel(Lang::t('settings.server.port.label'))
                ->withHelp('The port `serve` binds; 0 picks a free one.')
                ->withReaderSymbol(ServerConfig::class . '::resolve')
                ->withReadBy('`Cli\Serve::config()` → `ServerConfig::resolve()`'),
            SettingDefinition::new('server.allowedOrigins', SettingType::StringList, [])
                ->withCategory(SettingCategory::Server)
                ->withRiskClass(RiskClass::Security)
                ->withEnvVar('SUGARCRUSH_SERVER_ALLOWED_ORIGINS')
                ->withUi(UiEditability::List)
                ->withLabel(Lang::t('settings.server.allowedOrigins.label'))
                ->withHelp('Extra browser origins (http(s)://host[:port]) the server accepts beside its own.')
                ->withReaderSymbol(ServerConfig::class . '::resolve')
                ->withReadBy('`Cli\Serve::config()` → `ServerConfig::resolve()`'),
            SettingDefinition::new('server.allowedHosts', SettingType::StringList, [])
                ->withCategory(SettingCategory::Server)
                ->withRiskClass(RiskClass::Security)
                ->withEnvVar('SUGARCRUSH_SERVER_ALLOWED_HOSTS')
                ->withUi(UiEditability::List)
                ->withLabel(Lang::t('settings.server.allowedHosts.label'))
                ->withHelp(Lang::t('settings.server.allowedHosts.help'))
                ->withReaderSymbol(ServerConfig::class . '::resolve')
                ->withReadBy('`Cli\Serve::config()` → `ServerConfig::resolve()`'),
            SettingDefinition::new('server.allowedIps', SettingType::StringList, [])
                ->withCategory(SettingCategory::Server)
                ->withRiskClass(RiskClass::Security)
                ->withEnvVar('SUGARCRUSH_SERVER_ALLOWED_IPS')
                ->withUi(UiEditability::List)
                ->withLabel('Server allowed client IPs')
                ->withHelp('Client IPs or CIDR ranges allowed to connect beside loopback; anyone else is refused 403 before sign-in. Empty: no address filter.')
                ->withReaderSymbol(ServerConfig::class . '::resolve')
                ->withReadBy('`Cli\Serve::config()` → `ServerConfig::resolve()`'),
            SettingDefinition::new('server.trustedProxies', SettingType::StringList, [])
                ->withCategory(SettingCategory::Server)
                ->withRiskClass(RiskClass::Security)
                ->withUi(UiEditability::List)
                ->withLabel(Lang::t('settings.server.trustedProxies.label'))
                ->withHelp('IPs or CIDRs whose X-Forwarded-For / X-Forwarded-Proto the server believes.')
                ->withReaderSymbol(ServerConfig::class . '::resolve')
                ->withReadBy('`Cli\Serve::config()` → `ServerConfig::resolve()`'),
            SettingDefinition::new('server.maxOpenSessions', SettingType::Int, ServerConfig::DEFAULT_MAX_OPEN_SESSIONS)
                ->withCategory(SettingCategory::Server)
                ->withRiskClass(RiskClass::Security)
                ->withRange(1, null)
                ->withLabel(Lang::t('settings.server.maxOpenSessions.label'))
                ->withHelp('Sessions a server keeps open at once; past it the least recently used idle one is released.')
                ->withReaderSymbol(ServerConfig::class . '::resolve')
                ->withReadBy('`Cli\Serve::config()` → `ServerConfig::resolve()`'),
            SettingDefinition::new('server.maxConcurrentTurns', SettingType::Int, ServerConfig::DEFAULT_MAX_CONCURRENT_TURNS)
                ->withCategory(SettingCategory::Server)
                ->withRiskClass(RiskClass::Security)
                ->withRange(1, null)
                ->withLabel(Lang::t('settings.server.maxConcurrentTurns.label'))
                ->withHelp('Turns a server runs at once across its sessions; one more is refused busy (retryable).')
                ->withReaderSymbol(ServerConfig::class . '::resolve')
                ->withReadBy('`Cli\Serve::config()` → `ServerConfig::resolve()`'),
            SettingDefinition::new('server.askTimeoutSeconds', SettingType::Float, ServerConfig::DEFAULT_ASK_TIMEOUT_SECONDS)
                ->withCategory(SettingCategory::Server)
                ->withRiskClass(RiskClass::Security)
                ->withRange(0, null)
                ->withLabel(Lang::t('settings.server.askTimeoutSeconds.label'))
                ->withHelp('Seconds a permission question waits for a client before it is refused; 0 waits forever.')
                ->withReaderSymbol(ServerConfig::class . '::resolve')
                ->withReadBy('`Cli\Serve::config()` → `ServerConfig::resolve()`'),
            SettingDefinition::new('server.drainSeconds', SettingType::Float, ServerConfig::DEFAULT_DRAIN_SECONDS)
                ->withCategory(SettingCategory::Server)
                ->withRiskClass(RiskClass::Security)
                ->withRange(0, null)
                ->withLabel(Lang::t('settings.server.drainSeconds.label'))
                ->withHelp('Seconds a stopping server waits for running turns before cancelling them; 0 stops at once.')
                ->withReaderSymbol(ServerConfig::class . '::resolve')
                ->withReadBy('`Cli\Serve::config()` → `ServerConfig::resolve()`'),
            SettingDefinition::new('server.allowBypass', SettingType::Bool, false)
                ->withCategory(SettingCategory::Server)
                ->withRiskClass(RiskClass::Security)
                ->withLabel(Lang::t('settings.server.allowBypass.label'))
                ->withHelp('Whether server sessions may run in bypass-permissions or dont-ask (same as --allow-bypass).')
                ->withReaderSymbol(ServerConfig::class . '::resolve')
                ->withReadBy('`Cli\Serve::config()` → `ServerConfig::resolve()`'),
        ];
    }
}
