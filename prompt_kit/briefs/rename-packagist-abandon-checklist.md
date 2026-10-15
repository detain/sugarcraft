# Packagist abandon checklist (manual web-UI — Phase 10)

Packagist has no abandon API (plan R9). For every completed rename, the OLD
package `sugarcraft/<old>` stays installable at its last indexed dev-master but
must be abandoned manually in the Packagist web UI once the campaign finalizes
(Phase 10). Do NOT delete old packages — that breaks existing lock files.

Append one `<old> → <new>` line per rename here when its R9 lands.

- honey-flap → candy-flappy <!-- @rename-keep --> (R9 DONE 2026-10-15: registered via packagist.sh create HTTP 202; dev-master indexed at split SHA e166e660e3a756dede36e644316fd7567f495019. Old package sugarcraft/honey-flap stays installable at its last index — abandon manually in the Packagist web UI in Phase 10; its auto-updates now fail by design.)
- candy-mines → candy-minesweeper <!-- @rename-keep --> (R9 DONE 2026-10-15: registered via packagist.sh create HTTP 202 after one forced update of the old name refreshed Packagist's cached composer.json — create initially 406'd with "sugarcraft/candy-mines already exists" until that recrawl; dev-master indexed at split SHA fef92a4f7b877044819a81da162cdc1d310c83e8. Old package sugarcraft/candy-mines stays installable; note it currently re-crawls fine under its old name serving the renamed repo (name-mismatch tolerated by Packagist) — abandon manually in the Packagist web UI in Phase 10.)
