# C-CTX-3.f — weld (batch-16, 2026-10-29)
Picks 72a4003b1 -> edfc05c72, bac64a612 -> eef1d908d (onto 86f80fef8, zero-conflict).
WELDER SEAMS LANDED (meta commit):
- seam#1 MemoryStore when: persistence — src/Memory/MemoryStore.php writeEntry emits when: only when non-empty; decode() lenient read (normalizeWhen in try/catch, malformed -> ''). New pin testAnIntentSavedByTheToolSurvivesTheStoreRoundTripAndArmsTheTracker (tool save -> tracker envelope; disk re-arm flip -> disarm).
- seam#2 /memory list shows standing intents under heading host.memory.intents.heading (lang/en.php + MemoryCommand::list() partition, entryRows withWhen param). Pin testListShowsStandingIntentsUnderTheirOwnHeading. NOTE: list() body slice for DocFigure arm testMemoryStoreOpsSentenceReadsTheChatArmsItStates is now 2980/3000 bytes from 'function list(' — any future comment addition inside list() redden it; trim comments, never widen the arm.
- flipped pin: old testTheIndexNeverClaimsAnArmedHeadingForTriggerlessDiskNotes -> testTheIndexArmsItsHeadingOnlyForRoundTrippedTriggers (index heading is live for round-tripped triggers post-seam#1).
- docs/MEMORY.md Status paragraph truth-flipped.
DUR row minted Context/StandingIntentTest 0.061 22 (re-minted after cure: 21->22 methods).
Gates: MemoryCommandTest|StandingIntentTest 58T OK; DocFigure|Memory|StandingIntent 831T/1F (1F = foreign DreamPass, red at base). Re-pin +21T = closeout duty.
SEAM#5 (C-CTX-3.e MemoryCommand collision): 3.e landed earlier; no conflict materialized (auto-merge kept both intents).
