# C-TUI-6.h — community locales lane (report, final continuation 2026-11-01)

VERDICT: COMPLETE. Strict parity with master en.php @26462c3ae holds (3675 = 3675).
Tip: bf349531521fa6d63622c208aaf2fa56c6856e3e (cat-file-verified; branch cc/C-TUI-6.h
rebased --onto 26462c3ae over base 16d1a94e8, 3 commits replayed conflict-free:
2f53689bf locales+docs+test, 11d0d647f dispatch-harness en-floor fix (kept: foreign
authorship on this branch but scoped+sound, its new pin passes), bf3495315 this step).

## This step (C-WEB-5.f follow-on)
Master en.php grew 4 keys (web.chrome.about.logs{,_empty,_no_log,_refresh}, master
L4324-4327, alphabetically after loading). Added the MT of these 4 to each of the
11 community files at the SAME slot (after loading, before permissions, line 4292+
in every file). Key-init census before/after: 3671 -> 3675 per file, equal to
master en.php's 3675. Translated with the same local SGLang Qwen3.8 temp-0 harness
(mt_add4.py — reuses mt_run.py prompt rules + valid(); extra guards: em dash count,
server.log literal present); ordered placeholders trivially satisfied (no {braces}
in these 4). esc_sq identical to build.py's (apostrophes escape as \', e.g. fr
`n\'a`). mt_run.py cache tsv NOT used for these (out-of-band keys); the .tsv caches
still describe the 4689 base items — the later-lanes rule in CONTRIBUTING covers it.

## Translations (logs | logs_empty | logs_no_log | logs_refresh)
fr  Journaux | Le journal est vide pour le moment. | Ce serveur n'a jamais écrit de server.log — un lancement en premier plan journalise dans son terminal à la place. | Actualiser
de  Protokolle | Das Protokoll ist bisher leer. | Dieser Server hat nie eine server.log geschrieben — ein Vordergrundstart protokolliert stattdessen in sein Terminal. | Aktualisieren
es  Registros | El registro está vacío por ahora. | Este servidor nunca escribió un server.log — una ejecución en primer plano registra en su terminal en su lugar. | actualizar
pt  Registos | O registo está vazio até agora. | Este servidor nunca escreveu um server.log — uma execução em primeiro plano regista no respetivo terminal. | atualizar
pt-br  Logs | O log está vazio até agora. | Este servidor nunca gravou um server.log — uma execução em primeiro plano registra no próprio terminal. | atualizar
zh-cn  日志 | 日志目前为空。 | 此服务器从未写入 server.log — 前台启动会改为输出到终端。 | 刷新
zh-tw  日誌 | 目前日誌為空。 | 此伺服器從未寫入 server.log — 前景啟動會改為記錄到其終端機。 | 重新整理
ja  ログ | ログはまだ空です。 | このサーバーは server.log を一度も書き込んでいません — フォアグラウンド起動では代わりにターミナルに出力されます。 | 更新
ru  Журналы | Журнал пока пуст. | Этот сервер ни разу не записал server.log — запуск на переднем плане пишет в свой терминал. | обновить
it  Log | Il log è ancora vuoto. | Questo server non ha mai scritto un server.log — un avvio in foreground scrive sul proprio terminale. | aggiorna
ko  로그 | 아직 로그가 비어 있습니다. | 이 서버는 server.log를 작성한 적이 없습니다 — 포그라운드 실행은 대신 터미널에 기록합니다. | 새로고침

## Gates (cwd=candy-code, -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)
- --filter 'Lang'  =>  OK: Tests 635, Assertions 52058, Deprecations 2 (pre-existing),
  Skipped 1. STRICT PARITY PROOF: LangParityTest GREEN across en + 11 community files
  vs the CURRENT 3675-key en.php. (Pre-rebase this same filter showed 22 failures —
  pure artifact of the branch en.php lacking the 4 keys; resolved by the rebase, NOT
  by editing LangParityTest/en.php.)
- php -l lang/<11 codes>.php  =>  11/11 "No syntax errors".
- --filter 'BinCandyCodeDispatchTest'  =>  138T, 1F = testEveryVerbTheParserAccepts...
  '--worktree ... offered by no completion' (file:1958). PROVEN FOREIGN-INHERITED:
  master 26462c3ae's own ArgvParser carries --worktree (20 refs), master's test file
  has zero worktree mentions -> red at pure master tip. This is the banked §7 gate
  rider ("BinCandyCodeDispatchTest --worktree cure", cc-cmp run memory) — not this
  lane's ownership; my kept commit's new pin (testTheChildSpeaksEnglish...) passes.
- Doc guards --filter 'ReadmeRosterDrift|DocFigureProseDrift|EnvRosterDrift'
  =>  OK 140T / 47834A (A grew vs 47777 base from master-side content; green).

## STRICT constraints honored
en.php untouched by MY commits (branch en.php == master's by rebase). LangParityTest
untouched. src/** untouched. README/CONTRIBUTING/LangCommunityLocaleTest edits are
the already-shipped 2f53689bf ones, unchanged. No dist/protocol/durations touches.
No new *Test.php files this step -> durations.tsv unaffected.

## Files changed by bf3495315
candy-code/lang/{de,es,fr,it,ja,ko,pt,pt-br,ru,zh-cn,zh-tw}.php (+4 lines each, 44 total)
