<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\MediaForm;

use SugarCraft\Crush\Media\Sd\Client;

/**
 * The runtime choice sets for the form's option rows (crush-media W3.4,
 * plan §4.4): when an SD base URL is configured, `GET /samplers` and
 * `GET /schedulers` replace the §2.9 enum rosters at form open, cached for
 * the process; any failure leaves the enums in place (fail-open — a dead
 * server must never cost the user the form).
 *
 * The transport is idle-bounded but carries no total deadline (the E646
 * doctrine), so this hop runs ONLY on the real launch path — the App seam
 * that opens the form — and never inside `MediaForm::open()` itself, which
 * stays deterministic and HTTP-free for every unit pin. A host that answers
 * slowly delays the first frame, not the test suite; the cache makes it a
 * per-process cost.
 */
final class ChoiceFeed
{
    /** @var array<string, list<string>>|null result cache (feed name => labels) */
    private static ?array $cache = null;
    private static ?string $cacheBase = null;

    private function __construct()
    {
    }

    /**
     * The feed for the currently configured base, or null when nothing is
     * configured or the lookup failed. Keys are the FieldSpec::CHOICES_*
     * names the option rows ask for.
     *
     * @return array<string, list<string>>|null
     */
    public static function forForm(): ?array
    {
        $base = Client::resolveBaseUrl();
        if ($base === null) {
            return null;
        }

        if (self::$cache !== null && self::$cacheBase === $base) {
            return self::$cache;
        }

        try {
            $client = Client::configured();
            $choices = array_filter([
                FieldSpec::CHOICES_SAMPLERS => self::namesFrom($client->samplers()),
                FieldSpec::CHOICES_SCHEDULERS => self::namesFrom($client->schedulers()),
                // §2.8: the six latent modes are the static floor (every
                // A1111 answers them without being asked); the server's own
                // names merge on top. /latent-upscale-modes is deliberately
                // not fetched — it re-lists those same six (W3.5 ruling).
                FieldSpec::CHOICES_UPSCALERS => array_values(array_unique([
                    ...FieldSpec::latentUpscalers(),
                    ...self::namesFrom(self::attempt(static fn (): array => $client->upscalers())),
                ])),
                // Checkpoint titles for the hires/refiner dropdowns; empty
                // is fine — those rows keep their neutral sentinel alone.
                FieldSpec::CHOICES_MODELS => self::titlesFrom(self::attempt(static fn (): array => $client->sdModels())),
            ], static fn (array $names): bool => $names !== []);
        } catch (\Throwable) {
            return null;
        }

        if ($choices === []) {
            return null;
        }

        self::$cache = $choices;
        self::$cacheBase = $base;

        return $choices;
    }

    /**
     * The `name` column of an options-listing response, as labels.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    public static function namesFrom(array $rows): array
    {
        $names = [];
        foreach ($rows as $row) {
            $name = $row['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * One optional hop: a host without /upscalers or /sdapi/v1/sd-models
     * must not cost the rows that did answer (W3.5 widening — the W3.4 pair
     * stays all-or-nothing because the params selects need both).
     *
     * @param callable(): list<array<string, mixed>> $fetch
     * @return list<array<string, mixed>>
     */
    private static function attempt(callable $fetch): array
    {
        try {
            return $fetch();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The `title` column of an sd-models listing (A1111 shows titles), with
     * `name` as the defensive fallback for forks that answer differently.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    public static function titlesFrom(array $rows): array
    {
        $titles = [];
        foreach ($rows as $row) {
            $title = $row['title'] ?? $row['name'] ?? null;
            if (is_string($title) && $title !== '') {
                $titles[] = $title;
            }
        }

        return $titles;
    }

    /** Forget the cached set (test seam; production caches per process). */
    public static function resetForTesting(): void
    {
        self::$cache = null;
        self::$cacheBase = null;
    }
}
