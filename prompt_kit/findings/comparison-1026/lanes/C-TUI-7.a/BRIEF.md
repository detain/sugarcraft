# BRIEF — C-TUI-7.a: Startup summary: personal files + gather off `view()`

- area: tui | src: design-tui.md | item: C-TUI-7 (sources: VIS-1-P1, VIS-1-P2) | kind: fix · MINOR/NIT · effort M | phase: normal | ruling: none
- deps: none
- serialized (claims): [chat:init, applyCommandResult (ClearTranscript/SwitchSession arms)] [hot:candy-code/src/Support/StartupSummary.php] [hot:candy-code/src/Chat/Handlers/HandlesRunControl.php] [lang:chat.startup.]

## Files to create
`candy-code/src/App/StartupSummaryGatheredMsg.php`

## Files to modify

- `candy-code/src/Support/StartupSummary.php`: `render`, `instructionFiles`, new `gatherCmd`/`store`.
- `candy-code/src/Chat.php`: `init()` L1873; the `applyCommandResult()` L11551 `ClearTranscript`/`SwitchSession` arms.
- `candy-code/src/Chat/Handlers/HandlesRunControl.php`: a new gathered-facts arm.
- `candy-code/lang/en.php`: `chat.startup.personal`.

## Declared regions
{"candy-code/src/Support/StartupSummary.php": ["?"], "candy-code/src/Chat.php": ["?"], "candy-code/src/Chat/Handlers/HandlesRunControl.php": ["?"], "candy-code/lang/en.php": ["?"]}

## Tests

- Modify `candy-code/tests/Support/StartupSummaryTest.php`: personal docs listed and deduplicated.
- Create `candy-code/tests/Chat/StartupSummaryGatherTest.php`: `view()` never spawns git (a GitRunner spy); `/clear` re-gathers a changed branch.

## Docs/guards
`tests/Chat/RouteTraitParityTest.php`

## Approach (verbatim)

- VIS-1-P1: `StartupSummary::instructionFiles()` (`src/Support/StartupSummary.php:282-290`) lists `loadDocuments()` only, while `/context` also lists `loadPersonal()`. Fix: append the personal documents with a personal marker, deduplicated by realpath.
- VIS-1-P2: `render()` memoizes on session/root/mode/provider/model only (L80-88). On a miss it runs `git branch` (L265) and a full `SkillManager::loadAll` (L323-326) **inside `view()`**. Root-cause fix: a new `gatherCmd(Chat)` computes branch, instruction, memory, skill and MCP facts off the view path and lands a `StartupSummaryGatheredMsg`.
- `render()` paints the cheap rows until those facts arrive.
- The gather runs from `Chat::init()` and again from the `ClearTranscript`/`SwitchSession` arms of `applyCommandResult()`.

## Risks
C-SES-6 adds an "update available" line to the same file, so keep it out of this step's wave.
