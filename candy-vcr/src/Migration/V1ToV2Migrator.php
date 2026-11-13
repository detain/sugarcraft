<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Migration;

use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;

/**
 * Migrates cassette format v1 → v2.
 *
 * v1 format:
 *   Header: `{"v":1,"created":"...","cols":N,"rows":N,"runtime":"..."}` plus the
 *           optional fields CassetteHeader::toWireArray() emits (timestampMode,
 *           env, typingSpeed, theme, playbackSpeed, fontSize, fontFamily,
 *           widthPx, heightPx) — all of them survive migration
 *   Events: `{"t":0.0,"k":"resize","cols":N,"rows":N}` or `{"t":0.0,"k":"output","b":"..."}`
 *           or `{"t":0.0,"k":"input","msg":{...}}` or `{"t":0.0,"k":"input","b":"..."}`
 *           or `{"t":0.0,"k":"quit"}`
 *
 * v2 format adds:
 *   - Numeric `v` header field carries the semantic target version (2)
 *   - Events gain a `_id` field (zero-based sequential integer) for stable event
 *     references across migrations
 *   - Output events gain `_enc` (encoding) field set to "utf-8" for explicit encoding
 *
 * `_id` and `_enc` are v2 RESERVED payload keys: a v1 payload that already
 * carries them (v1 defined neither) is overwritten by the stamp, never
 * silently — {@see reservedKeyCollisions()} names the count and the migrate
 * door warns with it before writing (finding 108).
 *
 * No header field is dropped in translation: v2 is v1 plus the version stamp
 * and the two payload keys above. The recorder still writes v1
 * (CassetteHeader::CURRENT_VERSION); v2 is readable today
 * (CassetteHeader::MAX_READABLE_VERSION) but nothing emits it until a future
 * release changes the writer. It is a no-op when the cassette is already v2+.
 */
final class V1ToV2Migrator implements CassetteMigrator
{
    public const TARGET_VERSION = 2;

    public function canMigrate(Cassette $cassette): bool
    {
        return $cassette->header->version < self::TARGET_VERSION;
    }

    public function migrate(Cassette $cassette, bool $dryRun = false): Cassette
    {
        if (!$this->canMigrate($cassette)) {
            return $cassette;
        }

        $migratedEvents = [];
        $eventId = 0;
        foreach ($cassette->events as $event) {
            $migratedEvents[] = $this->migrateEvent($event, $eventId++);
        }

        $migratedHeader = $this->migrateHeader($cassette->header);

        // $dryRun is caller-side policy (MigrateCommand skips the write); this
        // method is a pure Cassette→Cassette derivation either way, and the
        // backup that a real migration earns is created by the caller.
        return new Cassette($migratedHeader, $migratedEvents);
    }

    public function sourceVersion(): int
    {
        return 1;
    }

    public function targetVersion(): int
    {
        return self::TARGET_VERSION;
    }

    public function describe(): string
    {
        return 'Upgrades cassette format v1 to v2: adds event ids and '
            . 'explicit encoding on output events.';
    }

    private function migrateHeader(CassetteHeader $header): CassetteHeader
    {
        // v2 changes the version stamp and nothing else. The pre-fix rebuild
        // named only 5 fields, silently DESTROYING timestampMode, env, theme,
        // typingSpeed, playbackSpeed and the font/pixel geometry on every
        // migration — and `diff` (which now compares all of them) would then
        // report false drift between the original backup and its migration.
        // withVersion() guarantees future header fields ride through too.
        return $header->withVersion(self::TARGET_VERSION);
    }

    private function migrateEvent(Event $event, int $eventId): Event
    {
        $payload = $event->payload;

        // Add sequential event id. `_id`/`_enc` are v2-reserved (finding 108):
        // pre-existing user payloads under those names are overwritten — the
        // skip alternative would emit a malformed v2 (non-sequential ids,
        // un-declared encodings) — but not silently: the migrate door counts
        // them with reservedKeyCollisions() and warns by name.
        $payload['_id'] = $eventId;

        // Normalize output events with explicit encoding
        if ($event->kind === EventKind::Output) {
            $payload['_enc'] = 'utf-8';
        }

        return new Event(
            t: $event->t,
            kind: $event->kind,
            payload: $payload,
        );
    }

    /**
     * How many events in $cassette already carry a payload key the v2 stamp
     * reserves (`_id` on any event, `_enc` on output events) — i.e. how many
     * pre-existing values migrate() will overwrite. Pure; the CLI consults
     * it only for sub-target versions, where every hit is a real collision.
     */
    public static function reservedKeyCollisions(Cassette $cassette): int
    {
        $count = 0;
        foreach ($cassette->events as $event) {
            if (array_key_exists('_id', $event->payload)) {
                $count++;
            }
            if ($event->kind === EventKind::Output && array_key_exists('_enc', $event->payload)) {
                $count++;
            }
        }

        return $count;
    }
}
