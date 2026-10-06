# 13 — A settings pane for sugar-crush, and making its behaviour configurable (design report)

Feeds steps: none open — every N-* step has landed.

**What this is.** A design report, based on reading the source, for an in-TUI settings editor in `sugar-crush`. It covers:
- making the hard-coded behaviours below into settings;
- building the editor from existing SugarCraft libraries.

Paths are relative to `sugar-crush/` unless they start with `/` or with a sibling lib's directory (`candy-forms/…`).

**Labels:**
- **[V]** — verified in source by this author;
- **[I]** — inferred from reading, not run;
- **[P]** — proposal.

---

No section of this design is still needed. The shipped behaviour is documented in `sugar-crush/docs/SETTINGS.md`; the settings follow-ups (live `permissionRules` and `connectTimeoutSeconds`, translated badges and help text) are in the synthesis under "Open follow-ups".
