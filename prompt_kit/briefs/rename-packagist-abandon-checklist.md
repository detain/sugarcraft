# Packagist abandon checklist (manual web-UI — Phase 10)

Packagist has no abandon API (plan R9). For every completed rename, the OLD
package `sugarcraft/<old>` stays installable at its last indexed dev-master but
must be abandoned manually in the Packagist web UI once the campaign finalizes
(Phase 10). Do NOT delete old packages — that breaks existing lock files.

Append one `<old> → <new>` line per rename here when its R9 lands.

- honey-flap → candy-flappy <!-- @rename-keep --> (R9 registration pending: Packagist API token file /home/sites/mystage/auth_login_credentials.txt no longer exists on this box as of 2026-10-15 — `packagist.sh create candy-flappy` needs the owner-supplied PACKAGIST_TOKEN; old package sugarcraft/honey-flap currently still installable from its last index.)
