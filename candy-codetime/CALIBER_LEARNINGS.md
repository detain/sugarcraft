# Caliber Learnings

Accumulated patterns and anti-patterns for candy-codetime development.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

### 2026-06-01 — CancellationToken for best-effort I/O cancellation
Pattern: Use CancellationToken for best-effort I/O cancellation; true preemption requires async rewrite.
Source: step-35 ai/async-adopters

### 2026-11-06 — EAW-ambiguous glyphs in fixed pane text are a fleet idiom, not a local bug
Pattern: Renderer pane text uses U+2013 (–), U+2190/U+2192 (←/→) and U+00B7 (·), which are East-Asian-Ambiguous width under some host profiles. This is the fleet-wide idiom (candy-top, candy-code and the charts panes ship the same set); per-lib swaps desync the visual language. Any cleanup must unify fleet-wide or not at all — DECLINED here (docs-fix N-14).
Source: docs-fix-1104 candy-codetime step 15

### 2026-11-06 — .sugartrackignore keeps its pre-rename dotname deliberately
Pattern: the ignore-file name predates the sugar-tick → candy-codetime rename. Renaming it is breaking (user plants already exist) and owner-gated; the code and every doc surface (README Ignore section, SugarTrackIgnore docblock, cli wording) are self-consistent on the old name, so leave it as-is (docs-fix N-18 — recorded, no action).
Source: docs-fix-1104 candy-codetime step 15

### 2026-11-13 — CLI catalogue house style (CT-S49)
Pattern: every `lang/*.php` message follows one register — one clause per message (a consequence may ride a semicolon/dash, shout-caps never carry the emphasis), ONE quote glyph (straight `"` around substituted values, inside single-quoted PHP literals so no `\"`/`\$` escape tax is levied on translators), one numeric-door phrasing ("must be a whole number", unit included: "of seconds"). Counters with an inline numeral take the `_one` twin pair (`cli.backup_done_one` / `cli.backup_done`), selector at the call site (`$count === 1 ? ...`), `{count}` kept so the placeholder-faithfulness guard stays meaningful. Success echoes state the confirmation first ("recorded {duration}s of {project} ({language})"), never a `+`-increment shape that reads as a delta on a total.
Source: review-pipeline-1112 candy-codetime CT-S49 (findings 060+061)

### 2026-11-13 — Translated chrome, English date formats, verbatim storage errors (CT-S13)
Pattern: the dashboard paints every chrome string through `Lang::t('ui.*')` (titles, help row, headings, suffixes, '(no data)'), but the DATE FORMATS stay English letters: `ui.range_format`/`ui.weekday_format` hold the PHP format characters (`M j`, `D`) themselves, so every locale catalogue carries them byte-identical and month/weekday names render in English on the timeline — deliberate, because `DateTimeImmutable::format()` has no locale table and shipping one for 16 locales inside the chrome is out of scope for a TUI whose dates are read numerically anyway. Second law: `Store`/`SqliteBackend` RuntimeException and STDERR texts ("cannot read day file…", "Heartbeat append cancelled") stay VERBATIM English — they name storage invariants for log forensics and the tests pin them byte-wise; user-facing CLI chrome all rides `cli.*`/`ui.*` keys. Help-row width: translated rows are longer than English (de 75 cells); the renderer falls back to the locale-independent `ui.help_row_compact` (key symbols only, identical in all 16) when the terminal cannot hold the full translated row, so words are never clipped mid-binding.
Source: review-pipeline-1112 candy-codetime CT-S13 (finding 012 call-site half)
