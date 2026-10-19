<?php

declare(strict_types=1);

namespace SugarCraft\Code\Providers;

use SugarCraft\Code\Providers\Concerns\HttpClientDefaults;
use SugarCraft\Code\Providers\ToolCallParser\DsmlToolCallParser;
use SugarCraft\Code\Providers\ToolCallParser\MinimaxXmlFallbackToolCallParser;
use SugarCraft\Code\Providers\ToolCallParser\OpenAiArrayToolCallParser;
use SugarCraft\Code\Providers\ToolCallParser\ToolCallParserInterface;
use SugarCraft\Code\Support\ContainedPath;

/**
 * Factory for creating provider instances from configuration arrays.
 *
 * Mirrors charmbracelet/charmbracelet.ProviderFactory - creates providers
 * from config with environment variable resolution support.
 */
final readonly class ProviderFactory
{
    use HttpClientDefaults;

    /**
     * @param ModelMetadata|null $modelMetadata Roadmap 5.13a: the model
     *        database the `openai` and `custom` providers consult between the
     *        operator's settings and their built-in fallbacks. (ISS-023 took
     *        `anthropic` off this list: the native Messages provider ships
     *        its own family price and window tables, like Vertex and Bedrock.)
     *        Null (every production construction) is {@see ModelMetadata::new()},
     *        resolved per provider built — cheap, because it loads nothing
     *        until a provider first asks and the parsed table is shared
     *        process-wide. A test or an embedder passes its own.
     */
    public function __construct(
        private ?ModelMetadata $modelMetadata = null,
    ) {
    }

    /**
     * §12 D6 tool-call-parser names, mirroring SGLang's `--tool-call-parser`
     * flag: the default assumes the server was launched with a real parser
     * flag; the fallback is for one that was not.
     */
    public const TOOL_CALL_PARSER_OPENAI = 'openai';

    public const TOOL_CALL_PARSER_MINIMAX_XML_FALLBACK = 'minimax-xml-fallback';

    /**
     * DeepSeek-V4's native DSML markup ({@see DsmlToolCallParser}), for a
     * DeepSeek deployment launched without a `--tool-call-parser` flag - which
     * is what that model card's own documented launch command shows.
     */
    public const TOOL_CALL_PARSER_DSML = 'dsml';

    /**
     * Every selectable name, in one place, so
     * {@see toolCallParser()}'s error message CANNOT omit a parser it accepts.
     *
     * That message used to be `'expected "%s" or "%s"'` with exactly two
     * arguments: adding a third parser without touching it would have printed
     * an error omitting the very name the operator was trying to spell. The
     * list is built from this constant rather than typed as a third literal
     * for the same reason.
     *
     * This does NOT by itself keep the constant in step with the `match` arms
     * - PHP has no way to derive one from the other. That guarantee is a test:
     * every name here must construct, and the thrown message must name every
     * name here.
     */
    public const TOOL_CALL_PARSER_NAMES = [
        self::TOOL_CALL_PARSER_OPENAI,
        self::TOOL_CALL_PARSER_MINIMAX_XML_FALLBACK,
        self::TOOL_CALL_PARSER_DSML,
    ];

    /**
     * The dev/test-fixture config this factory reads, relative to
     * {@see packageRoot()}.
     *
     * ONE LITERAL, split by `dirname()` where the directory half is needed, for the
     * reason {@see \SugarCraft\Code\Agents\WorktreeConfig::readConfig()} keeps one:
     * {@see \SugarCraft\Code\Tests\Cli\ProjectTierRefusalInventoryTest} derives its
     * dot-path census from string literals, and a path assembled from fragments is
     * its stated blind spot — so spelling the two halves separately would hide this
     * read path from the instrument that classifies it.
     */
    private const CONFIG_PATH = '.candy-code/config.dev.json';

    /**
     * Cheap per-type defaults for auxiliary calls (ISS-077): session
     * titling, `/btw`, goal judgements and prompt suggestions are a handful
     * of output tokens, so when the user names no `titleModel` the tool-less
     * backend picks one of these instead of billing the provider block's
     * conversation-class default. A type absent from the map (sglang, custom,
     * claude-code) keeps the block model - there is no sibling id we can
     * promise a local/subscription endpoint serves.
     *
     * @var array<string, string>
     */
    public const AUXILIARY_MODEL_BY_TYPE = [
        'anthropic' => 'claude-haiku-4-5',
        'vertex' => 'claude-haiku-4-5',
        'bedrock' => 'us.anthropic.claude-haiku-4-5',
        'openai' => 'gpt-4o-mini',
    ];

    /**
     * The auxiliary-cheap model id for a provider type, or null when the
     * type has none (see {@see AUXILIARY_MODEL_BY_TYPE}).
     */
    public static function auxiliaryModelFor(string $type): ?string
    {
        return self::AUXILIARY_MODEL_BY_TYPE[$type] ?? null;
    }

    private const TYPE_SCHEMAS = [
        'openai' => [
            'required' => ['apiKey'],
            // rv-enduser-1: the wire target of every other type is settable;
            // `openai` was the one stuck to the SDK's hardcoded host, which
            // made gateways and self-hosted compat servers require renaming
            // the block to `custom` and re-declaring everything.
            'optional' => ['baseUrl', 'organization', 'model', 'modelPrices', 'contextWindow', 'supportsVision', 'fallbackModels'],
        ],
        'anthropic' => [
            'required' => ['apiKey'],
            'optional' => ['baseUrl', 'model', 'modelPrices', 'contextWindow', 'fallbackModels', 'thinkingBudget'],
        ],
        'claude-code' => [
            'required' => ['claudePath'],
            'optional' => ['model', 'bare', 'fallbackModels'],
        ],
        'sglang' => [
            'required' => ['baseUrl', 'model'],
            'optional' => ['apiKey', 'toolCallParser', 'reasoningEffort', 'templateKwargs', 'discoverServerInfo', 'supportsVision', 'fallbackModels', 'mediaKinds', 'mediaBaseUrl', 'mediaApiKey', 'contextWindow'],
        ],
        'bedrock' => [
            'required' => ['region'],
            'optional' => ['model', 'modelPrices', 'promptCache', 'fallbackModels', 'contextWindow'],
        ],
        'vertex' => [
            'required' => ['projectId'],
            'optional' => ['location', 'model', 'modelPrices', 'thinkingBudget', 'promptCache', 'fallbackModels', 'contextWindow'],
        ],
        'custom' => [
            'required' => ['name', 'baseUrl', 'model'],
            'optional' => ['apiKey', 'supportsStreaming', 'supportsFunctionCalling', 'extraBody', 'supportsVision', 'modelPrices', 'contextWindow', 'fallbackModels', 'mediaKinds', 'mediaBaseUrl', 'mediaApiKey'],
        ],
    ];

    /**
     * Creates a provider from a config array or JSON string.
     *
     * @param array|string $config Configuration as array or JSON string
     * @throws \InvalidArgumentException When config is invalid or type is missing
     * @throws \RuntimeException When required keys are missing
     */
    public function create(array|string $config): ProviderInterface
    {
        // Parse JSON string to array if needed - Early Exit on invalid JSON
        if (is_string($config)) {
            $config = $this->parseJson($config);
        }

        // Validate config is now an array
        if (!is_array($config)) {
            throw new \InvalidArgumentException('Config must be an array or valid JSON string');
        }

        // Early Exit - must have 'type' key
        if (!isset($config['type'])) {
            throw new \InvalidArgumentException('Config must have a "type" key');
        }

        $type = $config['type'];

        // Early Exit - validate provider type
        if (!$this->isValidType($type)) {
            throw $this->unknownTypeError($type);
        }

        // Resolve environment variables in all string values
        $config = $this->resolveEnvVars($config);

        // Validate required keys for this type
        $this->validateRequiredKeys($type, $config);

        // Create the appropriate provider
        $provider = $this->instantiateProvider($type, $config);

        // Roadmap 5.13b: a block naming `fallbackModels` is wrapped, so every
        // reader of this one factory call — the session, the turn child that
        // rebuilds it from the same block, the title and summary backends —
        // inherits the chain. Each fallback is THIS block with only `model`
        // replaced, built on first use, so it is sized and priced as itself.
        $fallbackModels = self::configuredFallbackModels($config['fallbackModels'] ?? null);
        if ($fallbackModels === []) {
            return $provider;
        }

        $primaryModel = is_string($config['model'] ?? null) ? $config['model'] : '';
        $builders = [];
        foreach ($fallbackModels as $model) {
            $builders[$model] = fn (): ProviderInterface => $this->instantiateProvider(
                $type,
                array_replace($config, ['model' => $model]),
            );
        }
        unset($builders[$primaryModel]);

        return $builders === [] ? $provider : FallbackProvider::new($provider, $primaryModel, $builders);
    }

    /**
     * The `fallbackModels` provider-block key (roadmap 5.13b): model ids of
     * the same provider to try, in order, when a request fails transiently or
     * overflows ({@see FallbackProvider}). A single string is a one-model
     * list. Non-strings and blanks are dropped and duplicates collapse — the
     * tolerant posture of {@see configuredContextWindow()}, because a provider
     * that throws while being built degrades the whole launch to `echo`.
     *
     * @return list<string>
     */
    private static function configuredFallbackModels(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        $models = [];
        foreach ($value as $model) {
            if (is_string($model) && trim($model) !== '') {
                $models[trim($model)] = true;
            }
        }

        return array_keys($models);
    }

    /**
     * The `mediaKinds` provider-block key (plan_crush_media W1.8 step 2):
     * which media this endpoint generates — a subset of `image` and `video`.
     * A single string is a one-kind list. Unknown words are dropped and
     * duplicates collapse in input order — the tolerant posture of
     * {@see self::configuredFallbackModels()}, because a provider that throws
     * while being built degrades the whole launch to `echo`. Public as the
     * seam the media router (W1.9+) reads kinds through; `create()` itself
     * does not consume the key yet.
     *
     * @return list<'image'|'video'>
     */
    public static function configuredMediaKinds(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        $kinds = [];
        foreach ($value as $kind) {
            if (is_string($kind) && \in_array($kind, ['image', 'video'], true) && !\in_array($kind, $kinds, true)) {
                $kinds[] = $kind;
            }
        }

        return $kinds;
    }

    /**
     * The library root this factory reads its dev config from: two directories
     * above this file (`src/Providers` -> `src` -> root).
     *
     * TWO climbs, not three. A third overshoots into whatever happens to sit above
     * this library on disk — fine by coincidence in the monorepo checkout, where a
     * sibling `.candy-code/` exists one level up, but wrong in general and fatal
     * once candy-code is split into its own repo, where nothing exists above it.
     *
     * A named seam rather than an inline expression, for the reason
     * {@see \SugarCraft\Code\Agents\WorktreeConfig::defaultConfigDir()} is one:
     * the containment gates below need to name the tree they anchor to, and a
     * boundary spelled twice is a boundary that drifts.
     */
    private static function packageRoot(): string
    {
        return \dirname(__DIR__, 2);
    }

    /**
     * Filesystem location of the dev/test-fixture provider config.
     *
     * WHERE IT WOULD BE, not a promise that it may be read — the same distinction
     * {@see \SugarCraft\Code\Workflows\WorkflowRegistry::projectWorkflowsPath()}
     * draws. {@see readableDefaultConfigPath()} is what answers the second
     * question, and every reader in `src/` asks that one.
     */
    public static function defaultConfigPath(): string
    {
        return self::packageRoot() . '/' . self::CONFIG_PATH;
    }

    /**
     * {@see defaultConfigPath()} when it may actually be read, or null.
     *
     * THE SAME `__DIR__`-RELATIVE, CONTAINMENT-FREE CONSTRUCTION AS THE NINTH READ
     * PATH, and it was on neither inventory. `WorktreeConfig::new()` read
     * `__DIR__ . '/../../../.candy-code/config.json'` with no gate of any kind and
     * was closed a round ago; this read `__DIR__ . '/../../.candy-code/config.dev.json'`
     * the same way, and its contents decide which provider a launch talks to —
     * `baseUrl` and `apiKey` included — through {@see fromProjectConfig()},
     * {@see projectProviderConfig()} and, at launch,
     * {@see \SugarCraft\Code\Cli\Bootstrap::availableProviders()}.
     *
     * TWO BOUNDARIES, the pair every repository-chosen read in this package
     * carries:
     *
     *  - `<root>/.candy-code` must resolve STRICTLY inside `<root>`
     *    ({@see ContainedPath::below()}), so a `.candy-code -> /elsewhere` inside
     *    the shipped package relocates the check instead of tripping it;
     *  - `config.dev.json` must resolve inside that directory
     *    ({@see ContainedPath::within()}), so `config.dev.json -> /elsewhere/evil.json`
     *    is refused on its own.
     *
     * WHOSE CHOICE IS THIS PATH, stated because the answer decides whether the gate
     * is worth anything. Under a composer install the package sits in
     * `vendor/sugarcraft/candy-code`, so its `.candy-code/` arrives with the
     * DEPENDENCY — and a dependency's tarball carries symlinks exactly as a clone
     * carries committed ones (the measurement on
     * {@see \SugarCraft\Code\Cli\Bootstrap::agentPresetTiers()}). In the monorepo
     * it is this checkout's own file. Both cases are content whose location this
     * process did not choose.
     *
     * ABSENCE IS NOT REFUSAL. A missing file returns null here as well, because
     * {@see ContainedPath} refuses what it cannot resolve — so callers must keep
     * reporting "not found" from the CONFIGURED path
     * ({@see defaultConfigPath()}) rather than from this. The distinction the two
     * methods draw is "where" versus "may I", and neither is "does it exist".
     *
     * @param string|null $packageRoot The tree holding `.candy-code/`; null means
     *        {@see packageRoot()}. Injectable for the reason
     *        {@see \SugarCraft\Code\Agents\WorktreeConfig::new()}'s `$configDir` is:
     *        both gates can only be DRIVEN against a synthetic tree, and the
     *        alternative is committing a symlink into this repository's own
     *        `.candy-code/` and restoring it in a `finally` — a git-tracked file
     *        that an interrupted run leaves mutated. It is NOT a way around the
     *        gates, which apply to an injected root exactly as to the default one.
     */
    public static function readableDefaultConfigPath(?string $packageRoot = null): ?string
    {
        $root = $packageRoot ?? self::packageRoot();
        $path = $root . '/' . self::CONFIG_PATH;
        $dir = \dirname($path);

        if (!ContainedPath::below($dir, $root) || !ContainedPath::within($path, $dir)) {
            return null;
        }

        return $path;
    }

    /**
     * Creates a provider from the project's .candy-code/config.dev.json
     * (or an explicit override path).
     *
     * Without $name, the provider named by the config file's
     * 'defaultProvider' key is used - this is what makes dev-sglang the
     * default backend for the dev loop and test fixtures. With $name, a
     * specific entry under 'providers' is selected instead, so any provider
     * declared in the file - not only the default - is loadable.
     *
     * @throws \RuntimeException When the file is missing/unreadable, invalid
     *     JSON, missing 'defaultProvider' (when $name is null), or missing
     *     the requested entry under 'providers'.
     */
    public function fromProjectConfig(?string $name = null, ?string $configPath = null): ProviderInterface
    {
        // THE GATE APPLIES TO THE DEFAULT PATH ONLY, and the asymmetry is a
        // decision rather than an oversight: the default is the `__DIR__`-relative
        // construction nobody in the session chose ({@see readableDefaultConfigPath()}),
        // while an explicit $configPath is a path THIS CALLER named — a test
        // fixture, or an operator pointing at their own file — and gating it would
        // need a containing tree that only the caller can name. What the caller
        // gets by passing one is the per-file boundary they chose to skip; that is
        // stated here rather than left as an inference from the signature.
        if ($configPath === null) {
            $configPath = self::readableDefaultConfigPath();

            if ($configPath === null) {
                // The CONFIGURED path in the message, since the refused one has no
                // path this factory is willing to have resolved. "not found" is the
                // shared wording because absence and refusal are the same answer to
                // this method's caller: there is no project config to read.
                throw new \RuntimeException(
                    'Provider config file not found: ' . self::defaultConfigPath(),
                );
            }
        }

        if (!is_file($configPath)) {
            throw new \RuntimeException("Provider config file not found: {$configPath}");
        }

        $contents = file_get_contents($configPath);
        if ($contents === false) {
            throw new \RuntimeException("Failed to read provider config file: {$configPath}");
        }

        $data = $this->parseJson($contents);

        if ($name === null) {
            if (!isset($data['defaultProvider']) || !is_string($data['defaultProvider']) || $data['defaultProvider'] === '') {
                throw new \RuntimeException("Provider config file '{$configPath}' is missing a 'defaultProvider' key");
            }
            $name = $data['defaultProvider'];
        }

        if (!isset($data['providers'][$name]) || !is_array($data['providers'][$name])) {
            throw new \RuntimeException("Provider config file '{$configPath}' has no 'providers.{$name}' entry");
        }

        return $this->create($data['providers'][$name]);
    }

    /**
     * Resolves ${VAR} and ${VAR:-default} patterns from environment.
     *
     * @param string|null $value The value to resolve
     * @return string|null Resolved value or null if not set and no default
     */
    public function resolveEnv(?string $value): ?string
    {
        // Early exit - nothing to resolve
        if ($value === null) {
            return null;
        }

        // Pattern: ${VAR} or ${VAR:-default}
        return preg_replace_callback(
            '/\$\{([A-Z_][A-Z0-9_]*)(?::-([^}]*))?\}/',
            function (array $matches): string {
                $varName = $matches[1];
                $default = $matches[2] ?? null;

                $envValue = getenv($varName);

                if ($envValue === false || $envValue === '') {
                    return $default ?? '';
                }

                return $envValue;
            },
            $value
        );
    }

    /**
     * Returns the list of available provider types.
     *
     * @return array<string>
     */
    public function availableTypes(): array
    {
        return ['openai', 'anthropic', 'claude-code', 'sglang', 'bedrock', 'vertex', 'custom'];
    }

    /**
     * Returns default configuration for a provider type.
     *
     * bin/candy-code's $CANDY_CODE_PROVIDER backend selection calls exactly
     * `$factory->create($factory->defaultConfig($providerType))` - it has no
     * other hook into the provider system. Before a name is rejected as
     * unknown, fall back to the project's .candy-code/config.dev.json
     * 'providers' map: this is what makes 'dev-sglang' (config.dev.json's own
     * 'defaultProvider') a name $CANDY_CODE_PROVIDER can actually select,
     * without bin/candy-code needing any awareness of fromProjectConfig().
     *
     * @param string $type The provider type, or a name declared under
     *     'providers' in .candy-code/config.dev.json (e.g. 'dev-sglang')
     * @return array<string, mixed> Default configuration for the type
     * @throws \InvalidArgumentException When type is neither a built-in type
     *     nor a name declared in the project's provider config
     */
    public function defaultConfig(string $type): array
    {
        if (!$this->isValidType($type)) {
            $projectConfig = $this->projectProviderConfig($type);
            if ($projectConfig !== null) {
                return $projectConfig;
            }

            throw $this->unknownTypeError($type);
        }

        return match ($type) {
            'openai' => [
                'type' => 'openai',
                'apiKey' => getenv('OPENAI_API_KEY') ?: '',
                'organization' => getenv('OPENAI_ORG_ID') ?: null,
                // rv-enduser-1: same env-var shape as anthropic's line below.
                // Null means "the SDK's own api.openai.com", and the provider
                // only calls withBaseUri() when it is set, so an unset env is
                // byte-identical to before.
                'baseUrl' => getenv('OPENAI_BASE_URL') ?: null,
                'model' => 'gpt-4o',
            ],
            'anthropic' => [
                'type' => 'anthropic',
                'apiKey' => getenv('ANTHROPIC_API_KEY') ?: '',
                'baseUrl' => getenv('ANTHROPIC_BASE_URL') ?: 'https://api.anthropic.com',
                'model' => 'claude-sonnet-4-6',
            ],
            'claude-code' => [
                'type' => 'claude-code',
                'claudePath' => 'claude',
                'model' => 'claude-sonnet-4-6',
            ],
            'sglang' => [
                'type' => 'sglang',
                // rv-enduser-1: OPENAI_BASE_URL is the one endpoint variable
                // operators already export for OpenAI-compatible servers
                // (rv-sec's `providers` ask names it too); the localhost URL
                // stays the fallback when it is unset.
                'baseUrl' => getenv('OPENAI_BASE_URL') ?: 'http://localhost:30000',
                // The FOURTH place this default lived, and the one
                // `$CANDY_CODE_PROVIDER=sglang` actually reaches (this method
                // is bin/candy-code's only hook into the provider system, see
                // above). It said MiniMax-M2.7 while the confirmed deployment
                // had already been switched to DeepSeek-V4-Flash, so a default
                // sglang run 404'd on the model name. Sourced from
                // {@see SglangProvider::DEFAULT_MODEL} rather than repeated as
                // a literal, so the id cannot drift between the two files.
                //
                // And since audit A26 the id is a FALLBACK: the provider built
                // from it sends the model the server reports serving instead
                // (SglangProvider::addressedToServedModel()), so the next
                // redeploy does not leave this default naming a gone model.
                // Another id here - or `--model` - is sent as named.
                'model' => SglangProvider::DEFAULT_MODEL,
                'apiKey' => getenv('SGLANG_API_KEY') ?: null,
                // Null, NOT a concrete level, and the difference is the
                // point: the effective default is derived from the MODEL
                // ({@see SglangProvider::defaultReasoningEffort()}) - 'max'
                // for DeepSeek-V4, the Qwen3.8 rule for that family - so
                // stamping a literal here would keep sending it after someone
                // edits `model` (or the server's served model changes) to a
                // family it was never measured on. The key is present anyway so the knob is discoverable
                // from defaultConfig() output, which is what the Ctrl+P
                // palette's Switch Model listing shows.
                'reasoningEffort' => null,
                // Null, NOT a concrete parser name, for EXACTLY the reason
                // `reasoningEffort` above is null: the effective default is
                // derived from the MODEL
                // ({@see defaultToolCallParserFor()} - `dsml` for the
                // DeepSeek-V4 family, `openai` otherwise), so stamping
                // `'openai'` here would keep selecting the OpenAI-only parser
                // after someone edits `model`, silently disarming the DSML
                // fallback on the very family that needs it.
                //
                // This USED to be `self::TOOL_CALL_PARSER_OPENAI`. It was a
                // correct literal while `openai` was the unconditional
                // default; it became a decaying one the moment the default
                // grew a second arm.
                //
                // The key is still present so the knob stays discoverable from
                // defaultConfig() output - which is exactly what the Ctrl+P
                // palette's Switch Model listing shows
                // ({@see \SugarCraft\Code\Cli\Bootstrap::availableProviders()}).
                'toolCallParser' => null,
            ],
            'bedrock' => [
                'type' => 'bedrock',
                'region' => 'us-east-1',
                // Sourced from the provider rather than repeated (audit A20):
                // this literal still named the bare foundation-model id after
                // the provider's own default moved to the inference-profile id
                // that Claude 4.x on-demand throughput requires, so the one
                // config a default `bedrock` launch actually reaches sent the
                // shape AWS refuses.
                'model' => BedrockProvider::DEFAULT_MODEL,
            ],
            'vertex' => [
                'type' => 'vertex',
                'projectId' => getenv('GCP_PROJECT_ID') ?: '',
                'location' => 'us-central1',
                // ISS-025: the previous default, claude-3-sonnet@20240229,
                // is a retired model - Anthropic retired Claude 3 Sonnet
                // (2025-07-21) and Vertex has shut the endpoint down, so a
                // fresh vertex config failed every request with "publisher
                // model not found". The default must be a current model that
                // also resolves in VertexProvider::PRICE_TABLE.
                'model' => 'claude-sonnet-4-6',
            ],
            'custom' => [
                'type' => 'custom',
                'name' => 'custom',
                // Same OPENAI_BASE_URL honour as sglang above.
                'baseUrl' => getenv('OPENAI_BASE_URL') ?: 'http://localhost:8080',
                'model' => 'gpt-4o',
                'apiKey' => null,
                'supportsStreaming' => true,
                'supportsFunctionCalling' => true,
            ],
            default => throw $this->unknownTypeError($type),
        };
    }

    /**
     * Validates whether a type is a known provider type.
     */
    private function isValidType(string $type): bool
    {
        return isset(self::TYPE_SCHEMAS[$type]);
    }

    /**
     * Looks up $name under 'providers' in the project's
     * .candy-code/config.dev.json, for defaultConfig()'s fallback.
     *
     * Returns null - never throws - when the config file is absent,
     * unreadable, invalid JSON, or simply doesn't declare $name, so
     * defaultConfig() can fall through to its normal "Unknown provider
     * type" error for a name that is neither a built-in type nor a
     * project-config entry. fromProjectConfig() covers the throwing,
     * diagnostic-message variant of this same lookup; this helper is
     * deliberately silent because defaultConfig() must keep working with
     * zero project config present (e.g. the standalone-repo split case
     * documented on defaultConfigPath()).
     *
     * @return array<string, mixed>|null
     */
    private function projectProviderConfig(string $name): ?array
    {
        // Null covers refusal as well as absence here, which is exactly this
        // helper's documented tolerance: {@see defaultConfig()} must keep working
        // with no project config present, and a config this package may not read is
        // one that is not present as far as that fall-through is concerned.
        $configPath = self::readableDefaultConfigPath();

        if ($configPath === null || !is_file($configPath)) {
            return null;
        }

        $contents = file_get_contents($configPath);
        if ($contents === false) {
            return null;
        }

        try {
            $data = $this->parseJson($contents);
        } catch (\InvalidArgumentException) {
            return null;
        }

        if (!isset($data['providers'][$name]) || !is_array($data['providers'][$name])) {
            return null;
        }

        return $data['providers'][$name];
    }

    /**
     * Parses a JSON string into an array.
     *
     * @throws \InvalidArgumentException When JSON is invalid
     */
    private function parseJson(string $json): array
    {
        // Early exit on empty string
        if (trim($json) === '') {
            throw new \InvalidArgumentException('JSON string cannot be empty');
        }

        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Invalid JSON: ' . json_last_error_msg());
        }

        if (!is_array($data)) {
            throw new \InvalidArgumentException('JSON must decode to an array');
        }

        return $data;
    }

    /**
     * Recursively resolves environment variables in config values.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function resolveEnvVars(array $config): array
    {
        foreach ($config as $key => $value) {
            if (is_string($value)) {
                $config[$key] = $this->resolveEnv($value);
            } elseif (is_array($value)) {
                $config[$key] = $this->resolveEnvVars($value);
            }
        }

        return $config;
    }

    /**
     * Required config key => the environment variable that answers it,
     * per provider type (rv-enduser-3).
     *
     * A missing `apiKey` today says "requires 'apiKey' to be set" — true, and
     * useless to someone who has never seen a config block. The env var is the
     * documented front door for exactly these values (`${VAR}` interpolation
     * and the built-in defaults below both read it), so the refusal names it
     * beside the config path. Types or keys absent from this map keep the bare
     * sentence: inventing an env var that nothing reads would be worse than
     * naming none.
     *
     * @var array<string, array<string, string>>
     */
    private const REQUIRED_KEY_ENVS = [
        'openai' => ['apiKey' => 'OPENAI_API_KEY'],
        'anthropic' => ['apiKey' => 'ANTHROPIC_API_KEY'],
        'sglang' => ['baseUrl' => 'OPENAI_BASE_URL'],
        'bedrock' => ['region' => 'AWS_REGION'],
        'vertex' => ['projectId' => 'GCP_PROJECT_ID'],
    ];

    /**
     * Validates that all required keys are present for the given type.
     *
     * @throws \RuntimeException When required keys are missing
     */
    private function validateRequiredKeys(string $type, array $config): void
    {
        $schema = self::TYPE_SCHEMAS[$type];
        $required = $schema['required'];

        foreach ($required as $key) {
            if (!isset($config[$key]) || (is_string($config[$key]) && trim($config[$key]) === '')) {
                $hint = self::REQUIRED_KEY_ENVS[$type][$key] ?? null;

                throw new \RuntimeException(
                    "Provider type '{$type}' requires '{$key}' to be set"
                    . ($hint === null
                        ? ''
                        : " — set the {$hint} environment variable, or a \"{$key}\" entry under providers.<name> in ~/.candy-code/config.json"),
                );
            }
        }
    }

    /**
     * The refusal every unknown-name seam raises (rv-enduser-3).
     *
     * "Unknown provider type: opnenai" used to be the whole sentence, leaving
     * the typo to rediscover the valid set by reading source. The prefix stays
     * byte-identical — existing pins and the transcript's denial-shape scans
     * key off it — and behind it now ride the seven valid types, the closest
     * spelling by {@see similar_text()} percentage (only when the candidate
     * shares at least half its length with the ask, so `cat` does not get
     * told it meant `custom`), and the one command that lists what this
     * install can actually select.
     *
     * @throws \InvalidArgumentException never returns
     */
    private function unknownTypeError(string $type): \InvalidArgumentException
    {
        $types = $this->availableTypes();
        $best = null;
        $bestPercent = 0.0;
        foreach ($types as $candidate) {
            \similar_text($type, $candidate, $percent);
            if ($percent > $bestPercent && $percent >= 50.0) {
                $best = $candidate;
                $bestPercent = $percent;
            }
        }

        return new \InvalidArgumentException(
            "Unknown provider type: {$type}"
            . ' (valid types: ' . \implode(', ', $types) . ')'
            . ($best === null ? '' : " — closest match: {$best}")
            . ' — run `candy-code models` to see selectable providers',
        );
    }

    /**
     * Instantiates the appropriate provider based on type and config.
     *
     * @param array<string, mixed> $config
     */
    private function instantiateProvider(string $type, array $config): ProviderInterface
    {
        return match ($type) {
            'openai' => $this->createOpenAI($config),
            'anthropic' => $this->createAnthropic($config),
            'claude-code' => $this->createClaudeCode($config),
            'sglang' => $this->createSglang($config),
            'bedrock' => $this->createBedrock($config),
            'vertex' => $this->createVertex($config),
            'custom' => $this->createCustom($config),
            default => throw new \RuntimeException("Unsupported provider type: {$type}"),
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createOpenAI(array $config): OpenAIProvider
    {
        // The openai-php package declares its factory as the GLOBAL `\OpenAI`
        // class (file src/OpenAI.php has no namespace), so it must be referenced
        // unqualified. Importing `OpenAI\OpenAI` made the autoloader re-load that
        // file under the wrong PSR-4 path and fatal with "Cannot declare class
        // OpenAI, because the name is already in use" the moment this ran.
        //
        // Expanded from the one-line `\OpenAI::client()` shortcut because that
        // shortcut resolves its transport via `Psr18ClientDiscovery`, yielding a
        // default Guzzle client with NO connect timeout - the same
        // unreachable-host hang HttpClientDefaults exists to close, just one
        // layer down. Every other step below reproduces `\OpenAI::client()`
        // verbatim, including the assistants=v2 beta header it sets; only the
        // injected HTTP client differs. `withProject()` is omitted because its
        // default is already null and nothing here configures a project.
        // rv-enduser-1: an explicit baseUrl (providers map or the
        // OPENAI_BASE_URL default above) redirects the wire; absent/null it is
        // NOT passed, so the factory's own api.openai.com default rides the
        // exact same code path as before this key existed.
        $baseUrl = $config['baseUrl'] ?? null;
        $factory = \OpenAI::factory()
            ->withApiKey($config['apiKey'])
            ->withOrganization($config['organization'] ?? null)
            ->withHttpHeader('OpenAI-Beta', 'assistants=v2')
            ->withHttpClient(self::guzzleClient());
        if (is_string($baseUrl) && trim($baseUrl) !== '') {
            $factory = $factory->withBaseUri($baseUrl);
        }
        $client = $factory->make();

        $model = $config['model'] ?? 'gpt-4o';

        // Billing fix: operator-declared rates ride the provider all the way to
        // `calculateCost()`. An explicit `modelPrices` inside THIS provider's
        // file config wins (it is the narrower statement); otherwise the key is
        // read off the merged user-tier settings, which is where the
        // `LayeredSettings::LAYERED_KEYS` entry lives — and because
        // `readUserConfig()` answers `mergedConfig(true)`, the project tier has
        // already been stripped from `modelPrices` inside that merge (it is
        // user-tier only), so this read cannot be poisoned by a checkout.
        return new OpenAIProvider(
            $client,
            $model,
            self::modelPricesFor($config),
            // Audit A13: the operator's window for this model, for one the
            // built-in table does not size (or sizes wrongly).
            self::configuredContextWindow(
                $config['contextWindow'] ?? self::userTierSetting('contextWindow'),
                $model,
            ),
            // Audit 15b-15 residual: null leaves vision to the model table;
            // the block's key overrides it.
            self::configuredSupportsVision($config['supportsVision'] ?? null),
            // Roadmap 5.13a: the model database, between the operator's
            // figures above and the built-in tables.
            $this->modelMetadata(),
        );
    }

    /**
     * Roadmap 5.13a: the database handed to every provider that consults one
     * — the injected instance, else the production one.
     */
    private function modelMetadata(): ModelMetadata
    {
        return $this->modelMetadata ?? ModelMetadata::new();
    }

    /**
     * Normalises the `contextWindow` setting (audit A13) for `$model`.
     *
     * Two shapes, because the operator's two questions differ: a NUMBER is
     * "this provider's window", applied to whatever model it runs; an OBJECT,
     * `{"<model>": tokens}`, sizes models individually and leaves an unnamed
     * one on the built-in table. Anything else - zero, negative, fractional,
     * a non-number - is no override, the same tolerant posture as
     * `modelPrices` and `maxOutputTokens`: a malformed settings value costs
     * the setting, never the launch.
     */
    private static function configuredContextWindow(mixed $value, string $model): ?int
    {
        if (is_array($value)) {
            $value = $value[$model] ?? null;
        }

        $tokens = self::wholeNumber($value);

        return $tokens !== null && $tokens > 0 ? $tokens : null;
    }

    /**
     * Normalises the `thinkingBudget` setting (audit A21 b): -1 (dynamic),
     * 0 (off) or a positive token count; anything else is no setting, for
     * {@see configuredContextWindow()}'s tolerant reason.
     */
    private static function configuredThinkingBudget(mixed $value): ?int
    {
        $tokens = self::wholeNumber($value);

        return $tokens !== null && $tokens >= -1 ? $tokens : null;
    }

    /**
     * CTX-1 (§6.3): the same `thinkingBudget` setting read for the ANTHROPIC
     * arm, where the number is a `budget_tokens` the Messages API will be
     * sent - so the tolerance of {@see configuredThinkingBudget()} is wrong
     * here: silently dropping a value the user paid a Spend-class setting to
     * raise, or forwarding one the server 400s on both turns and the retry,
     * are both worse than naming it. This parse is STRICT and LOUD for values
     * that cannot mean anything the API accepts, and generous only for
     * "thinking off" in its conventional spellings (absent, null, the empty
     * unset-`${VAR}` placeholder, 0). Everything reaching the provider is
     * therefore trusted: a whole number at or above the protocol floor.
     *
     * `-1` is deliberately refused rather than reinterpreted: it is Gemini's
     * dynamic-thinking spelling and has no Anthropic equivalent, so on this
     * provider it is a setting addressed to the wrong arm.
     */
    private static function configuredAnthropicThinkingBudget(mixed $value): ?int
    {
        if ($value === null || $value === '' || (is_array($value) && $value === [])) {
            return null;
        }

        $tokens = self::wholeNumber($value);

        if ($tokens === null) {
            throw new \InvalidArgumentException(sprintf(
                'anthropic: the `thinkingBudget` setting must be a whole token count (or 0 for off), '
                . 'got %s.',
                get_debug_type($value),
            ));
        }

        if ($tokens === 0) {
            return null;
        }

        if ($tokens < AnthropicProvider::MIN_THINKING_BUDGET) {
            throw new \InvalidArgumentException(sprintf(
                'anthropic: the `thinkingBudget` setting must be at least %d tokens (the Messages API '
                . 'refuses any smaller extended-thinking budget); got %d - use 0 to disable thinking.',
                AnthropicProvider::MIN_THINKING_BUDGET,
                $tokens,
            ));
        }

        return $tokens;
    }

    /**
     * Normalises the `extraBody` setting (audit A10): an object of top-level
     * request fields, or `[]`. Only the SHAPE is checked here (null, `''` -
     * an unset `${VAR}` placeholder - and non-arrays mean none); the KEYS are
     * {@see CustomProvider}'s to validate, so the refusal of `extra_body`
     * and of the fields that provider writes itself has one definition.
     *
     * @return array<array-key, mixed>
     */
    private static function configuredExtraBody(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * An integer from a JSON number or a numeric string (which is what a
     * `${VAR}` placeholder in a provider block resolves to), or null. A float
     * counts only when it is whole and fits an int, so `2.5` and `1e19` are
     * refused rather than truncated or wrapped.
     */
    private static function wholeNumber(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d{1,18}$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 1e18) {
            return (int) $value;
        }

        return null;
    }

    /**
     * The `modelPrices` a provider is built with: the provider block's own
     * map when it carries one (the narrower statement), else the user-tier
     * setting ({@see userTierModelPrices()}). One helper for the three
     * providers with a price table - OpenAI, Vertex and Bedrock - because
     * audit A15 found the second two never received the user's map at all,
     * while Chat's unpriced-model notice told the user to set it.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function modelPricesFor(array $config): array
    {
        $prices = $config['modelPrices'] ?? self::userTierModelPrices();

        return is_array($prices) ? $prices : [];
    }

    /**
     * The `modelPrices` map from {@see \SugarCraft\Code\Cli\Bootstrap::readUserConfig()}
     * — USD per 1M tokens per model. A malformed shape degrades to "no
     * overrides" exactly like every other tolerant read of that funnel;
     * anything else rides downstream, where naming a model makes the
     * declaration AUTHORITATIVE for it
     * ({@see OpenAIProvider::costPer1kTokens()}): a model the operator named
     * whose per-direction rate fails validation is answered UNPRICED — the
     * loud $0 lower bound with its transcript notice and /budget disclosure —
     * never silently re-priced at the built-in row the override replaced, and
     * never at a negative figure that {@see \SugarCraft\Code\Usage} would
     * floor into a fake-free 0.0.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function userTierModelPrices(): array
    {
        $prices = self::userTierSetting('modelPrices') ?? [];

        return is_array($prices) ? $prices : [];
    }

    /**
     * One key of the merged user-tier settings
     * ({@see \SugarCraft\Code\Cli\Bootstrap::readUserConfig()}), or null.
     *
     * The provider-shaping keys read here - `modelPrices`, `contextWindow`,
     * `extraBody`, `thinkingBudget`, `promptCache` - are all USER-TIER ONLY in
     * {@see \SugarCraft\Code\Config\LayeredSettings}, so the project tier has
     * already been stripped from them inside that merge and a checkout cannot
     * reach this read.
     */
    private static function userTierSetting(string $key): mixed
    {
        try {
            return \SugarCraft\Code\Cli\Bootstrap::readUserConfig()[$key] ?? null;
        } catch (\Throwable) {
            // Same posture as EngineBackend::userConfig(): a settings read may
            // never take the provider launch down with it.
            return null;
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createAnthropic(array $config): ProviderInterface
    {
        // ISS-023: the `anthropic` type is a native Messages-API provider, no
        // longer an OpenAI-compat CustomProvider. The wire document, family
        // pricing, context-window table and prompt-cache marking all live in
        // AnthropicMessagesWire, shared with Vertex's `:rawPredict` arm, so a
        // direct-api key gets the SAME caching behaviour A15 armed on Vertex.
        $model = $config['model'] ?? 'claude-sonnet-4-6';

        // Authentication is x-api-key + anthropic-version headers - NOT a
        // bearer token; {@see AnthropicProvider} sets both on its client so
        // they reach the wire. The configured baseUrl is the API root:
        // ANTHROPIC_BASE_URL is shared with the `claude` binary, whose SDK
        // appends /v1/... itself, so the provider appends `/v1/messages` here.
        return new AnthropicProvider(
            $config['apiKey'],
            $config['baseUrl'] ?? 'https://api.anthropic.com',
            $model,
            modelPrices: self::modelPricesFor($config),
            promptCache: self::promptCacheEnabled($config),
            contextWindowOverride: self::configuredContextWindow(
                $config['contextWindow'] ?? self::userTierSetting('contextWindow'),
                $model,
            ),
            thinkingBudget: self::configuredAnthropicThinkingBudget(
                $config['thinkingBudget'] ?? self::userTierSetting('thinkingBudget'),
            ),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createClaudeCode(array $config): ClaudeCodeProvider
    {
        $claudePath = $config['claudePath'];
        $model = $config['model'] ?? 'claude-sonnet-4-6';

        $invocation = new ClaudeCodeInvocation(claudePath: $claudePath);

        // ISS-078: `bare` defaults to true. Turning it off is the operator's
        // deliberate choice to let the CLI keep its normal setting sources
        // (subscription login reachable) at the cost of the --bare isolation.
        return new ClaudeCodeProvider($invocation, $model, self::configuredBare($config['bare'] ?? null));
    }

    /**
     * Normalises the optional claude-code `bare` flag (ISS-078) the same way
     * {@see configuredDiscoverServerInfo()} normalises its flag: absent or
     * empty means the default (true here), real booleans ride through, and
     * boolean-ish strings parse; anything else fails loudly rather than
     * silently picking a side on an isolation decision.
     */
    private static function configuredBare(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value) || is_int($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'bare must be true or false, got %s',
            is_scalar($value) ? var_export($value, true) : get_debug_type($value),
        ));
    }

    /**
     * W1.A6 (§12 D6): selects the client-side tool-call parser from the
     * optional `toolCallParser` config key. OMITTING THE KEY DOES NOT MEAN
     * `'openai'` - it means {@see defaultToolCallParserFor()}, which derives
     * the parser from the MODEL: `dsml` for the DeepSeek-V4 family, `openai`
     * for everything else.
     *
     * This sentence used to say the key defaulted to `'openai'` flatly, and
     * that stopped being true 120 lines below it when the default grew its
     * second arm. A signature described at the wrong scope is this file's own
     * instance of the defect the bundle it shipped in was fixing elsewhere.
     *
     * Before this, both parser classes existed but nothing constructed either
     * one; the factory now selects between them. The explicit names still
     * match the confirmed live deployment (which does pass
     * `--tool-call-parser`), so this is a seam a misconfigured deployment can
     * switch, not a change to normal behaviour.
     *
     * THAT GAP IS NOW CLOSED. This docblock previously recorded that the
     * selected parser was consulted only by
     * {@see SglangProvider::parseResponse()}, i.e. the batch `complete()`
     * path, so selecting a fallback recovered nothing in the live chat loop -
     * which takes `completeStream()`, since
     * `SglangProvider::supportsStreaming()` returns true and both production
     * consumers branch on it ({@see \SugarCraft\Code\Runtime} and
     * {@see \SugarCraft\Code\Agents\AgentManager}).
     * {@see SglangProvider::recoverTextualToolCalls()} now runs the same
     * parser over the reassembled streamed content whenever the structured
     * `delta.tool_calls[]` path produced nothing, so a selected fallback is
     * armed on the path the TUI actually uses. That method's docblock argues
     * the choice of seam.
     *
     * @param array<string, mixed> $config
     */
    private function createSglang(array $config): SglangProvider
    {
        $model = (string) $config['model'];
        $discover = self::configuredDiscoverServerInfo($config['discoverServerInfo'] ?? null);
        $parserName = $config['toolCallParser'] ?? null;

        return SglangProvider::openAiCompatible(
            baseUrl: $config['baseUrl'],
            model: $model,
            apiKey: $config['apiKey'] ?? null,
            // The model is passed because an unnamed parser is chosen FROM it
            // ({@see defaultToolCallParserFor()}) - the same model-derived
            // defaulting `reasoningEffort` uses, and for the same reason: a
            // literal stamped here would keep applying after someone edits
            // `model` to a different family.
            //
            // EXCEPT for the default id with discovery on (audit A26): that
            // provider talks to whatever the server serves, which is not
            // known until discovery runs, so the unnamed parser is left null
            // and the provider picks it from the served model at request
            // time ({@see SglangProvider::defaultToolCallParserFor()}, the
            // same rule).
            toolCallParser: ($parserName === null || $parserName === '')
                && $discover
                && $model === SglangProvider::DEFAULT_MODEL
                    ? null
                    : $this->toolCallParser($parserName, $model),
            reasoningEffort: self::configuredReasoningEffort($config['reasoningEffort'] ?? null),
            // Shape-checked at this parse seam (associative array of string
            // keys); values ride to the server untouched, where the Jinja
            // template is the authority on them.
            extraTemplateKwargs: self::configuredTemplateKwargs($config['templateKwargs'] ?? null),
            // Audit 15a A18: read the live server's limits once per provider
            // (lazily - construction stays I/O-free), so the context window
            // and the default `max_tokens` follow the deployment instead of a
            // transcription of it. On by default; `"discoverServerInfo":
            // false` turns it off for a proxy that blocks the root endpoints.
            discoverServerInfo: $discover,
            // Audit 15b-15: null leaves the answer to discovery's
            // `has_image_understanding`; the block's key overrides it.
            supportsVision: self::configuredSupportsVision($config['supportsVision'] ?? null),
            // ISS-073 review fix: the `sglang` TYPE_SCHEMAS row admits
            // `contextWindow`; without this sink the key was a silent no-op -
            // block key first, user-tier fallback, map-or-scalar (same
            // normaliser the other arms use).
            contextWindowOverride: self::configuredContextWindow(
                $config['contextWindow'] ?? self::userTierSetting('contextWindow'),
                $model,
            ),
        );
    }

    /**
     * Normalises a provider block's optional `supportsVision` flag (audit
     * 15b-15): null when the block does not say, so the provider's own answer
     * stands (SGLang's discovered `has_image_understanding`, Custom's off).
     *
     * Absent, null and `''` (an unset `${VAR}` placeholder) all mean "not
     * said"; a JSON boolean is taken as is, and the string spellings
     * {@see configuredDiscoverServerInfo()} accepts are accepted too. Anything
     * else throws, so a typo cannot silently leave images off - or on.
     */
    private static function configuredSupportsVision(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value) || is_int($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'supportsVision must be true or false, got %s',
            is_scalar($value) ? var_export($value, true) : get_debug_type($value),
        ));
    }

    /**
     * Normalises the optional `discoverServerInfo` flag (audit 15a A18).
     *
     * Absent, null and `''` all mean ON - `''` being what
     * {@see resolveEnvVars()} yields for an unset `${VAR}` placeholder, the
     * same "absent, not misspelled" exception {@see toolCallParser()} makes.
     * A JSON boolean is taken as is; because a placeholder always resolves to
     * a STRING, the spellings `true`/`false`/`1`/`0`/`yes`/`no`/`on`/`off`
     * are accepted too. Anything else throws, so a typo does not silently
     * leave discovery in the state the operator was trying to change.
     */
    private static function configuredDiscoverServerInfo(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value) || is_int($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'discoverServerInfo must be true or false, got %s',
            is_scalar($value) ? var_export($value, true) : get_debug_type($value),
        ));
    }

    /**
     * Normalises the optional `reasoningEffort` config value.
     *
     * Only one thing happens here: the EMPTY STRING becomes null. That is not
     * a value an operator types - `''` is what {@see resolveEnvVars()} yields
     * for a `${CANDY_CODE_REASONING_EFFORT}` placeholder whose variable is
     * unset, i.e. the key being ABSENT rather than misspelled - so it takes the
     * same branch as a missing key. This is the identical exception
     * {@see toolCallParser()} makes for the same mechanism, and choosing a
     * config key over a bespoke env var is what buys env-var support for free:
     * the placeholder syntax already works in every config value.
     *
     * Every other value is passed through UNCHECKED here on purpose - it is
     * validated in {@see SglangProvider}'s constructor, so there is exactly
     * one definition of what the server accepts
     * ({@see SglangProvider::REASONING_EFFORT_LEVELS}) instead of a copy in
     * this file that could drift from it.
     *
     * THE INT CAST IS NOT COSMETIC, AND ITS RANGE IS NOT VALIDATED ANYWHERE.
     * `json_decode` gives an int for a JSON whole number, and the DTO field is
     * `string|float|null`, so `"reasoningEffort": 0` would TypeError without
     * the cast - and `0.0` is a value the server accepts (measured 200 on
     * 2026-08-20). The cast therefore has to exist. What it also does is turn
     * `"reasoningEffort": 1` into `1.0`, which is the ONE float just outside
     * SGLang's bound: measured the same day, the server answers HTTP 400
     * `{"object":"error", ...}` naming `le: 0.99`, and it does so on EVERY
     * completion rather than at build time.
     *
     * So the construction-time guarantee {@see SglangProvider}'s constructor
     * docblock states covers the STRING tier only. That asymmetry is
     * deliberate: the level names are a closed pydantic literal, where a typo
     * is unrecoverable garbage worth refusing locally, while the float bound is
     * a server-side constraint a later SGLang may widen - hardcoding 0.99 here
     * would refuse whatever it widens to. It is stated rather than left
     * implicit because "validated at construction" read as covering both, and a
     * whole-number effort is exactly what an operator would try first.
     */
    private static function configuredReasoningEffort(mixed $value): string|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) || is_float($value) || is_int($value)) {
            return is_int($value) ? (float) $value : $value;
        }

        throw new \InvalidArgumentException(sprintf(
            'reasoningEffort must be a level name or a number, got %s',
            get_debug_type($value),
        ));
    }

    /**
     * Normalises the optional `templateKwargs` config value - the
     * deployment-wide `chat_template_kwargs` object, shaped exactly like the
     * per-request DTO's `$extraTemplateKwargs` so the two merge per key at the
     * wire ({@see SglangProvider::mergedTemplateKwargs()}).
     *
     * The SAME three rules as {@see configuredReasoningEffort()}, and for the
     * SAME reasons:
     *
     *  - null and `''` are both "absent". `''` is not a shape an operator
     *    types - it is what {@see resolveEnvVars()} yields for a `${...}`
     *    placeholder whose variable is unset, i.e. the key being ABSENT
     *    rather than misspelled;
     *  - the SHAPE is refused here, at config-parse time: an associative
     *    array of string keys. A JSON list (`["enable_thinking"]`) decodes to
     *    an int-keyed array, so `get_debug_type` alone would misreport it as
     *    a plain `array` - the integer-key branch names the offending offset
     *    instead;
     *  - the VALUES are passed through UNCHECKED, unlike the effort level
     *    names. Template kwargs are an open, server-side vocabulary (the
     *    deployed Jinja template answers bad values with its own 400);
     *    copying a validation set for it here would only drift.
     *
     * Env expansion needs nothing new: {@see resolveEnvVars()} already
     * RECURSES into array values before validation, so `${VAR}` placeholders
     * inside kwarg STRINGS resolve through the existing mechanism - the same
     * "choose a config key, get env support for free" property every other
     * key here enjoys.
     *
     * @return array<string, mixed>
     */
    private static function configuredTemplateKwargs(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (!is_array($value)) {
            throw new \InvalidArgumentException(sprintf(
                'templateKwargs must be an associative array, got %s',
                get_debug_type($value),
            ));
        }

        foreach ($value as $key => $unused) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException(sprintf(
                    'templateKwargs must be an associative array, got an integer key at offset %d',
                    $key,
                ));
            }
        }

        return $value;
    }

    /**
     * Builds the named tool-call parser.
     *
     * Both strategies decode `function.arguments` through
     * {@see SglangProvider::argumentDecoder()} so the §12 D5 MiniMax
     * `</parameter>` truncation stays observable whichever one is selected -
     * picking the fallback must not cost the diagnostics.
     *
     * An unrecognised name throws rather than silently falling back to the
     * default: a typo'd `toolCallParser` would otherwise leave the operator
     * believing the fallback is armed when it is not, and CONTRIBUTING.md's
     * no-silent-failures rule covers exactly that.
     *
     * The empty string is the one deliberate exception to that rule, because
     * it is not a name an operator types: `''` is what
     * {@see resolveEnvVars()} yields for a `${CANDY_CODE_TOOL_CALL_PARSER}`
     * placeholder whose variable is unset. That is the config key being
     * absent, not misspelled, so it takes the same branch as `null`.
     *
     * @throws \InvalidArgumentException When the name is not a known parser.
     */
    private function toolCallParser(mixed $name, string $model): ToolCallParserInterface
    {
        $openAi = OpenAiArrayToolCallParser::new(SglangProvider::argumentDecoder());

        return match ($name) {
            null, '' => $this->defaultToolCallParserFor($model),
            self::TOOL_CALL_PARSER_OPENAI => $openAi,
            self::TOOL_CALL_PARSER_MINIMAX_XML_FALLBACK => MinimaxXmlFallbackToolCallParser::new($openAi),
            self::TOOL_CALL_PARSER_DSML => DsmlToolCallParser::new($openAi),
            default => throw new \InvalidArgumentException(sprintf(
                'Unknown toolCallParser: %s (expected %s)',
                is_scalar($name) ? (string) $name : get_debug_type($name),
                self::quotedToolCallParserNames(),
            )),
        };
    }

    /**
     * The parser to use when the operator named none - derived from the MODEL,
     * not stamped as a literal.
     *
     * DSML IS THE DEFAULT FOR THE DEEPSEEK-V4 FAMILY, and opt-in elsewhere.
     * The reasoning, since this DIVERGES from the precedent set by
     * {@see TOOL_CALL_PARSER_MINIMAX_XML_FALLBACK}, which was left opt-in:
     *
     * - The cost of arming it is near zero. {@see DsmlToolCallParser::parse()}
     *   delegates immediately whenever `tool_calls` is set, so on a correctly
     *   configured server it is one `isset` per response and nothing else. Its
     *   scan needs BOTH an absent `tool_calls` AND a literal
     *   `<｜DSML｜tool_calls>` marker in the content, a pair that cannot occur
     *   on a server that decoded the call itself.
     * - The cost of NOT arming it is total and silent. Without a
     *   `--tool-call-parser` flag - which the DeepSeek-V4 card's own launch
     *   command omits - every tool call arrives as unparsed text and the agent
     *   does nothing, with no error anywhere.
     * - The precedent points the other way only because the deployed family
     *   changed. When the MiniMax fallback was written, MiniMax was the
     *   deployed model and the flag was being passed. When this was written
     *   {@see SglangProvider::DEFAULT_MODEL} was a DeepSeek-V4 id, so leaving
     *   DSML opt-in would have meant the DEFAULT model was the one with no
     *   safety net. That is the asymmetry that justifies diverging, not a
     *   general preference for armed fallbacks - and it still holds for any
     *   DeepSeek-V4 deployment, configured or (audit A26) adopted as the
     *   served model of a default launch.
     *
     * GATED ON {@see SglangProvider::isDeepSeekV4()}, reused rather than
     * respelled. That predicate deliberately OVER-matches (`deepseek-v40`,
     * `DeepSeek-V4.5` and `DeepSeek-V4.1-Flash` all take the V4 arm), and the
     * asymmetry suits this use even better than its original one. A FALSE
     * MATCH costs nothing: a non-DeepSeek model gets a parser that delegates
     * everything, because it will never emit a DSML envelope for the scan to
     * find. A MISS - a DeepSeek-V4 deployment under a model id that does not
     * contain the family token - costs exactly today's behaviour, the OpenAI
     * parser alone, and is recoverable by naming `dsml` explicitly. So both
     * error directions are strictly better than the status quo.
     *
     * QWEN3.8 GETS `openai`, AND THAT IS CORRECT - re-checked against the live
     * server for audit 15a A18 on 2026-10-02. skynet2's `/model_info` reports
     * `served_model_name: "Qwen/Qwen3.8-Flash-Next-FP8"` with
     * `tool_call_parser: "qwen3_coder"`, so SGLang itself decodes the model's
     * `<tool_call><function=…>` markup and returns the standard OpenAI
     * `tool_calls[]` array (measured on this family as qwen.md E-24/E-26:
     * `arguments` a JSON string, streamed fragments keyed by `index`). The
     * array parser is the whole job; no Qwen textual fallback class is needed,
     * and DSML stays DeepSeek-only - a DSML envelope cannot appear in Qwen
     * output. The one way that breaks - a server relaunched WITHOUT
     * `--tool-call-parser` - is now detected rather than silent:
     * {@see SglangProvider::serverInfo()} reads the parser the server reports
     * and warns when it is null and no textual fallback is armed.
     */
    private function defaultToolCallParserFor(string $model): ToolCallParserInterface
    {
        // The rule lives on the provider, which also applies it to a served
        // model it adopts at request time (audit A26).
        return SglangProvider::defaultToolCallParserFor($model);
    }

    /**
     * `"openai", "minimax-xml-fallback" or "dsml"` - built from
     * {@see TOOL_CALL_PARSER_NAMES} so the error text cannot fall behind the
     * set of names actually accepted.
     */
    private static function quotedToolCallParserNames(): string
    {
        $quoted = array_map(
            static fn (string $name): string => sprintf('"%s"', $name),
            self::TOOL_CALL_PARSER_NAMES,
        );

        $last = array_pop($quoted);

        return $quoted === [] ? $last : implode(', ', $quoted) . ' or ' . $last;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createBedrock(array $config): BedrockProvider
    {
        // create() resolves a null model to DEFAULT_MODEL; the override
        // normaliser needs the concrete id it will end up serving, so the
        // default is applied here once and passed down.
        $model = $config['model'] ?? BedrockProvider::DEFAULT_MODEL;

        return BedrockProvider::create(
            region: $config['region'],
            model: $model,
            modelPrices: self::modelPricesFor($config),
            // Audit A15: Converse cache points after the system prompt and
            // the conversation, unless the operator turned them off.
            promptCache: self::promptCacheEnabled($config),
            // ISS-073 review fix: the `bedrock` TYPE_SCHEMAS row admits
            // `contextWindow`; without this sink the key was a silent no-op -
            // block key first, user-tier fallback, map-or-scalar (same
            // normaliser the other arms use).
            contextWindowOverride: self::configuredContextWindow(
                $config['contextWindow'] ?? self::userTierSetting('contextWindow'),
                $model,
            ),
        );
    }

    /**
     * Whether a provider built from `$config` marks prompt-cache breakpoints
     * (audit A15): on unless something turns it off.
     *
     * `CANDY_CODE_DISABLE_PROMPT_CACHE` wins - the environment outranks
     * settings for every flag of this shape, and it is the one switch that
     * reaches a provider whose config the operator cannot edit. Then the
     * provider block's own `promptCache`, then the user-tier `promptCache`
     * setting. A value that is not a recognisable boolean (`true`/`false`,
     * `1`/`0`, or those spelled as strings - what a `${VAR}` placeholder in a
     * provider block resolves to) leaves the default on: a malformed setting
     * costs the setting, never the launch, and "on" is the cheaper reading.
     *
     * User-tier only: caching off raises what every request bills, so it is
     * a money decision a checked-out repository may not make.
     *
     * @param array<string, mixed> $config
     */
    private static function promptCacheEnabled(array $config): bool
    {
        if (CacheBreakpoints::disabledFromEnvironment()) {
            return false;
        }

        // `null` and `''` (an unset `${VAR}` placeholder) in the provider
        // block say nothing, so the user tier still answers.
        $value = $config['promptCache'] ?? '';
        if ($value === '') {
            $value = self::userTierSetting('promptCache');
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));
        }

        return match ($value) {
            false, 0, 'false', '0' => false,
            default => true,
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createVertex(array $config): VertexProvider
    {
        $model = $config['model'] ?? 'claude-sonnet-4-6';

        return VertexProvider::create(
            projectId: $config['projectId'],
            location: $config['location'] ?? 'us-central1',
            model: $model,
            modelPrices: self::modelPricesFor($config),
            // Audit A21 (b): how much of a Gemini reply's output budget
            // thinking may spend. User-tier only - raising it raises spend.
            thinkingBudget: self::configuredThinkingBudget(
                $config['thinkingBudget'] ?? self::userTierSetting('thinkingBudget'),
            ),
            // Audit A15: `cache_control` breakpoints on the Anthropic arm
            // (system, last tool, conversation tail), unless turned off.
            promptCache: self::promptCacheEnabled($config),
            // ISS-073: the `contextWindow` setting now reaches every wire
            // that has a per-family table, vertex included - block key
            // first, user-tier fallback, map-or-scalar both accepted (same
            // normaliser the openai/anthropic/custom arms use).
            contextWindowOverride: self::configuredContextWindow(
                $config['contextWindow'] ?? self::userTierSetting('contextWindow'),
                $model,
            ),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createCustom(array $config): CustomProvider
    {
        return CustomProvider::openAiCompatible(
            name: $config['name'],
            baseUrl: $config['baseUrl'],
            model: $config['model'],
            apiKey: $config['apiKey'] ?? null,
            supportsStreaming: $config['supportsStreaming'] ?? true,
            supportsFunctionCalling: $config['supportsFunctionCalling'] ?? true,
            // Audit A10: server-specific top-level body fields (for example
            // SGLang's `separate_reasoning`), which used to have a
            // constructor seam and no config key. The provider block's own
            // map wins; else the user-tier setting, which a project may not
            // set - a field such as `n` multiplies what every request bills.
            extraBody: self::configuredExtraBody($config['extraBody'] ?? self::userTierSetting('extraBody')),
            // Audit 15b-15: opt-in, per provider block - see the constructor.
            supportsVision: self::configuredSupportsVision($config['supportsVision'] ?? null) ?? false,
            // Roadmap 5.13a: precedence is the operator's settings (the block's
            // own keys, else the user tier — both user-only, so a checkout
            // cannot inflate the window), then the model database, then the
            // provider's built-in 128k / $0.
            modelMetadata: $this->modelMetadata(),
            modelPrices: self::modelPricesFor($config),
            contextWindowOverride: self::configuredContextWindow(
                $config['contextWindow'] ?? self::userTierSetting('contextWindow'),
                $config['model'],
            ),
        );
    }
}
