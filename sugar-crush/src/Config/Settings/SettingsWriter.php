<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings;

use SugarCraft\Core\Util\AtomicJsonFile;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Permissions\PermissionMode;

/**
 * The settings editor's write door (roadmap N-P2, Appendix N §4.4) — a SEPARATE
 * door from `Chat`'s `onConfigChange`, so that callback's census stays exactly
 * `provider` and `theme`
 * ({@see \SugarCraft\Crush\Tests\Config\ConfigWriteProducerDocumentationDriftTest}),
 * and itself censused by
 * {@see \SugarCraft\Crush\Tests\Config\Settings\SettingsWriterCensusTest}.
 *
 * THREE TIERS ({@see SettingsTier}):
 *
 *  - YOU writes the user's `config.json` through the injected `$userConfigDoor`,
 *    which the launch wires to `Bootstrap::writeUserConfig()` — the one writer
 *    that file already has, with its lock, its symlink rule and its refusal to
 *    overwrite a config it cannot parse. Nothing here re-implements those.
 *    `settings.json` is never written, so README's promise stays true, and the
 *    written value outranks it, so the save sticks.
 *  - PROJECT-LOCAL writes `<root>/.sugar-crush/settings.local.json`, only the
 *    project-settable keys, and only for a project the operator already trusts
 *    — {@see LayeredSettings::projectLocalPath()} answers with the file the
 *    merge would read back, or null.
 *  - SESSION writes nothing to disk: the change set lands in
 *    {@see SessionSettings}, which `Bootstrap::mergedConfig()` lays over every
 *    file for the rest of this process (roadmap N-P3). Only a layered key that
 *    takes effect without a restart may go there — a session value for a key
 *    read once at launch, or one whose reader opens `config.json` directly,
 *    would be accepted and then never used.
 *
 * WHAT IT REFUSES, before anything is written (so the editor can show why):
 *  - a key the schema does not define, or one it marks read-only or hidden;
 *  - `provider` and `theme` on the You tier: `/model` and `/theme` (and their
 *    palette rows) apply them live and are their only writers, which is what
 *    the config-change census pins;
 *  - the trust and grant keys (apply mode Frozen): those change only through
 *    {@see grantTrust()} / {@see revokeTrust()}, the confirmed action;
 *  - a value of the wrong type, or one a definition's validators reject;
 *  - on the project tier, any key a project may not set, and any write at all
 *    for an untrusted (or unnamed) project.
 *
 * A RESET DELETES THE KEY (`$unset`) rather than writing the default, so a
 * later change to the default still reaches the user. An explicit `null` is
 * refused: in a settings file null is a statement that outranks every lower
 * layer, not "unset".
 *
 * Dotted keys are stored FLAT on disk (`"compaction.autoPercent": 80`), because
 * {@see LayeredSettings::merge()} is key-wise.
 */
final class SettingsWriter
{
    /** User-tier keys whose only writers are the live commands named. */
    public const LIVE_COMMAND_KEYS = ['provider' => '/model', 'theme' => '/theme'];

    /**
     * What a session-tier save reports as "written to": there is no file, and
     * the reply must not suggest one.
     */
    public const SESSION_TARGET = 'this session only (nothing written to disk)';

    /**
     * Keys whose values must be maps of non-empty strings to non-empty strings.
     * `models` is `{"<provider>": "<model id>"}` (decision D9).
     */
    private const STRING_MAP_KEYS = ['models'];

    /**
     * @param \Closure(array<string, mixed>, list<string>): void $userConfigDoor
     */
    private function __construct(
        private readonly string $userConfigPath,
        private readonly \Closure $userConfigDoor,
        private readonly ?string $projectRoot,
        private readonly bool $projectTrusted,
    ) {
    }

    /**
     * @param string $userConfigPath the file `$userConfigDoor` writes (`config.json`, or `--config`'s)
     * @param \Closure(array<string, mixed> $set, list<string> $unset): void $userConfigDoor
     */
    public static function new(string $userConfigPath, \Closure $userConfigDoor): self
    {
        return new self($userConfigPath, $userConfigDoor, null, false);
    }

    /** The project the project-local tier writes for, and whether it is trusted. */
    public function withProject(?string $root, bool $trusted): self
    {
        $root = $root === null || trim($root) === '' ? null : $root;

        return new self($this->userConfigPath, $this->userConfigDoor, $root, $trusted);
    }

    /**
     * The file a tier writes, or null when that tier cannot be written — and
     * null for the session tier, which writes no file at all.
     */
    public function targetPath(SettingsTier $tier): ?string
    {
        return match ($tier) {
            SettingsTier::You => $this->userConfigPath,
            SettingsTier::ProjectLocal => $this->projectRoot === null
                ? null
                : LayeredSettings::projectLocalPath($this->projectRoot, $this->projectTrusted),
            SettingsTier::Session => null,
        };
    }

    /** Why a tier cannot be written at all, or null when it can. */
    public function tierRefusal(SettingsTier $tier): ?string
    {
        if ($tier === SettingsTier::You || $tier === SettingsTier::Session || $this->targetPath($tier) !== null) {
            return null;
        }

        return $this->projectRoot === null
            ? 'no project is open, so there is no project settings file to write'
            : 'project settings are ignored until you trust this project (' . LayeredSettings::PROJECT_SETTINGS_TRUST_KEY . ')';
    }

    /**
     * Why setting `$key` to `$value` on `$tier` is refused, or null when it may
     * be written.
     */
    public function refusal(SettingsTier $tier, string $key, mixed $value): ?string
    {
        $definition = $this->writableDefinition($tier, $key, $reason);
        if ($definition === null) {
            return $reason;
        }

        if ($value === null) {
            return "{$key}: a null would mask every lower layer — reset the key instead";
        }

        return self::typeRefusal($definition, $value) ?? self::validatorRefusal($definition, $value);
    }

    /**
     * Every refusal for a whole change set, keyed by setting key (`'*'` for the
     * tier itself). Empty means {@see write()} would proceed.
     *
     * @param array<string, mixed> $set
     * @param list<string> $unset
     * @return array<string, string>
     */
    public function refusals(SettingsTier $tier, array $set, array $unset = []): array
    {
        $refusals = [];
        $tierRefusal = $this->tierRefusal($tier);
        if ($tierRefusal !== null) {
            $refusals['*'] = $tierRefusal;
        }

        foreach ($set as $key => $value) {
            $reason = $this->refusal($tier, (string) $key, $value);
            if ($reason !== null) {
                $refusals[(string) $key] = $reason;
            }
        }

        foreach ($unset as $key) {
            if (\array_key_exists($key, $set)) {
                $refusals[$key] = "{$key}: set and reset in the same save";
                continue;
            }

            if ($this->writableDefinition($tier, $key, $reason) === null) {
                $refusals[$key] = (string) $reason;
            }
        }

        return $refusals;
    }

    /**
     * What `$tier`'s file holds now, read STRICTLY: a missing file is `[]`, and
     * a file that exists but is not a JSON object throws — the editor must not
     * preview (or write) a change over a file it cannot read.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    public function current(SettingsTier $tier): array
    {
        if ($tier === SettingsTier::Session) {
            return SessionSettings::all();
        }

        $path = $this->targetPath($tier) ?? throw new \RuntimeException((string) $this->tierRefusal($tier));
        if (!is_file($path)) {
            return [];
        }

        try {
            $data = AtomicJsonFile::new($path)->read();
        } catch (\Throwable $e) {
            throw new \RuntimeException("{$path} is not a readable JSON object (" . $e->getMessage() . '); fix it by hand first', 0, $e);
        }

        if ($data !== [] && array_is_list($data)) {
            throw new \RuntimeException("{$path} holds a JSON list, not an object; fix it by hand first");
        }

        return $data;
    }

    /**
     * `$current` with `$set` applied and `$unset` removed — what the file will
     * hold after {@see write()}, for the save preview.
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $set
     * @param list<string> $unset
     * @return array<string, mixed>
     */
    public static function patched(array $current, array $set, array $unset = []): array
    {
        $next = array_merge($current, $set);
        foreach ($unset as $key) {
            unset($next[$key]);
        }

        return $next;
    }

    /**
     * Write a change set to `$tier`'s file and answer the path written.
     *
     * @param array<string, mixed> $set
     * @param list<string> $unset keys to remove (reset to default)
     *
     * @throws \InvalidArgumentException when anything in the set is refused ({@see refusals()})
     * @throws \RuntimeException when the file could not be written
     */
    public function write(SettingsTier $tier, array $set, array $unset = []): string
    {
        $unset = array_values(array_unique($unset));
        $refusals = $this->refusals($tier, $set, $unset);
        if ($refusals !== []) {
            throw new \InvalidArgumentException(implode('; ', $refusals));
        }

        if ($tier === SettingsTier::Session) {
            SessionSettings::apply($set, $unset);

            return self::SESSION_TARGET;
        }

        $path = (string) $this->targetPath($tier);
        if ($set === [] && $unset === []) {
            return $path;
        }

        if ($tier === SettingsTier::You) {
            ($this->userConfigDoor)($set, $unset);
            $this->assertLanded($path, $set, $unset);

            return $path;
        }

        $next = self::patched($this->current($tier), $set, $unset);
        try {
            AtomicJsonFile::new($path)->write($next);
        } catch (\Throwable $e) {
            throw new \RuntimeException("{$path} could not be written (" . $e->getMessage() . ')', 0, $e);
        }

        return $path;
    }

    /**
     * Add the project at `$root` to the `$trustKey` list in the user's
     * `config.json` — the confirmed trust action. User tier only, by
     * construction: a project file cannot carry a trust key at all. Like every
     * trust grant it applies from the NEXT launch, because each list is frozen
     * per process.
     *
     * @throws \InvalidArgumentException for a key that is not a trust list, or a root that does not resolve
     * @throws \RuntimeException when the file could not be written
     */
    public function grantTrust(string $trustKey, string $root): string
    {
        return $this->editTrust($trustKey, $root, true);
    }

    /** Remove `$root` from the `$trustKey` list; see {@see grantTrust()}. */
    public function revokeTrust(string $trustKey, string $root): string
    {
        return $this->editTrust($trustKey, $root, false);
    }

    /**
     * The trust lists the confirmed action may edit: the schema's Frozen
     * permission lists (`trustedProject*`).
     *
     * @return list<string>
     */
    public static function trustKeys(): array
    {
        return array_values(array_map(
            static fn (SettingDefinition $d): string => $d->key,
            array_filter(
                SettingsSchema::inCategory(SettingCategory::Permissions),
                static fn (SettingDefinition $d): bool => $d->applyMode === ApplyMode::Frozen && $d->type === SettingType::StringList,
            ),
        ));
    }

    private function editTrust(string $trustKey, string $root, bool $grant): string
    {
        if (!\in_array($trustKey, self::trustKeys(), true)) {
            throw new \InvalidArgumentException("{$trustKey} is not a project trust list");
        }

        $canonical = realpath($root);
        if ($canonical === false || !is_dir($canonical)) {
            throw new \InvalidArgumentException("{$root} is not a directory");
        }

        $listed = $this->current(SettingsTier::You)[$trustKey] ?? [];
        $listed = \is_array($listed) ? array_values(array_filter($listed, 'is_string')) : [];
        $next = $grant
            ? array_values(array_unique([...$listed, $canonical]))
            : array_values(array_filter($listed, static fn (string $r): bool => $r !== $canonical));

        if ($next === $listed) {
            return $this->userConfigPath;
        }

        ($this->userConfigDoor)([$trustKey => $next], []);
        $this->assertLanded($this->userConfigPath, [$trustKey => $next], []);

        return $this->userConfigPath;
    }

    /**
     * Why `$definition` may not be set on the session tier, or null when it
     * may. One predicate for the writer and for the docs that list the tier's
     * keys ({@see sessionKeys()}), so the two cannot disagree.
     *
     * The tier only makes sense for a key something re-reads while the
     * process runs: a layered key (anything else is read straight from
     * `config.json`) that applies live or next turn. `provider` is the one
     * live key it refuses — switching it rebuilds the backend, and `/model` is
     * the door that does that.
     */
    public static function sessionRefusal(SettingDefinition $definition): ?string
    {
        $key = $definition->key;

        return match (true) {
            $key === 'provider' => "{$key} is switched by /model, which rebuilds the backend",
            !$definition->layered => "{$key} is read from config.json at launch, so a session-only value would never be seen",
            $definition->applyMode !== ApplyMode::Live && $definition->applyMode !== ApplyMode::NextTurn
                => "{$key} applies only at restart, so a session-only value would never be used",
            default => null,
        };
    }

    /**
     * The keys the session tier accepts, in schema order.
     *
     * @return list<string>
     */
    public static function sessionKeys(): array
    {
        $keys = [];
        foreach (SettingsSchema::all() as $definition) {
            $editable = $definition->ui !== UiEditability::ReadOnly && $definition->ui !== UiEditability::Hidden;
            if ($editable && self::sessionRefusal($definition) === null) {
                $keys[] = $definition->key;
            }
        }

        return $keys;
    }

    /**
     * The definition of a key `$tier` may write, or null with `$reason` set.
     */
    private function writableDefinition(SettingsTier $tier, string $key, ?string &$reason = null): ?SettingDefinition
    {
        $definition = SettingsSchema::byKey($key);
        $reason = match (true) {
            $definition === null => "{$key} is not a setting",
            $definition->ui === UiEditability::ReadOnly, $definition->ui === UiEditability::Hidden
                => "{$key} is not edited here",
            $definition->applyMode === ApplyMode::Frozen
                => "{$key} is a trust grant; it changes only through the confirmed trust action",
            $tier === SettingsTier::You && isset(self::LIVE_COMMAND_KEYS[$key])
                => "{$key} is saved by " . self::LIVE_COMMAND_KEYS[$key] . ', which also applies it now',
            $tier === SettingsTier::ProjectLocal && !($definition->layered && $definition->projectSettable)
                => "{$key} may not be set by a project file",
            $tier === SettingsTier::Session => self::sessionRefusal($definition),
            default => null,
        };

        return $reason === null ? $definition : null;
    }

    private static function typeRefusal(SettingDefinition $definition, mixed $value): ?string
    {
        $key = $definition->key;
        $ok = match ($definition->type) {
            SettingType::Bool => \is_bool($value),
            SettingType::Int => \is_int($value),
            SettingType::Float => \is_int($value) || \is_float($value),
            SettingType::Enum, SettingType::String, SettingType::Secret, SettingType::Path, SettingType::Url => \is_string($value),
            SettingType::StringList => \is_array($value) && array_is_list($value)
                && array_filter($value, static fn (mixed $v): bool => !\is_string($v)) === [],
            SettingType::Map => \is_array($value) && ($value === [] || !array_is_list($value)),
            SettingType::Json => \is_array($value) || \is_scalar($value),
        };

        if (!$ok) {
            return "{$key}: expected " . $definition->type->label();
        }

        if (\in_array($key, self::STRING_MAP_KEYS, true)) {
            foreach ($value as $name => $entry) {
                if (!\is_string($name) || trim($name) === '' || !\is_string($entry) || trim($entry) === '') {
                    return "{$key}: every entry must map a provider name to a model id";
                }
            }
        }

        // The strict key: the launch refuses a permissionMode it cannot parse,
        // so the editor must never be able to write one.
        if ($key === 'permissionMode' && PermissionMode::tryFrom((string) $value) === null) {
            return "{$key}: '{$value}' is not a permission mode";
        }

        return null;
    }

    private static function validatorRefusal(SettingDefinition $definition, mixed $value): ?string
    {
        foreach ($definition->effectiveValidators() as $validator) {
            $error = $validator->validate($value);
            if ($error !== null) {
                return "{$definition->key}: {$error}";
            }
        }

        return null;
    }

    /**
     * The user-tier door reports nothing (a failed `writeUserConfig()` costs the
     * setting silently, by its own contract), so the write is checked by
     * reading the file back: every set key holds its value, every reset key is
     * gone.
     *
     * @param array<string, mixed> $set
     * @param list<string> $unset
     */
    private function assertLanded(string $path, array $set, array $unset): void
    {
        $now = LayeredSettings::decodedFile($path);
        foreach ($set as $key => $value) {
            if (!\array_key_exists($key, $now) || $now[$key] !== $value) {
                throw new \RuntimeException("{$path} was not updated (is it readable JSON, owned by you and not a link to another account's file?)");
            }
        }

        foreach ($unset as $key) {
            if (\array_key_exists($key, $now)) {
                throw new \RuntimeException("{$path} was not updated ({$key} is still set)");
            }
        }
    }
}
