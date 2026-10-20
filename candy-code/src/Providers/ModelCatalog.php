<?php

declare(strict_types=1);

namespace SugarCraft\Code\Providers;

/**
 * PRV-1 (§6.5): the once-per-process cache in front of the two model-catalog
 * sources, and the merge order the palette and `models all` render.
 *
 * WHY IT EXISTS. The catalog tiers would otherwise mean a network GET and a
 * sweep of the LiteLLM table on every keystroke the palette takes — model
 * listing is read on the render path, and the render path never blocks. So
 * all fetching lives in {@see prime()}, which only update-side code may call
 * (a palette open, a `/model` command, `models all`), while the render
 * path reads the memo through {@see live()} and {@see litellm()} — cold
 * simply means "only the tiers you already have", never a stall and never a
 * wrong label.
 *
 * THE MERGE EMITS `current-three > live > LiteLLM` — {@see merge()} takes
 * the configured/current ids from {@see \SugarCraft\Code\Config\Settings\ModelChoice::paletteLabels()}
 * as the leading tier because they are what this session actually speaks,
 * then the endpoint's own answer, then the community database's names for
 * the hosted family: rows come out in that order, and a label already
 * emitted by an earlier tier is deduplicated away, so tier precedence and
 * row order are one and the same list. Capped at {@see MAX_ROWS} so a
 * hosted catalog with thousands of ids cannot turn the palette into an
 * ocean.
 *
 * Mutable by design and process-global, like {@see ModelMetadata}: this is
 * a cache, not identity. This is a SugarCraft architecture class, not a
 * port: no `Mirrors charmbracelet/...` citation attaches to it.
 */
final class ModelCatalog
{
    /** Rows the palette and `models all` offer per provider at most. */
    public const MAX_ROWS = 64;

    /**
     * Chat provider name -> the `litellm_provider` family LiteLLM uses for
     * it, where one exists. sglang and custom are deliberately absent: the
     * table attributes rows to HOSTED families, and guessing a family for a
     * self-hosted base is the "bill a local model at the hosted price" error
     * {@see ModelMetadata::entry()} refuses for pricing.
     */
    private const LITELLM_FAMILY = [
        'anthropic' => 'anthropic',
        'claude' => 'anthropic',
        'openai' => 'openai',
        'bedrock' => 'bedrock',
        'vertex' => 'vertex_ai',
    ];

    /** @var array<string, list<string>|null> catalog key -> ids, null = asked and failed */
    private static array $live = [];

    /** @var array<string, list<string>> provider name -> LiteLLM candidates */
    private static array $litellm = [];

    /**
     * Ask the endpoint once per process for this provider's catalog. Null
     * answers (unreachable host, refused route) are memoised too — the
     * "do not ask again this session" rule {@see ModelMetadata} applies to
     * failed downloads. Safe on the update side; NEVER call from a render
     * path.
     *
     * The live listing is part of the model-discovery family, so it rides
     * the SAME opt-out as the cached table: with
     * {@see ModelMetadata::OPT_OUT_ENV} set (air-gapped hosts, and the whole
     * test suite via tests/bootstrap.php) no request is made at all and the
     * catalog answers from the table tiers only.
     */
    public static function prefetch(?HasModelCatalog $provider): void
    {
        $key = $provider?->catalogKey();
        if ($key === null || \array_key_exists($key, self::$live) || ModelMetadata::disabledFromEnvironment()) {
            return;
        }

        self::$live[$key] = $provider?->listModels();
    }

    /**
     * The live tier for a provider instance, from the memo only — null when
     * nothing was prefetched yet or the fetch failed. Render-path safe.
     *
     * @return list<string>|null
     */
    public static function live(?HasModelCatalog $provider): ?array
    {
        $key = $provider?->catalogKey();

        return $key === null ? null : (self::$live[$key] ?? null);
    }

    /**
     * The community-database tier for a chat provider name, memoised. The
     * one read of the LiteLLM table happens here (its own first read stays
     * non-blocking: cache as-is, refresh forked). Render-path safe once
     * primed; cold, it takes the bounded cache read the same accessor
     * providers already use for windows and rates.
     *
     * @return list<string>
     */
    public static function litellm(string $providerName, ?ModelMetadata $metadata = null): array
    {
        $name = strtolower($providerName);
        if (isset(self::$litellm[$name])) {
            return self::$litellm[$name];
        }

        $family = self::family($name);

        return self::$litellm[$name] = $family === null
            ? []
            : ($metadata ?? ModelMetadata::new())->candidates($family, self::MAX_ROWS);
    }

    /**
     * The provider's LiteLLM attribution family, or null for none — the one
     * name→family rule shared by the palette and `models all`.
     */
    public static function family(string $providerName): ?string
    {
        return self::LITELLM_FAMILY[strtolower($providerName)] ?? null;
    }

    /**
     * The three tiers merged into the list a picker shows: configured/current
     * first (the ids this session speaks), then the endpoint's live answer,
     * then the community database — deduplicated, capped, in that order.
     * Pure over its arguments.
     *
     * @param list<string> $base
     * @param list<string>|null $live
     * @param list<string> $litellm
     * @return list<string>
     */
    public static function merge(array $base, ?array $live, array $litellm, int $limit = self::MAX_ROWS): array
    {
        $out = [];
        foreach ([...$base, ...($live ?? []), ...$litellm] as $label) {
            if ($label === '' || isset($out[$label])) {
                continue;
            }

            $out[$label] = true;
            if (\count($out) >= $limit) {
                break;
            }
        }

        return array_keys($out);
    }

    /** Test seam: forget every memoised tier. */
    public static function resetForTesting(): void
    {
        self::$live = [];
        self::$litellm = [];
    }
}
