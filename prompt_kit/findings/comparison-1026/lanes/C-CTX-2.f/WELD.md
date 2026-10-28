# C-CTX-2.f WELD — batch-7 (2026-10-29)

Order: lead run-state tick-224 BATCH-7 pool note supersedes the original 3-pick brief — pick all FOUR commits (adds 96f4e4461 real-fork leg, additive test-only, accepted per note without re-review).

- picks 4a36a153a->c77030fa0 + 645e4ca2f->87f26fdf2 + a853615d0->13de7554d + 96f4e4461->93bc8f028.
- CONFLICTS (pick 1 only, both resolved preserving both intents):
  - candy-code/lang/en.php: kept HEAD's 3 chat.instructions.quick_add.* rows (lane C-CTX-3.b landed first) THEN incoming chat.instructions.loaded + chat.instructions.updated. php -l clean.
  - candy-code/docs/MEMORY.md: incoming 'A newly detected change...' paragraph placed at END of '### Instruction diff' (its lane-base context); '### Quick-add from the chat' section follows intact.
  - picks 2-4 applied clean (MEMORY.md auto-merged; loader docblock union held: 'What is byte-identical is the KEY NAME ... byte-identical; the VALUE is versioned by content' — the r2 final wording, both intents preserved).
- gates merged tree (cwd=candy-code, plain pipe): InstructionVisibility 12T/71A OK (r3 was 11T/60A at a853615d0; +1T/+11A = 96f4e4461 real-fork pin, matches lead note's 12T/71A exactly); Instruction family 339T/1740A OK (r3 322T/1652A + lane-1's 16T quick-add family growth); guard family DocFigure|GlobDialect|LangParity|TuiLang|DuplicatedTestHelper|ChildWallClock 147T/21192A OK (r3: 147T/20969A).
- ChatHostLangCatalogue 1F: FOREIGN-STANDING (lead roster W14/W15) — not attributed, not cured here.
- durations row tests/Context/InstructionVisibilityNoticeTest.php 5.329/12; §4 cites 96f4e4461 (per lead note; brief's original cited a853615d0 before the pool note); carry +12T.
