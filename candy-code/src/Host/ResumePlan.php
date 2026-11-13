<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host;

use SugarCraft\Code\Lang;

/**
 * What a resume should put back on the live engine, decided from the stored
 * session row alone so the decision can be tested without a running Chat.
 *
 * A resumed session should run on the model and provider it last ran on, but
 * only when they are still configured: a provider removed from the config, or
 * a model no longer offered by its provider, falls back to the current default
 * and says so. A stored working directory is never applied, only compared: the
 * process cwd is the user's to change, so a mismatch is a warning, not a move.
 *
 * The caller supplies `$configured`, a map of provider name to the models that
 * provider can run, its configured model first. Chat applies `provider` and
 * `model` and prints `notices` and `restored` into the transcript.
 */
final readonly class ResumePlan
{
    /**
     * @param list<string> $notices warnings, one per stored value that could not be re-applied
     */
    private function __construct(
        public string $provider,
        public string $model,
        public array $notices,
        public string $restored,
    ) {}

    /**
     * @param array<string, mixed> $row the stored session row
     * @param array<string, list<string>> $configured provider => runnable models, configured model first
     */
    public static function for(
        array $row,
        array $configured,
        string $defaultProvider,
        string $defaultModel,
        ?string $currentCwd,
    ): self {
        $notices = [];
        $storedProvider = self::text($row['provider'] ?? null);
        $storedModel = self::text($row['model'] ?? null);

        $provider = $defaultProvider;
        if ($storedProvider !== null) {
            if (\array_key_exists($storedProvider, $configured)) {
                $provider = $storedProvider;
            } else {
                $notices[] = Lang::t('host.session.resume.provider_missing', [
                    'provider' => $storedProvider,
                    'fallback' => $defaultProvider,
                ]);
            }
        }

        $models = $configured[$provider] ?? [];
        $fallbackModel = $provider === $defaultProvider ? $defaultModel : ($models[0] ?? $defaultModel);
        $model = $fallbackModel;
        if ($storedModel !== null) {
            if (\in_array($storedModel, $models, true)) {
                $model = $storedModel;
            } else {
                $notices[] = Lang::t('host.session.resume.model_missing', [
                    'model' => $storedModel,
                    'provider' => $provider,
                    'fallback' => $fallbackModel,
                ]);
            }
        }

        $storedCwd = self::text($row['cwd'] ?? null);
        if ($storedCwd !== null && $currentCwd !== null && \rtrim($storedCwd, '/\\') !== \rtrim($currentCwd, '/\\')) {
            $notices[] = Lang::t('host.session.resume.cwd_differs', [
                'stored' => $storedCwd,
                'current' => $currentCwd,
            ]);
        }

        return new self(
            $provider,
            $model,
            $notices,
            Lang::t('host.session.resume.restored', ['provider' => $provider, 'model' => $model]),
        );
    }

    private static function text(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }
}
