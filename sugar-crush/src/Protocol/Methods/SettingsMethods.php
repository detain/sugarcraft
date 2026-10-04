<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol\Methods;

use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Config\Settings\ApplyMode;
use SugarCraft\Crush\Config\Settings\ResolvedSetting;
use SugarCraft\Crush\Config\Settings\RiskClass;
use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Crush\Config\Settings\SettingsSchema;
use SugarCraft\Crush\Config\Settings\SettingsTier;
use SugarCraft\Crush\Config\Settings\SettingsWriter;
use SugarCraft\Crush\Config\Settings\SettingType;
use SugarCraft\Crush\Config\Settings\UiEditability;
use SugarCraft\Crush\Protocol\CallContext;
use SugarCraft\Crush\Protocol\ErrorCode;
use SugarCraft\Crush\Protocol\MethodRegistry;
use SugarCraft\Crush\Protocol\MethodSpec;
use SugarCraft\Crush\Protocol\Params;
use SugarCraft\Crush\Protocol\RpcError;
use SugarCraft\Crush\Protocol\Scope;
use SugarCraft\Crush\Support\HomeDirectory;
use SugarCraft\Crush\Tui\Settings\SettingsSources;

/**
 * Settings over the wire (Appendix O §6.10), from the one schema the TUI
 * settings editor and `docs/SETTINGS.md` are built from
 * ({@see SettingsSchema}, Appendix N).
 *
 * READING masks every value a client has no business holding: a `secret`
 * key's value, and any key whose name says it is a credential, travels as
 * `"********"` when set.
 *
 * WRITING IS ALLOWLISTED (§8.5), and the allowlist is the schema's own risk
 * classes rather than a second list that could drift: a key is writable
 * remotely only when it is {@see RiskClass::Cosmetic}, {@see RiskClass::Tuning}
 * or {@see RiskClass::Narrowing}, editable in the settings UI, not a trust
 * grant, not a `server.*` key, and not one a live command owns (`theme` is
 * `/theme`'s, `provider` is `/model`'s — the writer refuses them too). That leaves out everything that runs a command (status
 * line, hooks, MCP), pulls files into prompts, spends money, opens the box
 * (trust lists, permission rules and mode, the server's own binding), or holds
 * a secret. The write goes through the launch's {@see SettingsWriter} — the
 * same door, and the same refusals, as the TUI editor's — and only to the user
 * tier or a trusted project's local file.
 */
final class SettingsMethods
{
    public const MASK = '********';

    private const REMOTE_RISK_CLASSES = [RiskClass::Cosmetic, RiskClass::Tuning, RiskClass::Narrowing];

    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new('settings.schema', Scope::Read, 'Every setting: type, default, help, and whether a client may write it.', self::schema(...)));
        $registry->add(MethodSpec::new('settings.get', Scope::Read, 'Effective values and where each came from, or one tier\'s file; secrets masked.', self::get(...)));
        $registry->add(MethodSpec::new('settings.set', Scope::Admin, 'Write an allowlisted setting to the user tier or a trusted project.', self::set(...), true));
    }

    /** Whether a client may write $definition over the wire. */
    public static function writableRemotely(SettingDefinition $definition): bool
    {
        return \in_array($definition->riskClass, self::REMOTE_RISK_CLASSES, true)
            && !\in_array($definition->ui, [UiEditability::Hidden, UiEditability::ReadOnly], true)
            && $definition->applyMode !== ApplyMode::Frozen
            && !\str_starts_with($definition->key, 'server.')
            && !isset(SettingsWriter::LIVE_COMMAND_KEYS[$definition->key])
            && !\in_array($definition->key, SettingsWriter::trustKeys(), true);
    }

    /** Whether $definition's value is a secret a client never sees. */
    public static function sensitive(SettingDefinition $definition): bool
    {
        return $definition->type === SettingType::Secret
            || \preg_match('/(api.?key|token|secret|password|credential)/i', $definition->key) === 1;
    }

    /** @return array<string, mixed> */
    private static function schema(CallContext $call, Params $params): array
    {
        $items = [];
        foreach (SettingsSchema::all() as $definition) {
            $sensitive = self::sensitive($definition);
            $items[] = \array_filter([
                'key' => $definition->key,
                'type' => $definition->type->value,
                'default' => $sensitive ? null : $definition->default,
                'group' => $definition->category->value,
                'label' => $definition->label,
                'help' => $definition->help !== '' ? $definition->help : null,
                'enum' => $definition->enumValues !== [] ? $definition->enumValues : null,
                'min' => $definition->min,
                'max' => $definition->max,
                'riskClass' => $definition->riskClass->value,
                'applies' => $definition->applyMode->value,
                'projectSettable' => $definition->projectSettable,
                'sensitive' => $sensitive,
                'writableRemotely' => self::writableRemotely($definition),
            ], static fn (mixed $value): bool => $value !== null);
        }

        return ['items' => $items];
    }

    /** @return array<string, mixed> */
    private static function get(CallContext $call, Params $params): array
    {
        $scope = $params->enum('scope', ['effective', 'user', 'project'], 'effective');
        if ($scope !== 'effective') {
            $writer = self::writer($call);
            $tier = $scope === 'user' ? SettingsTier::You : SettingsTier::ProjectLocal;
            if ($writer->tierRefusal($tier) !== null) {
                throw RpcError::of(ErrorCode::NotFound, (string) $writer->tierRefusal($tier), 'tier_unavailable');
            }
            try {
                $values = $writer->current($tier);
            } catch (\RuntimeException $e) {
                throw RpcError::of(ErrorCode::Conflict, $e->getMessage(), 'unreadable');
            }
            $masked = [];
            foreach ($values as $key => $value) {
                $definition = SettingsSchema::byKey((string) $key);
                $masked[(string) $key] = $definition !== null && self::sensitive($definition) && $value !== null && $value !== '' ? self::MASK : $value;
            }

            return ['scope' => $scope, 'values' => $masked === [] ? new \stdClass() : $masked];
        }

        $workspace = $call->server->hub()->workspace();
        $root = $workspace->root;
        $writer = $workspace->service(SettingsWriter::class);
        $home = HomeDirectory::owned();
        $env = [];
        foreach (\getenv() as $name => $value) {
            if (\is_string($name) && \is_string($value)) {
                $env[$name] = $value;
            }
        }
        $sources = SettingsSources::fromLaunch(
            $root,
            // The launch's trust answer, read off its writer (it may write the
            // project's local file only when the project is trusted); unknown
            // without one, and then the project files are not read.
            $root === null || !$writer instanceof SettingsWriter ? null : $writer->targetPath(SettingsTier::ProjectLocal) !== null,
            $home === null ? null : \rtrim($home, '/') . '/' . LayeredSettings::dir(),
            self::userConfigPath(),
            $env,
        );

        $values = [];
        foreach ($sources->resolver->resolveAll() as $key => $resolved) {
            /** @var ResolvedSetting $resolved */
            $definition = SettingsSchema::byKey($key);
            $hidden = $definition !== null && self::sensitive($definition) && $resolved->value !== null && $resolved->value !== '';
            $values[$key] = [
                'value' => $hidden ? self::MASK : $resolved->value,
                'source' => $resolved->source->value,
                'locked' => $resolved->locked,
            ];
        }

        return ['scope' => 'effective', 'values' => $values];
    }

    /** @return array<string, mixed> */
    private static function set(CallContext $call, Params $params): array
    {
        $key = $params->string('key', 128);
        $scope = $params->enum('scope', ['user', 'project'], 'user');
        $definition = SettingsSchema::byKey($key) ?? throw RpcError::notFound(\sprintf('no setting %s', $key), 'setting_not_found');
        if (!self::writableRemotely($definition)) {
            throw RpcError::of(ErrorCode::Forbidden, \sprintf('%s cannot be changed over the wire; edit it in the terminal UI or your config', $key), 'not_writable_remotely');
        }

        $tier = $scope === 'user' ? SettingsTier::You : SettingsTier::ProjectLocal;
        $writer = self::writer($call);
        $unset = $params->bool('reset');
        $value = $params->raw('value');
        $refusal = $unset ? $writer->tierRefusal($tier) : ($writer->tierRefusal($tier) ?? $writer->refusal($tier, $key, $value));
        if ($refusal !== null) {
            throw RpcError::invalidParams($refusal, 'setting_refused');
        }

        try {
            $path = $unset ? $writer->write($tier, [], [$key]) : $writer->write($tier, [$key => $value]);
        } catch (\InvalidArgumentException $e) {
            throw RpcError::invalidParams($e->getMessage(), 'setting_refused');
        } catch (\RuntimeException $e) {
            throw RpcError::of(ErrorCode::Conflict, $e->getMessage(), 'write_failed');
        }

        return ['key' => $key, 'scope' => $scope, 'written' => $path, 'applies' => $definition->applyMode->value];
    }

    /**
     * The launch's settings writer — the one door config.json has, built once
     * per launch by `Bootstrap::workspace()`. A workspace built without it (an
     * embedder) offers no writes over the wire rather than a second door.
     */
    private static function writer(CallContext $call): SettingsWriter
    {
        $writer = $call->server->hub()->workspace()->service(SettingsWriter::class);
        if (!$writer instanceof SettingsWriter) {
            throw RpcError::of(ErrorCode::UnsupportedInServer, 'this server has no settings writer', 'settings_unavailable');
        }

        return $writer;
    }

    private static function userConfigPath(): ?string
    {
        try {
            return Bootstrap::userConfigPath();
        } catch (\Throwable) {
            return null;
        }
    }
}
