<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plugin;

/**
 * Plugin SDK for authoring plugins in PHP.
 *
 * Provides a run loop that reads requests from stdin, dispatches to a handler,
 * and writes responses to stdout. Plugin authors extend this class and
 * implement the init(), update(), and view() methods.
 *
 * Mirrors the lattice plugin SDK pattern.
 *
 * @example
 * ```php
 * class MyPlugin extends PluginSdk {
 *     public function init(): array {
 *         return ['name' => 'my-plugin', 'minSize' => [20, 4], 'interval' => 5];
 *     }
 *
 *     public function update(array $state): array {
 *         return array_merge($state, ['tick' => ($state['tick'] ?? 0) + 1]);
 *     }
 *
 *     public function view(array $state, int $width, int $height): string {
 *         return "Tick: {$state['tick']}";
 *     }
 * }
 *
 * MyPlugin::run(fn($req) => $handler($req));
 * ```
 */
abstract class PluginSdk
{
    /** @var array<string, mixed> */
    private array $state = [];

    private int $interval = 0;

    /**
     * Run the plugin as the main loop of its own process.
     *
     * E723 (round 82) — exit posture on the record. This is a plugin-process
     * entrypoint, not a composable library routine: the example at the top of
     * this class is the ENTIRE body of the plugin author's launch script, and
     * the loop consumes the process's STDIN for its whole life — an in-host
     * caller would already have lost its stdin long before the exit matters.
     * `exit(0)` at the tail is the DESIGNED terminus on stdin EOF, and it is
     * the cooperative half of {@see ExternalModule}'s E366 teardown ladder:
     * the host closes the pipes, the SDK sees EOF, exits on its own inside
     * the grace rung, and the host never escalates to a signal.
     *
     * The `never` is load-bearing, not decoration: normal completion of a
     * never-typed function raises a TypeError, so deleting the exit would
     * turn the child's observable status from 0 into 255 — an uncaught
     * throw at the top of the plugin's script. The terminus therefore stays
     * `exit(0)`, fail-closed policed by tests/Plugin/SrcExitCensusTest.php:
     * exactly one justified T_EXIT in src/, inside THIS body, and a real
     * child proving EOF ⇒ status 0 with clean stderr.
     *
     * @param callable(Request): Response $handler Request handler
     */
    final public static function run(callable $handler): never
    {
        $sdk = new static();

        while (true) {
            $line = fgets(STDIN);
            if ($line === false) {
                break;
            }

            $line = trim($line);
            // E726 (round 82) — PROTOCOL CONTRACT, judged against the host side
            // of the pipe: the wire is line-delimited JSON, one toJson()."\n"
            // per request (ExternalModule::sendRequest()); a blank line is
            // never a delimiter, a flush signal, or a keepalive. So a blank or
            // whitespace-only read is out-of-band noise: skip it without
            // parsing and WITHOUT answering — emitting anything here would
            // desync the host's strict one-request/one-response pairing in
            // readResponse(). Pinned by PluginSdkBlankLineContractTest.
            if ($line === '') {
                continue;
            }

            try {
                $request = Request::fromJson($line);
                $response = $sdk->handle($request);
                echo $response->toJson() . "\n";
                fflush(STDOUT);
            } catch (\Throwable $e) {
                $errorResponse = Response::error($e->getMessage());
                echo $errorResponse->toJson() . "\n";
                fflush(STDOUT);
            }
        }

        exit(0);
    }

    /**
     * Handle a request and return a response.
     */
    private function handle(Request $request): Response
    {
        return match ($request->type) {
            'init' => $this->handleInit(),
            'update' => $this->handleUpdate($request->data['state'] ?? []),
            'view' => $this->handleView(
                $request->data['width'] ?? 80,
                $request->data['height'] ?? 24,
                $request->data['state'] ?? []
            ),
            default => Response::error("Unknown request type: {$request->type}"),
        };
    }

    /**
     * Handle an init request.
     */
    private function handleInit(): Response
    {
        $meta = $this->init();
        $interval = max(0, min((int) ($meta['interval'] ?? 0), 86400));

        $minSize = $meta['minSize'] ?? null;
        if (
            is_array($minSize)
            && count($minSize) === 2
            && is_int($minSize[0] ?? null)
            && is_int($minSize[1] ?? null)
            && ($minSize[0] ?? 0) > 0
            && ($minSize[1] ?? 0) > 0
        ) {
            $minSize = [
                max(1, min((int) $minSize[0], 10000)),
                max(1, min((int) $minSize[1], 1000)),
            ];
        } else {
            $minSize = [30, 4];
        }

        $this->interval = $interval;
        return Response::init(
            $meta['name'] ?? 'unnamed',
            $minSize,
            $this->interval
        );
    }

    /**
     * Handle an update request.
     */
    private function handleUpdate(array $state): Response
    {
        $this->state = $this->update($state);
        return Response::update($this->state);
    }

    /**
     * Handle a view request.
     */
    private function handleView(int $width, int $height, array $state): Response
    {
        $content = $this->view($state, $width, $height);
        return Response::view($content);
    }

    /**
     * Initialize the plugin.
     *
     * @return array{name:string, minSize:array{0:int,1:int}, interval:int}
     */
    abstract protected function init(): array;

    /**
     * Update the plugin state.
     *
     * @param array<string, mixed> $state Current state
     * @return array<string, mixed> Updated state
     */
    abstract protected function update(array $state): array;

    /**
     * Render the plugin view.
     *
     * @param array<string, mixed> $state Current state
     * @param int $width Available width
     * @param int $height Available height
     * @return string Rendered content
     */
    abstract protected function view(array $state, int $width, int $height): string;
}
