<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings;

/**
 * The interface settings as the running TUI reads them (roadmap N-P4g): what
 * Enter does mid-turn, the mouse, the wheel step, the double-Esc window, the
 * palette's recent list, the collapsed preview sizes and the checkpoint cap.
 *
 * MEMOISED, AND THAT IS THE REASON THIS CLASS EXISTS. Most readers of a
 * setting run once a turn and read the merged config each time
 * (`Bootstrap::readUserConfig()`, as `TransientFailure::maxAttempts()` does).
 * These run per FRAME (`Chat::mouseClicksEnabled()` gates every click zone the
 * renderer marks, `Renderer` sizes every collapsed diff) or per wheel tick, and
 * the merged read opens up to four files and walks the trust lists. So the
 * values are read once and held, keyed by what can change them inside one
 * process — the `config.json` path, `$HOME` and the session tier — and dropped
 * by {@see forget()}, which `Chat::applySettings()` calls on every save, so a
 * change made in the settings view applies at once. A hand edit of a file
 * applies at the next launch, or the next save.
 *
 * A value that fails its definition's own validation (wrong type, out of
 * range, not one of the enum's values) reads as the definition's default —
 * the same tolerance every other settings reader has: a bad hand edit costs
 * that one knob, never the launch.
 *
 * Not only the Interface tab reads through it: any reader that runs often and
 * wants a save to reach it without a restart may — the Auto breaker's limits
 * (`PermissionGate::autoBreakerLimits()`), for one. {@see KEYS} lists only the
 * keys badged live, which a save applies by dropping the held values.
 */
final class UiSettings
{
    /**
     * The keys read through this class, and therefore live: a save drops the
     * held values ({@see forget()}) and the next read takes the new one.
     * `mouse` also re-arms the terminal's mouse reporting in
     * `Chat::applySettings()`.
     *
     * @var list<string>
     */
    public const KEYS = [
        'queueMode',
        'terminalBackground',
        'mouse',
        'mouseClicks',
        'scrollWheelLines',
        'doubleEscSeconds',
        'paletteMru',
        'diffPreviewRows',
        'toolOutputPreviewLines',
        'maxCheckpoints',
    ];

    /** @var array{fingerprint: string, values: array<string, mixed>}|null */
    private static ?array $memo = null;

    private function __construct()
    {
    }

    /**
     * `$key`'s effective value: the merged settings' when it validates, else
     * the schema default.
     *
     * @throws \InvalidArgumentException for a key the schema does not define
     */
    public static function value(string $key): mixed
    {
        $definition = SettingsSchema::byKey($key)
            ?? throw new \InvalidArgumentException("'{$key}' is not a settings key");

        $config = self::config();
        if (!\array_key_exists($key, $config) || $config[$key] === null) {
            return $definition->default;
        }

        $value = $config[$key];
        if ($definition->type === SettingType::Float && \is_int($value)) {
            $value = (float) $value;
        }

        return $definition->validate($value, $config) === null ? $value : $definition->default;
    }

    /** {@see value()} for an Int key. */
    public static function int(string $key): int
    {
        return (int) self::value($key);
    }

    /** {@see value()} for a Float key. */
    public static function float(string $key): float
    {
        return (float) self::value($key);
    }

    /** {@see value()} for a Bool key. */
    public static function bool(string $key): bool
    {
        return (bool) self::value($key);
    }

    /**
     * Drop the held values, so the next read re-reads the settings files —
     * after a save, and between tests that share one process.
     */
    public static function forget(): void
    {
        self::$memo = null;
    }

    /** @return array<string, mixed> */
    private static function config(): array
    {
        $fingerprint = self::fingerprint();
        if (self::$memo !== null && self::$memo['fingerprint'] === $fingerprint) {
            return self::$memo['values'];
        }

        try {
            $values = \SugarCraft\Crush\Cli\Bootstrap::readUserConfig();
        } catch (\Throwable) {
            $values = [];
        }

        self::$memo = ['fingerprint' => $fingerprint, 'values' => $values];

        return $values;
    }

    /**
     * What, inside one process, changes which files the merged read opens or
     * what lies on top of them.
     */
    private static function fingerprint(): string
    {
        try {
            $path = \SugarCraft\Crush\Cli\Bootstrap::userConfigPath();
        } catch (\Throwable) {
            $path = '';
        }

        return $path . "\0" . (string) getenv('HOME') . "\0"
            . (string) json_encode(SessionSettings::all(), JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
