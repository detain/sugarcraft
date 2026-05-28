# Second-Stage Ecosystem Intelligence Report: p-gen/smenu

## 1. Repository Overview

| Field | Value |
|---|---|
| **URL** | https://github.com/p-gen/smenu |
| **Language** | C (~17,500 lines, single-file architecture) |
| **Stars** | ~900 |
| **License** | MPL 2.0 |
| **Version** | 1.5.0 |
| **Issues** | 50 total (3 open, 47 closed) |
| **PRs** | 7 total (all merged/closed) |
| **Discussions** | Not used (GitHub Discussions disabled) |
| **First Release** | ~2016 |
| **Active Maintainer** | p-gen (Pierre Gentile) |

**Summary**: smenu is a mature, stable C application for terminal-based word selection with integrated prefix/fuzzy/substring search. Its ~900 stars and low issue volume (50 issues over 9 years) indicate a focused, niche tool with high user satisfaction. The codebase is monolithic, which has both enabled simplicity and blocked library extraction.

---

## 2. Existing SugarCraft Mapping

From `repo_map/p-gen_smenu.md`:

| SugarCraft Lib | Relevant smenu Concept |
|---|---|
| **sugar-bits** | TST implementation, bitmap tracking for search highlighting, linked list |
| **sugar-prompt / candy-shine** | Word/tag selection model, scrollable window rendering, search with real-time highlighting |
| **honey-bounce** | Scrolling behavior and cursor movement timing |
| **Overall** | **No direct port exists.** smenu is a standalone app, not a library. SugarCraft gap: no TST-based prefix/fuzzy filtering of large word lists. |

**Strategic note from first pass**: "A SugarCraft port would likely be a `sugar-select` or `candy-pick` component that provides the selection filtering capability for use within other PHP TUI applications — essentially extracting the core algorithm (TST-based filtering with fuzzy search) into a reusable library."

---

## 3. Previously Identified Gaps

From `repo_map/p-gen_smenu.md`:
- No existing lib handles TST-based prefix/fuzzy filtering of large word lists
- Single-user focus, no async I/O, C-only with no bindings
- RTL languages incomplete
- Ctxopt migration post v0.9.15 breaks existing scripts
- No built-in caching

---

## 4. High-Signal Open Issues

### #48: musl-static ARM binaries (Jan 2026) — 1 comment
**Request**: Static binaries linked with musl for armv7l and aarch64 for use in Android adb shell/Termux and other ARM Linux environments.

**Relevance**: Indicates niche embedded/mobile use cases for selection filtering. SugarCraft should consider cross-compilation and musl targets.

---

### #19: Move most logic from main into functions (Jun 2019) — 4 comments, OPEN
**Request**: Extract smenu core into a library function callable via `smenu.h` with API: input = pointer to array of strings + options, output = pointer to array of selected strings. User wanted to call from Nim language.

**Discussion**: A third-party Nim wrapper exists ([kaushalmodi/nim-smenu](https://github.com/kaushalmodi/nim-smenu)) but stalled because most code is inline in `main`.

**Maintainer response**: Not hostile, but no action taken. Issue remains open after 6+ years.

**Signal**: Strong demand for library API. **SugarCraft opportunity**: Provide the library that smenu hasn't become.

---

### #6: Windows support? (Jan 2018) — 7 comments, OPEN
**Request**: Compile to .exe with Visual Studio tools for use with MinTTY.

**Discussion**: No resolution. MinTTY supports most Unix builtins but smenu requires PTY/terminal introspection not available on native Windows.

**Signal**: Cross-platform demand exists but Windows support is architecturally complex (no termios/terminfo equivalent). **SugarCraft lesson**: Windows ConPTY support should be planned from start.

---

### #3: Completion of error handling (Dec 2016) — 4 comments, OPEN
**Request**: Add error handling for `fprintf` and `strdup` return values.

**Discussion**: Points to specific lines in smenu.c where error handling is missing.

**Signal**: Quality/code completeness request, not a crash bug. **SugarCraft lesson**: Even mature projects have incomplete error handling; SugarCraft should enforce comprehensive error handling via code review standards.

---

## 5. Important Closed Issues

### #49: Print help/usage messages to stderr (Feb 2026) — 4 comments, MERGED
**Request**: Print help/usage to stderr instead of stdout so `answer=$(smenu)` captures clean output.

**Shell script problem**:
```bash
answer=$(smenu)
if [ -z answer ] ...
```

**Solution**: PR merged to print help to stderr.

**Signal**: This was a UX friction point that broke shell script patterns. **SugarCraft lesson**: stdout/stderr separation is critical for CLI usability. SugarCraft components that output to stdout should separate informational messages to stderr.

---

### #45: Terminal does not have capability to report cursor position (Feb 2025) — 2 comments
**Request**: Error occurs when terminal input arrives before smenu renders. Reproduction:
```bash
$ sleep 2 && smenu -2 ^Y -1 ^N -3 ^C -s /^N -m "Please" <<< "Yes"
```
During the 2-second sleep, pressing any key causes the error.

**Root cause**: Terminal cursor position query (DSR) fails when there are pending characters in the buffer.

**Signal**: Race condition between terminal I/O and smenu initialization. **SugarCraft lesson**: Handle terminal capability detection gracefully; don't fail catastrophically on capability probes.

---

### #42: v1.3.0 not working with TERM=xterm (Oct 2023) — 8 comments
**Report**: Static binary says "unknown terminal type" for xterm despite xterm being listed as supported.

**Resolution**: User discovered the static Linux glibc binary didn't include xterm terminfo. Solution was to use `tic` to install terminfo.

**Signal**: Static binaries lose the terminfo database. **SugarCraft lesson**: Static linking doesn't solve all portability problems; terminal capability databases must be distributed separately.

---

### #41: Option to start with search running (Jun 2023) — 2 comments
**Request**: Start smenu with predefined search already active (without pressing `/`) to directly search server names.

**Maintainer**: Not implemented. Suggestion is to use `-` to prefix search term: `echo "server list" | smenu -s "searchterm"`.

**Signal**: Meta-search (pre-filtering the list before user interacts) is a desired pattern. **SugarCraft opportunity**: Support initial filter state in selection components.

---

### #40: Press key to choose selection and exit without ENTER (May 2023) — 2 comments
**Request**: For menus like "a) OptionA b) OptionB c) OptionC", allow pressing `a`, `b`, or `c` to select and exit immediately, like pressing `q` exits immediately.

**Maintainer**: Not implemented; would require additional key binding logic.

**Signal**: Direct character selection (single-key binding) is a desired UX pattern. **SugarCraft opportunity**: Implement single-key selection mode in selection components.

---

### #39: Segmentation fault with hidden timeout word mode (Mar 2023) — 3 comments
**Report**:
```bash
# Works
echo "a b c" | smenu -x word d 5

# Crashes
echo "a b c" | smenu -X word d 5  # Segmentation fault
```
`-X` (hidden timeout) with `word` mode crashes; `-x` (normal timeout) works.

**Signal**: Edge case in timeout handling with word-mode output. **SugarCraft lesson**: Timeout/cancel race conditions are common; comprehensive test coverage for cancel paths is essential.

---

### #37: Column context limiting columns produced (Sep 2022) — 4 comments
**Request**: Partial column selection. Input has 3 fields (stream name, view count, title) but user wants only first 2 columns to be "selectable" while keeping the rest free-form:
```
foobar | 1234 | title of the stream
baz    | 1337 | another title for another stream
```
Instead of aligned per-word:
```
foobar | 1234 | title   | of    | the | stream
baz    | 1337 | another | title | for | another stream
```

**Maintainer**: No resolution. May require new layout mode.

**Signal**: Mixed selectable/non-selectable columns is a specialized but real use case. **SugarCraft opportunity**: Support hybrid columns (some selectable, some display-only).

---

### #36: Insufficient memory with malformed sed substitution (Aug 2022) — 8 comments
**Report**:
```bash
$ echo '[A]' '[B]' '[C]' | smenu -ES '/([^[]]+)/\2/'
Error: Insufficient memory (attempt to calloc 2305843009213693952 bytes)
```

**Root cause**: Typo in sed replacement (`\2` instead of `\1`) causes integer overflow in size calculation.

**Signal**: Input validation failures can cause absurd memory allocation attempts. **SugarCraft lesson**: Validate substitution patterns and cap size calculations to prevent integer overflow.

---

### #33: Add support for fuzzy matching equivalent to gum filter (Aug 2022) — 1 comment
**Request**: Implement `gum filter` equivalent — visual fuzzy filtering menu.

**Reference**: Links to charmbracelet/gum filter animation.

**Signal**: This was essentially a request to add the fuzzy search that smenu already had! The request was closed as "already implemented" with reference to existing fuzzy search.

**SugarCraft relevance**: Charmbracelet ecosystem uses different terminology. SugarCraft should ensure fuzzy filtering terminology is discoverable.

---

### #31: Word limit 512 characters (Jul 2022) — 5 comments
**Report**: `smenu` fails with "length of word has reached limit of 512" when used as CTRL-R replacement for large history files (2MB/~30K lines, 3.6MB/~80K lines). `hstr` handles same files fine.

**Discussion**: hstr uses different algorithm (doesn't load full history into memory at once).

**Signal**: smenu's 512-byte word limit is a hard architectural constraint. **SugarCraft opportunity**: Implement streaming/chunked history loading for large datasets.

---

### #28: dmenu input behavior (May 2021) — 2 comments
**Request**: Reproduce dmenu behavior where user can type arbitrary input not in the list if the list is empty OR the typed input becomes the result.

**Discussion**: smenu can't accept input outside the provided list. This is by design for menu selection, but limits use as input prompt.

**Signal**: Selection vs. input-prompt are different use cases. **SugarCraft lesson**: SugarPrompt's text input behavior should support "fallback to typed input when list is empty" pattern for dmenu compatibility.

---

### #25: Exit 1 on Control-C pressed? (Nov 2020) — 12 comments
**Request**: Distinguish between empty selection and Ctrl-C cancel via different exit codes. Currently both exit 0.

**Maintainer**: Won't implement; suggests using signal handler or checking if output is empty.

**Signal**: Exit code semantics are a recurring request. **SugarCraft lesson**: Document exit code semantics clearly; consider separating "user cancelled" from "empty selection".

---

### #23: Multi-select question (Apr 2020) — 2 comments
**Request**: How to select multiple entries when using `-T` (tag mode)?

**Explanation**: Enter selects and exits. Multi-select requires SPACE to tag, then Enter to confirm.

**Signal**: Tagging/selecting UX is non-obvious despite documentation. **SugarCraft lesson**: Multi-select UX needs clear affordances; the SugarCraft ItemList component should visualize "tagged" state.

---

### #1: Support for multiple selections (Dec 2016) — 4 comments
**Original multi-select request**: Output format like `"A\0B\0C"` for multiple selections.

**Resolution**: Tag mode (`-T`) with null-delimited output was implemented.

**Signal**: Multi-select was a highly requested feature that took years to land. **SugarCraft**: MultiSelect field in SugarPrompt already implements this pattern.

---

## 6. Recurring Pain Points

| Pain Point | Issues | Pattern |
|---|---|---|
| **Word length limits** | #31, #17, (original #256 limit) | Hard-coded 512-byte max causes failures on long lines |
| **Terminal compatibility** | #45, #42, #16, #7 | xterm, FreeBSD, various TERM values cause failures |
| **Exit code semantics** | #25, #10 (version to stderr) | Can't distinguish cancel/empty/error |
| **Search activation** | #41, #33 | Want to start with search active, fuzzy discoverability |
| **Selection without ENTER** | #40 | Single-key selection for rapid menu use |
| **ini/config file loading** | #50 | .smenu.ini not being read |

---

## 7. Frequently Requested Features

| Feature | Issues | Status |
|---|---|---|
| **Library API extraction** | #19 | Open (6+ years), not implemented |
| **Single-key selection (no ENTER)** | #40 | Not implemented |
| **Pre-activated search** | #41 | Workaround via `-s prefix` |
| **dmenu-compatible input** | #28 | Not implemented (different use case) |
| **Multi-line text input** | #32 (gum write equivalent) | Not implemented |
| **musl-static ARM binaries** | #48 | Not implemented |
| **Exit 1 on Ctrl-C** | #25 | Not implemented |
| **Hybrid selectable columns** | #37 | Not implemented |
| **Windows support** | #6 | Not implemented (architectural) |

---

## 8. Important PRs

| PR | Title | Impact |
|---|---|---|
| **#49** | Print help/usage to stderr | Fixed shell script `$(smenu)` capturing help text |
| **#47** | Add example of alternative delimiter for substitution | Documentation improvement |
| **#43** | README.rst fix typo | Docs |
| **#18** | Fix array use for compatibility | Build fix |
| **#15** | Fix minor typos in README | Docs |
| **#12** | Corrected syntax | Docs |
| **#9** | Improve manpage | Documentation |

**Pattern**: Most PRs are documentation/typo fixes. The only behavioral PR (#49) was a UX fix for stderr separation.

---

## 9. Architectural Changes

### Ctxopt Migration (v0.9.15)
- **Change**: Introduced `ctxopt` framework for context-sensitive option handling
- **Impact**: Broke existing scripts that relied on old option parsing behavior (#46 - gcc15 compilation failure)
- **Signal**: Internal refactoring caused breaking changes despite not changing user-facing behavior

### Word Length Limits Evolution
- Original limit: 256 bytes (#17)
- Current limit: 512 bytes (#31)
- **Signal**: Limits are architectural constraints, not easily changed without breaking existing users

### Single-File Architecture
- All ~17,500 lines in `smenu.c` with module headers
- **Why**: Simplicity, no build system complexity for linking
- **Consequence**: Blocks library extraction (#19)

---

## 10. Performance Discussions

No explicit performance issues in issues/PRs. However:

- **Memory handling**: `xmalloc` wrappers with fatal-on-failure (no graceful degradation)
- **TST indexing**: O(k) for k=key length prefix search, works on millions of words
- **Integer overflow**: #36 shows catastrophic failure when size calculations overflow
- **Timeout system**: 100ms tick granularity via `setitimer`

**SugarCraft lessons**:
- Implement integer overflow protection in size calculations
- Use safe arithmetic with explicit overflow checks

---

## 11. Extensibility Discussions

### Library Extraction Request (#19)
User wanted to call smenu from Nim with:
```c
// Input: pointer to array of strings + smenu options
// Output: pointer to array of selected strings
char** smenu_interface(char **input, int input_count, smenu_options_t *opts);
```

**Blocker**: All logic is in `main()`, no internal API.

**SugarCraft opportunity**: This is exactly what SugarCraft should provide — a composable selection/filtering library.

### Plugin System
No plugin system exists. smenu is a single-purpose tool.

---

## 12. API/UX Complaints

| Issue | Complaint | Resolution |
|---|---|---|
| #50 | .ini file not working | User error (path/format) |
| #49 | Help text captured by `$(smenu)` | Fixed (print to stderr) |
| #25 | Can't detect Ctrl-C from empty selection | Not fixed (maintainer workaround) |
| #10 | Version output to stderr | By design (stderr for version) |

**Key insight**: The most impactful UX complaint (#49) was about stdout/stderr separation breaking shell scripting. SugarCraft must be meticulous about this.

---

## 13. Migration Problems

| Problem | Issue | Details |
|---|---|---|
| **Ctxopt breaking change** | #46 (gcc15), #17 | Old code patterns fail with new option system |
| **Word limit changes** | #31, #17 | 256 → 512 still too small for some use cases |
| **Terminal compatibility** | #42, #45 | Different terminal types behave differently |

---

## 14. Clever Fixes & Workarounds

| Workaround | Issue | Context |
|---|---|---|
| `smenu -s "prefix"` | #41 | Start with search active via prefix argument |
| Signal handler for Ctrl-C | #25 | Check if output is non-empty to distinguish cancel |
| `tic` for terminfo | #42 | User-installed missing terminfo for xterm |
| Nim wrapper attempt | #19 | Third-party tried to wrap but blocked by monolithic code |

---

## 15. Community Workarounds

1. **Large history files**: Use `hstr` instead of smenu for >30K line histories due to word limit
2. **ARM/musl**: Build from source or use distribution packages
3. **Windows**: Use WSL or MSYS2 rather than native Windows
4. **Multi-select output**: Parse null-delimited output (`-T` tag mode)

---

## 16. Maintainer Guidance Patterns

| Pattern | Evidence |
|---|---|
| **Declines API changes** | #19 (library extraction) not implemented despite agreement |
| **Points to existing features** | #33 (fuzzy) already exists, just terminology differs |
| **Suggests workarounds** | #25 (exit code) suggest signal handler instead |
| **Minimal changes** | PRs are mostly docs/typos, few behavioral changes |
| **Pragmatic fixes** | #46 (gcc15) fixed type casting in ctxopt |

**Maintainer philosophy**: smenu is a focused tool. Maintainer resists expanding scope but is responsive to build/portability issues.

---

## 17. Rejected Ideas Worth Revisiting

| Idea | Why Rejected | SugarCraft Opportunity |
|---|---|---|
| **Library API extraction** | Too much architectural work | SugarCraft should provide this as a new lib |
| **Single-key selection** | Would require significant key binding logic | SugarCraft could implement as optional mode |
| **Exit 1 on Ctrl-C** | Maintainer prefers signal handlers | SugarCraft could provide exit code constants |
| **dmenu compatibility mode** | Different tool philosophy | SugarCraft could provide dmenu-compatible mode in SugarPrompt |
| **Windows native support** | Architectural (no termios equivalent) | Not worth the effort; WSL/MSYS2 acceptable |

---

## 18. Problems Likely Relevant To SugarCraft

| Problem | SugarCraft Relevance |
|---|---|
| **Word length limits** | SugarPrompt text inputs have max length; should be configurable and generous |
| **Terminal capability detection** | sugar-prompt and sugar-readline must handle TERM variations |
| **Exit code semantics** | SugarPrompt/SugarCrush should document exit codes |
| **Pre-filtered selection** | SugarPrompt's Select field could support initial filter state |
| **Multi-select UX** | MultiSelect field should visualize tagged state clearly |
| **stdout/stderr separation** | All SugarCraft CLI tools must separate output types correctly |
| **Integer overflow in size calculations** | SugarCraft should use safe arithmetic in any array/index calculations |

---

## 19. Features SugarCraft Should Consider

### High Priority

1. **TST-based prefix/fuzzy filter library** (`sugar-sift` or `candy-sift`)
   - Reusable filtering engine based on smenu's algorithm
   - Expose prefix, fuzzy, substring search modes
   - Bitmap-based match highlighting
   - Can be used by other SugarCraft components

2. **Single-key selection mode** for menus
   - Press a/b/c to select without ENTER
   - Replaces need for complex key binding systems

3. **Pre-filtered initial state** for selection components
   - Start with search/filter already active
   - Enables "search-first" UX pattern

4. **Hybrid selectable/non-selectable columns**
   - Some columns selectable, others display-only
   - Enables "stream title + view count" use case

5. **Enhanced exit code semantics**
   - 0 = success with output
   - 1 = user cancelled
   - 2 = error
   - Document this in all SugarCraft CLI tools

### Medium Priority

6. **Streaming input support** for large datasets
   - Don't load entire input into memory
   - Enable use with multi-GB files

7. **Config file support** (ini/yaml/json)
   - `.smenu.ini` parsing is a known friction point (#50)
   - SugarCraft could provide a standard config pattern

8. **dmenu compatibility mode** in SugarPrompt
   - Allow typing arbitrary input when list is empty
   - Better migration path for dmenu users

---

## 20. Architectural Lessons

### What smenu Did Right

1. **TST-based search is elegant and fast**
   - O(k) prefix search where k = key length
   - Multiple search modes (prefix/fuzzy/substring) with same structure
   - Bitmap tracking for highlighting avoids re-scanning

2. **Non-destructive overlay**
   - Doesn't clear screen on start/end
   - Overlays at cursor position
   - Much more pleasant for interactive shell use

3. **Context-aware options (ctxopt)**
   - Options change based on context/mode
   - Reduces surface area of available options at any point

4. **Timeout system is flexible**
   - Multiple independent timers
   - Different behaviors (auto-select, quit, word output)

### What smenu Did Wrong

1. **Single-file architecture**
   - Blocks library extraction
   - Makes refactoring risky
   - No internal API to test against

2. **Hard-coded limits are embedded**
   - 512-byte word limit baked into structure
   - Changing requires ABI break

3. **Error messages can be confusing**
   - "Insufficient memory" for obviously malformed input (#36)
   - Terminal errors not actionable

4. **No async architecture**
   - Blocking read architecture
   - Can't integrate into event-driven applications

---

## 21. Defensive Design Lessons

| Lesson | Implementation |
|---|---|
| **Validate size calculations** | Cap maximum allocations; check for overflow before multiplying |
| **Separate stdout/stderr** | All informational output to stderr; stdout only for actual results |
| **Handle terminal probing gracefully** | Don't fail catastrophically if cursor position query fails |
| **Document exit codes** | Always document what 0, 1, 2 mean for CLI tools |
| **Provide generous limits with warnings** | Instead of hard 512-byte limit, allow more with deprecation warnings |
| **Make search discoverable** | Fuzzy search existed but users didn't know to ask for it (#33 closed as duplicate) |

---

## 22. Ecosystem Trends

1. **Selection/filtering tools are proliferating**: fzf, gum filter, smenu, dmenu, rofi — all solve similar problems with different philosophies
2. **Library extraction is a common request**: Users want to embed these tools in their own applications
3. **Cross-platform is hard**: Windows support remains elusive for terminal tools
4. **Static binaries + musl are popular**: For container/embedded use cases
5. **Charmbracelet ecosystem dominance**: gum filter is the reference implementation for fuzzy selection

---

## 23. Strategic Opportunities

### For SugarCraft

| Opportunity | Rationale |
|---|---|
| **Port TST-based filtering as standalone library** | smenu's core algorithm is excellent but locked in app; SugarCraft could provide reusable library |
| **Implement single-key selection in sugar-prompt** | Clear demand, not implemented in smenu |
| **Pre-filtered initial state for Select field** | Meta-search is a recognized pattern |
| **Exit code constants package** | SugarCrush and other CLIs should share exit code conventions |
| **Reference smenu's bitmap tracking** | Smart highlighting without re-scanning |

### For sugar-bits specifically

| Opportunity | Rationale |
|---|---|
| **TST implementation** | Ternary Search Tree for fast prefix/fuzzy search |
| **Bit array utilities** | Already in smenu; useful for many purposes |
| **Linked list implementation** | Doubly-linked list with sort/find/delete |

### For sugar-prompt specifically

| Opportunity | Rationale |
|---|---|
| **Single-key selection mode** | Direct demand from #40 |
| **Initial filter state** | Demand from #41 |
| **Exit 1 on cancel option** | Demand from #25 |

---

## 24. Cross-Ecosystem Pattern Matches

| Pattern | smenu | gum (Charmbracelet) | SugarCraft |
|---|---|---|---|
| **Fuzzy filter** | ✅ (but not called fuzzy) | `gum filter` | SugarPrompt Select |
| **Multi-select** | Tag mode (`-T`) | `--no-limit` | MultiSelect |
| **Single-key select** | ❌ | ❌ | Opportunity |
| **Library extraction** | ❌ | ❌ (app only) | Opportunity |
| **Pre-filtered start** | `-s prefix` | `echo $list \| gum filter --initial-value` | Opportunity |
| **Exit code on cancel** | ❌ | ❌ | Opportunity |

**Key insight**: smenu and gum solve the same problem (terminal selection/filtering) with different implementations. SugarCraft should borrow proven patterns from both.

---

## 25. High ROI Recommendations

### Immediate (High Impact, Low Effort)

1. **Document exit code semantics** in all SugarCraft CLI tools
   - 0 = success
   - 1 = user cancelled  
   - 2 = error
   - Add to contributing guide

2. **Ensure stdout/stderr separation** is correct across all libs
   - Search for `echo`, `print`, `printf` that might leak to stdout
   - Any informational output should go to stderr

3. **Add single-key selection mode** to sugar-prompt Select field
   - Based on #40 demand
   - Enable rapid menu navigation without ENTER

### Short-term (Medium Impact, Medium Effort)

4. **Implement `sugar-sift`** — TST-based filtering library
   - Core of smenu's search algorithm
   - Reusable across SugarCraft components
   - Enables fuzzy/prefix/substring search for any list

5. **Add pre-filtered initial state** to sugar-prompt Select
   - Based on #41 demand
   - `->withInitialFilter('prefix')` pattern

6. **Increase text input limits** with deprecation warnings
   - Instead of hard 512/256 limits, allow 4KB+ with warning
   - Based on #31 pain point

### Long-term (High Impact, High Effort)

7. **Streaming/chunked input support** for large datasets
   - Enable use with multi-GB inputs
   - Based on #31 (large history files)

8. **Hybrid selectable columns** in sugar-table
   - Based on #37 use case
   - Some columns selectable, others display-only

9. **Library API for selection filtering**
   - Extract core algorithm into embeddable form
   - Based on #19 (6+ year-old unfulfilled request)

---

## Appendix: Issue Statistics

| Metric | Value |
|---|---|
| Total issues | 50 |
| Open issues | 3 (#48, #19, #6, #3) |
| Closed issues | 47 |
| Issues with 5+ comments | 8 (#49, #36, #37, #42, #25, #7, #1, #19) |
| Most discussed | #49 (4), #36 (8), #25 (12) |
| Average close time | ~6 months |
| PRs merged | 7 |

---

*Report generated: Second-stage ecosystem intelligence analysis*
*Sources: GitHub Issues API, Closed Issues, PRs, repo content*
