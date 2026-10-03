<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

/**
 * A provider that places prompt-cache breakpoints on its requests, and can
 * say for which model it does (audit A15, P10.S3).
 *
 * The question has two halves that only the provider can answer together:
 * whether it was built with the `promptCache` setting on, and whether the
 * model's family offers caching at all. {@see VertexProvider} marks
 * `cache_control` through {@see CacheBreakpoints} on its Anthropic arm;
 * {@see BedrockProvider} places Converse `cachePoint` blocks of its own.
 *
 * WHY IT IS AN INTERFACE. {@see \SugarCraft\Crush\Backend\EngineBackend}
 * watches each step's cache buckets for the "nothing is being cached"
 * diagnostic ({@see CacheBreakpoints::observeCacheHealth()}), and that
 * warning is only true of a request that ASKED for caching. A provider that
 * caches server-side without marks (`openai`, `sglang`) or not at all does not
 * implement this, so it can never be told its cache is broken.
 */
interface MarksPromptCache
{
    /**
     * Does a request for `$model` carry prompt-cache breakpoints? An empty id
     * names the provider's configured default — the same fallback a request
     * with no model takes on the wire.
     */
    public function marksPromptCache(string $model): bool;
}
