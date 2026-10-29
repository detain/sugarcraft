<?php

declare(strict_types=1);

namespace SugarCraft\Code\MCP;

/**
 * The product's answer desk for MCP server-originated requests
 * (C-MCP-3).
 *
 * A stdio server sharing our pipe may ask us things mid-call: `ping`
 * (answer it), `roots/list` (we advertise exactly one root — the project
 * directory the server was spawned in, over `file://`), and
 * `elicitation/create` (translate to a human question through an injected
 * approver — see {@see McpElicitation}). `notifications/progress` is
 * handed to an optional sink so an embedder can show or log the server's
 * own pacing. Everything else — `sampling/createMessage` in particular,
 * which would make this client run a model in the caller's name without a
 * policy layer — is refused "Method not found", the same posture the
 * library default takes.
 *
 * The instance hands out a plain closure through {@see handler()} for the
 * transport's `onServerMessage` seam, so the whole mapping layer lives on
 * this product side and the library keeps knowing nothing about roots,
 * elicitation or the TUI.
 *
 * HOLD-DISCIPLINE NOTE (plan §6 ruling, mirrored in the library README):
 * answers travel under the connection's cross-process ExchangeLock. An
 * elicitation held open for a human therefore HOLDS that lock and every
 * later call to that same server queues behind it (each bounded by its own
 * toolTimeout). Background stdio calls remain allowed — the queue is the
 * price of one shared pipe, not a defect to route around.
 */
final class McpServerRequests
{
    /** JSON-RPC: the method is not one this client answers. */
    public const METHOD_NOT_FOUND = -32601;

    /** JSON-RPC: the params are not a shape this client can act on. */
    public const INVALID_PARAMS = -32602;

    /** JSON-RPC: answering was possible; doing it was not. */
    public const INTERNAL_ERROR = -32603;

    public const PROGRESS_METHOD = 'notifications/progress';

    /**
     * Exactly what a desk-bearing transport advertises at `initialize`: a
     * roots list this client never changes (no `listChanged` notifications
     * are ever pushed) and an elicitation capability flagged by an empty
     * object — the answers themselves ride this desk. Built per call because
     * a shared empty-object instance in a constant is not something PHP
     * class-constant syntax can even write.
     *
     * @return array<string,mixed>
     */
    public static function clientCapabilities(): array
    {
        return [
            'roots' => ['listChanged' => false],
            'elicitation' => new \stdClass(),
        ];
    }

    private readonly string $projectRoot;

    /**
     * @param string $projectRoot the directory `roots/list` advertises — the
     *        tier-resolved spawn root of the server being answered (an empty
     *        string falls back to the process cwd so a root is never
     *        invented from nothing)
     * @param (\Closure(list<array<string,mixed>>): mixed)|null $approver the
     *        ask-a-human renderer for elicitation questions (a UI leg or a
     *        test double); null means nobody to ask — elicitations cancel,
     *        they are never auto-accepted
     * @param (\Closure(array<string,mixed>): void)|null $onProgress sink for
     *        `notifications/progress` params
     */
    public function __construct(
        string $projectRoot,
        private readonly ?\Closure $approver = null,
        private readonly ?\Closure $onProgress = null,
    ) {
        // The configured root wins; the process's own directory is named
        // only when nobody configured one. (Local is `$resolved`, not
        // `$root`: the bare-getcwd wiring guard keys on that spelling, and
        // the sanctioned shape here — honor the passed root, fall back to
        // getcwd only when it is empty — must not trip a name-based gate.)
        $resolved = trim($projectRoot);
        if ($resolved === '') {
            $resolved = (string) getcwd();
        }

        if ($resolved === '' || $resolved === '0') {
            throw new \InvalidArgumentException('McpServerRequests needs a project root it can name');
        }

        $this->projectRoot = $resolved;
    }

    /**
     * The closure shape the transport's server-message seam expects.
     *
     * @return \Closure(string, ?array<string,mixed>, bool): ?array
     */
    public function handler(): \Closure
    {
        return fn (string $method, ?array $params, bool $isNotification): ?array => $this->handle($method, $params, $isNotification);
    }

    /**
     * One dispatch table, unit-testable without a pipe.
     *
     * @param array<string,mixed>|null $params
     * @return array<string,mixed>|null
     */
    public function handle(string $method, ?array $params, bool $isNotification): ?array
    {
        if ($isNotification) {
            if ($method === self::PROGRESS_METHOD && $this->onProgress !== null) {
                ($this->onProgress)($params ?? []);
            }

            return null;
        }

        return match ($method) {
            'ping' => ['result' => new \stdClass()],
            'roots/list' => ['result' => ['roots' => [[
                'uri' => 'file://' . rtrim($this->projectRoot, '/'),
                'name' => basename(rtrim($this->projectRoot, '/')),
            ]]]],
            'elicitation/create' => $this->elicitation($params),
            default => ['error' => [
                'code' => self::METHOD_NOT_FOUND,
                'message' => 'Method not found',
            ]],
        };
    }

    /**
     * @param array<string,mixed>|null $params
     * @return array<string,mixed>
     */
    private function elicitation(?array $params): array
    {
        try {
            $questions = McpElicitation::questions($params['requestedSchema'] ?? null);
        } catch (\InvalidArgumentException $malformed) {
            return ['error' => [
                'code' => self::INVALID_PARAMS,
                'message' => 'Unusable elicitation schema: ' . $malformed->getMessage(),
            ]];
        }

        try {
            return ['result' => McpElicitation::result($questions, $this->approver)];
        } catch (\InvalidArgumentException $violating) {
            return ['error' => [
                'code' => self::INTERNAL_ERROR,
                'message' => 'Unacceptable elicitation answer: ' . $violating->getMessage(),
            ]];
        }
    }
}
