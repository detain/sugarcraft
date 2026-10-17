<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\MediaForm;

/**
 * Named presets over the FieldSpec value lattice (crush-media W3.9): a
 * preset is `{presetVersion: 1, values: {...}}` keyed by name inside the
 * `sd.presets` settings map, exactly the bundle shape §5.8's mirror-item 1
 * copies from A1111's `settings.json` style lists.
 *
 * This is the pure model — parse, validate, save, apply, delete. It touches
 * no settings file and names no writer: persistence rides the injected
 * closure door at {@see MediaForm::open()}, same I/O-free doctrine as every
 * other media-form part (the App seam wires the door to the sanctioned
 * SettingsWriter path, and the writer's own refusal-before-write protocol
 * gives the whole sd.presets map its atomicity).
 *
 * THE SECRET SCRUB (W3.9 law): a preset saves what the FORM holds, and the
 * form's lattice could one day grow a secret-shaped knob — so every saved
 * values map drops any key matching {@see SECRET_KEY_PATTERN} before it is
 * stored. Secrets reach the server only through the `${VAR}` channel
 * (ENVIRONMENT.md); a preset file is no place for one. The scrub is
 * deny-shaped on purpose: a match is dropped silently, because a preset
 * that carries an API key is a preset that must never have been savable.
 */
final class MediaPresets
{
    /** The layered-settings key the stored map lives under (W1.8 row). */
    public const string SETTINGS_KEY = 'sd.presets';

    /** Every bundle this build can read; a newer stamp is a loud refusal. */
    public const int PRESET_VERSION = 1;

    /**
     * Value keys stripped from every saved bundle (the secret scrub).
     * W7 security (low): widened with `auth` — which also spells
     * `authorization`, `x-auth-token`, the header names an exfiltration
     * bundle would wear — and `cookie`, the session-credential shape the
     * original deny list missed. Field names in the generation lattice
     * (steps, cfg, sampler, …) contain neither stem, so the widening costs
     * no false drops.
     */
    public const string SECRET_KEY_PATTERN = '/api|key|token|secret|password|auth|cookie/i';

    /** Status line when a press arrives after the store failed to parse. */
    public const string STORE_UNAVAILABLE = 'preset store unavailable: sd.presets did not parse when the form opened';

    /**
     * @param array<string, array{presetVersion: int, values: array<string, mixed>}> $entries keyed by preset name, insertion order
     */
    private function __construct(
        private readonly array $entries,
    ) {
    }

    /** @return self */
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Parse the stored `sd.presets` map at the boundary (parse, don't
     * validate): every entry is checked here, so {@see apply()} and
     * {@see save()} downstream never re-inspect a half-broken bundle. One
     * malformed entry fails the WHOLE map loudly — a silently skipped preset
     * would let the operator apply "the other one" by name-mismatch, and a
     * settings list nobody trusts is worse than none.
     *
     * @param array<array-key, mixed> $stored
     * @throws \InvalidArgumentException naming the offending preset
     */
    public static function fromStored(array $stored): self
    {
        $entries = [];
        foreach ($stored as $name => $entry) {
            if (!\is_string($name) || $name === '') {
                throw new \InvalidArgumentException(
                    'sd.presets carries a preset under a non-string or empty name',
                );
            }

            if (!\is_array($entry)) {
                throw new \InvalidArgumentException(
                    "sd.presets preset '{$name}' is not a preset bundle",
                );
            }

            $version = $entry['presetVersion'] ?? null;
            if ($version !== self::PRESET_VERSION) {
                throw new \InvalidArgumentException(
                    "sd.presets preset '{$name}' carries presetVersion " . self::describe($version)
                        . '; this build reads version ' . self::PRESET_VERSION,
                );
            }

            $values = $entry['values'] ?? null;
            if (!\is_array($values) || ($values !== [] && array_is_list($values))) {
                throw new \InvalidArgumentException(
                    "sd.presets preset '{$name}' carries no values map",
                );
            }

            foreach (array_keys($values) as $key) {
                if (!\is_string($key)) {
                    throw new \InvalidArgumentException(
                        "sd.presets preset '{$name}' carries a numeric-keyed value",
                    );
                }
            }

            /** @var array<string, mixed> $values */
            $entries[$name] = ['presetVersion' => self::PRESET_VERSION, 'values' => $values];
        }

        return new self($entries);
    }

    /** @return list<string> preset names, insertion order */
    public function list(): array
    {
        return array_keys($this->entries);
    }

    public function has(string $name): bool
    {
        return isset($this->entries[$name]);
    }

    /**
     * The hydrate payload for one preset — a flat FieldSpec-keyed map ready
     * for {@see MediaForm::hydrateFields()}.
     *
     * @return array<string, mixed>
     * @throws \InvalidArgumentException when no such preset exists
     */
    public function apply(string $name): array
    {
        if (!$this->has($name)) {
            throw new \InvalidArgumentException("no preset named '{$name}'");
        }

        return $this->entries[$name]['values'];
    }

    /**
     * A store with `name` set to the scrubbed copy of `$values`, replacing
     * any preset of the same name (A1111 style-list semantics).
     *
     * @param array<array-key, mixed> $values
     * @throws \InvalidArgumentException on a blank name or a numeric-keyed value
     */
    public function save(string $name, array $values): self
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('a preset needs a name');
        }

        $scrubbed = [];
        foreach ($values as $key => $value) {
            if (!\is_string($key)) {
                throw new \InvalidArgumentException(
                    "preset '{$name}' cannot hold a numeric-keyed value",
                );
            }

            if (preg_match(self::SECRET_KEY_PATTERN, $key) === 1) {
                continue;
            }

            $scrubbed[$key] = $value;
        }

        // Normalise through the JSON the settings file is made of BEFORE the
        // bundle is accepted: a float the widget carries (cfg_scale 7.0) is
        // written by config.json as `7` and read back an int, so a store that
        // skipped this hop could never survive SettingsWriter's read-back
        // proof. What is held here is therefore exactly what a reload hands
        // back — round-trip identity by construction, not by hope.
        $encoded = json_encode($scrubbed);
        if ($encoded === false) {
            throw new \InvalidArgumentException(
                "preset '{$name}' carries a value that cannot be persisted",
            );
        }
        /** @var array<string, mixed> $scrubbed */
        $scrubbed = json_decode($encoded, true);

        $entries = $this->entries;
        $entries[$name] = ['presetVersion' => self::PRESET_VERSION, 'values' => $scrubbed];

        return new self($entries);
    }

    /**
     * A store with `name` removed. Unknown names fail loud — a delete that
     * quietly matched nothing would report success over an unchanged store.
     */
    public function delete(string $name): self
    {
        if (!$this->has($name)) {
            throw new \InvalidArgumentException("no preset named '{$name}'");
        }

        $entries = $this->entries;
        unset($entries[$name]);

        return new self($entries);
    }

    /** The whole map as it goes to `sd.presets` (round-trips through {@see fromStored()}). */
    public function toStored(): array
    {
        return $this->entries;
    }

    private static function describe(mixed $version): string
    {
        return match (true) {
            \is_int($version) => (string) $version,
            \is_string($version) => "'{$version}'",
            $version === null => 'no stamp',
            default => get_debug_type($version),
        };
    }
}
