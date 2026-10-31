<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\ClientTools;

/**
 * The client-registered tool set of ONE session host.
 *
 * One instance lives on a {@see \SugarCraft\Code\Host\SessionHost}; a client
 * holding the session's Write scope calls `register()` (wired to the RPC door
 * by C-TOOL-6.i; in-process by tests today) and its descriptors become
 * `client__<name>` proxies for the NEXT turn — a registration never mutates a
 * running turn's roster, because the roster is fixed per dispatch. When the
 * client's connection dies, 6.i calls {@see forget()} and the set dies with
 * it, exactly as the ruling demands: registrations are scoped to the
 * connection, not to the session's life.
 *
 * ## The shape of a registration
 *
 * `register()` REPLACES the client's whole set (re-announcing is the cheap
 * operation a reconnecting client needs; merging would hide a rename as an
 * addition). Each successful call returns a fresh 16-hex registration id;
 * answers that carry a stale id read as stale ids
 * ({@see \SugarCraft\Code\Host\SessionHost::resolveClientCall()}), so a
 * replace also retires every answer aimed at the previous set. A name another
 * client already holds is refused — two clients answering for one model-facing
 * name would make "first answer wins" a race nobody could reason about. The
 * cap ({@see MAX_TOOLS_PER_CLIENT}) bounds the prompt cost of a hostile
 * registration to sixteen descriptions.
 *
 * Names here are validated twice over and mean two different things: the
 * constructor of {@see ClientToolSpec} owns the shape of each descriptor,
 * while this class owns the COLLISIONS — a well-formed name can still be
 * spoken for. Keeping both judgements here would leave `owner()` answering
 * whatever the last caller's spelling of a name happened to be, so callers
 * hand raw spec names to `register()` and read FULL proxy names from
 * {@see owner()} and {@see proxies()}; the prefix is applied in exactly one
 * place, {@see ClientToolProxy::NAME_PREFIX}.
 */
final class ClientToolRegistry
{
    /** The largest set one client may announce. */
    public const MAX_TOOLS_PER_CLIENT = 16;

    /**
     * Each client's current set, keyed by the host's client key.
     *
     * @var array<string, array{registrationId: string, specs: list<ClientToolSpec>}>
     */
    private array $byClient = [];

    /** Count of registrations ever made, folded into each registration id. */
    private int $registrations = 0;

    /**
     * Announce (replacing any previous set of this client).
     *
     * @param list<ClientToolSpec> $specs
     *
     * @return string the registration id answers must carry
     *
     * @throws \InvalidArgumentException on a bad client key, an over-sized or
     *                                    duplicated set, or a name another
     *                                    client already holds
     */
    public function register(string $clientKey, array $specs): string
    {
        if ($clientKey === '') {
            throw new \InvalidArgumentException('bad_client_key');
        }

        if (count($specs) > self::MAX_TOOLS_PER_CLIENT) {
            throw new \InvalidArgumentException('too_many_tools');
        }

        $proxied = [];
        foreach ($specs as $spec) {
            $full = ClientToolProxy::NAME_PREFIX . $spec->name;
            if (in_array($full, $proxied, true)) {
                // Duplicates WITHIN one announcement are the submitting
                // client's own confusion — refuse rather than silently keep
                // the first, which would answer for the wrong descriptor.
                throw new \InvalidArgumentException('duplicate_name');
            }

            $owner = $this->owner($full);
            if ($owner !== null && $owner !== $clientKey) {
                throw new \InvalidArgumentException('name_conflict');
            }

            $proxied[] = $full;
        }

        $this->registrations++;
        // A content hash alone repeats when a client re-announces the same
        // set (a reconnect with the same file), and a reconnect MUST retire
        // the pendings of the id it left behind. The counter makes every
        // successful registration unique without consulting a clock, so ids
        // stay reproducible under a test that replays a sequence.
        $registrationId = substr(hash('sha256', $clientKey . '#' . $this->registrations), 0, 16);

        $this->byClient[$clientKey] = ['registrationId' => $registrationId, 'specs' => array_values($specs)];

        return $registrationId;
    }

    /**
     * Drop one client's whole set. Unknown keys are a no-op — a connection
     * that never registered has nothing to forget, and the caller (6.i's
     * disconnect path) must not need to know that.
     */
    public function forget(string $clientKey): void
    {
        unset($this->byClient[$clientKey]);
    }

    /**
     * The proxies the next turn's roster carries, sorted by proxy name so the
     * roster order never depends on connection arrival order.
     *
     * @return list<ClientToolProxy>
     */
    public function proxies(): array
    {
        $specs = [];
        foreach ($this->byClient as $entry) {
            foreach ($entry['specs'] as $spec) {
                $specs[ClientToolProxy::NAME_PREFIX . $spec->name] = $spec;
            }
        }

        ksort($specs);

        $proxies = [];
        foreach ($specs as $spec) {
            // Fresh instances per call: a proxy is immutable state, but the
            // TURN binds its caller into a copy, and handing out shared
            // originals keeps that binding honest for the next turn too.
            $proxies[] = new ClientToolProxy($spec);
        }

        return $proxies;
    }

    /**
     * Which client currently holds the tool named `$name` — a FULL proxy name
     * ({@see ClientToolProxy::NAME_PREFIX} included), which is what a frame
     * and the permission layer both carry.
     */
    public function owner(string $name): ?string
    {
        foreach ($this->byClient as $clientKey => $entry) {
            foreach ($entry['specs'] as $spec) {
                if (ClientToolProxy::NAME_PREFIX . $spec->name === $name) {
                    return $clientKey;
                }
            }
        }

        return null;
    }

    /**
     * The registration id a client's CURRENT set answers must carry; null once
     * the client is forgotten. The answer-validation leg in
     * {@see \SugarCraft\Code\Host\SessionHost::resolveClientCall()} compares
     * against this, which is how "wrong registration id" and "stale id" become
     * the same reading without a lookup table that outlives a replace.
     */
    public function currentRegistrationId(string $clientKey): ?string
    {
        return $this->byClient[$clientKey]['registrationId'] ?? null;
    }

    /** How many clients currently hold a set — a test/telemetry surface, not a hot path. */
    public function clientCount(): int
    {
        return count($this->byClient);
    }
}
