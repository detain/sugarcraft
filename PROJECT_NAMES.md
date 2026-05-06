# Project naming — decision history

> The **canonical upstream → SugarCraft port mapping** lives in
> [MATCHUPS.md](./MATCHUPS.md). This file is the open-ended naming
> sketchpad — early ideas, rejected names, and the rationale behind
> the prefix system (Candy- / Sugar- / Honey-). When you're adding a
> new library, pick a name here, add it to MATCHUPS.md, then follow
> the contributor playbook in [AGENTS.md](./AGENTS.md).

### ✅ Strong (keep / build around)

* **CandyCore** → excellent foundation name (framework/kernel)
* **SugarBits** → great for components (very clear metaphor)
* **CandyDrops** → nice for small UI elements or helpers
* **HoneyComb** → good for layout/grid (very fitting metaphor)

### ⚠️ Decent but situational

* **SugarCraft** → energetic, but vague (better for async/events than core libs)
* **SweetShop** → sounds like a marketplace, not a library (could confuse users)
* **CookiePress** → implies HTTP/cookies or WordPress-like tooling, not TUI
* **CutieMarks** → very niche reference, might not age well or be universally understood

# If I were shaping your ecosystem

You want names that:

* feel cohesive
* hint at function
* don’t sound silly in logs/CLI

---

# 🔥 Strong refined set (based on yours)

* **CandyCore** → framework
* **SugarBits** → components
* **HoneyComb** → layout/grid
* **CandyDrops** → small utilities/effects
* **SugarPrompt** → forms (better than vague names)
* **CandySprinkles** → styling (keeps Lip Gloss vibe)
* **CandyZone** → terminal/viewport
* **SugarCharts** → charts

---

# 💡 New name ideas (same style, high quality)

## 🍬 Modular / building blocks

* CandyChunks
* SugarPieces
* SweetParts
* HoneyBits
* JellyBits

## 🫧 UI / effects / visuals

* CandyPop
* SugarGlow
* SweetSpark
* CandyShine
* SugarRipple

## 🧱 Layout / structure

* SugarGrid
* CandyFlow
* HoneyGrid
* SweetLayout
* CandyFrame

## 🧠 State / logic

* SugarState
* CandyStore
* SweetSignal
* HoneyState
* CandyFlux

## ✍️ Input / forms

* CandyPrompt
* SugarInput
* SweetAsk
* HoneyForm

## 🎨 Styling

* CandySprinkles
* SugarStyle
* SweetSkin
* HoneyTheme

## 📊 Charts / data

* SugarCharts
* CandyPlots
* SweetGraphs
* HoneyCharts

Stick close to this structure:
> **[Cute prefix] + [technical/function suffix]**

---

## New entries (2026-05-06)

| Name | Upstream | Rationale |
|---|---|---|
| **CandyLog** | charmbracelet/log | Foundation/system — logging is infrastructure |
| **CandyPalette** | charmbracelet/colorprofile | Styling/colors → foundation-level color tooling |
| **CandyServe** | charmbracelet/soft-serve | Foundation — self-hostable Git server |
| **CandyLister** | treilik/bubblelister | Foundation — list/box layout component |
| **CandyHermit** | Genekkion/theHermit | Foundation — model/lifecycle helper |
| **SugarSkate** | charmbracelet/skate | Data — key/value store (data layer) |
| **SugarPost** | charmbracelet/pop | App — terminal email client |
| **SugarBoxer** | treilik/bubbleboxer | Component — boxing/padding |
| **SugarVeil** | rmhubbert/bubbletea-overlay | Component — modal/overlay |
| **SugarCrumbs** | KevM/bubbleo | Component — NavStack/Breadcrumbs/Menu |
| **SugarTable** | Evertras/bubble-table | Component — interactive table |
| **SugarReadline** | erikgeiser/promptkit | Component — line-editing prompt |
| **SugarCalendar** | EthanEFung/bubble-datepicker | Component — date picker |
| **SugarToast** | DaltonSW/bubbleup | Component — floating alerts |
| **SugarStickers** | 76creates/stickers | Component — Lipgloss building blocks |

