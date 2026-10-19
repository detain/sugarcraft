# Step 11 — sugar-markdown StyleSheet + BlockStack

**Branch:** `ai/sugar-markdown-blockstack`
**Depends on:** step-00 ✅ (no new shared package required; this is internal architecture)
**Blocks:** step-28 (golden file rollout for sugar-markdown renders)

## Goal

Add `StyleSheet` cascading style inheritance and `BlockStack` block-context tracking to `sugar-markdown`, matching glamour's behavior for deeply-nested markdown (`blockquote > list > blockquote > paragraph`). Today sugar-markdown cannot propagate accumulated indent / style through nested blocks — those are rendered as if at top level.

Reference: `docs/repo_map_update.md` §345.9 (StyleSheet / BlockStack), shared docs gaps "Cascading style inheritance + BlockStack" (§69).

## Files expected to be created

- `sugar-markdown/src/Render/BlockStack.php` — stack of `BlockContext` records.
- `sugar-markdown/src/Render/BlockContext.php` — readonly (kind: enum [Document, Heading, Paragraph, BlockQuote, List, ListItem, CodeBlock, Table], depth: int, availableWidth: int, accumulatedIndent: int, cascadedStyle: Style).
- `sugar-markdown/src/Style/StyleSheet.php` — `StyleSheet::base()` factory; `StyleSheet::for(BlockKind, int $depth): Style`; cascading resolution.
- `sugar-markdown/src/Style/StyleCascade.php` — helper merging parent + child Style with CSS-like cascade.

## Files expected to be modified

- `sugar-markdown/src/Renderer.php` — push/pop BlockContext on each block enter/exit; pass current BlockStack to child renderers; consult StyleSheet for style at depth.
- Per-block renderers (heading, paragraph, list, blockquote, code-block, table): accept a `BlockStack` parameter, use it to compute indent + available width.
- Existing tests must keep passing where their fixtures didn't depend on the broken nesting; **add** golden fixtures for the broken cases that now render correctly.

## Acceptance criteria

- [ ] Markdown `> > para` (nested blockquote) renders with cumulative indent matching glamour.
- [ ] Markdown `- - - nested list` indents at each level matching glamour.
- [ ] Markdown `> 1. item\n>    > inner quote` — list inside blockquote with inner blockquote — gets correct indent + style for `inner quote`.
- [ ] `StyleSheet::base()` returns the default sheet; `StyleSheet::with*()` overrides specific block styles fluently.
- [ ] BlockStack depth correctly tracked; `popTo(BlockKind)` and `peek()` work.
- [ ] No regression on existing single-level markdown rendering.
- [ ] ≥95 % coverage maintained on sugar-markdown.
- [ ] `git status` clean on master.

## Coder brief

1. **Delegate to an Explore subagent**: "Map the current `sugar-markdown/src/Renderer.php` block-handling code. List every block type renderer (heading, paragraph, etc.) and confirm whether each currently passes any indent/width context, or always renders at column 0 with global width."
2. **Spawn a Researcher**: "How does glamour's BlockStack work? Specifically: how does it compute available width as blocks nest? What's the rule for indent accumulation (additive vs replace)? How does style cascade through nested contexts (CSS-like or block-specific overrides only)?"
3. **Implement BlockStack + BlockContext**: stack with `push`/`pop`/`peek`. Each BlockContext is immutable. <!-- @rename-keep -->
4. **Implement StyleSheet**: keyed by `BlockKind` + depth, with a default sheet and fluent overrides. Use `Mutable` trait.
5. **Implement StyleCascade**: rules — child Style overrides parent on specified attrs; unspecified attrs inherit.
6. **Refactor block renderers** one at a time: pass `BlockStack` into each `render(Block, BlockStack): string` method. Compute available width as `parent.availableWidth - parent.indentForChildren`. Apply `StyleCascade(parent.cascadedStyle, blockStyle)` to produce the effective style.
7. **Add golden fixtures** for nested cases: `tests/fixtures/nested_blockquote.md` + `.golden`, `tests/fixtures/nested_list.md` + `.golden`, `tests/fixtures/quote_with_list.md` + `.golden`.
8. Run phpunit + check-path-repos.

## Tester brief

- BlockStack: push/pop sequence; popTo(BlockKind) pops until found; peek on empty returns null.
- StyleSheet: base() returns deterministic defaults; with*() overrides only the named attr; cascading resolution via StyleCascade.
- StyleCascade: parent Style.bold=true, child Style.italic=true → resolved has both; parent.bold=true, child.bold=false → child wins.
- Golden tests for the 3 nesting cases above. Use `assertGoldenAnsi` from sugar-testing if it's ready (step-04); otherwise inline byte assertion.
- Regression: run existing sugar-markdown fixtures, assert unchanged output.

## Scribe brief

- `sugar-markdown/README.md`: add a `## Nested block rendering` section explaining BlockStack + StyleSheet with a worked example (the `> > para` case before/after).
- `sugar-markdown/CALIBER_LEARNINGS.md`: "Use BlockStack for ANY new block renderer. Don't render at fixed column 0. StyleCascade rules: child wins on specified attrs, inherit otherwise."
- MATCHUPS row note: sugar-markdown now matches glamour on deep nesting.

## Ship brief

- **PR title**: `sugar-markdown: StyleSheet cascade + BlockStack for nested markdown`
- **PR body**:
  ```
  ## Summary
  - sugar-markdown now matches glamour's nested-block rendering via BlockStack + StyleSheet.
  - Nested blockquote, list-in-quote, quote-in-list render with correct cumulative indent + cascaded style.
  - Existing single-level markdown unchanged.
  - Golden fixtures added for 3 previously-broken nesting cases.

  ## Test plan
  - [x] vendor/bin/phpunit in sugar-markdown (≥95% coverage, existing fixtures unchanged, 3 new golden fixtures)
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_11.md, docs/repo_map_update.md §345.9, §69
  ```
- Commit subject: `sugar-markdown: StyleSheet cascade + BlockStack for nested rendering`.
