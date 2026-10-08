# Upstream Opportunities

> Upstream parity audit snapshot — **2026-05-10**

Tracks newly-merged upstream features and high-comment open issues against
SugarCraft ports. Use this to prioritize the next port wave.

**Status legend:**

| Icon | Meaning |
|---|---|
| ✅ | Feature already present in our port |
| 🟡 | Partially present / variation landed |
| 🔴 | Missing — port candidate |
| ⚪ | Not applicable (Go/Rust semantics, deferred v2 work) |

---

## charmbracelet/bubbletea → candy-core

### Recent merged PRs

| Upstream | Title | Status in candy-core | Notes |
|---|---|:--:|---|
| [#1680](https://github.com/charmbracelet/bubbletea/pull/1680) (2026-04-20) | fix: avoid Exec restore panic with WithInput(nil) | 🟡 | PHP side likely has similar issue with input lifecycle — needs investigation |
| [#1611](https://github.com/charmbracelet/bubbletea/pull/1611) (2026-04-13) | Change KeyMsg to KeyPressMsg in Update function | ⚪ | v2 work — deferred per CONVERSION.md |
| [#1626](https://github.com/charmbracelet/bubbletea/pull/1626) (2026-04-07) | feat: support extended keyboard enhancements | 🔴 | **Effort**: 2-3h **Benefit**: Better key detection (MediaKey, etc.) **Downside**: May conflict with existing key handling |
| [#1677](https://github.com/charmbracelet/bubbletea/pull/1677) (2026-04-13) | fix(renderer): restore tab stops if hard tabs are enabled | 🟡 | Renderer tab handling may need review |
| [#1674](https://github.com/charmbracelet/bubbletea/pull/1674) (2026-04-13) | fix: add missing signal.Stop in suspendProcess | 🟡 | Signal lifecycle cleanup — confirm PHP implementation handles SIGTSTP properly |
| [#1118](https://github.com/charmbracelet/bubbletea/pull/1118) (2026-02-24) | (v2) Bubble Tea v2 | ⚪ | v2 work — tracked separately |

### High-comment open issues

| Upstream | Title | Applicability | Recommendation |
|---|---|---|---|
| [#163](https://github.com/charmbracelet/bubbletea/issues/163) (17c) | Displaying images | ⚪ | Covered by `candy-mosaic` plan |
| [#1590](https://github.com/charmbracelet/bubbletea/issues/1590) (13c) | If tea program quits too early, garbage chars are printed | 🔴 | **Effort**: 1-2h **Benefit**: Fixes confusing terminal state on early exit **Downside**: May be renderer-specific |
| [#573](https://github.com/charmbracelet/bubbletea/issues/573) (13c) | Altscreen: rendering artifacts when resizing window | 🟡 | Likely renderer internal — may need review |
| [#1225](https://github.com/charmbracelet/bubbletea/issues/1225) (12c) | lipgloss styles rendered incorrectly when `.Width()` applied | ⚪ | Lipgloss issue — candy-sprinkles may have similar behavior |

---

## charmbracelet/lipgloss → candy-sprinkles

### Recent merged PRs

| Upstream | Title | Status in candy-sprinkles | Notes |
|---|---|:--:|---|
| [#636](https://github.com/charmbracelet/lipgloss/pull/636) (2026-04-13) | fix: Avoid background color query hang | 🟡 |确认 PHP `queryTerminal()` 实现没有相同问题 |
| [#592](https://github.com/charmbracelet/lipgloss/pull/592) (2026-01-05) | perf: improve performance of maxRuneWidth | 🟡 | Width 计算性能改进 — 确认 PHP 实现相似 |
| [#607](https://github.com/charmbracelet/lipgloss/pull/607) (2026-02-05) | feat: implement uv.Drawable for *Layer | ⚪ | Go-specific rendering interface |
| [#617](https://github.com/charmbracelet/lipgloss/pull/617) (2026-02-24) | Lip Gloss v2 | ⚪ | v2 work — deferred |

### High-comment open issues

| Upstream | Title | Applicability | Recommendation |
|---|---|---|---|
| [#220](https://github.com/charmbracelet/lipglose/issues/220) (15c) | OSC 8 (hyperlink) support | 🟡 | candy-shine 已经处理 OSC 8; 检查是否有遗漏 |
| [#166](https://github.com/charmbracelet/lipgloss/issues/166) (6c) | feat: flexbox | 🔴 | **Effort**: 4-6h **Benefit**: CSS-like flex layout for TUIs **Downside**: Significant API addition |
| [#40](https://github.com/charmbracelet/lipgloss/issues/40) (16c) | Rendering Issues with zh_CN.UTF-8 | 🟡 | 可能影响任何使用 Unicode 的组件 |
| [#81](https://github.com/charmbracelet/lipgloss/issues/81) (6c) | New API: fast colors | 🔴 | **Effort**: 1-2h **Benefit**: Faster color rendering for high-frequency updates **Downside**: Minimal |

---

## charmbracelet/harmonica → honey-bounce

### Recent merged PRs

| Upstream | Title | Status in honey-bounce | Notes |
|---|---|:--:|---|
| [#9](https://github.com/charmbracelet/harmonica/pull/9) (2021-11-02) | Expose Velocity and Acceleration on Projectiles | ✅ | Already present in `Projectile` class |
| [#8](https://github.com/charmbracelet/harmonica/pull/8) (2021-10-27) | Generalize package to include projectiles | ✅ | Already implemented |
| — | (no recent non-chore activity) | — | Repo is stable |

### High-comment open issues

No high-comment open issues found. Repo is quiet.

---

## lrstanley/bubblezone → candy-zone

### Recent merged PRs

| Upstream | Title | Status in candy-zone | Notes |
|---|---|:--:|---|
| [#51](https://github.com/lrstanley/bubblezone/pull/51) (2026-02-28) | breaking: bubbletea/lipgloss/bubbles v2 | ⚪ | v2 dependency update — no semantic change for zone tracking |
| [#20](https://github.com/lrstanley/bubblezone/pull/20) (2024-06-09) | use private CSI sequence for marking | 🟡 | Private CSI sequence for marking — verify PHP implementation matches |
| [#15](https://github.com/lrstanley/bubblezone/pull/15) (2024-06-09) | Add AnyInBoundsAndUpdate | ✅ | SugarCraft\Zone has `anyInBoundsAndUpdate()` |

### High-comment open issues

No high-comment open issues found. Repo is relatively quiet.

---

## charmbracelet/bubbles → sugar-bits

### Recent merged PRs

| Upstream | Title | Status in sugar-bits | Notes |
|---|---|:--:|---|
| [#910](https://github.com/charmbracelet/bubbles/pull/910) (2026-03-25) | feat(textarea): dynamic height | 🟡 | TextArea has `withHeight()` but dynamic height based on content needs review |
| [#891](https://github.com/charmbracelet/bubbles/pull/891) (2026-02-24) | fix(filepicker): fix a panic due to unchecked assertion | 🟡 | FilePicker 可能需要类似修复 |
| [#884](https://github.com/charmbracelet/bubbles/pull/884) (2026-02-13) | fix(table): Use ansi.Truncate instead of runewidth.Truncate | 🟡 | Table truncation logic — verify PHP equivalent |
| [#853](https://github.com/charmbracelet/bubbles/pull/853) (2026-02-24) | V2 exp | ⚪ | v2 work — deferred |

### High-comment open issues

| Upstream | Title | Applicability | Recommendation |
|---|---|---|---|
| [#12](https://github.com/charmbracelet/bubbles/issues/12) (11c) | Choose and filter don't work well with long lines | 🟡 | 可能需要审查 sugar-bits 中长文本处理 |

---

## NimbleMarkets/ntcharts → sugar-charts

### Recent merged PRs

| Upstream | Title | Status in sugar-charts | Notes |
|---|---|:--:|---|
| [#5](https://github.com/NimbleMarkets/ntcharts/pull/5) (2025-01-08) | Restore background to blockStyle vars in barchart examples | ⚪ | Example-only change |

### High-comment open issues

No high-comment open issues found. Repo is very quiet.

---

## charmbracelet/huh → sugar-prompt

### Recent merged PRs

| Upstream | Title | Status in sugar-prompt | Notes |
|---|---|:--:|---|
| [#749](https://github.com/charmbracelet/huh/pull/749) (2026-03-10) | fix(select): ensure cursor visibility when navigating multiline options | 🟡 | Select cursor visibility — verify PHP implementation |
| [#746](https://github.com/charmbracelet/huh/pull/746) (2026-03-10) | fix: Prevent double paste in focused field | 🔴 | **Effort**: 1h **Benefit**: Fixes unexpected paste behavior **Downside**: Minimal |
| [#747](https://github.com/charmbracelet/huh/pull/747) (2026-03-10) | fix(select): recompute viewport width on resize | 🟡 | Resize handling — may affect Prompt viewport |
| [#609](https://github.com/charmbracelet/huh/pull/609) (2026-03-09) | feat!: v2 | ⚪ | v2 work — deferred |
| [#716](https://github.com/charmbracelet/huh/pull/716) (2025-12-15) | Adjust Input to Enter in multiselect to match select | 🟡 | Keyboard handling consistency — may need review |

### High-comment open issues

| Upstream | Title | Applicability | Recommendation |
|---|---|---|---|
| [#679](https://github.com/charmbracelet/huh/issues/679) (5c) | Select doesn't render elements before selected if Value is set | 🟡 | May be PHP-specific issue to investigate |
| [#286](https://github.com/charmbracelet/huh/issues/286) (4c) | Windows: Tab/Enter keys not handled correctly | 🟡 | Windows key handling — may affect candy-core Windows backend |

---

## charmbracelet/glamour → candy-shine

### Recent merged PRs

| Upstream | Title | Status in candy-shine | Notes |
|---|---|:--:|---|
| [#499](https://github.com/charmbracelet/glamour/pull/499) (2026-01-23) | fix(ansi): handle multi-byte UTF-8 in margin writers | 🟡 | UTF-8 处理 — 确认 PHP 实现正确 |
| [#496](https://github.com/charmbracelet/glamour/pull/496) (2026-01-23) | fix: hide mailto: prefix in rendered email links | 🟡 | 可能需要添加到 Markdown 渲染 |
| [#408](https://github.com/charmbracelet/glamour/pull/408) (2026-03-09) | (v2) migrate to v2 packages | ⚪ | v2 work — deferred |
| [#489](https://github.com/charmbracelet/glamour/pull/489) (2025-11-10) | refactor: migrate to charm.land module path | ⚪ | Module path change only |

### High-comment open issues

| Upstream | Title | Applicability | Recommendation |
|---|---|---|---|
| [#186](https://github.com/charmbracelet/glamour/issues/186) (8c) | Support wikilinks | 🔴 | **Effort**: 2-3h **Benefit**: Support `[[wikilink]]` syntax **Downside**: Parse tree change |
| [#205](https://github.com/charmbracelet/glamour/issues/205) (6c) | Frontmatter support | 🔴 | **Effort**: 1-2h **Benefit**: Parse YAML frontmatter in markdown **Downside**: New dependency (symfony/yaml) |
| [#2](https://github.com/charmbracelet/glamour/issues/2) (8c) | Background color on non-Chroma'd code block | 🟡 | Code block background — may need CSS-style background support |
| [#168](https://github.com/charmbracelet/glamour/issues/168) (7c) | would be great if there would be gruvbox colorscheme | 🔴 | **Effort**: 30min **Benefit**: Add Gruvbox theme **Downside**: New theme file |

---

## charmbracelet/fang → candy-kit

### Recent merged PRs

| Upstream | Title | Status in candy-kit | Notes |
|---|---|:--:|---|
| [#86](https://github.com/charmbracelet/fang/pull/86) (2026-03-11) | fix: don't strip newlines in error messages | ✅ | Already handled in `Output` helper |
| [#84](https://github.com/charmbracelet/fang/pull/84) (2026-03-09) | docs: add upgrade guide for v2 + release notes | ⚪ | v2 docs |
| [#87](https://github.com/charmbracelet/fang/pull/87) (2026-03-07) | docs: minor housekeeping | ⚪ | Docs only |
| [#48](https://github.com/charmbracelet/fang/pull/48) (2025-06-27) | feat: WithNotifySignal | 🟡 | Signal notification — check if useful for CLI apps |
| [#28](https://github.com/charmbracelet/fang/pull/28) (2025-06-18) | feat: support having a custom error handler | 🟡 | Custom error handler pattern — may enhance candy-kit |
| [#42](https://github.com/charmbracelet/fang/pull/42) (2025-06-23) | feat: support grouped commands and fix flag style | 🟡 | Grouped commands — already in CLI design? |
| [#71](https://github.com/charmbracelet/fang/pull/71) (2025-09-19) | fix: preserve multiline formatting in flag descriptions | ✅ | Already preserved in HelpText |

### High-comment open issues

No high-comment open issues.

---

## charmbracelet/wish → candy-wish

### Recent merged PRs

| Upstream | Title | Status in candy-wish | Notes |
|---|---|:--:|---|
| [#392](https://github.com/charmbracelet/wish/pull/392) (2026-03-09) | feat!: v2 | ⚪ | v2 work — deferred |
| [#281](https://github.com/charmbracelet/wish/pull/281) (2025-10-02) | fix: respect $TERM and force colors | 🟡 | TERM handling — verify PHP respects FORCE_COLOR |
| [#531](https://github.com/charmbracelet/wish/pull/531) (2026-03-09) | docs: add upgrade guide for Wish v2 | ⚪ | v2 docs |

### High-comment open issues

| Upstream | Title | Applicability | Recommendation |
|---|---|---|---|
| (none with >5 comments) | | | |

---

## charmbracelet/promwish → candy-metrics

### Recent merged PRs

| Upstream | Title | Status in candy-metrics | Notes |
|---|---|:--:|---|
| [#38](https://github.com/charmbracelet/promwish/pull/38) (2024-03-01) | feat: New, Start, Shutdown | 🟡 | Lifecycle methods — verify matches PHP pattern |
| [#32](https://github.com/charmbracelet/promwish/pull/32) (2024-01-30) | Improve Logging | 🟡 | Logging improvements — review against candy-log |

### High-comment open issues

No high-comment open issues.

---

## charmbracelet/log → candy-log

### Recent merged PRs

| Upstream | Title | Status in candy-log | Notes |
|---|---|:--:|---|
| [#207](https://github.com/charmbracelet/log/pull/207) (2026-04-20) | chore(deps): bump lipgloss/v2 | ⚪ | Dependency only |
| [#143](https://github.com/charmbracelet/log/pull/143) (2026-03-09) | (v2) use v2 of libraries | ⚪ | v2 work — deferred |
| [#127](https://github.com/charmbracelet/log/pull/127) (2024-06-18) | feat: support slog attributes | ✅ | `SugarCraft\Log` already supports slog-style attributes via `withAttributes()` |
| [#171](https://github.com/charmbracelet/log/pull/171) (2025-05-12) | fix: ensure we recognize errors as slog.AnyValue | ✅ | Already handled |

### High-comment open issues

No high-comment open issues.

---

## charmbracelet/gum → candy-shell

### Recent merged PRs

| Upstream | Title | Status in candy-shell | Notes |
|---|---|:--:|---|
| (all recent are chore/deps) | | | No significant semantic changes |
| [#1072](https://github.com/charmbracelet/gum/pull/1072) (2026-05-04) | chore(deps): bump semver | ⚪ | Dep only |
| [#1068](https://github.com/charmbracelet/gum/pull/1068) (2026-05-04) | docs: fix log section typo | ⚪ | Doc only |

### High-comment open issues

| Upstream | Title | Applicability | Recommendation |
|---|---|---|---|
| [#921](https://github.com/charmbracelet/gum/issues/921) (17c) | Module checksum error while installing with go install | ⚪ | Go installation issue, not TUI relevant |
| [#12](https://github.com/charmbracelet/gum/issues/12) (11c) | Choose and filter don't work well with long lines | 🟡 | Long line handling — may affect filter/choose implementation |
| [#153](https://github.com/charmbracelet/gum/issues/153) (10c) | Add `gum progress` bar | 🔴 | **Effort**: 3-4h **Benefit**: Add Progress component to sugar-bits **Downside**: New component API |
| [#445](https://github.com/charmbracelet/gum/issues/445) (7c) | Double encoding in Powershell | ⚪ | Windows/PowerShell edge case |
| [#434](https://github.com/charmbracelet/gum/issues/434) (7c) | Add command to tail n lines of output | 🔴 | **Effort**: 2h **Benefit**: `gum tail` command **Downside**: New command in shell |

---

## charmbracelet/freeze → candy-freeze

### Recent merged PRs

| Upstream | Title | Status in candy-freeze | Notes |
|---|---|:--:|---|
| [#265](https://github.com/charmbracelet/freeze/pull/265) (2026-05-04) | chore(deps): bump chroma | ⚪ | Dep only |
| [#254](https://github.com/charmbracelet/freeze/pull/254) (2026-03-16) | security: update go version | ⚪ | Security fix, no semantic change |

### High-comment open issues

| Upstream | Title | Applicability | Recommendation |
|---|---|---|---|
| (none significant) | | | |

---

## charmbracelet/glow → sugar-glow

### Recent merged PRs

| Upstream | Title | Status in sugar-glow | Notes |
|---|---|:--:|---|
| [#922](https://github.com/charmbracelet/glow/pull/922) (2026-04-09) | fix: use `shell.Fields` to parse `$PAGER` command | 🟡 | PAGER parsing — verify PHP handles correctly |
| [#756](https://github.com/charmbracelet/glow/pull/756) (2025-04-28) | fix: sync viewport on half page up/down | 🟡 | Viewport sync — may need review in pager |

### High-comment open issues

No high-comment open issues.

---

## Evertras/bubble-table → sugar-table

### Recent merged PRs

| Upstream | Title | Status in sugar-table | Notes |
|---|---|:--:|---|
| [#210](https://github.com/Evertras/bubble-table/pull/210) (2025-09-06) | Global metadata for style and filter funcs | 🟡 | Style/filter context — may enhance sugar-table API |
| [#209](https://github.com/Evertras/bubble-table/pull/209) (2025-09-05) | Add Row to StyleFuncInput | 🟡 | Row context in style functions |
| [#196](https://github.com/Evertras/bubble-table/pull/196) (2025-09-02) | feat: Add `WithFuzzyFilter` to enable fuzzy filter matching | 🔴 | **Effort**: 2-3h **Benefit**: Fuzzy filtering for tables **Downside**: New dependency or implementation |
| [#205](https://github.com/Evertras/bubble-table/pull/205) (2025-09-02) | Filterfunc with input | ✅ | Already implemented in sugar-table |
| [#159](https://github.com/Evertras/bubble-table/pull/159) (2023-11-26) | Multiline cells | ✅ | Already supported via `withWrappedContent()` |

### High-comment open issues

| Upstream | Title | Applicability | Recommendation |
|---|---|---|---|
| (none significant) | | | |

---

## charmbracelet/sequin → sugar-spark

### Recent merged PRs

| Upstream | Title | Status in sugar-spark | Notes |
|---|---|:--:|---|
| [#106](https://github.com/charmbracelet/sequin/pull/106) (2026-03-02) | chore(deps): bump lipgloss/v2 | ⚪ | Dep only |
| [#62](https://github.com/charmbracelet/sequin/pull/62) (2025-03-18) | implement FinalTerm escape sequences | 🟡 | FinalTerm sequences — likely covered by ANSI decoder |
| [#61](https://github.com/charmbracelet/sequin/pull/61) (2025-03-18) | add support for keypad modes | 🟡 | Keypad mode — may already be in input handling |

### High-comment open issues

No high-comment open issues.

---

## charmbracelet/soft-serve → candy-serve

### Recent merged PRs

| Upstream | Title | Status in candy-serve | Notes |
|---|---|:--:|---|
| [#791](https://github.com/charmbracelet/soft-serve/pull/791) (2026-03-06) | fix(ssrf): pin resolved IP in dial to prevent DNS rebinding | 🟡 | Security fix — may need review in SSH handling |
| [#884](https://github.com/charmbracelet/soft-serve/pull/884) (2026-05-04) | chore(deps): | ⚪ | Dep only |

### High-comment open issues

| Upstream | Title | Applicability | Recommendation |
|---|---|---|---|
| (none significant) | | | |

---

## charmbracelet/skate → sugar-skate

### Recent merged PRs

| Upstream | Title | Status in sugar-skate | Notes |
|---|---|:--:|---|
| [#151](https://github.com/charmbracelet/skate/pull/151) (2025-06-26) | feat: use fang | ⚪ | Uses fang for CLI |
| [#150](https://github.com/charmbracelet/skate/pull/150) (2025-06-23) | feat: basic aliases for list and delete commands | 🟡 | Aliases — may be nice to add |
| [#96](https://github.com/charmbracelet/skate/pull/96) (2025-02-03) | Document STDIN support for `set` | ✅ | sugar-skate already supports STDIN for values |

### High-comment open issues

No high-comment open issues.

---

## Recommended next-port wave

### High-priority port candidates

1. **bubble-table fuzzy filter (`WithFuzzyFilter`)** → sugar-table
   - **Effort**: 2-3h **Benefit**: High — adds fuzzy search to tables, a common need
   - **Downside**: New dependency (levenshtein or similar)

2. **gum progress bar** → sugar-bits (new Progress component)
   - **Effort**: 3-4h **Benefit**: High — Progress is a missing component in sugar-bits
   - **Downside**: New API surface, snapshot tests needed

3. **glamour wikilinks support** → candy-shine
   - **Effort**: 2-3h **Benefit**: Medium — common markdown extension
   - **Downside**: Parse tree modification

4. **bubbletea extended keyboard enhancements** → candy-core
   - **Effort**: 2-3h **Benefit**: Medium — better key detection for special keys
   - **Downside**: May interact with existing key handling

5. **lipgloss flexbox layout** → candy-sprinkles
   - **Effort**: 4-6h **Benefit**: High — CSS-like flex for TUIs is powerful
   - **Downside**: Significant API addition, could be backward compatible

### Smaller fixes worth bundling into a cleanup PR

1. **huh double-paste fix** — 1h — confirm sugar-prompt doesn't have same issue
2. **glamour Gruvbox theme** — 30min — easy theme addition
3. **glamour frontmatter support** — 1-2h — small new feature
4. **bubbletea garbage chars on early exit** — 1-2h — bug fix

### Plan candidates surfaced

- `plans/x-windows.md` already covers Windows console backend for candy-core
- `plans/x-mosaic.md` covers image rendering which overlaps with bubbletea "Displaying images" issue
- `plans/x-vt.md` covers virtual terminal which may relate to sequin escape sequence work

### Deferred (v2 work)

The following upstreams have v2 work in progress that is explicitly out of scope per CONVERSION.md:
- charmbracelet/bubbletea (KeyMsg → KeyPressMsg split)
- charmbracelet/lipgloss (Canvas/Compositor/Layer API)
- charmbracelet/bubbles (v2)
- charmbracelet/huh (v2)
- charmbracelet/glamour (v2 migration)
- charmbracelet/wish (v2)
- charmbracelet/log (v2)

Track v2 parity as a separate roadmap item.

---

*Last audit: 2026-05-10. Next audit recommended: 2026-08-10 (quarterly) or before next port wave.*
