<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol\Methods;

use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Config\Settings\ApplyMode;
use SugarCraft\Crush\Config\Settings\OptionsProvider;
use SugarCraft\Crush\Config\Settings\ResolvedSetting;
use SugarCraft\Crush\Config\Settings\RiskClass;
use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Crush\Config\Settings\SettingSource;
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
use SugarCraft\Crush\Tui\Settings\SettingsSavePreview;
use SugarCraft\Crush\Tui\Settings\SettingsSources;

/**
 * Settings over the wire (roadmap O-6b; Appendix O §6.10), from the one
 * schema the TUI settings editor and `docs/SETTINGS.md` are built from
 * ({@see SettingsSchema}, Appendix N) — so the web settings form is generated
 * from the same rows as the terminal editor, never from a second list.
 *
 * FOUR METHODS, the editor's four moves:
 *  - `settings.schema` — every row (type, default, choices, help, apply mode,
 *    env var / flag that can lock it, whether a client may write it and why
 *    not) plus the tiers a save can target and whether each is writable;
 *  - `settings.get` — the effective value of every key with its provenance
 *    (which layer won, from which file, which layers it shadows, and the
 *    env/flag lock), plus the files behind them; or one tier's file;
 *  - `settings.preview` — what a save WOULD do, writing nothing: the target
 *    file, a unified diff of its JSON, when each change applies, what blocks
 *    it, and the precedence advisories the TUI preview shows;
 *  - `settings.set` — the save: one key or a whole change set, in one write.
 *
 * READING masks every value a client has no business holding: a `secret`
 * key, any key (at any depth of a tier file) whose name says it is a
 * credential, and the userinfo of a URL travel as `"********"`.
 *
 * WRITING IS ALLOWLISTED (§8.5), and the allowlist is the schema's own risk
 * classes rather than a second list that could drift: a key is writable
 * remotely only when it is {@see RiskClass::Cosmetic}, {@see RiskClass::Tuning}
 * or {@see RiskClass::Narrowing}, editable in the settings UI, not a trust
 * grant, not a `server.*` key, not a secret, and not one a live command owns
 * (`theme` is `/theme`'s, `provider` is `/model`'s — the writer refuses them
 * too). That leaves out everything that runs a command (status line, hooks,
 * MCP), pulls files into prompts, spends money, opens the box (trust lists,
 * permission rules and mode, the server's own binding), or holds a secret.
 * Every write goes through the launch's {@see SettingsWriter} — the same door,
 * with the same tier and type refusals, as the TUI editor's and `/model`'s —
 * and only to the user tier or a trusted project's local file. The session
 * tier is not offered: in a server it would be process state shared by every
 * session the server hosts, which is not what "this session only" promises.
 */
final class SettingsMethods
{
    public const MASK = '********';

    /** A change set larger than this is refused rather than diffed. */
    public const MAX_CHANGES = 200;

    private const REMOTE_RISK_CLASSES = [RiskClass::Cosmetic, RiskClass::Tuning, RiskClass::Narrowing];

    /**
     * A key name that holds a credential: the LAST segment ends in one of
     * these words. Anchored at the end so `maxOutputTokens` (a count) and
     * `secretEnvAllowlist` (a list of variable NAMES) are not mistaken for one.
     */
    private const CREDENTIAL_NAME = '/(?:api[_-]?key|token|secret|password|passwd|credentials?|authorization|cookie|private[_-]?key)$/i';

    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new('settings.schema', Scope::Read, 'Every setting: type, default, help, apply mode, and whether a client may write it; and the tiers a save can target.', self::schema(...)));
        $registry->add(MethodSpec::new('settings.get', Scope::Read, 'Effective values and where each came from, or one tier\'s file; secrets masked.', self::get(...)));
        $registry->add(MethodSpec::new('settings.preview', Scope::Admin, 'What a save would write — the target file\'s diff, when each change applies, and what blocks it — without writing.', self::preview(...)));
        $registry->add(MethodSpec::new('settings.set', Scope::Admin, 'Write allowlisted settings to the user tier or a trusted project, in one write.', self::set(...), true));
    }

    /** Whether a client may write $definition over the wire. */
    public static function writableRemotely(SettingDefinition $definition): bool
    {
        return self::remoteRefusal($definition) === null;
    }

    /**
     * Why a client may not write $definition over the wire, or null when it
     * may — the reason the web form shows beside a disabled field.
     */
    public static function remoteRefusal(SettingDefinition $definition): ?string
    {
        return match (true) {
            \in_array($definition->ui, [UiEditability::Hidden, UiEditability::ReadOnly], true) => 'read-only: the app writes it itself',
            \in_array($definition->key, SettingsWriter::trustKeys(), true), $definition->applyMode === ApplyMode::Frozen
                => 'a trust grant: changes only through the terminal UI\'s confirmed trust action',
            isset(SettingsWriter::LIVE_COMMAND_KEYS[$definition->key])
                => 'saved by ' . SettingsWriter::LIVE_COMMAND_KEYS[$definition->key] . ', which also applies it',
            \str_starts_with($definition->key, 'server.') => 'the server\'s own binding: edit your config and restart the server',
            self::sensitive($definition) => 'holds a secret: never sent or written over the wire',
            !\in_array($definition->riskClass, self::REMOTE_RISK_CLASSES, true)
                => self::riskReason($definition->riskClass) . ': not writable remotely — edit it in the terminal UI or your config',
            default => null,
        };
    }

    /** Whether $definition's value is a secret a client never sees. */
    public static function sensitive(SettingDefinition $definition): bool
    {
        return $definition->type === SettingType::Secret || self::credentialName($definition->key);
    }

    /**
     * $value with every credential masked: a value under a credential-named
     * key (at any depth), and the userinfo of a URL. `$key` is the name the
     * value sits under, when it has one.
     */
    public static function masked(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && self::credentialName($key) && $value !== null && $value !== '' && $value !== []) {
            return self::MASK;
        }

        if (\is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::masked($v, \is_string($k) ? $k : null);
            }

            return $out;
        }

        if (\is_string($value)) {
            return (string) \preg_replace('#^([a-z][a-z0-9+.-]*://)[^/@\s]+@#i', '$1' . self::MASK . '@', $value);
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private static function schema(CallContext $call, Params $params): array
    {
        $options = $call->server->hub()->workspace()->service(OptionsProvider::class);
        $options = $options instanceof OptionsProvider ? $options : OptionsProvider::new();

        $items = [];
        foreach (SettingsSchema::all() as $definition) {
            $sensitive = self::sensitive($definition);
            $refusal = self::remoteRefusal($definition);
            $choices = $options->for($definition);
            $items[] = \array_filter([
                'key' => $definition->key,
                'type' => $definition->type->value,
                'default' => $sensitive ? null : $definition->default,
                'defaultText' => $definition->defaultText,
                'group' => $definition->category->value,
                'label' => $definition->label,
                'help' => $definition->help !== '' ? $definition->help : null,
                'enum' => $definition->enumValues !== [] ? $definition->enumValues : null,
                'options' => $choices !== [] ? $choices : null,
                'min' => $definition->min,
                'max' => $definition->max,
                'riskClass' => $definition->riskClass->value,
                'applies' => $definition->applyMode->value,
                'appliesLabel' => $definition->applyMode->badge(),
                'ui' => $definition->ui->value,
                'layered' => $definition->layered,
                'projectSettable' => $definition->projectSettable,
                'envVar' => $definition->envVar,
                'cliFlag' => $definition->cliFlag,
                'sensitive' => $sensitive,
                'writableRemotely' => $refusal === null,
                'remoteRefusal' => $refusal,
            ], static fn (mixed $value): bool => $value !== null);
        }

        $writer = $call->server->hub()->workspace()->service(SettingsWriter::class);
        $tiers = [];
        foreach (['user' => SettingsTier::You, 'project' => SettingsTier::ProjectLocal] as $scope => $tier) {
            $refusal = $writer instanceof SettingsWriter ? $writer->tierRefusal($tier) : 'this server has no settings writer';
            $tiers[] = \array_filter([
                'scope' => $scope,
                'label' => $tier->label(),
                'path' => $writer instanceof SettingsWriter ? $writer->targetPath($tier) : null,
                'writable' => $refusal === null,
                'refusal' => $refusal,
            ], static fn (mixed $value): bool => $value !== null);
        }

        return ['items' => $items, 'tiers' => $tiers];
    }

    /** @return array<string, mixed> */
    private static function get(CallContext $call, Params $params): array
    {
        $scope = $params->enum('scope', ['effective', 'user', 'project'], 'effective');
        if ($scope !== 'effective') {
            $tier = self::tier($scope);
            $values = self::masked(self::current(self::writer($call), $tier));

            return ['scope' => $scope, 'values' => $values === [] ? new \stdClass() : $values];
        }

        $sources = self::sources($call);
        $values = [];
        foreach ($sources->resolver->resolveAll() as $key => $resolved) {
            /** @var ResolvedSetting $resolved */
            $definition = SettingsSchema::byKey($key);
            $hidden = $definition !== null && self::sensitive($definition) && $resolved->value !== null && $resolved->value !== '';
            $values[$key] = \array_filter([
                'value' => $hidden ? self::MASK : self::masked($resolved->value),
                'source' => $resolved->source->value,
                'sourceLabel' => $resolved->source->label(),
                'sourcePath' => $resolved->sourcePath,
                'shadowed' => \array_map(static fn (SettingSource $s): string => $s->value, $resolved->shadowed),
                'locked' => $resolved->locked,
                'lockReason' => $resolved->lockReason,
            ], static fn (mixed $value, string $field): bool => $value !== null || $field === 'value', \ARRAY_FILTER_USE_BOTH);
        }

        $files = [];
        foreach ($sources->files as $file) {
            $files[] = ['role' => $file->role, 'path' => $file->path, 'status' => $file->status, 'note' => $file->note];
        }

        return ['scope' => 'effective', 'values' => $values === [] ? new \stdClass() : $values, 'files' => $files];
    }

    /** @return array<string, mixed> */
    private static function preview(CallContext $call, Params $params): array
    {
        $scope = $params->enum('scope', ['user', 'project'], 'user');
        $tier = self::tier($scope);
        [$set, $unset] = self::changeSet($params);
        $writer = self::writer($call);

        $refusals = [];
        foreach ([...\array_keys($set), ...$unset] as $key) {
            $key = (string) $key;
            $definition = SettingsSchema::byKey($key);
            $reason = $definition === null ? "no setting {$key}" : self::remoteRefusal($definition);
            if ($reason !== null) {
                $refusals[$key] = $definition === null ? $reason : "{$key}: {$reason}";
            }
        }
        $refusals += $writer->refusals($tier, $set, $unset);

        $path = $writer->targetPath($tier);
        try {
            $before = $writer->tierRefusal($tier) === null ? $writer->current($tier) : [];
        } catch (\RuntimeException $e) {
            $refusals = ['*' => $e->getMessage()] + $refusals;
            $before = [];
        }

        $preview = SettingsSavePreview::new(
            $tier,
            $path,
            self::masked($before),
            self::masked(SettingsWriter::patched($before, $set, $unset)),
            $set,
            $unset,
            $refusals,
        );

        $changes = [];
        foreach ($preview->changed() as $key) {
            $definition = SettingsSchema::byKey($key);
            $mode = $definition?->applyMode ?? ApplyMode::Restart;
            $change = ['key' => $key, 'action' => \array_key_exists($key, $set) ? 'set' : 'reset', 'applies' => $mode->value, 'appliesLabel' => $mode->badge()];
            if (\array_key_exists($key, $set)) {
                $change['value'] = $definition !== null && self::sensitive($definition) ? self::MASK : self::masked($set[$key]);
            }
            $changes[] = $change;
        }

        return [
            'scope' => $scope,
            'path' => $path,
            'canSave' => $preview->canSave(),
            'refusals' => $refusals === [] ? new \stdClass() : $refusals,
            'changes' => $changes,
            'applySummary' => $preview->applySummary(),
            'notes' => self::shadowNotes(self::sources($call), $tier, $set),
            'diff' => $preview->diff()->hunkText(),
        ];
    }

    /** @return array<string, mixed> */
    private static function set(CallContext $call, Params $params): array
    {
        $scope = $params->enum('scope', ['user', 'project'], 'user');
        [$set, $unset] = self::changeSet($params);

        $forbidden = [];
        foreach ([...\array_keys($set), ...$unset] as $key) {
            $key = (string) $key;
            $definition = SettingsSchema::byKey($key) ?? throw RpcError::notFound(\sprintf('no setting %s', $key), 'setting_not_found');
            if (!self::writableRemotely($definition)) {
                $forbidden[] = $key;
            }
        }
        if ($forbidden !== []) {
            throw RpcError::of(
                ErrorCode::Forbidden,
                \sprintf('%s cannot be changed over the wire; edit it in the terminal UI or your config', \implode(', ', $forbidden)),
                'not_writable_remotely',
                ['keys' => $forbidden],
            );
        }

        $tier = self::tier($scope);
        $writer = self::writer($call);
        $refusals = $writer->refusals($tier, $set, $unset);
        if ($refusals !== []) {
            throw RpcError::of(ErrorCode::InvalidParams, \implode('; ', $refusals), 'setting_refused', ['refusals' => $refusals]);
        }

        try {
            $path = $writer->write($tier, $set, $unset);
        } catch (\InvalidArgumentException $e) {
            throw RpcError::invalidParams($e->getMessage(), 'setting_refused');
        } catch (\RuntimeException $e) {
            throw RpcError::of(ErrorCode::Conflict, $e->getMessage(), 'write_failed');
        }

        $changed = [...\array_map('strval', \array_keys($set)), ...$unset];
        $applies = [];
        foreach ($changed as $key) {
            $applies[$key] = (SettingsSchema::byKey($key)?->applyMode ?? ApplyMode::Restart)->value;
        }

        $result = ['scope' => $scope, 'written' => $path, 'changed' => $changed, 'appliesByKey' => $applies];
        if ($params->has('key')) {
            $result = ['key' => (string) $params->raw('key')] + $result + ['applies' => $applies[(string) $params->raw('key')] ?? ApplyMode::Restart->value];
        }

        return $result;
    }

    /**
     * The change set a request names: the single-key form (`key` + `value`,
     * or `key` + `reset`) and/or the batch form (`set` map + `unset` list).
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private static function changeSet(Params $params): array
    {
        $set = $params->object('set');
        $unset = $params->strings('unset', self::MAX_CHANGES);
        if ($params->has('key')) {
            $key = $params->string('key', 128);
            if ($params->bool('reset')) {
                $unset[] = $key;
            } else {
                $set[$key] = $params->raw('value');
            }
        }

        $unset = \array_values(\array_unique($unset));
        if ($set === [] && $unset === []) {
            throw RpcError::invalidParams('nothing to save: name a key, or a set / unset change set', 'nothing_to_save');
        }
        if (\count($set) + \count($unset) > self::MAX_CHANGES) {
            throw RpcError::invalidParams(\sprintf('at most %d settings per save', self::MAX_CHANGES));
        }

        return [$set, $unset];
    }

    /**
     * What the preview says about precedence — the TUI preview's advisories:
     * a user-tier save that now overrides your `settings.json`, and a save
     * that a higher layer (your own file, the environment, a flag) still
     * outranks, so it is saved but not what this launch uses.
     *
     * @param array<string, mixed> $set
     * @return list<string>
     */
    private static function shadowNotes(SettingsSources $sources, SettingsTier $tier, array $set): array
    {
        $resolved = $sources->resolver->resolveAll();
        $notes = [];
        foreach (\array_keys($set) as $key) {
            $current = $resolved[(string) $key] ?? null;
            if (!$current instanceof ResolvedSetting) {
                continue;
            }

            $layers = [$current->source, ...$current->shadowed];
            if ($tier === SettingsTier::You && \in_array(SettingSource::UserSettings, $layers, true)) {
                $notes[] = "{$key}: overrides the value in your settings.json";
            }
            if ($current->source->precedence() > $tier->source()->precedence()) {
                $notes[] = "{$key}: {$current->source->label()} still sets it, and outranks this file";
            }
        }

        return $notes;
    }

    /** The launch's layers, as the TUI settings view reads them. */
    private static function sources(CallContext $call): SettingsSources
    {
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

        $options = $workspace->service(OptionsProvider::class);

        return SettingsSources::fromLaunch(
            $root,
            // The launch's trust answer, read off its writer (it may write the
            // project's local file only when the project is trusted); unknown
            // without one, and then the project files are not read.
            $root === null || !$writer instanceof SettingsWriter ? null : $writer->targetPath(SettingsTier::ProjectLocal) !== null,
            $home === null ? null : \rtrim($home, '/') . '/' . LayeredSettings::dir(),
            // The file the writer saves to is the file read back as layer 4,
            // so a save is seen at once (and `--config` moves both).
            $writer instanceof SettingsWriter ? $writer->targetPath(SettingsTier::You) : self::userConfigPath(),
            $env,
            [],
            $options instanceof OptionsProvider ? $options : null,
        );
    }

    /**
     * $tier's file, read strictly; a tier that cannot be written, or a file
     * that cannot be read, is an error rather than an empty object.
     *
     * @return array<string, mixed>
     */
    private static function current(SettingsWriter $writer, SettingsTier $tier): array
    {
        if ($writer->tierRefusal($tier) !== null) {
            throw RpcError::of(ErrorCode::NotFound, (string) $writer->tierRefusal($tier), 'tier_unavailable');
        }

        try {
            return $writer->current($tier);
        } catch (\RuntimeException $e) {
            throw RpcError::of(ErrorCode::Conflict, $e->getMessage(), 'unreadable');
        }
    }

    private static function tier(string $scope): SettingsTier
    {
        return $scope === 'user' ? SettingsTier::You : SettingsTier::ProjectLocal;
    }

    private static function credentialName(string $key): bool
    {
        $segment = \substr($key, (int) \strrpos('.' . $key, '.'));

        return \preg_match(self::CREDENTIAL_NAME, $segment) === 1;
    }

    private static function riskReason(RiskClass $risk): string
    {
        return match ($risk) {
            RiskClass::Spend => 'spends money',
            RiskClass::Exec => 'runs a command',
            RiskClass::Security => 'changes permissions or trust',
            RiskClass::Egress => 'changes where requests go',
            RiskClass::Prompt => 'changes what reaches the prompt',
            RiskClass::Cosmetic, RiskClass::Narrowing, RiskClass::Tuning => 'not allowlisted',
        };
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
