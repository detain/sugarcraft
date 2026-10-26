<?php

declare(strict_types=1);

namespace SugarCraft\Code\Providers;

use GuzzleHttp\HandlerStack;
use SugarCraft\Code\Providers\Concerns\HttpClientDefaults;
use SugarCraft\Code\Support\AtomicFileWriter;
use SugarCraft\Code\Support\ForkedChild;
use SugarCraft\Code\Support\HomeDirectory;
use SugarCraft\Code\Tui\Components\PaneLabel;

/**
 * Context windows and per-token prices for models this package has no table
 * for (roadmap 5.13a), read from LiteLLM's community-maintained
 * `model_prices_and_context_window.json` — the database Aider sizes and
 * prices every model from.
 *
 * WHAT IT REPLACES. {@see CustomProvider} answered a fixed 128,000-token
 * window and $0 for every model behind it — which until ISS-023 included
 * the `anthropic` type, whose models bill real money — and models whose real
 * window is 200k or 1M, so the context tiers fired at the wrong point. The
 * native `AnthropicProvider` carries its own tables and does not consult this
 * one; `openai` and `custom` are its only provider readers of the figures.
 * PRV-1 (§6.5) adds a second kind of reader, {@see candidates()}: the model
 * palette and `models all` enumerate names for ANY provider type from the
 * same table. {@see OpenAIProvider} answered 0
 * ("unknown") for a model its built-in table did not list. Both now consult
 * this database between the operator's own settings and their built-in
 * fallbacks: `contextWindow` / `modelPrices` setting > this database >
 * built-in table.
 *
 * WHERE THE BYTES LIVE. One cache file,
 * `~/.candy-code/cache/model_prices_and_context_window.json`, refreshed when
 * it is older than {@see TTL_SECONDS} (24 h). The home is
 * {@see HomeDirectory::owned()}, never the temp-dir fallback: these figures
 * size the compaction tiers and the spend caps, so a file another local user
 * could plant (an inflated window switches auto-compaction off) must not be
 * read. No owned home means no cache and no download.
 *
 * NEVER BLOCKS THE TUI. The first lookup in a process reads the cache as it
 * is — stale or absent included — and, when it is stale, starts the refresh
 * in a detached grandchild (`pcntl_fork()` twice, like
 * {@see \SugarCraft\Code\Background\BackgroundSupervisor}): the parent reaps
 * the intermediate child at once and goes on with what it read, and the next
 * launch sees the new file. Without ext-pcntl the refresh runs inline, held to
 * {@see DISCOVERY_CONNECT_TIMEOUT_SECONDS} / {@see DISCOVERY_TIMEOUT_SECONDS}.
 * A lock file stops two processes refreshing at once.
 *
 * OFFLINE-SAFE. A failed download never loses data and never retries on every
 * launch: a stale cache is kept and its clock restarted (touched), and a
 * missing one is written as `{}` — the same "do not ask again for a day" rule
 * Aider uses. An empty or unreadable database answers null for every model,
 * which every caller treats as "fall through to the next source".
 *
 * A TOTAL TIMEOUT IS RIGHT HERE and would be wrong on a completion: this is a
 * one-file metadata download, not an LLM call
 * ({@see Concerns\HttpClientDefaults}), so `ProviderConnectTimeoutTest` lists
 * this file among its metadata-read exemptions by name.
 *
 * OPT-OUT. {@see OPT_OUT_ENV} (`CANDY_CODE_DISABLE_MODEL_METADATA`, flag-style:
 * unset, empty and `0` mean "not set") turns the database off entirely — no
 * read, no download — for air-gapped hosts and for the test suite, whose
 * results must not depend on a file in the developer's home.
 *
 * Mutable by design: it memoises the parsed table on first use. Nothing about
 * it is part of any value object's identity. This is a SugarCraft
 * architecture class, not a port: no `Mirrors charmbracelet/...` citation
 * attaches to it.
 */
final class ModelMetadata
{
    use HttpClientDefaults;

    /** LiteLLM's model database, the file Aider downloads. */
    public const SOURCE_URL = 'https://raw.githubusercontent.com/BerriAI/litellm/main/model_prices_and_context_window.json';

    /** The flag-style opt-out; see the class docblock. */
    public const OPT_OUT_ENV = 'CANDY_CODE_DISABLE_MODEL_METADATA';

    /** A cache older than this is refreshed (24 h). */
    public const TTL_SECONDS = 86_400;

    /** The cache file's name under `~/.candy-code/cache/`. */
    public const CACHE_FILE = 'model_prices_and_context_window.json';

    /** Connect bound on the download. */
    public const DISCOVERY_CONNECT_TIMEOUT_SECONDS = 3.0;

    /**
     * Total bound on the download: about 1.4 MB, so generous for a slow link
     * and still short enough that the inline (no-pcntl) path cannot stall a
     * launch for long.
     */
    public const DISCOVERY_TIMEOUT_SECONDS = 10.0;

    /** A window or a rate outside these bounds is junk, not a figure. */
    private const MAX_WINDOW_TOKENS = 100_000_000;

    private const MAX_RATE_PER_TOKEN = 1.0;

    /**
     * PRV-1 (MAJOR-1): the leading segments that are LiteLLM ROUTING
     * NAMESPACES — provider names the project's own set of hosted
     * transports plus the common hosted families. A table key starting
     * with one of these followed by `/` is an alias for the provider's
     * NATIVE id (`openai/gpt-4o` means `gpt-4o` on api.openai.com), not
     * a model the endpoint knows by that spelling.
     *
     * The list is deliberately conservative in the fail-safe direction:
     * a missing name costs a palette row (the alias is dropped), while a
     * wrong one could strip a slash that belongs to a real id. Unknown
     * leading segments are NEVER stripped.
     *
     * @var list<string>
     */
    private const PROVIDER_PREFIXES = [
        'ai21',
        'aleph_alpha',
        'anthropic',
        'azure',
        'azure_ai',
        'baseten',
        'bedrock',
        'cerebras',
        'codestral',
        'cohere',
        'dashscope',
        'deepseek',
        'doubao',
        'featherless_ai',
        'fireworks_ai',
        'gemini',
        'github_ai',
        'github_copilot',
        'groq',
        'heroku',
        'hosted_vllm',
        'huggingface',
        'jina_ai',
        'lambda_ai',
        'mistral',
        'nebius',
        'novita_nlp',
        'nvidia_nim',
        'ollama',
        'ollama_chat',
        'openai',
        'openrouter',
        'perplexity',
        'publicai',
        'sagemaker',
        'scaleway',
        'text-completion-azure',
        'text-completion-openai',
        'together_ai',
        'vertex',
        'vertex_ai',
        'volc_engine',
        'xai',
        'xiaomi',
        'zai',
    ];

    /**
     * Parsed tables per cache path, shared by every instance in the process
     * and inherited by forked turn children, so a figure cannot change under
     * a running session and the file is decoded once.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private static array $loaded = [];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $table;

    /** @var array<string, string>|null lower-cased id => real key */
    private ?array $folded = null;

    /**
     * @param array<string, array<string, mixed>>|null $table  a fixed table, or null to load from $cachePath
     * @param (\Closure(): ?string)|null               $fetch  the download; null never downloads
     */
    private function __construct(
        ?array $table,
        private readonly ?string $cachePath,
        private readonly ?\Closure $fetch,
        private readonly bool $background,
        private readonly ?int $now,
    ) {
        $this->table = $table === null ? null : self::project($table);
    }

    /**
     * The production database: the cache under the owned home, refreshed in
     * the background from {@see SOURCE_URL} — or nothing at all when
     * {@see OPT_OUT_ENV} is set or no home can be established as this user's.
     */
    public static function new(): self
    {
        if (self::disabledFromEnvironment()) {
            return self::disabled();
        }

        $home = HomeDirectory::owned();
        if ($home === null) {
            return self::disabled();
        }

        return new self(
            null,
            rtrim($home, '/') . '/.candy-code/cache/' . self::CACHE_FILE,
            static fn (): ?string => self::download(self::SOURCE_URL),
            background: true,
            now: null,
        );
    }

    /** A database that knows no model. */
    public static function disabled(): self
    {
        return new self([], null, null, false, null);
    }

    /**
     * A fixed table in LiteLLM's shape — for embedders and tests.
     *
     * @param array<array-key, mixed> $table
     */
    public static function fromTable(array $table): self
    {
        return new self($table, null, null, false, null);
    }

    /**
     * The cache at `$cachePath`, refreshed through `$fetch` when it is older
     * than {@see TTL_SECONDS} at `$now` — inline unless `$background`. The
     * seam {@see new()} is built on, public so a test can drive the refresh
     * with no network and no real home.
     *
     * @param (\Closure(): ?string)|null $fetch returns the database body, or null on failure
     */
    public static function cachedAt(string $cachePath, ?\Closure $fetch = null, bool $background = false, ?int $now = null): self
    {
        return new self(null, $cachePath, $fetch, $background, $now);
    }

    /** Whether {@see OPT_OUT_ENV} is set: unset, empty and `0` are not. */
    public static function disabledFromEnvironment(): bool
    {
        $value = getenv(self::OPT_OUT_ENV);

        return $value !== false && $value !== '' && $value !== '0';
    }

    /**
     * The model's input window in tokens (`max_input_tokens`), or null when
     * the database does not know it.
     */
    public function contextWindow(string $model): ?int
    {
        $window = $this->entry($model)['max_input_tokens'] ?? null;

        return \is_int($window) ? $window : null;
    }

    /**
     * USD per 1K tokens for `$direction` — `input`, `output`, or `cached`
     * (a cache-hit prompt token) — or null when the database has no rate.
     *
     * @param 'input'|'output'|'cached' $direction
     */
    public function costPer1kTokens(string $model, string $direction): ?float
    {
        $field = match ($direction) {
            'input' => 'input_cost_per_token',
            'output' => 'output_cost_per_token',
            'cached' => 'cache_read_input_token_cost',
            default => null,
        };

        $rate = $field === null ? null : ($this->entry($model)[$field] ?? null);

        return \is_float($rate) ? $rate * 1000 : null;
    }

    /**
     * The database row for `$model`, projected to the fields this package
     * reads, or null. Matched exactly, then case-insensitively, then — Aider's
     * rule — as `<provider>/<id>` against the row for `<id>` whose
     * `litellm_provider` is that provider. No looser match: a self-hosted
     * model that merely shares a name fragment with a hosted one must not be
     * billed at the hosted price.
     *
     * @return array<string, mixed>|null
     */
    public function entry(string $model): ?array
    {
        $table = $this->table();
        if ($model === '' || $table === []) {
            return null;
        }

        if (isset($table[$model])) {
            return $table[$model];
        }

        $this->folded ??= self::fold($table);
        $key = $this->folded[strtolower($model)] ?? null;
        if ($key !== null) {
            return $table[$key];
        }

        $pieces = explode('/', $model);
        if (\count($pieces) === 2) {
            $key = $this->folded[strtolower($pieces[1])] ?? null;
            $row = $key === null ? null : $table[$key];
            if ($row !== null && strtolower((string) ($row['litellm_provider'] ?? '')) === strtolower($pieces[0])) {
                return $row;
            }
        }

        return null;
    }

    /**
     * PRV-1 (§6.5): the model names this database knows, for filling the
     * model palette and `models all` without any provider round trip.
     *
     * WITHOUT a $providerHint every entry is a table key verbatim, so
     * {@see entry()} resolves each one directly.
     *
     * WITH a $providerHint (the palette and `models all` — the paths whose
     * answer a user can SELECT and this app then WIREs), every entry is a
     * valid native model id for that family's endpoint. The table carries
     * routing-prefixed aliases next to the bare keys — `openai/gpt-4o`,
     * `bedrock/anthropic.claude-…:0`, `vertex_ai/…` — and a prefix is
     * LiteLLM's namespace, not part of what the endpoint accepts: wiring
     * `openai/gpt-4o` to api.openai.com 400s every request. The rule, per
     * row (matched case-insensitively against the row's own
     * `litellm_provider`, exactly like entry()'s two-piece lookup, so a
     * self-hosted name fragment never rides in — the same honesty rule as
     * the pricing match):
     *
     *  - a slash-free key rides verbatim;
     *  - a `{prefix}/{id}` key has its prefix stripped only when the
     *    prefix is a recognized routing namespace AND names the very
     *    family being queried (a foreign family's prefix on this row is
     *    unverifiable for this endpoint);
     *  - a key whose leading segment is NOT a recognized prefix is
     *    excluded outright — nothing proves it is native to this endpoint
     *    rather than another gateway's routing form, and an omitted row
     *    is safe where a wire-invalid one is the MAJOR this rule closes.
     *    (Such ids still reach the palette through the endpoint's own
     *    live listing, where they come from the server that serves them.)
     *
     * The stripped remainder keeps its internal slashes, colons and at-
     * signs untouched — Bedrock inference-profile ARNs, `…:0` version
     * suffixes and `…@20240620` Vertex pins are native spellings. A
     * stripped alias that names a bare row is the same string, so the
     * dedupe leaves exactly one entry and the bare id is its spelling.
     *
     * Wildcard pattern rows (LiteLLM's defaults for unlisted variants —
     * see the chr(42) respelling in the tests) are never offered: they
     * are price fallbacks, not selectable models. The result is sorted
     * for stable palette order and capped at $limit rows when given.
     *
     * CONTAINMENT (fx/palette-sec review MINOR-1): every entry passes
     * {@see PaneLabel::of()} before it is offered. The table's keys are
     * remote wire bytes — the download is parsed by {@see json_decode()},
     * and a JSON *escape* (`\u001b`) is well-formed where a raw control
     * byte is not, so a poisoned database could otherwise ship an OSC-8
     * id straight into the palette rows and the `models all` print loop
     * (the endpoint tier is swept at {@see OpenAiModelsEndpoint::decode()},
     * this is the table twin). Honest ids contain no control or spacing
     * bytes, so they round-trip byte-identical; the fold may surface a
     * whitespace between a stripped prefix and its remainder, and an id
     * that sweeps to nothing is dropped like any unusable row.
     *
     * @return list<string>
     */
    public function candidates(?string $providerHint = null, int $limit = 0): array
    {
        $table = $this->table();
        if ($table === []) {
            return [];
        }

        $hint = $providerHint === null ? null : strtolower(trim($providerHint));

        $out = [];
        $seen = [];
        foreach ($table as $model => $row) {
            if ($model === '' || str_contains($model, '*')) {
                continue;
            }

            if ($hint !== null && strtolower((string) ($row['litellm_provider'] ?? '')) !== $hint) {
                continue;
            }

            $label = PaneLabel::of((string) $model);
            if ($label === '') {
                continue;
            }

            if ($hint !== null && str_contains($label, '/')) {
                $native = self::nativeAlias($label, $hint);
                if ($native === null) {
                    continue;
                }

                $label = $native;
            }

            // A stripped alias that equals a bare row is the SAME string,
            // so the bare entry survives this exact-match dedupe either
            // way — one row, native spelling, insertion order immaterial.
            if (isset($seen[$label])) {
                continue;
            }

            $seen[$label] = true;
            $out[] = $label;
        }

        sort($out, SORT_STRING);

        return $limit > 0 ? array_slice($out, 0, $limit) : $out;
    }

    /**
     * PRV-1 (MAJOR-1): the native endpoint id a `{prefix}/{id}` routing
     * alias stands for, or null to drop the row from a family-filtered
     * palette (unknown or foreign prefix, junk remainder).
     */
    private static function nativeAlias(string $key, string $hint): ?string
    {
        $slash = (int) strpos($key, '/');
        $prefix = strtolower(substr($key, 0, $slash));
        $rest = substr($key, $slash + 1);

        if (!\in_array($prefix, self::PROVIDER_PREFIXES, true) || $prefix !== $hint) {
            return null;
        }

        return $rest !== '' && self::isNativeModelId($rest) ? $rest : null;
    }

    /**
     * Whether `$id` is a plausible native model id: a tight character
     * grammar (letters, digits, and the `. _ : / @ + -` separators real
     * ids use — nothing else), starting alphanumeric, no wildcards, and
     * never absurdly long (ARNs and customer inference profiles are the
     * legitimate long tail).
     */
    private static function isNativeModelId(string $id): bool
    {
        // The (?: group is load-bearing beyond grammar: a bare-class preg
        // literal here would be glob-SHAPED to GlobDialectDifferentialTest's
        // corpus harvest and drift its census for no reason.
        return \strlen($id) <= 512 && preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9._:\/@+\-]*)$/', $id) === 1;
    }

    /**
     * The parsed table, loaded on first use: from a fixed table, else from
     * the process memo, else from the cache file — after starting a refresh
     * when that file is stale.
     *
     * @return array<string, array<string, mixed>>
     */
    private function table(): array
    {
        if ($this->table !== null) {
            return $this->table;
        }

        if ($this->cachePath === null) {
            return $this->table = [];
        }

        if (isset(self::$loaded[$this->cachePath])) {
            return $this->table = self::$loaded[$this->cachePath];
        }

        if ($this->fetch !== null && !$this->isFresh()) {
            $this->background ? $this->refreshInBackground() : $this->refresh();
        }

        return $this->table = self::$loaded[$this->cachePath] = $this->readCache();
    }

    private function isFresh(): bool
    {
        clearstatcache(true, (string) $this->cachePath);
        $mtime = @filemtime((string) $this->cachePath);

        return $mtime !== false && $mtime >= ($this->now ?? time()) - self::TTL_SECONDS;
    }

    /** @return array<string, array<string, mixed>> */
    private function readCache(): array
    {
        $path = (string) $this->cachePath;
        if (!is_file($path)) {
            return [];
        }

        $body = @file_get_contents($path);
        $decoded = \is_string($body) ? json_decode($body, true) : null;

        return \is_array($decoded) ? self::project($decoded) : [];
    }

    /**
     * Download, compact and publish the database under the refresh lock; on
     * any failure keep what is there and restart its clock (or write `{}` when
     * there is nothing), so an offline host neither loses its figures nor
     * retries on every launch. Never throws.
     */
    private function refresh(): void
    {
        $lock = $this->acquireLock();
        if ($lock === null) {
            return;
        }

        try {
            $this->downloadAndPublish();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * {@see refresh()} in a detached grandchild, so the caller goes on at
     * once. The lock is taken HERE, before forking, so a second process that
     * looks while the download runs sees it held and does not start another;
     * the grandchild inherits the locked descriptor and releases it by
     * exiting. Falls back to the inline refresh without ext-pcntl.
     */
    private function refreshInBackground(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            $this->refresh();

            return;
        }

        $lock = $this->acquireLock();
        if ($lock === null) {
            return;
        }

        $child = pcntl_fork();
        if ($child === 0) {
            $this->detachAndRefresh();
        }

        // The child never returns here, so from this line on this is the
        // caller: either the fork failed, or the intermediate child is
        // already on its way out. A flock belongs to the open file
        // description the children share, so after a successful fork the
        // caller only CLOSES its copy — LOCK_UN here would release the lock
        // the worker is refreshing under.
        if ($child === -1) {
            flock($lock, LOCK_UN);
        }
        fclose($lock);
        if ($child > 0) {
            // The intermediate child exits at once, so this wait is immediate;
            // the grandchild is reparented and never becomes our zombie.
            pcntl_waitpid($child, $status);
        }
    }

    /**
     * The intermediate child of {@see refreshInBackground()}: fork the
     * worker and leave. Both exits are {@see ForkedChild::exitNow()}, which
     * skips PHP's shutdown sequence, so nothing inherited from the TUI (its
     * raw-mode terminal, the event loop) is torn down from here. The worker
     * keeps the inherited, still-locked descriptor until it exits, which is
     * what holds the refresh lock after the caller has let go of its copy.
     */
    private function detachAndRefresh(): never
    {
        $worker = pcntl_fork();
        if ($worker === 0) {
            if (\function_exists('posix_setsid')) {
                @posix_setsid();
            }

            // downloadAndPublish() catches every Throwable itself.
            $this->downloadAndPublish();
            ForkedChild::exitNow(0);
        }

        ForkedChild::exitNow(0);
    }

    private function downloadAndPublish(): void
    {
        $path = (string) $this->cachePath;

        try {
            $body = $this->fetch === null ? null : ($this->fetch)();
            $decoded = \is_string($body) ? json_decode($body, true) : null;
            $table = \is_array($decoded) ? self::project($decoded) : [];

            if ($table !== []) {
                AtomicFileWriter::write($path, (string) json_encode($table, JSON_UNESCAPED_SLASHES), 0600);

                return;
            }
        } catch (\Throwable) {
            // Falls through to the keep-what-is-there arm below.
        }

        try {
            if (is_file($path)) {
                @touch($path, $this->now ?? time());
            } else {
                AtomicFileWriter::write($path, '{}', 0600);
                @touch($path, $this->now ?? time());
            }
        } catch (\Throwable) {
            // Best effort: an unwritable cache only means asking again next launch.
        }
    }

    /** @return resource|null the held lock, or null when another process holds it */
    private function acquireLock()
    {
        $path = (string) $this->cachePath;
        $dir = \dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return null;
        }

        $lock = @fopen($path . '.lock', 'c');
        if ($lock === false) {
            return null;
        }

        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return null;
        }

        return $lock;
    }

    /** The database body from `$url`, or null; bounded, never throws. */
    private static function download(string $url): ?string
    {
        try {
            $client = self::guzzleClient([
                'handler' => HandlerStack::create(),
                'connect_timeout' => self::DISCOVERY_CONNECT_TIMEOUT_SECONDS,
                'timeout' => self::DISCOVERY_TIMEOUT_SECONDS,
                'http_errors' => true,
                'headers' => ['Accept' => 'application/json'],
            ]);

            return (string) $client->get($url)->getBody();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * LiteLLM's rows reduced to the fields read here, each validated: chat
     * models only (no `mode`, or `chat` / `responses`), a window that is a
     * positive whole number of sane size, rates that are finite, non-negative
     * and below a dollar per token. A row with nothing usable left is
     * dropped, and so is the `sample_spec` documentation row.
     *
     * @param array<array-key, mixed> $raw
     *
     * @return array<string, array<string, mixed>>
     */
    private static function project(array $raw): array
    {
        $table = [];

        foreach ($raw as $model => $row) {
            if (!\is_string($model) || $model === '' || $model === 'sample_spec' || !\is_array($row)) {
                continue;
            }

            $mode = $row['mode'] ?? 'chat';
            if (!\in_array($mode, ['chat', 'responses'], true)) {
                continue;
            }

            $entry = [];
            $window = $row['max_input_tokens'] ?? null;
            if (\is_int($window) && $window > 0 && $window <= self::MAX_WINDOW_TOKENS) {
                $entry['max_input_tokens'] = $window;
            }

            foreach (['input_cost_per_token', 'output_cost_per_token', 'cache_read_input_token_cost'] as $field) {
                $rate = $row[$field] ?? null;
                if ((\is_int($rate) || \is_float($rate)) && is_finite((float) $rate) && $rate >= 0 && $rate < self::MAX_RATE_PER_TOKEN) {
                    $entry[$field] = (float) $rate;
                }
            }

            if ($entry === []) {
                continue;
            }

            if (\is_string($row['litellm_provider'] ?? null)) {
                $entry['litellm_provider'] = $row['litellm_provider'];
            }

            $table[$model] = $entry;
        }

        return $table;
    }

    /**
     * @param array<string, array<string, mixed>> $table
     *
     * @return array<string, string>
     */
    private static function fold(array $table): array
    {
        $folded = [];
        foreach (array_keys($table) as $key) {
            $folded[strtolower($key)] ??= $key;
        }

        return $folded;
    }
}
