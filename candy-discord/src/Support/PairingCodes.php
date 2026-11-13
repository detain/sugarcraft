<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Support;

/**
 * DM pairing codes (§7.3): the default gate for who may talk to the bot
 * in a direct message.
 *
 * A stranger DMs the bot → they get an 8-character code valid for one
 * hour; an approver runs /pair grant CODE (/pair approve is a deprecated alias); approval appends the peer's
 * Discord id to the account's allowlist inside channels.json (0600, user
 * tier — never config.json). The alphabet excludes the glyphs 0/O/1/I so
 * a code survives being read over a voice channel or typed from a phone.
 *
 * Redaction law: every minted code is registered with TextSafety the
 * moment it exists, so it can never ride an outbound transcript chunk —
 * the one legitimate surface is the direct reply to the requester (and
 * the /pair list answer to an approver), both written by this class's
 * callers deliberately. Codes ride the log only as event names.
 *
 * All state lives in the store row under pairingPending:
 *   code => {userId, createdAt, expiresAt, lastReminderAt}
 * so a restart mid-window loses nothing and the ≤3-pending / ≤1-reminder
 * per hour caps are enforced against disk truth, not memory.
 */
final class PairingCodes
{
    /** No 0/O/1/I — every glyph is unambiguous when spoken or hand-typed. */
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    public const CODE_LENGTH = 8;
    public const TTL_SECONDS = 3600;
    public const MAX_PENDING = 3;
    /**
     * Deliberately equal to TTL_SECONDS: the reminder window reopens exactly
     * when the code minted beside it expires, and livePending() prunes dead
     * rows before that window is ever consulted — so a peer who waits out
     * the hour is handed a freshly minted code, never a re-announcement of
     * a dead one. Change one constant and the other must move with it; the
     * rotation test pins the defense for manually decoupled stale rows.
     */
    public const REMINDER_SECONDS = 3600;

    public function __construct(
        private readonly CredentialStore $store,
        private readonly TextSafety $safety,
    ) {
    }

    /**
     * Decide what a DM from $peerId should see. Returns a shape, never
     * throws for ordinary flow:
     *   ['kind' => 'paired']                — allowlist already has them
     *   ['kind' => 'new', 'code' => ...]    — freshly minted, show the code
     *   ['kind' => 'pending', 'code' => ...]— reminder window reopened, re-show
     *   ['kind' => 'silent', 'code' => ...] — pending, reminder not due: say nothing
     *   ['kind' => 'full']                  — pending cap reached
     *
     * @return array{kind:string, code?:string}
     */
    public function request(string $account, string $peerId, ?int $now = null): array
    {
        $now ??= time();
        $pending = $this->livePending($account, $now);
        $allowUsers = $this->store->discordAllowUsers($account);

        if (in_array($peerId, $allowUsers, true)) {
            return ['kind' => 'paired'];
        }

        foreach ($pending as $code => $entry) {
            if (($entry['userId'] ?? null) !== $peerId) {
                continue;
            }

            $lastReminder = (int) ($entry['lastReminderAt'] ?? 0);
            if ($now - $lastReminder >= self::REMINDER_SECONDS) {
                $pending[$code]['lastReminderAt'] = $now;
                $this->store->writeDiscordPairing($account, $pending, $allowUsers);

                return ['kind' => 'pending', 'code' => (string) $code];
            }

            return ['kind' => 'silent', 'code' => (string) $code];
        }

        if (count($pending) >= self::MAX_PENDING) {
            return ['kind' => 'full'];
        }

        $code = $this->mintCode($pending);
        $pending[$code] = [
            'userId' => $peerId,
            'createdAt' => $now,
            'expiresAt' => $now + self::TTL_SECONDS,
            'lastReminderAt' => $now,
        ];
        $this->store->writeDiscordPairing($account, $pending, $allowUsers);
        $this->safety->addSecret($code);

        return ['kind' => 'new', 'code' => $code];
    }

    /**
     * Redeem a code for its peer id: exactly one pending entry matches, it
     * is removed, and its userId joins the allowlist in the same atomic
     * write. Returns the paired user id, or null for no/such/expired code.
     */
    public function approve(string $account, string $code, ?int $now = null): ?string
    {
        $now ??= time();
        $pending = $this->livePending($account, $now);
        if (!isset($pending[$code])) {
            return null;
        }

        $userId = strval($pending[$code]['userId'] ?? '');
        unset($pending[$code]);
        $allowUsers = $this->store->discordAllowUsers($account);
        if ($userId !== '' && !in_array($userId, $allowUsers, true)) {
            $allowUsers[] = $userId;
        }
        $this->store->writeDiscordPairing($account, $pending, $allowUsers);

        return $userId === '' ? null : $userId;
    }

    public function isPaired(string $account, string $peerId): bool
    {
        return in_array($peerId, $this->store->discordAllowUsers($account), true);
    }

    /**
     * The approved roster (§7.3's user-tier allowlist) — the DM policy door
     * unions this with the settings' allowFrom names.
     *
     * @return list<string>
     */
    public function allowUsers(string $account): array
    {
        return $this->store->discordAllowUsers($account);
    }

    /**
     * Live pending codes for /pair list — the approver-facing surface
     * (TextSafety would redact these in any transcript-routed chunk, so
     * the list must be delivered directly, never through deliver()).
     *
     * @return list<array{code:string, userId:string, expiresAt:int}>
     */
    public function pending(string $account, ?int $now = null): array
    {
        $now ??= time();
        $rows = [];
        foreach ($this->livePending($account, $now) as $code => $entry) {
            $rows[] = [
                'code' => strval($code),
                'userId' => strval($entry['userId'] ?? ''),
                'expiresAt' => (int) ($entry['expiresAt'] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Pending entries with every expired one dropped — and the drop
     * persisted, so disk never carries dead codes forward.
     *
     * @return array<string, array<string, mixed>>
     */
    private function livePending(string $account, int $now): array
    {
        $contents = $this->store->read();
        $row = $this->store->account($contents, 'discord', $account) ?? [];
        $raw = $row['pairingPending'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $live = [];
        $pruned = false;
        foreach ($raw as $code => $entry) {
            // A code made only of digits comes back from JSON as an int key;
            // rejecting non-strings here would prune a live code on every read.
            if (!is_array($entry) || !(is_string($code) || is_int($code)) || (int) ($entry['expiresAt'] ?? 0) <= $now) {
                $pruned = true;
                continue;
            }

            $live[$code] = $entry;
        }

        if ($pruned) {
            $this->store->writeDiscordPairing($account, $live, $this->store->discordAllowUsers($account));
        }

        return $live;
    }

    /**
     * 8 letters/digits from the unambiguous alphabet, collision-checked
     * against the live pending set. random_int only — no time-seeded mt_rand.
     *
     * @param array<string, mixed> $pending
     */
    private function mintCode(array $pending): string
    {
        $top = strlen(self::ALPHABET) - 1;
        do {
            $code = '';
            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= self::ALPHABET[random_int(0, $top)];
            }
        } while (isset($pending[$code]));

        return $code;
    }
}
