# Packagist abandon checklist (manual web-UI — Phase 10)

Packagist has no abandon API (plan R9). For every completed rename, the OLD
package `sugarcraft/<old>` stays installable at its last indexed dev-master but
must be abandoned manually in the Packagist web UI once the campaign finalizes
(Phase 10). Do NOT delete old packages — that breaks existing lock files.

Append one `<old> → <new>` line per rename here when its R9 lands.

- honey-flap → candy-flappy <!-- @rename-keep --> (R9 DONE 2026-10-15: registered via packagist.sh create HTTP 202; dev-master indexed at split SHA e166e660e3a756dede36e644316fd7567f495019. Old package sugarcraft/honey-flap stays installable at its last index — abandon manually in the Packagist web UI in Phase 10; its auto-updates now fail by design.)
