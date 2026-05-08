# Website audit — agent prompt template

Brings `docs/index.html` and per-lib detail pages in line with the
current lib roster, then runs accessibility / SEO / performance passes.

Copy the fenced block below into your chat app.

---

```
You are a frontend engineer auditing the SugarCraft project website.
Repo: https://github.com/detain/sugarcraft  (license: MIT)
Website source: `docs/` directory in the repo.
Live site: github.io/detain/sugarcraft (or wherever pages publishes from)

## Project

SugarCraft is a PHP 8.1+ monorepo of 40+ TUI library ports of the
Charmbracelet (Go) ecosystem. The website is a static site under `docs/`
served from GitHub Pages. Hand-written HTML — no static-site generator.

## Git identity (required on every commit)

All commits in this repo are authored as:

  Name:  Joe Huss
  Email: detain@interserver.net

Use `--author "Joe Huss <detain@interserver.net>"` on every `git commit`.
Do NOT use the agent's default identity, your own identity, or any
other email.

## Your task

Run a five-phase audit of the website:

1. **Completeness** — every lib in MATCHUPS.md has a tile + detail page
2. **Content** — each detail page has install / quickstart / features /
   demo / upstream link
3. **Accessibility** — WCAG AA pass: alt text, semantic HTML, contrast,
   keyboard nav, screen-reader friendly
4. **SEO + perf** — meta tags, Open Graph, image optimization, Lighthouse 90+
5. **Navigation + search** — sidebar lib list, footer cross-links,
   optional Cmd-K search

One PR per phase (or per logical sub-slice). Stop and wait after each.

## Read these files first

1. `CLAUDE.md`, `AGENTS.md`, `CONTRIBUTING.md` — conventions
2. `MATCHUPS.md` — canonical lib roster
3. `docs/index.html` — homepage
4. Every file under `docs/` (recursively listing — there should be lib
   detail pages under `docs/lib/<slug>/index.html` or
   `docs/<slug>.html`; figure out the existing convention from what's there)
5. `media/` — shared icons, social-preview.png, profile.png
6. `docs/img/icons/` if present

If you cannot access the repo, ASK the user to paste:
- `docs/index.html`
- `docs/<slug>.html` for two existing libs (so you can match style)
- `MATCHUPS.md`
- The contents of `media/` (just the file list, plus the dimensions /
  formats of icons)

## Phase 1 — completeness audit

Compare:

- Lib roster in `MATCHUPS.md` (libraries section + apps section)
- Tiles in `docs/index.html`
- Per-lib detail pages under `docs/`

Output a table:

  | Lib | Tile present? | Detail page? | Icon at media/<slug>.png? |
  |---|---|---|---|

For each gap:

- **Missing tile**: add to the homepage grid, copy the existing
  tile pattern (do NOT invent a new tile structure)
- **Missing detail page**: create following the existing lib pages'
  pattern. If no lib pages exist yet, propose a structure to the user
  before creating one.
- **Missing icon**: create a placeholder note in PR description; do NOT
  generate icons yourself unless the user explicitly asks. Icons
  should be candy-themed 256² PNGs (per AGENTS.md). Flag missing for
  the user to commission / generate.

One PR for missing tiles + detail pages. Bundle if several small.

## Phase 2 — content audit

Each lib detail page should contain:

- Lib name + role (one-line description from MATCHUPS.md)
- "Mirrors charmbracelet/<repo>" with link to upstream
- Install snippet: `composer require sugarcraft/<slug>:@dev`
- Quickstart: smallest possible runnable example
- Feature list (bullets)
- Demo: GIF embedded from `<slug>/.vhs/<demo>.gif` or
  `https://raw.githubusercontent.com/detain/sugarcraft/master/<slug>/.vhs/<demo>.gif`
- API summary table (public classes + key methods)
- Links section: GitHub repo, Packagist (when 🚀 split), upstream

For each gap, add the missing section. Pull content from the lib's
`README.md` to keep them in sync — flag if any lib's README is itself
incomplete (cross-reference `AUDIT_DOCS_PROMPT.md`).

One PR per phase.

## Phase 3 — accessibility audit

Run a WCAG AA compliance pass:

### HTML semantics

- [ ] `<html lang="en">` set on every page
- [ ] Headings in order — `<h1>` once, then `<h2>`/`<h3>` nested
- [ ] `<main>`, `<nav>`, `<article>`, `<aside>` used appropriately
- [ ] Landmarks have implicit or explicit ARIA labels
- [ ] Lists use `<ul>` / `<ol>`, not `<div>`s

### Images + media

- [ ] Every `<img>` has meaningful `alt=""` (or `alt=""` + `role="presentation"` if decorative)
- [ ] Decorative SVGs have `aria-hidden="true"`
- [ ] GIF demos have `<noscript>` fallback or static-image alternative
- [ ] No autoplay video without controls

### Color + contrast

- [ ] WCAG AA: 4.5:1 contrast for body text, 3:1 for large text +
      UI components
- [ ] Don't rely on color alone — link underlines, status icons with text labels
- [ ] `prefers-reduced-motion` respected for animations
- [ ] Light + dark theme variants? If yes, both pass contrast.

### Keyboard + focus

- [ ] Every interactive element is keyboard-reachable
- [ ] Focus indicators visible (default browser ring or custom — not `outline: none` without a replacement)
- [ ] Tab order matches visual order
- [ ] Skip-to-main-content link at the top of each page

### Screen reader

- [ ] Run an audit with axe-core or pa11y-ci against the live site;
      report violations
- [ ] Form labels associated with inputs (search box if present)
- [ ] Status messages use `role="status"` or `role="alert"`

Tools to run (locally or via the user):

  npx pa11y-ci docs/**/*.html
  # or
  npx @axe-core/cli docs/index.html

Document findings. Open ONE PR fixing the violations; if the count is
high (>20), split into multiple PRs by category (semantic HTML, alt
text, contrast, keyboard).

## Phase 4 — SEO + perf audit

### Per-page meta

- [ ] `<title>` unique per page, format `<Lib> · SugarCraft`
- [ ] `<meta name="description">` 120-160 chars, summarizes the page
- [ ] `<link rel="canonical">` to the page's canonical URL
- [ ] Open Graph: `og:title`, `og:description`, `og:image` (using `media/social-preview.png`), `og:url`, `og:type`
- [ ] Twitter Card: `twitter:card=summary_large_image`, `twitter:image`
- [ ] No `<meta name="robots" content="noindex">` on production pages

### Site-wide

- [ ] `sitemap.xml` listing every page
- [ ] `robots.txt` allowing crawl + pointing to sitemap
- [ ] Structured data: `<script type="application/ld+json">` describing
      the project as a SoftwareApplication or Library

### Performance

- [ ] Lighthouse score ≥ 90 on all four categories (perf, a11y, best-practices, SEO)
- [ ] Images: WebP variants for all PNGs >50KB; `loading="lazy"` on below-fold images
- [ ] CSS minified or inlined critical path
- [ ] No render-blocking JS in `<head>`
- [ ] No third-party trackers (Google Analytics / Hotjar / etc. — if present, document and ask)
- [ ] HTTP/2 or HTTP/3 served (verify on the live site)
- [ ] Service worker / offline cache? (optional — flag if missing)

Run Lighthouse:

  npx lighthouse https://detain.github.io/sugarcraft/ --output=html --output-path=./lighthouse-before.html

Capture before/after scores. Open one PR per category if the overhaul is large.

## Phase 5 — navigation + search

- [ ] Sidebar lists every lib (organized by prefix: Candy- / Sugar- / Honey- / Super-)
- [ ] Footer has links: GitHub, Packagist (when relevant), license,
      contributing
- [ ] Cross-links between related libs (e.g. CandyShine page links to SugarGlow)
- [ ] Optional Cmd-K / `/` search: not required, but flag if missing —
      Pagefind is a low-cost option for static sites
- [ ] 404 page custom-styled, lists popular libs

Open a PR adding navigation improvements. If Cmd-K search is added,
that's its own PR.

## Workflow per PR

1. Branch: `ai/website-<phase>-<slug>` e.g. `ai/website-a11y-fixes`
2. Stage explicit paths under `docs/` and `media/`
3. Commit author `Joe Huss <detain@interserver.net>`
4. Push, `unset GITHUB_TOKEN && gh pr create`
5. Visually verify the change on the live site (or branch preview if Pages supports it)
6. Merge `gh pr merge <n> --merge --delete-branch`
7. `git checkout master && git pull --ff-only`
8. Stop. Report. Wait.

PR body template:

  ## Summary

  Website audit — <phase>:
  - <bullets>

  ## Test plan

  - [x] All HTML files validated via `npx html-validate docs/**/*.html`
  - [x] Lighthouse: perf X (was Y), a11y X (was Y), SEO X (was Y)
  - [x] Visual review on live Pages preview: <screenshot links>

## Conventions

- HTML: 2-space indent, lowercase tags, attributes double-quoted
- CSS: 2-space indent, kebab-case class names
- No JS frameworks — vanilla or absolutely-essential dependencies only
- Match existing patterns in `docs/index.html` — don't introduce a new
  build system, don't migrate to a SSG

## Guard rails

- Never push to master directly. PRs only.
- Never overwrite hand-tuned CSS / HTML without confirming the user is OK
  with the visual change.
- Never add third-party trackers or analytics without explicit user approval.
- Never add framework dependencies (React / Vue / Svelte) — site stays vanilla.
- Don't edit `media/profile.png` or `media/social-preview.png` without
  explicit approval — those are author identity assets.
- Caliber sync per CLAUDE.md "Before Committing".

## Deliverable per PR

1. Phase + sub-slice name
2. Files modified (paths)
3. Lighthouse / pa11y / axe before-and-after if relevant
4. Screenshot links for visual changes
5. PR URL + merge confirmation
6. Next slice in the queue

Then stop.
```

---

## Tips for running this audit

- Phase 1 is mostly mechanical and benefits from agents with file
  search (Cursor, Aider). Phase 3-4 benefit from agents that can run
  CLI tools (Lighthouse, pa11y, axe).
- The site is hand-written HTML — resist any agent suggestion to migrate
  to Next.js / Astro / Hugo / etc. Note that explicitly in the prompt
  if the agent starts down that path.
- For visual changes (Phase 3 contrast, Phase 5 nav redesign), ask the
  agent to produce HTML mockups before committing — review them before
  green-lighting.
- Lighthouse scores are noisy; run 3 times, take median.
- If `media/` is missing icons for new libs, that's a real gap but NOT
  this audit's job to fix — ask the user to commission / generate them
  separately. The audit lists missing icons; it doesn't manufacture them.
