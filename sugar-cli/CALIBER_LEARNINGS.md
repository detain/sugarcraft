# Caliber Learnings — sugar-cli

Accumulated patterns and gotchas from implementing this library.

---

[pattern:snapshot-testing-assert-golden] — Use `sugar-testing`'s `assertGolden*` for any renderable/serializable output — ANSI presenter renders via `assertGoldenAnsi`, numeric data via `assertGolden` file equality. This pins canonical output so refactors are intentional rather than accidental regressions. Goldens are CREATE-ONLY: `SUGAR_TESTING_UPDATE_GOLDENS=1` (legacy alias `UPDATE_GOLDENS=1` — the spelling in `sugar-testing/src/Snapshot/Assertions.php` is the authority) mints a missing fixture but never rewrites an existing one — to accept an intended render change, delete the fixture and re-record it.

[pattern:no-i18n] — sugar-cli is exempt from the fleet `Lang::t()` rule by contract: its strings are presenter *structure* (section glyphs, help-page labels like "Commands"/"Flags"), authored by the consuming app, not translated UI copy. `HelpText::render()` therefore takes the labels as data and ships no catalog; apps that translate pass their own strings (and pair `titleCase: false` for locales where title-casing corrupts, e.g. German ß). This exemption is the documented answer to a fleet i18n audit, not an oversight.
