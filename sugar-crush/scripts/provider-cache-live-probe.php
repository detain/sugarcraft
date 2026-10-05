<?php

declare(strict_types=1);

/**
 * Provider prompt-cache LIVE checks (crush_report Part VI, roadmap W10-i).
 *
 * The unit suite pins every request this repo builds and every usage document
 * it parses, against recorded wire shapes. What it cannot pin is whether a
 * REAL endpoint answers those requests the way the recordings say. This
 * script asks, once per check, with small bounded requests:
 *
 *     php scripts/provider-cache-live-probe.php --check=LIVE-0.1
 *     php scripts/provider-cache-live-probe.php --check=all
 *
 * Checks (comma-separate several; `all` runs every one):
 *
 *  LIVE-0.1   SGLang radix-cache reuse across a write step (roadmap 0.1). A
 *             cold request makes the model call `write_file`; the next step
 *             replays that assistant row WITH its `reasoning_content` and the
 *             tool result. `prompt_tokens_details.cached_tokens` must cover
 *             the whole first prompt, and a control request that replays the
 *             same row WITHOUT the reasoning shows what 0.1 saves. Needs the
 *             server launched with `--enable-cache-report` (else the field is
 *             null and the check FAILs as "not reported").
 *             SGLang matches whole pages, so "the whole prompt" is its last
 *             full `--page-size` (default 64, skynet2's `page_size`).
 *             Endpoint: `--base-url`, else the `dev-sglang` provider of
 *             `.sugar-crush/config.dev.json`, else skynet2.
 *  LIVE-X31b  One tool-calling request through the `anthropic` type key
 *             (OpenAI-compatibility endpoint, `/v1` base). Needs
 *             ANTHROPIC_API_KEY.
 *  LIVE-A15b  Bedrock Converse cache points: the same request twice with a
 *             >1,024-token system prompt; call 1 writes the cache
 *             (`cacheCreationTokens > 0`), call 2 reads it
 *             (`cacheReadTokens > 0`). AWS SDK default credential chain.
 *  LIVE-A15v  The same on Vertex's Anthropic arm. Needs GCP_PROJECT_ID (or
 *             `--project`) and Application Default Credentials. The factory's
 *             default model is outdated, so this check sends `--model`
 *             (default `claude-sonnet-4-6`).
 *  LIVE-A21b  Vertex Gemini with `thinkingConfig`: `--model` (default
 *             `gemini-2.5-flash`), `--thinking-budget` (default 1024) and a
 *             small output budget. The request must be accepted, report
 *             `reasoningTokens`, and return non-empty, untruncated text.
 *  LIVE-CH    The cache-health notice. Three requests with prompt caching
 *             OFF through `--provider` (`bedrock` default, or `vertex`); each
 *             Usage goes to a fresh CacheHealthWatch. Both cache buckets must
 *             be reported as 0 (not null) every time and the notice must fire
 *             exactly once, on the third reply.
 *
 * Verdicts: PASS, FAIL (the endpoint answered and contradicted the claim), or
 * BLOCKED (no credentials, or the credentials lack access - nothing was
 * learned about the claim). Exit 0 when every requested check passed, 1 on
 * any FAIL, 3 when nothing failed but something was blocked.
 *
 * SECRETS: no key, token or account identifier is ever printed. Credentials
 * are only tested for presence, and every provider error is passed through
 * redact() before it is shown.
 *
 * BOUNDS: every request caps its output (`maxTokens` at most 1,536), and the
 * whole run is bounded by `--deadline` seconds (default 600) through
 * SIGALRM. That bound is this manual probe's, not the product's: the
 * providers themselves keep their connect-timeout-only policy.
 */

namespace SugarCrush\Scripts\CacheProbe;

use SugarCraft\Crush\Backend\CacheHealthWatch;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\BedrockProvider;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\ProviderFactory;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Providers\VertexProvider;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Usage;

require __DIR__ . '/../vendor/autoload.php';

const CHECKS = ['LIVE-0.1', 'LIVE-X31b', 'LIVE-A15b', 'LIVE-A15v', 'LIVE-A21b', 'LIVE-CH'];
const SKYNET2 = 'https://skynet2.interserver.net/v1';
const SKYNET2_MODEL = 'Qwen/Qwen3.8-Flash-Next';

const PASS = 'PASS';
const FAIL = 'FAIL';
const BLOCKED = 'BLOCKED';

/** One verdict: [status, detail]. */
function verdict(string $status, string $detail): array
{
    return [$status, $detail];
}

/**
 * Strip anything credential-shaped from a provider message before it is
 * printed: AWS ARNs (they carry the account id and the IAM user), bare
 * 12-digit account ids, Anthropic keys, bearer tokens, and the literal values
 * of the credential variables this script may have read.
 */
function redact(string $text): string
{
    foreach (['ANTHROPIC_API_KEY', 'AWS_SECRET_ACCESS_KEY', 'AWS_ACCESS_KEY_ID', 'AWS_SESSION_TOKEN', 'GCP_PROJECT_ID'] as $var) {
        $value = getenv($var);
        if (is_string($value) && strlen($value) >= 6) {
            $text = str_replace($value, "<{$var}>", $text);
        }
    }

    $text = (string) preg_replace('/arn:aws[a-z-]*:[^\s"\'<>]+/', 'arn:aws:<redacted>', $text);
    $text = (string) preg_replace('/\b\d{12}\b/', '<account>', $text);
    $text = (string) preg_replace('/sk-ant-[A-Za-z0-9_\-]+/', 'sk-ant-<redacted>', $text);
    $text = (string) preg_replace('/(Bearer\s+)[A-Za-z0-9._\-]+/i', '$1<redacted>', $text);

    return mb_strimwidth($text, 0, 600, '…');
}

/** @return array<string, string> the `--name=value` options; a bare `--flag` is "1". */
function options(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/', $arg, $m) !== 1) {
            fwrite(STDERR, "unrecognised argument: {$arg}\n");
            exit(64);
        }
        $options[$m[1]] = $m[2] ?? '1';
    }

    return $options;
}

/** A minimal tool: the probe only needs its schema on the wire, never its execution. */
function probeTool(string $name, string $description, array $properties): Tool
{
    return new class ($name, $description, $properties) implements Tool {
        public function __construct(
            private readonly string $toolName,
            private readonly string $toolDescription,
            private readonly array $properties,
        ) {}

        public function name(): string
        {
            return $this->toolName;
        }

        public function description(): string
        {
            return $this->toolDescription;
        }

        public function inputSchema(): array
        {
            return [
                'type' => 'object',
                'properties' => array_map(static fn(string $d): array => ['type' => 'string', 'description' => $d], $this->properties),
                'required' => array_keys($this->properties),
            ];
        }

        public function execute(array $args): ToolResult
        {
            return new ToolResult('', 'not executed by the probe');
        }
    };
}

/**
 * A deterministic system prompt of roughly $paragraphs * 60 tokens, opened by
 * a per-run nonce so no earlier run's cache entry can match it: the "before"
 * measurement must start cold.
 */
function longSystemPrompt(string $nonce, int $paragraphs): string
{
    $lines = ["Probe run {$nonce}. You are a careful assistant used to measure prompt caching."];
    for ($i = 1; $i <= $paragraphs; $i++) {
        $lines[] = "Rule {$i}: keep answers short, prefer the provided tools when a task names a file, "
            . "never invent file contents, report exactly what a tool returned, and treat rule {$i} as "
            . "equal in weight to every other numbered rule in this list of standing instructions.";
    }

    return implode("\n", $lines);
}

/**
 * The whole prompt an OpenAI-compatible usage document counted: fresh input
 * plus cached. {@see Usage::promptTokens()} refuses this sum while the
 * cache-creation bucket is unreported, which it always is on SGLang (the
 * protocol has no such field), so the probe adds the two buckets SGLang does
 * report itself.
 */
function sglangPrompt(?Usage $usage): int
{
    return (int) $usage?->inputTokens + (int) $usage?->cacheReadTokens;
}

function usageLine(?Usage $usage): string
{
    if ($usage === null) {
        return 'usage=null';
    }

    $show = static fn(?int $v): string => $v === null ? 'null' : (string) $v;

    return sprintf(
        'input=%s output=%s cacheRead=%s cacheCreation=%s reasoning=%s total=%d',
        $show($usage->inputTokens),
        $show($usage->outputTokens),
        $show($usage->cacheReadTokens),
        $show($usage->cacheCreationTokens),
        $show($usage->reasoningTokens),
        $usage->totalTokens,
    );
}

/**
 * Send one request; a thrown provider failure or an error response comes back
 * as the message string instead of a response.
 */
function send(ProviderInterface $provider, CompleteRequest $request): CompleteResponse|string
{
    try {
        $response = $provider->complete($request);
    } catch (\Throwable $e) {
        // The wrappers quote their cause's message; keep a cause only when
        // it says something its wrapper did not.
        $chain = [];
        for ($t = $e; $t !== null; $t = $t->getPrevious()) {
            $message = trim((string) preg_replace('/\s+/', ' ', $t->getMessage()));
            foreach ($chain as $kept) {
                if (str_contains($kept, $message)) {
                    continue 2;
                }
            }
            $chain[] = $message;
        }

        return redact(implode(' <- ', $chain));
    }

    if ($response->isError) {
        return redact(trim((string) preg_replace('/\s+/', ' ', (string) $response->errorMessage)));
    }

    return $response;
}

/** A failure that says the credentials exist but may not do this - not a finding about the claim. */
function isAccessFailure(string $message): bool
{
    return preg_match('/AccessDenied|not authorized|UnrecognizedClient|InvalidSignature|ExpiredToken|PERMISSION_DENIED|UNAUTHENTICATED|invalid x-api-key|authentication_error|Could not load the default credentials|credentials/i', $message) === 1;
}

function failedRequest(string $what, string $message): array
{
    return verdict(isAccessFailure($message) ? BLOCKED : FAIL, "{$what}: {$message}");
}

/** The SGLang endpoint: --base-url, the project's dev-sglang provider, or skynet2. */
function sglangConfig(array $options): array
{
    $config = ['type' => 'sglang', 'baseUrl' => SKYNET2, 'model' => SKYNET2_MODEL];
    try {
        $project = (new ProviderFactory())->defaultConfig('dev-sglang');
        if (($project['type'] ?? null) === 'sglang') {
            $config = $project;
        }
    } catch (\InvalidArgumentException) {
        // No project config in this checkout: skynet2 stands.
    }
    if (isset($options['base-url'])) {
        $config['baseUrl'] = $options['base-url'];
    }
    if (isset($options['model'])) {
        $config['model'] = $options['model'];
    }

    return $config;
}

// ---------------------------------------------------------------------------
// LIVE-0.1
// ---------------------------------------------------------------------------

function checkSglangWriteStep(array $options): array
{
    $config = sglangConfig($options);
    $provider = (new ProviderFactory())->create($config);
    $model = (string) $config['model'];
    $writeFile = probeTool('write_file', 'Write text to a file in the workspace.', [
        'path' => 'workspace-relative file path',
        'content' => 'the complete file contents',
    ]);
    $system = longSystemPrompt(bin2hex(random_bytes(8)), 24);
    $user = new UserMessage('Create notes.txt containing the numbers 1 to 150 separated by spaces. Use the write_file tool now; do not answer in prose.');

    $first = send($provider, new CompleteRequest(model: $model, messages: [$user], tools: [$writeFile], systemPrompt: $system, maxTokens: 1024));
    if (is_string($first)) {
        return failedRequest('step 1 (cold)', $first);
    }
    $calls = $first->toolCalls ?? [];
    if ($calls === [] || !$calls[0] instanceof ToolCall) {
        return verdict(FAIL, 'step 1 made no tool call (content: ' . redact($first->content) . ')');
    }
    $before = $first->usage;
    // SGLang writes `prompt_tokens_details: null` - not `cached_tokens: 0` -
    // when nothing was cached (measured on skynet2 2026-10-05, with
    // --enable-cache-report on), so a cold step reads as unreported here.
    // Only step 2's report tells whether cache reporting is on at all.
    $firstPrompt = sglangPrompt($before);
    $firstOutput = (int) $before?->outputTokens;

    $tail = [new ToolResultMessage($calls[0]->id(), 'wrote notes.txt')];
    $next = static fn(?string $reasoning): CompleteRequest => new CompleteRequest(
        model: $model,
        messages: [$user, new AssistantMessage($first->content, $calls, $reasoning), ...$tail],
        tools: [$writeFile],
        systemPrompt: $system,
        maxTokens: 128,
    );

    $replay = send($provider, $next($first->reasoning));
    if (is_string($replay)) {
        return failedRequest('step 2 (reasoning replayed)', $replay);
    }
    $after = $replay->usage;
    $control = send($provider, $next(null));
    $controlCached = is_string($control) ? null : $control->usage?->cacheReadTokens;

    if ($after?->cacheReadTokens === null) {
        return verdict(FAIL, 'step 2 reported no cached_tokens (server not launched with --enable-cache-report?): ' . usageLine($after));
    }
    $afterCached = $after->cacheReadTokens;
    $detail = sprintf(
        'model %s; step 1: prompt %d, cached %s, output %d (reasoning %s chars); '
        . 'step 2 with reasoning_content replayed: prompt %d, cached %d (%d past step 1\'s prompt); '
        . 'control without reasoning: cached %s',
        $model,
        $firstPrompt,
        $before?->cacheReadTokens === null ? 'null (SGLang omits a zero count)' : (string) $before->cacheReadTokens,
        $firstOutput,
        $first->reasoning === null ? 'none' : (string) strlen($first->reasoning),
        sglangPrompt($after),
        $afterCached,
        $afterCached - $firstPrompt,
        $controlCached === null ? (is_string($control) ? 'error: ' . $control : 'null') : (string) $controlCached,
    );

    // The radix cache matches whole pages (skynet2: `page_size` 64), so
    // "step 1's whole prompt" is its last full page.
    $pageSize = max(1, (int) ($options['page-size'] ?? 64));
    if ($afterCached < intdiv($firstPrompt, $pageSize) * $pageSize) {
        return verdict(FAIL, "step 2 reused less than step 1's whole prompt - {$detail}");
    }
    // 0.1's own claim: the replayed row is byte-stable with what the model
    // generated, so reuse runs on INTO it; the reasoning-less control
    // diverges at the row's start. Only measurable once the step wrote at
    // least a couple of pages.
    if ($controlCached !== null && $firstOutput >= 2 * $pageSize && $afterCached <= $controlCached) {
        return verdict(FAIL, "replaying reasoning_content reused nothing past the control - {$detail}");
    }

    return verdict(PASS, $detail);
}

// ---------------------------------------------------------------------------
// LIVE-X31b
// ---------------------------------------------------------------------------

function checkAnthropicToolCall(array $options): array
{
    if ((string) getenv('ANTHROPIC_API_KEY') === '') {
        return verdict(BLOCKED, 'ANTHROPIC_API_KEY is not set');
    }
    $factory = new ProviderFactory();
    $config = $factory->defaultConfig('anthropic');
    if (isset($options['model'])) {
        $config['model'] = $options['model'];
    }
    $provider = $factory->create($config);
    $weather = probeTool('get_weather', 'Get the current weather for a city.', ['city' => 'the city name']);

    $response = send($provider, new CompleteRequest(
        model: (string) $config['model'],
        messages: [new UserMessage('Call the get_weather tool for Paris right now. Do not answer in prose.')],
        tools: [$weather],
        maxTokens: 256,
    ));
    if (is_string($response)) {
        return failedRequest('request', $response);
    }
    $call = ($response->toolCalls ?? [])[0] ?? null;
    if (!$call instanceof ToolCall || $call->name() !== 'get_weather') {
        return verdict(FAIL, 'no get_weather tool call came back (content: ' . redact($response->content) . ')');
    }

    return verdict(PASS, sprintf('model %s called get_weather with %s; %s', $config['model'], json_encode($call->arguments()), usageLine($response->usage)));
}

// ---------------------------------------------------------------------------
// LIVE-A15b / LIVE-A15v
// ---------------------------------------------------------------------------

function awsCredentialsPresent(): bool
{
    if ((string) getenv('AWS_ACCESS_KEY_ID') !== '' || (string) getenv('AWS_PROFILE') !== '') {
        return true;
    }
    $home = (string) getenv('HOME');

    return $home !== '' && is_file("{$home}/.aws/credentials");
}

function gcpProject(array $options): ?string
{
    $project = $options['project'] ?? (string) getenv('GCP_PROJECT_ID');

    return $project === '' ? null : $project;
}

function gcpCredentialsPresent(): bool
{
    $file = (string) getenv('GOOGLE_APPLICATION_CREDENTIALS');
    if ($file !== '') {
        return is_file($file);
    }
    $home = (string) getenv('HOME');

    return $home !== '' && is_file("{$home}/.config/gcloud/application_default_credentials.json");
}

/** Blocked reason for Vertex, or null when it can be tried. */
function vertexBlocked(array $options): ?string
{
    $missing = [];
    if (gcpProject($options) === null) {
        $missing[] = 'GCP_PROJECT_ID (or --project)';
    }
    if (!gcpCredentialsPresent()) {
        $missing[] = 'Application Default Credentials (GOOGLE_APPLICATION_CREDENTIALS or gcloud ADC)';
    }

    return $missing === [] ? null : 'missing ' . implode(' and ', $missing);
}

/** The same >1,024-token request twice: the first writes the cache, the second reads it. */
function checkCacheMarks(ProviderInterface $provider, string $model): array
{
    $request = new CompleteRequest(
        model: $model,
        messages: [new UserMessage('Reply with the single word OK.')],
        systemPrompt: longSystemPrompt(bin2hex(random_bytes(8)), 40),
        maxTokens: 16,
    );

    $write = send($provider, $request);
    if (is_string($write)) {
        return failedRequest('call 1', $write);
    }
    $read = send($provider, $request);
    if (is_string($read)) {
        return failedRequest('call 2', $read);
    }

    $detail = sprintf('model %s; call 1: %s; call 2: %s', $model, usageLine($write->usage), usageLine($read->usage));
    if (($write->usage?->cacheCreationTokens ?? 0) <= 0) {
        return verdict(FAIL, "call 1 wrote no cache entry - {$detail}");
    }
    if (($read->usage?->cacheReadTokens ?? 0) <= 0) {
        return verdict(FAIL, "call 2 read nothing from the cache - {$detail}");
    }

    return verdict(PASS, $detail);
}

function bedrockProvider(array $options, bool $promptCache): BedrockProvider
{
    return BedrockProvider::create(
        region: $options['region'] ?? 'us-east-1',
        model: $options['model'] ?? null,
        promptCache: $promptCache,
    );
}

function checkBedrockCacheMarks(array $options): array
{
    if (!awsCredentialsPresent()) {
        return verdict(BLOCKED, 'no AWS credentials (env, AWS_PROFILE or ~/.aws/credentials)');
    }
    $provider = bedrockProvider($options, true);
    if (!$provider->marksPromptCache('')) {
        return verdict(FAIL, "provider does not mark prompt cache for {$provider->model()}");
    }

    return checkCacheMarks($provider, $provider->model());
}

function vertexProvider(array $options, string $model, bool $promptCache, ?int $thinkingBudget = null): VertexProvider
{
    return VertexProvider::create(
        projectId: (string) gcpProject($options),
        location: $options['location'] ?? 'us-central1',
        model: $model,
        thinkingBudget: $thinkingBudget,
        promptCache: $promptCache,
    );
}

function checkVertexCacheMarks(array $options): array
{
    $blocked = vertexBlocked($options);
    if ($blocked !== null) {
        return verdict(BLOCKED, $blocked);
    }
    $model = $options['model'] ?? 'claude-sonnet-4-6';

    return checkCacheMarks(vertexProvider($options, $model, true), $model);
}

// ---------------------------------------------------------------------------
// LIVE-A21b
// ---------------------------------------------------------------------------

function checkGeminiThinkingBudget(array $options): array
{
    $blocked = vertexBlocked($options);
    if ($blocked !== null) {
        return verdict(BLOCKED, $blocked);
    }
    $model = $options['model'] ?? 'gemini-2.5-flash';
    $budget = (int) ($options['thinking-budget'] ?? 1024);

    $response = send(vertexProvider($options, $model, false, $budget), new CompleteRequest(
        model: $model,
        messages: [new UserMessage('In one sentence, why is the sky blue?')],
        maxTokens: 1536,
    ));
    if (is_string($response)) {
        return failedRequest('request', $response);
    }
    $detail = sprintf('model %s, thinkingBudget %d; %s; text %d chars', $model, $budget, usageLine($response->usage), strlen($response->content));
    if ($response->usage?->reasoningTokens === null) {
        return verdict(FAIL, "reasoningTokens not reported - {$detail}");
    }
    if (trim($response->content) === '' || $response->truncated) {
        return verdict(FAIL, "reply empty or truncated by the thinking spend - {$detail}");
    }

    return verdict(PASS, $detail);
}

// ---------------------------------------------------------------------------
// LIVE-CH
// ---------------------------------------------------------------------------

function checkCacheHealthNotice(array $options): array
{
    $which = $options['provider'] ?? 'bedrock';
    if ($which === 'bedrock') {
        if (!awsCredentialsPresent()) {
            return verdict(BLOCKED, 'no AWS credentials (env, AWS_PROFILE or ~/.aws/credentials)');
        }
        $provider = bedrockProvider($options, false);
        $model = $provider->model();
    } elseif ($which === 'vertex') {
        $blocked = vertexBlocked($options);
        if ($blocked !== null) {
            return verdict(BLOCKED, $blocked);
        }
        $model = $options['model'] ?? 'claude-sonnet-4-6';
        $provider = vertexProvider($options, $model, false);
    } else {
        return verdict(FAIL, "--provider must be bedrock or vertex for LIVE-CH, got {$which}");
    }

    $watch = new CacheHealthWatch();
    $fired = [];
    $lines = [];
    for ($reply = 1; $reply <= 3; $reply++) {
        $response = send($provider, new CompleteRequest(
            model: $model,
            messages: [new UserMessage("Reply with the number {$reply}.")],
            maxTokens: 8,
        ));
        if (is_string($response)) {
            return failedRequest("reply {$reply}", $response);
        }
        $usage = $response->usage;
        $lines[] = "reply {$reply}: " . usageLine($usage);
        if ($usage?->cacheReadTokens !== 0 || $usage->cacheCreationTokens !== 0) {
            return verdict(FAIL, "reply {$reply} did not report both cache buckets as 0 - " . implode('; ', $lines));
        }
        if ($watch->observe($usage) !== null) {
            $fired[] = $reply;
        }
    }

    $detail = "{$which} {$model}; " . implode('; ', $lines) . '; notice fired on reply ' . ($fired === [] ? 'none' : implode(',', $fired));

    return verdict($fired === [3] ? PASS : FAIL, $detail);
}

// ---------------------------------------------------------------------------
// main
// ---------------------------------------------------------------------------

$options = options($argv);
$requested = strtoupper($options['check'] ?? '') === 'ALL'
    ? CHECKS
    : array_values(array_filter(array_map('trim', explode(',', $options['check'] ?? ''))));
if ($requested === []) {
    fwrite(STDERR, "usage: php scripts/provider-cache-live-probe.php --check=<" . implode('|', CHECKS) . ">[,…]|all\n"
        . "       [--model=ID] [--base-url=URL] [--region=AWS_REGION] [--project=GCP_PROJECT] [--location=GCP_LOCATION]\n"
        . "       [--thinking-budget=N] [--provider=bedrock|vertex] [--page-size=N] [--deadline=SECONDS]\n");
    exit(64);
}
foreach ($requested as $id) {
    if (!in_array($id, CHECKS, true)) {
        fwrite(STDERR, "unknown check {$id}; known: " . implode(', ', CHECKS) . "\n");
        exit(64);
    }
}

$deadline = (int) ($options['deadline'] ?? 600);
if ($deadline > 0 && function_exists('pcntl_alarm')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, static function () use ($deadline): never {
        fwrite(STDERR, "ABORT: the probe exceeded its {$deadline}s deadline\n");
        exit(1);
    });
    pcntl_alarm($deadline);
}

$runners = [
    'LIVE-0.1' => checkSglangWriteStep(...),
    'LIVE-X31b' => checkAnthropicToolCall(...),
    'LIVE-A15b' => checkBedrockCacheMarks(...),
    'LIVE-A15v' => checkVertexCacheMarks(...),
    'LIVE-A21b' => checkGeminiThinkingBudget(...),
    'LIVE-CH' => checkCacheHealthNotice(...),
];

$statuses = [];
foreach ($requested as $id) {
    try {
        [$status, $detail] = $runners[$id]($options);
    } catch (\Throwable $e) {
        [$status, $detail] = failedRequest('probe error', redact($e::class . ': ' . $e->getMessage()));
    }
    $statuses[] = $status;
    echo str_pad($status, 7) . " [{$id}] {$detail}\n";
}

exit(match (true) {
    in_array(FAIL, $statuses, true) => 1,
    in_array(BLOCKED, $statuses, true) => 3,
    default => 0,
});
