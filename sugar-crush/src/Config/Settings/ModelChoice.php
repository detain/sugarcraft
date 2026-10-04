<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings;

/**
 * The model axis of a provider switch (roadmap N-P3b): saving `/model
 * <provider> <model>` into the per-provider `models` setting (decision D9),
 * and the rows a model picker offers for one provider.
 *
 * WHY THE SAVE GOES THROUGH {@see SettingsWriter} and not `Chat`'s
 * `onConfigChange`: that callback's census is pinned to exactly `provider`
 * and `theme` ({@see \SugarCraft\Crush\Tests\Config\ConfigWriteProducerDocumentationDriftTest}),
 * and the writer is the door that already validates a `models` map, refuses
 * what the schema refuses and checks the write landed. This class builds the
 * value; the writer decides whether it may be written.
 *
 * WHY THE WHOLE MAP IS WRITTEN, MERGED. The settings layers merge KEY-WISE
 * ({@see \SugarCraft\Crush\Config\LayeredSettings::merge()}): a `models` in
 * `config.json` masks a `models` in `settings.json` entirely. Writing only
 * `{provider: model}` would silently drop every other provider's entry the
 * user keeps in `settings.json`, so the layered map is the base, the file's
 * own map goes over it, and the new entry over both.
 */
final class ModelChoice
{
    /**
     * Persist `$model` as `$provider`'s model on the "You" tier and answer the
     * file written.
     *
     * @param array<mixed> $layeredModels the `models` map the launch resolves now (every layer)
     *
     * @throws \InvalidArgumentException when the writer refuses the value
     * @throws \RuntimeException when the file cannot be read or written
     */
    public static function persist(SettingsWriter $writer, string $provider, string $model, array $layeredModels = []): string
    {
        $file = $writer->current(SettingsTier::You)['models'] ?? [];

        return $writer->write(SettingsTier::You, [
            'models' => self::merged(self::stringMap($layeredModels), self::stringMap(\is_array($file) ? $file : []), $provider, $model),
        ]);
    }

    /**
     * `$base`, then `$file` over it, then `$provider => $model` over both.
     *
     * @param array<string, string> $base
     * @param array<string, string> $file
     * @return array<string, string>
     */
    public static function merged(array $base, array $file, string $provider, string $model): array
    {
        $map = array_merge($base, $file, [$provider => $model]);
        ksort($map);

        return $map;
    }

    /**
     * The rows a model picker offers for one provider, deduplicated, in the
     * order a user wants them: the model running now (when it runs on this
     * provider), the one persisted for it, then its configured default.
     * No provider exposes a catalogue of its models without a network round
     * trip, so these are the ids this install actually knows; any other id is
     * typed as `/model <provider> <model>`.
     *
     * @return list<string>
     */
    public static function paletteLabels(?string $current, ?string $persisted, ?string $default): array
    {
        $labels = [];
        foreach ([$current, $persisted, $default] as $id) {
            if (\is_string($id) && trim($id) !== '' && !\in_array($id, $labels, true)) {
                $labels[] = $id;
            }
        }

        return $labels;
    }

    /**
     * The well-formed `provider => model id` entries of a decoded map; a
     * hand-edited entry of any other shape is dropped rather than copied into
     * a file the writer would then refuse to write.
     *
     * @param array<mixed> $map
     * @return array<string, string>
     */
    private static function stringMap(array $map): array
    {
        $out = [];
        foreach ($map as $name => $id) {
            if (\is_string($name) && trim($name) !== '' && \is_string($id) && trim($id) !== '') {
                $out[$name] = $id;
            }
        }

        return $out;
    }
}
