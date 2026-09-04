<?php

declare(strict_types=1);

/**
 * Q10 live smoke check — one-shot verification of the three wire shapes the
 * sugar-crush Qwen lane depends on, against the REAL dev deployment
 * (https://skynet2.interserver.net/v1, no auth, canonical model id per E-70).
 *
 * Run from the sugar-crush library root:
 *
 *     php scripts/qwen-live-smoke.php
 *
 * Exits 0 printing one PASS line per verified shape; exits non-zero printing
 * expected-vs-got on the first shape whose wire response deviates. This is a
 * manual gate (not CI): it exists to re-confirm, after any provider or
 * template change, that the condensed audit evidence in qwen.md Part III
 * still matches the live server. Evidence ids verified per shape:
 *
 *  1. E-24  non-stream single tool call (finish_reason, id pattern, JSON-string
 *     arguments), kwargs cheap-mode via E-22/E-40 (thinking off, effort low).
 *  2. E-25/E-26/E-27/E-30/E-31  streaming 3 parallel tool calls: index-keyed
 *     opener-then-continuations fragmentation, flat usage chunk with
 *     choices:[] preceding [DONE], no completion_tokens_details anywhere.
 *  3. E-10/E-11/E-13 + Q5 merge: a Chat-shaped multi-system stack collapses
 *     to exactly ONE leading system row before it reaches the wire, and the
 *     collapsed stack POSTs 200 (more than one system at the template is a
 *     400 "System message must be at the beginning", E-10).
 *
 * DOCUMENTED DEVIATION from the spec's "public seam" preference: SglangProvider
 * ::formatMessages() is private (src/Providers/SglangProvider.php:1542) and the
 * Q10 touch-list forbids src edits, so shape 3 reaches it through Reflection,
 * exactly like the pinned E-14 regression tests do.
 *
 * HTTP goes through plain PHP stream wrappers — no new dependencies. Every
 * request is bounded by a 120s timeout; transport failures retry twice before
 * failing loud. A SHAPE mismatch is a finding, never retried away.
 */

namespace SugarCrush\Scripts;

use GuzzleHttp\Client;
use ReflectionMethod;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\SglangProvider;

require __DIR__ . '/../vendor/autoload.php';

const BASE_URL = 'https://skynet2.interserver.net/v1';
const MODEL = 'Qwen/Qwen3.8-Flash-Next'; // canonical served id (E-70)

/** Print expected-vs-got and halt non-zero. Never returns. */
function fail(string $shape, string $expected, string $got): never
{
    fwrite(STDERR, "FAIL [{$shape}]\n  expected: {$expected}\n  got:      {$got}\n");
    exit(1);
}

function pass(string $shape, string $observed): void
{
    echo "PASS [{$shape}] {$observed}\n";
}

/**
 * POST JSON and return [status, rawBody]; up to 3 transport attempts, no
 * retry on HTTP 4xx/5xx (those bodies are the finding).
 *
 * @param array<string, mixed> $body
 * @return array{0: int, 1: string}
 */
function post(string $path, array $body): array
{
    $payload = json_encode($body, JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        fail('transport', 'encodable request body', json_last_error_msg());
    }

    $lastError = 'unknown transport error';
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $payload,
            'timeout' => 120,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents(BASE_URL . $path, false, $context);
        if ($raw === false) {
            $lastError = "no response on attempt {$attempt}";
            usleep(500_000);
            continue;
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return [$status, $raw];
    }

    fail('transport', 'HTTP response from ' . BASE_URL . $path, $lastError);
}

/** Assert HTTP 200 or fail loud with the error body (E-40 shows 400 is the template's own voice). */
function assertOk(int $status, string $raw, string $shape): void
{
    if ($status !== 200) {
        fail($shape, 'HTTP 200', "HTTP {$status}: " . substr($raw, 0, 400));
    }
}

/** @return array<string, mixed> */
function decode(string $raw, string $shape): array
{
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        fail($shape, 'JSON object body', 'unparseable: ' . substr($raw, 0, 400));
    }

    return $data;
}

/** @param array<string, mixed> $spec */
function tool(array $spec): array
{
    return [
        'type' => 'function',
        'function' => [
            'name' => $spec['name'],
            'description' => $spec['description'],
            'parameters' => [
                'type' => 'object',
                'properties' => [$spec['arg'] => ['type' => 'string']],
                'required' => [$spec['arg']],
            ],
        ],
    ];
}

// Shape 1 and shape 2 share the thinking-off kwargs set: cheap, deterministic,
// and itself a wire assertion target (E-22/E-40/E-42 accept these exact keys).
const CHEAP_KWARGS = [
    'enable_thinking' => false,
    'preserve_thinking' => false, // the shipped Q10 policy (E-28)
    'reasoning_effort' => 'low',  // member of the template's set (E-40)
];

// -------------------------------------------------------------------------
// Shape 1 — E-24: non-stream single tool call.
// -------------------------------------------------------------------------

$weather = tool(['name' => 'get_weather', 'description' => 'Get current weather for a city.', 'arg' => 'city']);

[$status, $raw] = post('/chat/completions', [
    'model' => MODEL,
    'messages' => [['role' => 'user', 'content' => 'Call the get_weather tool for Paris right now. Do not answer in prose.']],
    'tools' => [$weather],
    'tool_choice' => ['type' => 'function', 'function' => ['name' => 'get_weather']],
    'max_tokens' => 128,
    'chat_template_kwargs' => CHEAP_KWARGS,
]);
assertOk($status, $raw, 'shape1 E-24');
$data = decode($raw, 'shape1 E-24');

$choice = $data['choices'][0] ?? [];
if (($choice['finish_reason'] ?? null) !== 'tool_calls') {
    fail('shape1 E-24', 'finish_reason "tool_calls"', var_export($choice['finish_reason'] ?? null, true));
}
$calls = $choice['message']['tool_calls'] ?? [];
if (count($calls) !== 1) {
    fail('shape1 E-24', 'exactly 1 tool_calls entry', 'got ' . count($calls));
}
$call = $calls[0];
if (preg_match('/^call_[0-9a-f]{24}$/', (string) ($call['id'] ?? '')) !== 1) {
    fail('shape1 E-24', 'id matching /^call_[0-9a-f]{24}$/', var_export($call['id'] ?? null, true));
}
if (($call['function']['name'] ?? null) !== 'get_weather') {
    fail('shape1 E-24', 'function.name "get_weather"', var_export($call['function']['name'] ?? null, true));
}
$arguments = $call['function']['arguments'] ?? null;
if (!is_string($arguments) || json_decode($arguments) === null) {
    fail('shape1 E-24', 'function.arguments as a JSON string', var_export($arguments, true));
}
pass('shape1 E-24', 'single tool call: id ' . $call['id'] . ', name ' . $call['function']['name']
    . ', arguments JSON string ' . json_encode($arguments));

// -------------------------------------------------------------------------
// Shape 2 — E-25/26/27/30/31: streaming 3 parallel tool calls + usage chunk.
// -------------------------------------------------------------------------

$tools = [
    $weather,
    tool(['name' => 'get_time', 'description' => 'Get current time for a timezone.', 'arg' => 'timezone']),
    tool(['name' => 'get_population', 'description' => 'Get population of a country.', 'arg' => 'country']),
];

[$status, $raw] = post('/chat/completions', [
    'model' => MODEL,
    'messages' => [[
        'role' => 'user',
        'content' => 'Call all three tools in ONE turn, in parallel: get_weather for Paris, get_time for UTC, get_population for France. No prose.',
    ]],
    'tools' => $tools,
    'max_tokens' => 256,
    'stream' => true,
    'stream_options' => ['include_usage' => true],
    'chat_template_kwargs' => CHEAP_KWARGS,
]);
assertOk($status, $raw, 'shape2 E-25/26/27/30/31');

$events = [];
$done = false;
foreach (preg_split('/\R/', $raw) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || !str_starts_with($line, 'data:')) {
        continue;
    }
    $data = trim(substr($line, 5));
    if ($data === '[DONE]') {
        $done = true;
        break;
    }
    $event = json_decode($data, true);
    if (is_array($event)) {
        $events[] = $event;
    }
}
if (!$done) {
    fail('shape2 E-27', 'terminal data: [DONE]', 'stream ended without [DONE] after ' . count($events) . ' events');
}

/** @var array<int, array{ids: list<string>, names: list<string>, args: string, openerFirst: bool, continuationsOnlyIndexArgs: bool}> $groups */
$groups = [];
$usageEvents = 0;
$usageAfterLastChoice = true;
$sawFinish = null;
foreach ($events as $position => $event) {
    $choices = $event['choices'] ?? [];
    if (isset($event['usage']) && $event['usage'] !== null) {
        // E-30: the usage event is a final, choice-less frame.
        if ($choices !== []) {
            fail('shape2 E-30', 'usage event with choices:[]', 'choices had ' . count($choices) . ' entries');
        }
        if ($position !== count($events) - 1) {
            $usageAfterLastChoice = false;
        }
        $usage = $event['usage'];
        if ($usageEvents === 0) {
            foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $flat) {
                if (!is_int($usage[$flat] ?? null)) {
                    fail('shape2 E-31', "flat int {$flat} on usage", var_export($usage[$flat] ?? null, true));
                }
            }
            // E-31: this deployment never nests token details (thinking is off
            // here anyway; with thinking ON reasoning_tokens appears FLAT too).
            if (array_key_exists('completion_tokens_details', $usage)) {
                fail('shape2 E-31', 'ABSENT completion_tokens_details (flat usage)', json_encode($usage['completion_tokens_details']));
            }
            if (array_key_exists('reasoning_tokens', $usage) && !is_int($usage['reasoning_tokens'])) {
                fail('shape2 E-31', 'flat int reasoning_tokens when present', var_export($usage['reasoning_tokens'], true));
            }
        }
        $usageEvents++;
        continue;
    }
    if ($usageEvents > 0) {
        fail('shape2 E-30', 'usage as the LAST data event before [DONE]', 'a choice frame arrived after the usage event');
    }
    $delta = $choices[0]['delta'] ?? [];
    $sawFinish ??= null;
    if (isset($choices[0]['finish_reason']) && $choices[0]['finish_reason'] !== null) {
        $sawFinish = $choices[0]['finish_reason'];
    }
    foreach ($delta['tool_calls'] ?? [] as $fragment) {
        $index = $fragment['index'] ?? null;
        if (!is_int($index)) {
            fail('shape2 E-26', 'integer index on every tool_call delta', json_encode($fragment));
        }
        $isOpener = isset($fragment['id']) || isset($fragment['function']['name']);
        if (!isset($groups[$index])) {
            $groups[$index] = ['ids' => [], 'names' => [], 'args' => '', 'openerFirst' => true, 'continuationsOnlyIndexArgs' => true];
            if (!$isOpener) {
                $groups[$index]['openerFirst'] = false;
            }
        }
        if ($isOpener) {
            // E-26 opener: carries id + function.name, arguments empty.
            $groups[$index]['ids'][] = (string) ($fragment['id'] ?? '(missing)');
            $groups[$index]['names'][] = (string) ($fragment['function']['name'] ?? '(missing)');
            $openArgs = $fragment['function']['arguments'] ?? '';
            if ($openArgs !== '') {
                fail('shape2 E-26', 'opener arguments ""', json_encode($openArgs));
            }
        } else {
            // E-26 continuation payload: index + arguments carry the data.
            // LIVE WIRE TRUTH (2026-09-04 probe, wire evidence alongside this
            // script's output): the deployment repeats the full OpenAI
            // envelope on EVERY fragment — `id`/`function.name` present but
            // NULL, `type:"function"` always set. So E-26's "ONLY
            // {index,arguments}" is the non-NULL payload contract, not key
            // absence. Assert that reading: no continuation may smuggle a
            // non-null id/name.
            $fn = is_array($fragment['function'] ?? null) ? $fragment['function'] : [];
            if (($fragment['id'] ?? null) !== null || ($fn['name'] ?? null) !== null) {
                $groups[$index]['continuationsOnlyIndexArgs'] = false;
            }
            $groups[$index]['args'] .= (string) ($fn['arguments'] ?? '');
        }
    }
}

if (array_keys($groups) !== [0, 1, 2]) {
    fail('shape2 E-25', 'tool-call deltas grouped at indices 0,1,2', 'got indices ' . json_encode(array_keys($groups)));
}
foreach ($groups as $index => $group) {
    if (!$group['openerFirst']) {
        fail("shape2 E-26 (index {$index})", 'first delta to carry id+function.name is the opener', 'opener was not first');
    }
    if (!$group['continuationsOnlyIndexArgs']) {
        fail("shape2 E-26 (index {$index})", 'continuation payload limited to index+arguments (id/name null)', 'a continuation carried a non-null id/name');
    }
    if (count($group['ids']) !== 1 || preg_match('/^call_[0-9a-f]{24}$/', $group['ids'][0]) !== 1) {
        fail("shape2 E-24/26 (index {$index})", 'exactly one id matching /^call_[0-9a-f]{24}$/', json_encode($group['ids']));
    }
    if (json_decode($group['args']) === null) {
        fail("shape2 E-26 (index {$index})", 'concatenated arguments parse as JSON', json_encode(substr($group['args'], 0, 120)));
    }
}
if ($sawFinish !== 'tool_calls') {
    fail('shape2 E-24', 'finish_reason "tool_calls" observed', var_export($sawFinish, true));
}
if ($usageEvents !== 1) {
    fail('shape2 E-30', 'exactly one usage event', "got {$usageEvents}");
}
pass('shape2 E-25/26/27/30/31', '3 parallel streaming groups at indices 0,1,2 (ids '
    . implode(', ', array_map(static fn(array $g): string => $g['ids'][0], $groups)) . ') all opener-first with '
    . 'index+arguments-only continuations; flat usage (' . json_encode($usage) . ') was the final frame before [DONE]');

// -------------------------------------------------------------------------
// Shape 3 — E-10/11/13 + Q5: multi-system stack merges to ONE leading system.
//
// formatMessages() is private (:1542); the touch-list forbids src seams, so
// we reflect in exactly like the E-14 pinned tests. Join format read from the
// implementation: request-level prompt FIRST, then history system rows in
// order, non-empty contents joined with "\n\n" (E-11 Vertex-conformance).
// -------------------------------------------------------------------------

$baseSystem = 'You are a terse calculator. Answer with numbers only.';
$launchNotice = 'NOTICE: this session was resumed from a saved transcript.';

$stack = [
    new SystemMessage($baseSystem),
    new UserMessage('What is 2+2?'),
    new AssistantMessage('4'),
    new SystemMessage($launchNotice), // mid-history row — E-13's launch-notice class
    new UserMessage('And plus one?'),
];

$format = new ReflectionMethod(SglangProvider::class, 'formatMessages');
$format->setAccessible(true);
$provider = new SglangProvider(BASE_URL, MODEL, null, new Client());
$rows = $format->invoke($provider, $stack, null);

$systemRows = array_values(array_filter($rows, static fn(array $r): bool => $r['role'] === 'system'));
if (count($systemRows) !== 1) {
    fail('shape3 E-10/Q5', 'exactly ONE system row after merge', 'got ' . count($systemRows));
}
if ($rows[0]['role'] !== 'system') {
    fail('shape3 E-10', 'the merged system row at index 0', 'index 0 role was ' . $rows[0]['role']);
}
if ($rows[0]['content'] !== $baseSystem . "\n\n" . $launchNotice) {
    fail('shape3 E-11', json_encode($baseSystem . "\n\n" . $launchNotice), json_encode($rows[0]['content']));
}
$restRoles = array_map(static fn(array $r): string => $r['role'], array_slice($rows, 1));
if ($restRoles !== ['user', 'assistant', 'user']) {
    fail('shape3 Q5', 'non-system rows untouched in order', json_encode($restRoles));
}
pass('shape3 E-10/11/13/Q5', 'merged to one leading system row ('
    . strlen($rows[0]['content']) . " chars, \"\\n\\n\" join, prompt-first then history order); "
    . 'remaining rows kept user,assistant,user');

// The merged stack must also be ACCEPTED by the live template: E-10's 400
// ("System message must be at the beginning") is exactly what an un-merged
// multi-system payload would draw.
[$status, $raw] = post('/chat/completions', [
    'model' => MODEL,
    'messages' => $rows,
    'max_tokens' => 16,
    'chat_template_kwargs' => CHEAP_KWARGS,
]);
if ($status !== 200) {
    fail('shape3 E-10', 'HTTP 200 for the merged single-system stack', "HTTP {$status}: " . substr($raw, 0, 400));
}
pass('shape3 E-10 live', 'the same merged stack POSTed 200 (a surviving second system row would be a 400)');

echo "ALL 3 SHAPES VERIFIED against " . BASE_URL . ' (' . MODEL . ")\n";
exit(0);
