# 15b — Audit: sugar-crush interactive UI state machine and rendering

Feeds steps: 15b-14-1, 15b-14-2, 15b-14-3, 15b-14-4a, 15b-14-4b

Scope: `src/Chat.php`, `src/Renderer.php`, `src/App/`, `src/Tui/`, `src/Commands/`, `src/CommandParser.php`, the `*Msg.php` classes, `src/Attachment*.php`.

**One finding remains open.**

---

## Open finding

### 15b-14 — sugar-crush has no i18n: every user-facing string is hard-coded
- **Severity:** Low (convention gap) · **Confidence:** Verified-by-reading · **Status:** deferred by decision until after the roadmap
- **Where:** `grep -rl 'Lang::t' src/` finds no PHP file. There is no `lang/` directory. `Renderer.php` acknowledges this.
- **Conflict:** CLAUDE.md requires `Lang::t()`. This is recorded for completeness; it is a large job and not a defect in any one string.
