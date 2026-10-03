<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers\Concerns;

/**
 * The hashed session-affinity header every declared provider transport puts
 * on the wire, so a caching gateway can route one session's calls to the
 * same warm backend (prompt_plan.md P10.S4; Mirrors charmbracelet/crush
 * sessionHeaders(call.SessionID)).
 *
 * WHY HASHED AND NOT RAW — the plan's hard constraint. A raw session id in a
 * header is a per-user prefix: a gateway keying affinity on it would pin
 * every cache entry to one user, defeating the cross-user prompt-cache sharing
 * the same gateway exists to provide, and it leaks a persistent user-linked
 * identifier to every proxy hop between here and the model. `hash('sha256', …)`
 * keeps the routing property — same session, same value, so same warm backend —
 * while the value identifies nothing outside this session's own traffic, and
 * two sessions never collide.
 *
 * WHY THE FULL 64 HEX, NO TRUNCATION. In-tree precedent is unanimous:
 * `ContextCompactor::exchangeKey()` and every other `hash('sha256', …)` user
 * in `src/` ship the full digest. Cutting it shorter would need a collision
 * analysis the full hex simply does not ask for, and a header value has no
 * size budget worth that analysis.
 *
 * WHY AN ABSENT HEADER RATHER THAN AN EMPTY ONE. Hashing the empty string
 * gives a fine-looking 64-hex constant, and every session-less caller would
 * carry that identical value — pinning all unaffinity'd traffic to one backend
 * and silently concentrating exactly what this header exists to spread. A
 * null or blank id therefore emits no header at all, the same conditional
 * shape `Authorization` takes when there is no api key.
 *
 * CONSUMER CONTRACT — WIRED PER REQUEST (step 0.13-a). The id must be the
 * CURRENT session's: `Chat::$currentSessionId` changes under `/resume`,
 * `/branch` and Ctrl+Tab, so a provider built with a fixed id would keep
 * hashing the previous session. The live id therefore rides the REQUEST, not
 * the provider: `Chat::scheduleBackendCompletion()` stamps it onto the
 * engine per dispatch (`EngineBackend::withSessionId()`), the engine onto the
 * turn's App, and `Runtime::run()` onto every
 * {@see \SugarCraft\Crush\Providers\CompleteRequest::$sessionId}; each
 * completion post site hands that to {@see sessionAffinityHeaders()}, where a
 * request id WINS over the constructor's `$sessionAffinityId`. The
 * constructor id stays as the fallback for a request that names no session
 * and for `embeddings()`, whose request carries none. A turn with no session
 * (the `-p` path, a fresh unsaved chat) still sends no header.
 *
 * NOT ROUTED THROUGH THIS TRAIT (enumerated follow-ups, not oversights): the
 * OpenAI-SDK transports, Vertex, Bedrock and the ClaudeCode CLI all carry the
 * session in their own shapes — the CLI passes the raw id to a local
 * `--resume` argument, which is a filesystem handoff, not a proxy header, so
 * the leak analysis above does not apply to it. Only the two OpenAI-compatible
 * HTTP transports declare the header today.
 *
 * THE ID LIVES ON THE HOST CLASS, NOT HERE: both consumers are `final readonly
 * class`es and PHP refuses a readonly class that uses a trait declaring a
 * property, so `sessionAffinityHeaders()` reads the `$sessionAffinityId`
 * promoted constructor property each provider declares itself — the same
 * forced split {@see \SugarCraft\Crush\Tools\Concerns\DetectsCapabilities}
 * records for its carrier.
 */
trait SessionAffinity
{
    /**
     * The header name — the first header-name CONSTANT in `src/`: the other
     * transports that set headers so far inline their names as literals in the
     * client-defaults arrays, which is exactly the shape that drifts when the
     * next transport copies the block and retypes one letter.
     */
    public const SESSION_AFFINITY_HEADER = 'X-SugarCrush-Session';

    /**
     * The wire value for one session id: full 64-char lowercase hex, or null
     * when there is no session to be affine to (null or blank id).
     */
    public function sessionAffinityHeaderValue(?string $sessionId): ?string
    {
        if ($sessionId === null || $sessionId === '') {
            return null;
        }

        return hash('sha256', $sessionId);
    }

    /**
     * The per-request `headers` option at a `post()` site: `[]` or the one
     * affinity header — for the request's own session id when it names one
     * (step 0.13-a), else the host class's `$sessionAffinityId`.
     *
     * Returned as per-request options rather than baked into the client
     * defaults because an injected client is a real shape, not a test fiction:
     * the unit suites hand bare `Client`s straight to the constructor, and
     * `ProviderFactory::createAnthropic()` builds its own x-api-key client and
     * injects it. Client-level headers alone would miss every one of those.
     * Guzzle MERGES a request's `headers` with the client's defaults
     * per-name — pinned, with Content-Type and Authorization co-present on a
     * captured request, by
     * {@see \SugarCraft\Crush\Tests\Providers\SessionAffinityHeaderTest} —
     * so `sessionAffinityHeaders()` can travel alongside auth without
     * displacing it, and the empty-array form leaves the defaults untouched.
     *
     * @return array<string, string>
     */
    public function sessionAffinityHeaders(?string $requestSessionId = null): array
    {
        $value = $this->sessionAffinityHeaderValue(
            $requestSessionId !== null && $requestSessionId !== '' ? $requestSessionId : $this->sessionAffinityId,
        );

        if ($value === null) {
            return [];
        }

        return [self::SESSION_AFFINITY_HEADER => $value];
    }
}
