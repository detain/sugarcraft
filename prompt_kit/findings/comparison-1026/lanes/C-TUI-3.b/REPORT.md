# C-TUI-3.b — Web copy buttons, "Rewind to here", "Edit & resend"

## STATUS: COMPLETE — all gates green, client-only, no host/protocol seam hit

- Branch `cc/C-TUI-3.b`, base `cb2601f5d`. vitest (new): 25/25; regression window 606/606 + 14/14; vue-tsc --noEmit exit 0, 0 errors; eslint on every touched/created file: exit 0.
- GAP-UI-32 web half intentionally untouched (design: owned by C-WEB-4).

## UX contract landed (per design-tui.md §C-TUI-3.b)

- **Copy on code blocks** (`MessageMarkdown.vue`): one injected `<button class="code-copy">` per fenced block; hidden outside a secure context; text read from the `code` node **at click time** (trailing newline trimmed); success→"copied", refusal/missing clipboard→"couldn't copy". Buttons cannot ride v-html (DOMPurify FORBID_TAGS `button` — the negative-control test proves hostile markdown mints zero buttons), so they are DOM-injected in the same onMounted/onUpdated pass as the colouring walk.
- **Copy on assistant rows** (`TranscriptItem.vue`): `v-if` secureContext (the notifications precedent, `stores/settings.ts` `isSecureContext !== false` — undefined is not an announced refusal).
- **"Rewind to here" + "Edit & resend" on user rows**: emit relay `TranscriptItem → Transcript → SessionView`; SessionView gates BOTH behind `window.confirm` naming the prompt preview, refuses while a turn runs (loud error, zero exec — server would refuse anyway), and sends ONE `command.exec` of `rewind` `${n} --chat` via the new `session.rewindTo(n)`. "Edit & resend" seeds the composer **only after the rewind lands** and never calls `session.send` (no double-send by construction); a plain rewind leaves the draft alone; replacing a non-empty draft gets its own confirm clause before anything is clobbered. Snapshot refresh repaints (`rewindTo` refetches when the result carries rows — the same rule `send()` already applies).

## The n-derivation (the design's flagged risk)

`Checkpoints::rewindConversation(n)` counts **dispatched prompts** newest-first with a silent clamp at the 100-cap. The web cannot see `draftText`; the client-side proxy verified in-repo: `reducer.itemsFromRow` maps `role:user, uiOnly:true` rows to `kind:'notice'` (command echoes never mint a checkpoint), so every `kind:'user'` item ↔ exactly one live checkpoint. `rewindSteps = 1 + user items after the clicked one`; if the key is gone (compacted/pruned) the action refuses loudly instead of guessing an n. Derivation is documented at the `rewindTo` docblock and pinned by the uiOnly-echo test (`r1` with an echo between and a later prompt ⇒ `3 --chat`; `r5` ⇒ `1 --chat`).

## Files (all inside the design block's modify/create lists)

- modify `src-web/stores/session.ts` — `rewindTo(stepsBack)` + docblock (checkpoint-count law).
- modify `src-web/components/TranscriptItem.vue` — row actions + secure-context copy.
- modify `src-web/components/Transcript.vue` — relay emits.
- modify `src-web/views/SessionView.vue` — `rewindSteps`/`rewindRow` guards, confirm text, draft seeding.
- modify `src-web/components/MessageMarkdown.vue` — decorate pass + `:deep(.code-copy)` rule. Walk keys on each `pre` (`closest('.code-block') ?? pre`) because DOMPurify×happy-dom reproducibly drops a fragment-leading wrapper div under vitest (browsers keep it — pre-anchor is the fallback, wrapper stays preferred; commented in source).
- create `src-web/components/__tests__/transcriptActions.spec.ts` — 17 tests (row buttons, relay, SessionView exec/guards/draft law, store frame + refetch).
- create `src-web/components/__tests__/codeBlockCopy.spec.ts` — 8 tests (one button per block, per-block text + trim, re-render no-dupes, click-time read, non-secure absence, refused-clipboard honesty, hostile-markup negative control, source-scan CSS pin — happy-dom cannot falsify layout).
- NO PHP touched; zero `candy-code/**` edits; no schema/generated.ts change (no trio regen).

## Mutation proofs (each reverted by cp + md5 -c OK)

- M1 count notices too → exactly the 2 uiOnly-count pins red.
- M2 force secureContext → exactly the non-secure-absence pin red.
- M3 drop trailing-`\n` trim → exactly the 2 copy-content pins red.
- M4 seed composer before the exec settles → exactly the refused-exec + decline-draft pins red (2).
- M5 neuter busy guard → exactly the busy pin red.
- M6 remove confirm → 4 red (decline-exec-nothing, decline-keeps-draft, draft-warning, confirm-spy count).
- M8 drop Transcript relay → exactly the relay pin red.

## Weld carries

1. **`candy-code-web/dist` rebuild required** (src-web touched; welder rebuilds once).
2. No durations.tsv rows needed (no new `*Test.php`; vitest specs need none).
3. No protocol trio regen (verified `git status` clean of `candy-code/**`).
4. Sibling `C-TUI-3.a` (`/copy` TUI side, `candy-code/src/Chat.php`) is file-disjoint — no interaction.
