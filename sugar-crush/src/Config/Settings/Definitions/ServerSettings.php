<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings\Definitions;

use SugarCraft\Crush\Config\Settings\RiskClass;
use SugarCraft\Crush\Config\Settings\SettingCategory;
use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Crush\Config\Settings\SettingDefinitionSet;
use SugarCraft\Crush\Config\Settings\SettingType;
use SugarCraft\Crush\Config\Settings\UiEditability;
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
 * Only the keys `serve` reads today are here; the session caps
 * (`server.maxConcurrentTurns`, `server.maxOpenSessions`, …) join when the
 * protocol that enforces them does.
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
                ->withLabel('Server bind address')
                ->withHelp('The address `serve` binds. Anything but loopback also needs --allow-remote at launch.')
                ->withReaderSymbol(ServerConfig::class . '::resolve')
                ->withReadBy('`Cli\Serve::config()` → `ServerConfig::resolve()`'),
            SettingDefinition::new('server.port', SettingType::Int, ServerConfig::DEFAULT_PORT)
                ->withCategory(SettingCategory::Server)
                ->withRiskClass(RiskClass::Security)
                ->withEnvVar('SUGARCRUSH_SERVER_PORT')
                ->withRange(0, 65535)
                ->withLabel('Server port')
                ->withHelp('The port `serve` binds; 0 picks a free one.')
                ->withReaderSymbol(ServerConfig::class . '::resolve')
                ->withReadBy('`Cli\Serve::config()` → `ServerConfig::resolve()`'),
            SettingDefinition::new('server.allowedOrigins', SettingType::StringList, [])
                ->withCategory(SettingCategory::Server)
                ->withRiskClass(RiskClass::Security)
                ->withEnvVar('SUGARCRUSH_SERVER_ALLOWED_ORIGINS')
                ->withUi(UiEditability::List)
                ->withLabel('Server allowed origins')
                ->withHelp('Extra browser origins (http(s)://host[:port]) the server accepts beside its own.')
                ->withReaderSymbol(ServerConfig::class . '::resolve')
                ->withReadBy('`Cli\Serve::config()` → `ServerConfig::resolve()`'),
            SettingDefinition::new('server.allowedHosts', SettingType::StringList, [])
                ->withCategory(SettingCategory::Server)
                ->withRiskClass(RiskClass::Security)
                ->withUi(UiEditability::List)
                ->withLabel('Server allowed hosts')
                ->withHelp('Host names (or host:port) the server answers to beside the loopback names, e.g. a reverse proxy\'s.')
                ->withReaderSymbol(ServerConfig::class . '::resolve')
                ->withReadBy('`Cli\Serve::config()` → `ServerConfig::resolve()`'),
            SettingDefinition::new('server.trustedProxies', SettingType::StringList, [])
                ->withCategory(SettingCategory::Server)
                ->withRiskClass(RiskClass::Security)
                ->withUi(UiEditability::List)
                ->withLabel('Server trusted proxies')
                ->withHelp('IPs or CIDRs whose X-Forwarded-For / X-Forwarded-Proto the server believes.')
                ->withReaderSymbol(ServerConfig::class . '::resolve')
                ->withReadBy('`Cli\Serve::config()` → `ServerConfig::resolve()`'),
            SettingDefinition::new('server.allowBypass', SettingType::Bool, false)
                ->withCategory(SettingCategory::Server)
                ->withRiskClass(RiskClass::Security)
                ->withLabel('Server allows bypass modes')
                ->withHelp('Whether server sessions may run in bypass-permissions or dont-ask (same as --allow-bypass).')
                ->withReaderSymbol(ServerConfig::class . '::resolve')
                ->withReadBy('`Cli\Serve::config()` → `ServerConfig::resolve()`'),
        ];
    }
}
