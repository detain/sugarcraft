# sugar-post identity — strategic decision

**Status:** awaiting user decision · **Owner:** product call · **Branch:** `ai/plan-sugar-post-identity`

**Origin:** `plans/leftover_updates_later.md` §6 strategic decisions ·
`plans/leftover/phase-11-strategic-plans/step-01-sugar-post-identity.md`

> This document does **not** ship code. It is a decision memo. Pick A,
> B, or C below, then subsequent sugar-post step files draw from the
> chosen path.

---

## TL;DR

The shipped `sugar-post` is a faithful PHP port of **charmbracelet/pop**
— a CLI mailer that sends email via Resend HTTP API or direct SMTP. The
research doc (`docs/research/libraries/sugar-post-research.md`) framed
the library as a **"social-media TUI"** and benchmarked it against
Mastodon clients like Perch, Mastui, Toot, and Tootles. That framing
appears to be a misread of the name "Pop" (which is an email TUI in the
Charm ecosystem, not a microblog client).

Three options:

- **A — Stay an email tool.** Match upstream Pop's TUI surface and
  ship. ~2 weeks.
- **B — Pivot to social-media TUI.** Throw away the email transports,
  start over with Mastodon/Bluesky clients. ~10–14 weeks.
- **C — One repo, two binaries.** Keep the mailer, add a separate
  social-media binary alongside it. ~12–16 weeks. Composer package gets
  schizophrenic.

**Recommendation:** **Option A** (see §6). The research doc's social-
media framing should be retired; the shipped artifact tracks the real
upstream and i18n coverage is already done across 17 locales.

---

## 1. Current state — what shipped

`sugar-post/` is a working PHP-8.3 port of
[`charmbracelet/pop`](https://github.com/charmbracelet/pop):

| Surface | File | LOC | Status |
|---|---|---|---|
| Email value object (from / to / cc / bcc / subject / body / htmlBody / replyTo / attachments / signature) | `src/Email.php` | 271 | done |
| File / inline attachment | `src/Attachment.php` | 174 | done |
| Transport interface | `src/Transport.php` | 22 | done |
| Resend HTTP transport | `src/ResendTransport.php` | 116 | done |
| SMTP transport (full multipart MIME + TLS) | `src/SmtpTransport.php` | 323 | done |
| High-level Mailer | `src/Mailer.php` | 45 | done |
| i18n facade | `src/Lang.php` | 31 | done |
| CLI binary `pop` (stdin compose, attach, env config) | `bin/pop` | — | done |
| Tests (Attachment, Email, ResendTransport, SmtpTransport, CoverageBoost) | `tests/*.php` | — | 7 files, green |
| i18n locales (`ar cs de en es fr it ja ko nl pl pt pt-br ru tr zh-cn`) | `lang/*.php` | — | 16 locales |
| Examples (`basic`, `attachments`, `html-email`, `pipeline`, `showcase`, `smtp`) | `examples/*.php` | — | 6 files |
| MATCHUPS.md row | — | — | 🟢 |

What's **not** in the shipped lib (relative to upstream `pop`):

| Upstream Pop feature | Status in sugar-post |
|---|---|
| Bubble Tea Model/Update/View TUI | absent — CLI only |
| `State` enum (`idle`, `pickingFile`, `sendingEmail`, `hoveringSendButton`) | absent |
| Inline `textinput.Model` / `textarea.Model` field editing | absent |
| `filepicker.Model` for attachment selection | absent |
| Spinner during send | absent |
| Help bar (`help.Model`) | absent |
| Markdown body → HTML conversion before send | absent |
| Markdown preview before send | absent |

Everything else upstream Pop does has a matching PHP surface.

**Source citations:**
- `sugar-post/README.md:L13` — "PHP port of charmbracelet/pop"
- `sugar-post/composer.json:L2` — `sugarcraft/sugar-post`
- `sugar-post/src/Email.php:L17–L32` — immutable readonly props
- `sugar-post/src/SmtpTransport.php` — full MIME builder

---

## 2. The research-doc confusion

`docs/research/libraries/sugar-post-research.md` opens with:

> "**Critical Finding:** sugar-post is currently an **email client**
> (sending via Resend API or SMTP), not a social media TUI."

The research then surveys six terminal Mastodon / Bluesky / X clients
(Perch, Mastui, Toot, Unrager, Tootles, TerminalRant) and proposes
adding OAuth flows, multi-account stores, vim keybindings,
post composers with content warnings, virtual-scroll timelines, SSE
streaming, and Kitty / iTerm2 / Sixel image rendering.

The framing comes from reading "Pop" as a microblog verb ("post a
pop") rather than as the upstream tool's actual name. **No
charmbracelet repo named `post` exists.** The actual upstream is
[`charmbracelet/pop`](https://github.com/charmbracelet/pop) (2.8 k
stars), an email TUI — exactly what the shipped lib ports.

The research doc itself notes this implicitly in Part 2 §2.1, which
quotes Pop's `Model` struct with `From`, `To`, `Subject`, `Body`,
`Attachments`, `Cc`, `Bcc` text inputs and concludes Pop is "the
reference implementation" for "email TUI port from Pop."

`plans/leftover_updates_later.md` §6 carries the same misframing:

> "**sugar-post identity** — research describes a social-media TUI;
> the shipped lib is an email client. Pick one before adding features."

The sentence is technically accurate but loaded — it presents the two
framings as equally valid product directions when the shipped artifact
already tracks the real upstream correctly.

---

## 3. Option A — Stay an email tool (match upstream Pop)

**Bet:** sugar-post completes its current trajectory. The user picks it
up via `composer require sugarcraft/sugar-post` and gets a drop-in
mailer that mirrors charmbracelet/pop's surface area, including the
interactive TUI.

### Work to finish

| Item | Effort | Notes |
|---|---|---|
| **Bubble Tea-style Model** with `state` enum (`Idle` / `PickingFile` / `Sending`) | 1 d | candy-core Model/Update/View already exists |
| **Form fields** (from / to / subject / body textarea) using `sugar-prompt` TextInput + Textarea | 1 d | textarea component already in sugar-prompt; check coverage |
| **CC/BCC toggle** (show on user request — matches Pop) | ½ d | keybinding `c` to reveal |
| **Filepicker** for attachments | 1 d | use existing `super-candy` Picker, or build a thin wrapper |
| **Send spinner** during transport | ½ d | candy-sprinkles Spinner already available |
| **Markdown body → HTML** preview & send | 1 d | use `sugar-glow` for render |
| **Help bar** with key hints | ½ d | candy-sprinkles HelpBar or sugar-prompt help |
| **TUI tests** — model snapshot + scripted-input view tests | 1 d | follow `sugar-prompt/tests/` pattern |
| **VHS tape** for README GIF | ½ d | `.vhs/compose.tape` |
| **Docs** (README, `docs/lib/sugar-post.html`, end-user docs feature grid) | 1 d | — |

**Total:** ~8 working days (~2 weeks calendar).

### Features permanently shelved (not on Option A's roadmap)

All social-media-specific items from the research doc:

- OAuth 2.0 / OIDC flows
- Multi-account credential storage (Keychain / Secret Service)
- Mastodon, Bluesky, X transports
- Timeline rendering (virtual scrolling, date grouping, network badges)
- Post composer features specific to microblogs (content warnings,
  visibility selector, character counters per network, scheduled
  posts, polls, @mention / #hashtag autocomplete)
- Real-time SSE / WebSocket streaming of feeds
- Image rendering inside the TUI for received posts (Kitty / iTerm2 /
  Sixel) — sugar-post does not need to display arbitrary media; attach
  / send is enough
- Cross-posting to multiple networks
- "Doctor" / health-check command

These would all live in a hypothetical separate library
(`sugar-microblog` or similar — not named, not scoped, not on the
roadmap).

### Why A is cheap

- The 17-locale i18n surface stays intact.
- Tests stay intact.
- `MATCHUPS.md` row stays 🟢; only sub-status moves from "library
  surface complete" to "library surface + TUI complete."
- No dependency churn — sugar-post stays on `candy-core` and gains
  `sugar-prompt` / `sugar-glow` / `candy-sprinkles` (all already
  monorepo siblings).

### Risks

- **None significant.** The shipped surface is correct; this option
  just rounds out the missing TUI layer that the upstream Pop has and
  that the research doc identified as `Limitations → TUI → Critical`.

---

## 4. Option B — Pivot to social-media TUI (microblog client)

**Bet:** the research doc's framing is what users actually want, and
"sugar-post" is renamed mentally to mean "post a status update."

### Work required

This is a **full rewrite** in everything but name:

| Item | Effort | Notes |
|---|---|---|
| **Delete** Email / Attachment / Transport / ResendTransport / SmtpTransport / Mailer / their tests / `bin/pop` / all 6 examples | 0.5 d | mechanical |
| **Rename** `sugar-post` → keep the slug, redefine identity in README + composer description + matchups row | 0.5 d | |
| **Add** Mastodon API transport (OAuth 2.0 PKCE, accounts, posts, timelines, notifications) | 5 d | non-trivial; many endpoints |
| **Add** Bluesky AT-protocol transport | 5 d | protocol is unstable; risk |
| **Add** account store (~/.config/sugar-post/accounts, file-perm 0600, optional Keychain integration) | 2 d | |
| **Add** Timeline value object + virtual-scroll renderer | 3 d | matches Perch three-pane layout |
| **Add** Post composer (content warning, visibility, media upload, character counter per network) | 3 d | |
| **Add** vim keybindings (`j/k/g/G/r/n/c/l/b/q`) | 1 d | |
| **Add** real-time streaming (Mastodon SSE; X / Bluesky deferred) | 4 d | requires ReactPHP |
| **Add** image rendering for media in posts (Kitty / iTerm2 / Sixel) | 4 d | use candy-mosaic |
| **Add** scheduled-post daemon | 3 d | needs cron-like scheduler |
| **Add** cross-posting orchestrator | 2 d | |
| **Rewrite** the i18n surface — current locales are mail-specific keys (`mailer.no_recipient`, `cli.send_failed`); all 16 locales would need re-translation against a microblog vocabulary | 4 d | translator time, not coder time, but blocker |
| **Tests** — TimelineRenderer, ComposerModel, OAuth flow, AccountStore, MastodonTransport (mocked), BlueskyTransport (mocked) | 5 d | |
| **Docs** — README rewrite, end-user docs page rewrite, docs/lib/sugar-post.html rewrite, MATCHUPS.md row change (no upstream charmbracelet match) | 2 d | |
| **Examples** — replace 6 mailer examples with timeline / compose / cross-post examples | 2 d | |
| **VHS tapes** | 1 d | |

**Total:** ~10–14 weeks calendar; ~45–55 working days.

### What carries forward from current sugar-post

Honestly: **almost nothing.**

- The `Transport` interface idea generalises trivially but the
  Email-shaped `send(Email)` signature does not. A `Post` and an
  `Email` share no fields beyond "body string + zero-or-more
  attachments."
- The 17 i18n files would need full re-translation (all current keys
  are email-specific).
- The CLI argument parser in `bin/pop` is generic enough to keep, but
  it's ~50 lines — trivial to rewrite.

### Risks

1. **MATCHUPS.md becomes a lie.** No charmbracelet repo named
   `post` exists; the row currently points at `charmbracelet/pop`.
   Pivoting forces either deleting the row (sugar-post no longer
   ports anything in the Charm ecosystem) or pointing it at a
   non-existent upstream.
2. **Scope explosion.** "Social-media TUI" is several apps' worth of
   work (timeline + composer + multi-network + auth + streaming +
   media + scheduling). Picking which subset to ship first becomes its
   own multi-week design exercise.
3. **Authentication storage.** Keychain / Secret Service integration
   is platform-specific and PHP has no maintained library — would
   require shelling out to `secret-tool` (Linux) or `security`
   (macOS), or rolling a file-based fallback with explicit warnings.
4. **Bluesky AT-protocol churn.** API was still mutating as of late
   2025; binding now risks rework.
5. **Discards working software.** A green, tested, 17-language email
   tool gets deleted to start over.

---

## 5. Option C — Both (one repo, two binaries)

**Bet:** users want both, and bundling lets them share the i18n
infrastructure and CLI parsing.

### Shape

- Keep all of Option A.
- Add all of Option B alongside it.
- Two bin/ entries: `bin/pop` (mailer) + `bin/sugar-post` (microblog).
- Two namespaces:
  - `SugarCraft\Post\Mail\Email` etc.
  - `SugarCraft\Post\Microblog\Post` etc.

### Work required

≈ Option A (~2 weeks) **plus** Option B (~12 weeks) **minus** maybe 1
week of shared infra (CLI parser, i18n facade, transport interface
generalisation).

**Total:** ~12–16 weeks.

### Issues

1. **Composer package identity.** `composer require sugarcraft/sugar-post`
   pulls **both** a mailer and a microblog client. Users who only want
   one get unused dependencies (Mastodon HTTP client, Bluesky SDK, etc.)
   regardless. The composer description must explain "this is two
   things" — confusing.
2. **MATCHUPS.md row.** Maps to two upstreams (charmbracelet/pop for
   the mailer half; nothing for the microblog half). Splitting the row
   in two would make it the only multi-upstream entry in the matrix.
3. **Test surface doubles.** Coverage workflows now report on
   two unrelated feature trees.
4. **Versioning friction.** Bumping the microblog half breaks semver
   for mailer users; bumping mailer half breaks semver for microblog
   users. Library-level versioning becomes a coordination chore.
5. **Discoverability.** Anyone searching Packagist for "mastodon php
   tui" will not find `sugarcraft/sugar-post`. Anyone searching for
   "php mailer" will find it but be confused by the social-media docs.

The honest alternative to C is: **two libraries** (`sugar-post` mailer
+ a new `sugar-<name>` for microblogging). At that point Option C
reduces to Option A plus a separate, deferred library — i.e. Option A
with an asterisk.

---

## 6. Recommendation (awaiting user decision)

**Recommend Option A — stay an email tool, finish the upstream Pop TUI
surface.**

### Rationale

1. **The shipped artifact is correct.** MATCHUPS.md row, composer
   description, README, source — all consistent with the real upstream
   `charmbracelet/pop`. The research doc misread "Pop" as a microblog
   metaphor; reverting the framing costs zero code.
2. **Cheap to finish.** ~2 weeks adds the Bubble Tea TUI layer that
   matches upstream Pop's Model/View/Update flow. No new dependencies
   outside the monorepo.
3. **i18n investment preserved.** 16 locales of mail-specific strings
   stay valid.
4. **No upstream tracking lie.** sugar-post continues to mirror
   `charmbracelet/pop` 1:1.
5. **The "social-media TUI" itch is a separate library, if anyone
   actually wants it.** If a user later asks for a Mastodon TUI in
   PHP, the right answer is a new `sugar-<name>` (probably
   `sugar-toot` or `sugar-feed`) — not retconning sugar-post into
   something it never was. The research doc's six-tool benchmark
   (Perch, Mastui, Toot, etc.) is reusable when that library actually
   gets scoped.
6. **No reasonable B/C scenario survives scrutiny.** Option B discards
   working software. Option C creates a frankenpackage. Both burn 10+
   weeks chasing a misframed problem.

### Conditions that would flip the recommendation

The recommendation is conditional. Pick B / C instead if **any** of
these hold:

- The user explicitly states "I never wanted an email tool; I want a
  microblog client." (We have not heard this — the leftover-updates
  doc only says "pick one before adding features.")
- A specific downstream consumer (SugarCraft app or external user) has
  a hard requirement for a microblog TUI and no other PHP option
  exists.
- The Charm ecosystem ships a `charmbracelet/post` repo that we want
  to track. (At the time of writing, no such repo exists.)

If none of those hold, A is the correct call.

---

## 7. Decision criteria — questions the user needs to answer

1. **Was the original intent of `sugar-post` ever a microblog client,
   or has the slug always meant "send a message" (= email)?**
   - If always email → Option A.
   - If genuinely intended as a microblog → B or C, and the research
     doc's six-tool benchmark becomes the design doc.

2. **Is there a downstream user (you, a project, an external dev) that
   needs the microblog surface in the next 12 months?**
   - If no → A (defer microblog indefinitely).
   - If yes → C is still wrong (separate library), but B becomes
     plausible if the email use-case is itself low value.

3. **Is the existing 17-language i18n investment worth keeping?**
   - If yes → A (or C, but C still has all of B's risks).
   - If no → B becomes cheaper (~3 weeks saved on retranslation).

4. **Do we accept that "sugar-post" can stop tracking
   `charmbracelet/pop` and become an in-house lib with no upstream
   counterpart?**
   - If yes → B is on the table.
   - If no → A or C; in C, the microblog half has no upstream and the
     MATCHUPS row gets weird.

5. **For Option C only: are we willing to bundle two unrelated
   feature trees behind one composer package?**
   - If yes → C is on the table.
   - If no → the C path collapses to "A + a future separate library."

---

## 8. Next steps (post-decision)

### If Option A is picked

1. Update `docs/research/libraries/sugar-post-research.md` with a
   prefatory note: "Research doc misframed sugar-post as a social-media
   TUI. The shipped artifact correctly tracks `charmbracelet/pop`
   (email). Sections on Mastodon / Bluesky / X clients are archival
   reference for a hypothetical future `sugar-toot` / `sugar-feed`
   library, not for sugar-post." Mark the social-media §2.2–§5
   sections **DEFERRED — wrong library**.
2. Spawn step files under `plans/leftover/phase-NN-sugar-post-tui/`
   (a new phase, or fold into a deferred slot) covering:
   - step `01` — Bubble Tea Model + state enum + form fields
   - step `02` — Filepicker integration
   - step `03` — Send spinner + Markdown preview
   - step `04` — TUI tests + VHS tape + docs
3. Close `plans/leftover_updates_later.md` §6 sugar-post bullet by
   appending `✅ shipped (PR #NNN) — sugar-post stays an email tool;
   see plans/sugar-post-identity.md`.

### If Option B is picked

1. Open a sister plan `plans/sugar-post-pivot.md` that defines the
   microblog scope (which networks, which features, P0/P1/P2 ladder).
2. Decide the deprecation path for the current shipped surface
   (delete vs publish as `sugarcraft/sugar-mail` first, then redirect
   sugar-post).
3. Spawn the 14-week step file series. Most of `phase-11-strategic-plans/`
   ordering needs revisiting because sugar-post becomes a multi-PR
   program of its own.

### If Option C is picked

1. Open `plans/sugar-post-bimodal.md` covering namespace split,
   bin entries, composer description rewrite, MATCHUPS row structure.
2. Be explicit that the recommendation is **against** C because the
   honest version is "ship A, then ship a separate microblog
   library." If the user still wants C, document why.

---

## Appendix — file inventory under each option

### Option A — files to add (sugar-post/)

```
src/Tui/
    Model.php
    State.php (enum: Idle, PickingFile, Sending, Sent, Errored)
    Update.php (handlers per Msg)
    View.php
    KeyMap.php
src/Tui/Field/
    FormField.php (composes sugar-prompt TextInput / Textarea)
tests/Tui/
    ModelStateTest.php
    UpdateTest.php
    ViewSnapshotTest.php
examples/
    tui-compose.php
.vhs/
    compose.tape
```

### Option B — files to add (sugar-post/, post-rename)

```
src/Microblog/
    Post.php
    Account.php
    Network.php (enum)
    Visibility.php (enum)
    AccountStore.php
    OAuth/PkceFlow.php
    Transport/MastodonTransport.php
    Transport/BlueskyTransport.php
    Timeline/
        Timeline.php
        TimelineRenderer.php
        Stream.php
    Composer/
        Composer.php
        ContentWarning.php
        Media.php
    Schedule/
        Scheduler.php
    Image/Renderer.php (delegates to candy-mosaic)
[plus a Tui/ tree analogous to Option A but for microblog flows]
[delete all current sugar-post/src/* files except Lang.php]
[rewrite all sugar-post/lang/*.php with new keys]
[rewrite README, examples, docs]
```

### Option C — files to add

Everything in Option A's tree under `src/Mail/` + everything in Option
B's tree under `src/Microblog/`. Two bin entries: `bin/pop` (existing,
mailer) and `bin/sugar-post` (new, microblog).

---

*End of memo. Awaiting product decision.*
