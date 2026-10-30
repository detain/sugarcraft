FIXED — r1 MINOR-1 cured: commit a8ea134ae0dffcf7d31bd948eb9078cc8daabe39 on cc/C-SEC-8.b (atop e94d13c59), comment-only docblock reword.

## What
`candy-code/src/Config/Settings/Definitions/PermissionSettings.php` — the
redactToolOutput docblock claimed "/export stays raw on either setting",
false for post-toggle turns when ON (redaction happens at the settle choke,
before persistence, so the stored bytes an export prints are already
redacted). Reworded to the truth, in the form the landed en.php help already
uses ("adds no redaction of its own: it prints exactly what was stored, so
anything saved before turning this on stays raw"), and consistent with the
Runtime.php ctor docblock ("the export promise is that no SECOND redaction
is applied"). The WHY sentences (LOCAL-copy promise, never rewrites
persisted history, no env/CLI hatch) ride unchanged.

## Proof
- Diff: 1 file, +6/-3, every changed line a `//` comment — `git diff
  --ignore-all-space e94d13c59..HEAD` shows ZERO non-comment lines.
- php -l: No syntax errors detected.
- Gate: `env -u LINES -u COLUMNS vendor/bin/phpunit -c phpunit.xml --filter
  DocFigureProseDriftTest` (cwd=candy-code, linked vendor pre-seeded) →
  Tests: 92, Assertions: 4169, OK (2 PHPUnit deprecations = pre-existing
  env noise, comment-only change cannot move them).
- No guard pins the old phrase (tree grep for "stays raw on either
  setting"/"either setting": zero hits outside this docblock).
- Author/committer: %ae and %ce separate greps both detain@interserver.net,
  body has zero Co-Authored-By trailers. SHA cat-file = commit (anti-phantom).
- Composer/npm not run (broken host-wide, per law). Nothing else touched.
