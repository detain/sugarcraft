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

    /** Forget the cached set (test seam; production caches per process). */
    public static function resetForTesting(): void
    {
        self::$cache = null;
        self::$cacheBase = null;
    }
}
