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
 *   Header: `{"v":1,"created":"...","cols":N,"rows":N,"runtime":"..."}`
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
 * Since v2 does not yet exist in the codebase this migrator future-proofs the
 * infrastructure. It is a no-op when the cassette is already v2+.
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

        if (!$dryRun) {
            // In a real implementation with a backing store, we would persist the
            // original as .bak here. The caller is responsible for backup.
        }

        return new Cassette($migratedHeader, $migratedEvents);
    }

    public function getSourceVersion(): int
    {
        return 1;
    }

    public function getTargetVersion(): int
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
        // Build v2 header: the numeric `v` is bumped. CassetteHeader has no
        // `formatVersion` string field and no `migrationMeta` slot — the
        // upgrade path is described by describe().
        return new CassetteHeader(
            version: self::TARGET_VERSION,
            createdAt: $header->createdAt,
            cols: $header->cols,
            rows: $header->rows,
            runtime: $header->runtime,
        );
    }

    private function migrateEvent(Event $event, int $eventId): Event
    {
        $payload = $event->payload;

        // Add sequential event id
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
}
