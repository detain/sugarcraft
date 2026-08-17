<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

use SugarCraft\Crush\Providers\Concerns\HttpClientDefaults;
use SugarCraft\Crush\Providers\ToolCallParser\MinimaxXmlFallbackToolCallParser;
use SugarCraft\Crush\Providers\ToolCallParser\OpenAiArrayToolCallParser;
use SugarCraft\Crush\Providers\ToolCallParser\ToolCallParserInterface;
use SugarCraft\Crush\Support\ContainedPath;

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
     * §12 D6 tool-call-parser names, mirroring SGLang's `--tool-call-parser`
     * flag: the default assumes the server was launched with a real parser
     * flag; the fallback is for one that was not.
     */
    public const TOOL_CALL_PARSER_OPENAI = 'openai';

    public const TOOL_CALL_PARSER_MINIMAX_XML_FALLBACK = 'minimax-xml-fallback';

    /**
     * The dev/test-fixture config this factory reads, relative to
     * {@see packageRoot()}.
     *
     * ONE LITERAL, split by `dirname()` where the directory half is needed, for the
     * reason {@see \SugarCraft\Crush\Agents\WorktreeConfig::readConfig()} keeps one:
     * {@see \SugarCraft\Crush\Tests\Cli\ProjectTierRefusalInventoryTest} derives its
     * dot-path census from string literals, and a path assembled from fragments is
     * its stated blind spot — so spelling the two halves separately would hide this
     * read path from the instrument that classifies it.
     */
    private const CONFIG_PATH = '.sugar-crush/config.dev.json';

    /** @var array<string, array{required: string[], optional: string[]}> */
    private const TYPE_SCHEMAS = [
        'openai' => [
            'required' => ['apiKey'],
            'optional' => ['organization', 'model'],
        ],
        'anthropic' => [
            'required' => ['apiKey'],
            'optional' => ['baseUrl', 'model'],
        ],
        'claude-code' => [
            'required' => ['claudePath'],
            'optional' => ['model'],
        ],
        'sglang' => [
            'required' => ['baseUrl', 'model'],
            'optional' => ['apiKey', 'toolCallParser'],
        ],
        'bedrock' => [
            'required' => ['region'],
            'optional' => ['model'],
        ],
        'vertex' => [
            'required' => ['projectId'],
            'optional' => ['location', 'model'],
        ],
        'custom' => [
            'required' => ['name', 'baseUrl', 'model'],
            'optional' => ['apiKey', 'supportsStreaming', 'supportsFunctionCalling'],
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
            throw new \InvalidArgumentException("Unknown provider type: {$type}");
        }

        // Resolve environment variables in all string values
        $config = $this->resolveEnvVars($config);

        // Validate required keys for this type
        $this->validateRequiredKeys($type, $config);

        // Create the appropriate provider
        return $this->instantiateProvider($type, $config);
    }

    /**
     * The library root this factory reads its dev config from: two directories
     * above this file (`src/Providers` -> `src` -> root).
     *
     * TWO climbs, not three. A third overshoots into whatever happens to sit above
     * this library on disk — fine by coincidence in the monorepo checkout, where a
     * sibling `.sugar-crush/` exists one level up, but wrong in general and fatal
     * once sugar-crush is split into its own repo, where nothing exists above it.
     *
     * A named seam rather than an inline expression, for the reason
     * {@see \SugarCraft\Crush\Agents\WorktreeConfig::defaultConfigDir()} is one:
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
     * {@see \SugarCraft\Crush\Workflows\WorkflowRegistry::projectWorkflowsPath()}
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
     * `__DIR__ . '/../../../.sugar-crush/config.json'` with no gate of any kind and
     * was closed a round ago; this read `__DIR__ . '/../../.sugar-crush/config.dev.json'`
     * the same way, and its contents decide which provider a launch talks to —
     * `baseUrl` and `apiKey` included — through {@see fromProjectConfig()},
     * {@see projectProviderConfig()} and, at launch,
     * {@see \SugarCraft\Crush\Cli\Bootstrap::availableProviders()}.
     *
     * TWO BOUNDARIES, the pair every repository-chosen read in this package
     * carries:
     *
     *  - `<root>/.sugar-crush` must resolve STRICTLY inside `<root>`
     *    ({@see ContainedPath::below()}), so a `.sugar-crush -> /elsewhere` inside
     *    the shipped package relocates the check instead of tripping it;
     *  - `config.dev.json` must resolve inside that directory
     *    ({@see ContainedPath::within()}), so `config.dev.json -> /elsewhere/evil.json`
     *    is refused on its own.
     *
     * WHOSE CHOICE IS THIS PATH, stated because the answer decides whether the gate
     * is worth anything. Under a composer install the package sits in
     * `vendor/sugarcraft/sugar-crush`, so its `.sugar-crush/` arrives with the
     * DEPENDENCY — and a dependency's tarball carries symlinks exactly as a clone
     * carries committed ones (the measurement on
     * {@see \SugarCraft\Crush\Cli\Bootstrap::agentPresetTiers()}). In the monorepo
     * it is this checkout's own file. Both cases are content whose location this
     * process did not choose.
     *
     * ABSENCE IS NOT REFUSAL. A missing file returns null here as well, because
     * {@see ContainedPath} refuses what it cannot resolve — so callers must keep
     * reporting "not found" from the CONFIGURED path
     * ({@see defaultConfigPath()}) rather than from this. The distinction the two
     * methods draw is "where" versus "may I", and neither is "does it exist".
     *
     * @param string|null $packageRoot The tree holding `.sugar-crush/`; null means
     *        {@see packageRoot()}. Injectable for the reason
     *        {@see \SugarCraft\Crush\Agents\WorktreeConfig::new()}'s `$configDir` is:
     *        both gates can only be DRIVEN against a synthetic tree, and the
     *        alternative is committing a symlink into this repository's own
     *        `.sugar-crush/` and restoring it in a `finally` — a git-tracked file
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
     * Creates a provider from the project's .sugar-crush/config.dev.json
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
     * bin/sugarcrush's $SUGARCRUSH_PROVIDER backend selection calls exactly
     * `$factory->create($factory->defaultConfig($providerType))` - it has no
     * other hook into the provider system. Before a name is rejected as
     * unknown, fall back to the project's .sugar-crush/config.dev.json
     * 'providers' map: this is what makes 'dev-sglang' (config.dev.json's own
     * 'defaultProvider') a name $SUGARCRUSH_PROVIDER can actually select,
     * without bin/sugarcrush needing any awareness of fromProjectConfig().
     *
     * @param string $type The provider type, or a name declared under
     *     'providers' in .sugar-crush/config.dev.json (e.g. 'dev-sglang')
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

            throw new \InvalidArgumentException("Unknown provider type: {$type}");
        }

        return match ($type) {
            'openai' => [
                'type' => 'openai',
                'apiKey' => getenv('OPENAI_API_KEY') ?: '',
                'organization' => getenv('OPENAI_ORG_ID') ?: null,
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
                'baseUrl' => 'http://localhost:30000',
                'model' => 'MiniMax-M2.7',
                'apiKey' => getenv('SGLANG_API_KEY') ?: null,
                // §12 D6's documented default. Named explicitly rather than
                // left implicit so the knob is discoverable from
                // defaultConfig() output - which is exactly what the Ctrl+P
                // palette's Switch Model listing shows
                // ({@see \SugarCraft\Crush\Cli\Bootstrap::availableProviders()}).
                'toolCallParser' => self::TOOL_CALL_PARSER_OPENAI,
            ],
            'bedrock' => [
                'type' => 'bedrock',
                'region' => 'us-east-1',
                'model' => 'anthropic.claude-sonnet-4-6',
            ],
            'vertex' => [
                'type' => 'vertex',
                'projectId' => getenv('GCP_PROJECT_ID') ?: '',
                'location' => 'us-central1',
                'model' => 'claude-3-sonnet@20240229',
            ],
            'custom' => [
                'type' => 'custom',
                'name' => 'custom',
                'baseUrl' => 'http://localhost:8080',
                'model' => 'gpt-4o',
                'apiKey' => null,
                'supportsStreaming' => true,
                'supportsFunctionCalling' => true,
            ],
            default => throw new \InvalidArgumentException("Unknown provider type: {$type}"),
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
     * .sugar-crush/config.dev.json, for defaultConfig()'s fallback.
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
                throw new \RuntimeException("Provider type '{$type}' requires '{$key}' to be set");
            }
        }
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
        $client = \OpenAI::factory()
            ->withApiKey($config['apiKey'])
            ->withOrganization($config['organization'] ?? null)
            ->withHttpHeader('OpenAI-Beta', 'assistants=v2')
            ->withHttpClient(self::guzzleClient())
            ->make();

        $model = $config['model'] ?? 'gpt-4o';

        return new OpenAIProvider($client, $model);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createAnthropic(array $config): ProviderInterface
    {
        $baseUrl = $config['baseUrl'] ?? 'https://api.anthropic.com';
        $apiKey = $config['apiKey'];
        $model = $config['model'] ?? 'claude-sonnet-4-6';

        // Anthropic's Messages API authenticates with x-api-key + anthropic-version,
        // NOT a bearer token. Build the client with those headers and inject it directly
        // so the auth headers actually reach the wire (the previous code discarded this
        // client and fell back to CustomProvider's bearer-auth client).
        $headers = [
            'Content-Type' => 'application/json',
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
        ];

        // guzzleClient() (not `new Client`) so this provider inherits the
        // shared connect-timeout policy - see HttpClientDefaults.
        $client = self::guzzleClient([
            'base_uri' => $baseUrl,
            'headers' => $headers,
        ]);

        return new CustomProvider(
            'anthropic',
            $baseUrl,
            $model,
            $apiKey,
            $client,
            true,
            false,
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

        return new ClaudeCodeProvider($invocation, $model);
    }

    /**
     * W1.A6 (§12 D6): selects the client-side tool-call parser from the
     * optional `toolCallParser` config key, defaulting to `'openai'`.
     *
     * Before this, both parser classes existed but nothing constructed either
     * one; the factory now selects between them. The default deliberately
     * matches the confirmed live deployment (which does pass
     * `--tool-call-parser`), so this is a seam a misconfigured deployment can
     * switch, not a change to normal behaviour.
     *
     * KNOWN GAP, still open: the selected parser is consulted only by
     * {@see SglangProvider::parseResponse()}, i.e. the batch `complete()`
     * path. `SglangProvider::supportsStreaming()` returns true and both
     * production consumers branch on it ({@see \SugarCraft\Crush\Runtime} and
     * {@see \SugarCraft\Crush\Agents\AgentManager}), so the live chat loop
     * takes `completeStream()` -> `parseChunk()` ->
     * `resolveStreamedToolCalls()`, which reassembles tool calls itself and
     * never touches {@see ToolCallParser\ToolCallParserInterface}. Selecting
     * `minimax-xml-fallback` therefore recovers nothing on the streaming path
     * today. Threading the parser through streaming reassembly is §12 D2
     * territory, outside D6's scope, and remains unscheduled.
     *
     * @param array<string, mixed> $config
     */
    private function createSglang(array $config): SglangProvider
    {
        return SglangProvider::openAiCompatible(
            baseUrl: $config['baseUrl'],
            model: $config['model'],
            apiKey: $config['apiKey'] ?? null,
            toolCallParser: $this->toolCallParser($config['toolCallParser'] ?? null),
        );
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
     * {@see resolveEnvVars()} yields for a `${SUGARCRUSH_TOOL_CALL_PARSER}`
     * placeholder whose variable is unset. That is the config key being
     * absent, not misspelled, so it takes the same branch as `null`.
     *
     * @throws \InvalidArgumentException When the name is not a known parser.
     */
    private function toolCallParser(mixed $name): ToolCallParserInterface
    {
        $default = OpenAiArrayToolCallParser::new(SglangProvider::argumentDecoder());

        return match ($name) {
            null, '', self::TOOL_CALL_PARSER_OPENAI => $default,
            self::TOOL_CALL_PARSER_MINIMAX_XML_FALLBACK => MinimaxXmlFallbackToolCallParser::new($default),
            default => throw new \InvalidArgumentException(sprintf(
                'Unknown toolCallParser: %s (expected "%s" or "%s")',
                is_scalar($name) ? (string) $name : get_debug_type($name),
                self::TOOL_CALL_PARSER_OPENAI,
                self::TOOL_CALL_PARSER_MINIMAX_XML_FALLBACK,
            )),
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createBedrock(array $config): BedrockProvider
    {
        return BedrockProvider::create(
            region: $config['region'],
            model: $config['model'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createVertex(array $config): VertexProvider
    {
        return VertexProvider::create(
            projectId: $config['projectId'],
            location: $config['location'] ?? 'us-central1',
            model: $config['model'] ?? 'claude-3-sonnet@20240229',
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
        );
    }
}
