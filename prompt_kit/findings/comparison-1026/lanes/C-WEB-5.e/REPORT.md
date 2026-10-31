# C-WEB-5.e — Web Push client: service worker + consent toggle (W37)

STATUS: IN PROGRESS (grounding complete, implementing pick1 web).
Lane /home/sites/cc-cmp-C-WEB-5.e, branch cc/C-WEB-5.e, base/master 444bb0f70 (weld: C-TOOL-4.g — run-state fold), porcelain 0 at start.

## Grounding facts (verified on disk)
- Design block read verbatim from prompt_kit/findings/comparison-1026/design-web.md §C-WEB-5.e.
- Welded core (READ-ONLY): src/Protocol/Methods/PushMethods.php (push.vapidKey Read; push.subscribe Write {endpoint,keys{name}}→{subscribed,keyHash}, name omitted => redacted-generic payload = my default; push.unsubscribe Write {endpoint}→{unsubscribed}); src/Server/Push/*; ServerContext triggers. Zero edits planned to any of these.
- generated.ts ALREADY carries push.* params/results/METHOD_SCOPES (:1001/:1024/:1037, :1828-1830) → NO schema/surface change by this lane → protocol trio UNOWED (name-diff proof per pick).
- dormantRoster.spec.ts rows push.subscribe/push.unsubscribe/push.vapidKey (:33-35) DROPPED in pick1 (callers land in stores/push.ts with literal request('push.…' shape).
- en.php web.chrome span (:4210-:4760) verified SORT_STRING-sorted (541 keys, python assert passed). Insertion points computed: web.chrome.push.finished/question after :4350 (web.chrome.panels.title); web.settings.push_* after :4690 (web.settings.preview_save).
- SettingsView mounts with NO RPC on mount (SettingsViewKeymap.spec pattern) — push store stays lazy: only the toggle fires calls.
- DuplicatedTestHelperDriftTest scans PHP tests/ only (read guard source) — web spec helper reuse unaffected; PHP pin extends EXISTING tests/Server/StaticFilesTest.php (no new *Test.php file; §7 new-file list stays empty unless changed).

## CLOSEOUT pass (t-now, both picks landed)
- pick1 02e18c14c "C-WEB-5.e: Web Push client — service worker, consent toggle, payload sanitising" — 11 files +951/−5 (en.php 10 keys span-sorted 551/0 dups, web-strings.en.json +10, strings.en.generated.ts +10, NEW sw.ts/push.ts/push.spec.ts, vite.config.ts sw input+root naming, SettingsView.vue toggle+note+styles, pwa.spec.ts +2 source pins, dormantRoster.spec.ts −3 push rows, README bullet). cat-file commit; %ae/%ce separate greps 1/1; zero Co-Authored.
- pick2 c8b76091b "C-WEB-5.e: /sw.js served revalidated, WHY-pinned in StaticFilesTest" — StaticFiles.php comment + StaticFilesTest sw.js fixture/method, +26/−1. Same law greps 1/1, trailer grep 0.
- Trio: UNOWED+UNMOVED — per-pick name-only grep of exact trio paths (schema.json|docs/SERVER.md|generated.ts|dist/) = 0/0; push.* already typed in generated.ts, no RPC change. web-strings pair = sanctioned mirrors, committed.
- dist REBUILT-VERIFIED-RESTORED: npm run build → dist/sw.js 1.50 kB at ROOT + shared dist/assets/i18n-Bc3kfy5v.js chunk; index-<hash>.js/CSS renamed (BGaQf8A-→BmARdb9X, C0-nwVJU→COFHJQGR); index.html+manifest.json reference updates; build-info/favicon/icon/manifest.webmanifest unchanged. checkout+rm untracked → porcelain 0. WELDER CARRY: web.yml parity rebuild after weld (lane dist kept pre-sw on purpose; pwa.spec reads source only).
- Welded-core reuse: git diff base -- src/Server/Push src/Protocol/Methods/PushMethods.php src/Server/ServerContext.php = 0 lines (READ-only); push test family (PushPayloadCipher|PushSubscriptionStore|WebPushSender|PushMethods):: 42T/173A green in tree.
- Guards live (anchored full stems, cwd=candy-code, pipe): StaticFilesTest:: 7T/43A OK; (WebStringsTest|WebStringsDriftTest):: 9T/586A; (CliLangCatalogueTest|LangParityTest):: 15T/4220A; TreeWideGuardRosterTest 17T/3928A; DuplicatedTestHelperDriftTest 14T/204A. PHPUnit Deprecations 2 = suite-wide pre-existing shape.
- vitest serial 8 files/52 tests PASS post-commit (push 20, pwa, dormantRoster, i18nCoverage, SettingsViewKeymap, settings, settingsNotify, settingsTheme). vue-tsc: MY FILES 0 errors; 28 base-inherited errors in 11 untouched files (dormantRoster/i18nCoverage lines verbatim-at-base — adjudicate-only, typecheck red at base, disclosed).
- EXEC pins: all 20 vitest pins executed; StaticFiles sw pin mutation-EXEC'd (sw.js forced immutable → exactly 1F; md5-restore clean). sw.ts listener pins run under fake self (dispatch push/notificationclick).
- Safety trace: consent gates first push.ts enable() ~:101; insecure gate pre-prompt :107; roster gate :118; bearer endpoint/keys locals :127-131 (pin 10 absence-scans localStorage, pin 1 inert-without-consent); name NOT sent (pin 2 deepEqual); sw INVISIBLES incl bidi 202A-202E/2066-2069 + SESSION_ID + kind allowlist + server title ignored (pins 15-17); click href whitelist (18-20). E646: one attempt per flip, zero timers/retries.
- Deviations: (1) StaticFiles root already no-cache → comment+pin (design-sanctioned alternative), not carve; (2) manifest.webmanifest untouched (standard Web Push, no gcm_sender_id); (3) registration resolves against window.location.href — Vite rewrites new URL(spec, import.meta.url) into asset refs (push.spec pin 2 caught it); (4) new keys web.chrome.push.* (3, sw headings) + web.settings.push_* (7, toggle) per span-neighbor families; (5) push.ts keys-missing defense branch (TS nullability) unexercised, mirrors pinned refusal path.
- §7: NEW phpunit *Test.php NONE (StaticFilesTest extended, durations row unchanged). New vitest spec push.spec.ts (not durations-governed).
- Collisions: master unmoved at 444bb0f70 through lane; SettingsView.vue serialized-hot mine; no ff/rebase needed.
- Tree clean; branch tip c8b76091b = 2 picks over base.
