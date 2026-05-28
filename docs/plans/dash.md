 ---

  status: not-started

  phase: 1

  updated: 2026-05-12

  goal: Implement SugarDash dashboard TUI library

  ---


  # Implementation Plan: SugarDash


  ## Goal

  Implement SugarDash, a comprehensive PHP dashboard TUI library synthesizing
  the best patterns from 7 upstream/community Go libraries: bubble-grid,
Phase 1: Core Layout Systems

    1.1 Grid\ — Item/Sizer interfaces, StackedGrid (column layout), Frame (border/padding/fluent setters)
    1.2 Boxer\ — Boxer struct, Node, ModelMap, CreateLeaf, EditLeaf, UpdateSize, View
    1.3 Layout\ — Direction type, Size struct (Weight/Min/Max/Fixed), Tile interface, BaseTile, TileLayout with iterative constraint solving
    1.4 RatioGrid\ — GridItem with XRatio/WidthRatio, NewCol/NewRow factories, Grid.Set

Phase 2: Component Library

    2.1 GridTable\ — Column, Row, CellValue, GridModel (sort/filter/page/scroll), BorderConfig presets, KeyMap

    2.2 Tree\ — generic Node, TreeModel, BranchStyle, ExpanderControls, 5 style presets, scroll

    2.3 StatusBar\ — MenuItem, StatusIndicator, Model (two-zone rendering)

    2.4 Modal\ — ConfirmModel with withers, ListModel (generic), ClosedMsg/AnsweredYesMsg/etc.

    2.5 Select\ — Option, Model (auto-positioning above/below anchor), Open/Close

    2.6 Toast\ — NoticeKey/Definition/Position, Model (Initialize/RegisterNoticeType/NewNotifyCmd)

    2.7 Tabs\ — TabPane, NewTabPane, FocusLeft/Right, GetInnerRect

    2.8 Plot\ — Plot (MarkerBraille/MarkerDot/LineChart/ScatterPlot), Canvas (SetPoint/SetLine), Sparkline, Gauge, RingBuffer

Phase 3: Universal Drawable Interface

    3.1 Drawable\ — Drawable interface (GetRect/SetRect/Draw/Locker), Buffer (NewBuffer/GetCell/SetCell/Fill/SetString), Cell, Style, Color, Modifier, Render, ParseStyles ([text](fg:red,bg:blue))

Phase 4: Module/Plugin System

    4.1 Module\ — Module interface (Name/Init/Update/View/MinSize), ImagePlacer, ModuleConfig, Config with RefreshConfig/Disk, DiscoverDisks, env var override
    4.2 Registry\ — Register/Get/List/Reset, auto-registration via init()
    4.3 Plugin\ — Request/Response JSON protocol, Run SDK, ExternalModule, Find plugin discovery
    4.4 Modules\ — Clock, System (parallel stats), Greeting, Uptime, Weather, Generic; TickEpoch, NotificationQueue

Phase 5: Utilities & Styling

    5.1 Keys\ — KeyIdentifier, KeyMeta (Binding/StatusBar/HelpModal/Category), Registry (ForStatusBar/ForHelpModal/ByCategory)
    5.2 Theme — SystemPalette, Palette presets (Dark/Light/Adaptive/Default), ApplyNamed, GaugeColor, ContainerStateColor
    5.3 Position\ — CalculateCenter, MeasureRenderedView (ANSI-aware), CenterModal
    5.4 Output\ — RenderBar, RenderStat, Truncate, RenderGauge, WrapCells

Phase 6: Scaffold & Integration

    6.1 sugar-dash/composer.json (PHP ^8.3, PHPUnit ^10.5), phpunit.xml, README.md, CALIBER_LEARNINGS.md, src/ 18-package structure, lang/en.php, .vhs/ demos
    6.2 Root composer.json + path repo, CI/vhs workflow matrix entries, MATCHUPS.md, PROJECT_NAMES.md, docs/index.html tile

, docs/lib/sugar-dash.md

Phase 7: Tests

    7.1 Component tests: Grid/, Layout/, GridTable/, Tree/, StatusBar/, Modal/, Select/, Toast/, Tabs/, Plot/ (snapshot + behavior)
    7.2 Core tests: Drawable/ (Buffer, ParseStyles, Style), Module/ (registration, YAML), Plugin/ (ExternalModule JSON), Keys/, Theme/
    7.3 Stream-write: ftell/fseek/stream_get_contents per AGENTS.md gotcha



The plan saved successfully. Now let me present a summary to the user of what was accomplished.

Plan saved successfully. Here's a summary of what I found across all 7 repositories:
All 7 Repositories — Deep Dive Summary
1. shahar3/bubble-grid 🟢 Best for: Column Layout

    Core: StackedGrid with column-based item placement
    Key interfaces: Item (just Render()), Sizer (Item + SetSize())
    Frame: bordered container with style customization
    Strength: Simple, proven, works with nested grids
    Missing: No proportional weights, no constraint system

2. mko88/bubbletea-tilelayout 🟢 Best for: Constraint Layout

    Core: TileLayout with Tile interface (embedding tea.Model)
    Size struct: Weight (float64 0-1), MinWidth/Height, MaxWidth/Height, FixedWidth/Height
    Algorithm: Iterative leftover distribution (≤100 iterations) for constraint residual handling
    Strength: Most powerful layout engine found
    Missing: No column framing, no built-in border/panel concept

3. mikeschinkel/go-tealeaves 🟢 Best for: Component Suite

8 independent packages, all mature:

    teagrid: Sort/filter/page/scroll data grid with 8 border preset styles
    tealayout: Pane-based row/column/flex + FocusManager + StackLayoutModel drilldown
    teamodal: Wither pattern modals (OK, YesNo, List with edit/delete)
    teatree: Generic tree with 5 BranchStyle presets, viewport scroll
    teastatus: Two-zone status bar (menu items + indicators)
    teafields: Full-screen dropdown with auto-positioning
    teanotify: Toast overlay with positioned notices
    teautils: KeyRegistry (centralized key bindings for status bar + help visor)

4. treilik/bubbleboxer 🟢 Best for: Composition Model

    Core: Boxer with Node tree + ModelMap (address-based leaf indirection)
    Only valid leaf creation: CreateLeaf(address, model) — forces proper setup
    Safe mutation: EditLeaf(address, fn) — returns error instead of breaking
    SizeFunc: Custom size distribution per node
    Strength: Elegant separation of layout tree from model instances

5. floatpane/lattice 🟢 Best for: Extensibility Architecture

    Module interface: Name(), Init(), Update(), View(w,h), MinSize() + ImagePlacer
    Constructor registry: Global Register(name, Constructor) with init() auto-registration
    Plugin protocol: JSON over stdin/stdout — init → update → view phases, 30s interval
    Built-in modules: clock, greeting, system (CPU/mem/GPU), github, weather, uptime
    Strength: Language-agnostic plugin system works for Go, Python, Bash

6. kts982/Homedash 🟡 Best for: Dashboard Patterns

    TickEpoch: uint64 incremented on focus regain — stale ticks discarded
    RingBuffer: 60-sample circular buffer for sparkline history
    Parallel stats: 5-worker semaphore pool for concurrent Docker stats
    Notification queue: Current + history (ring buffer), 3 levels (info/warning/error)
    Panel rendering: Header with weather, system panel with sparklines + gauges, containers list
    Auto-disk detection: Scans /proc/mounts for local filesystems
    Log streaming: Context-cancelable, handles both TTY and multiplexed modes
    Strength: Complete dashboard app patterns ready to extract

7. sashakoshka/termui 🟡 Best for: Low-Level Primitives

    Drawable interface: GetRect()/SetRect()/Draw()/sync.Locker — universal renderable contract
    Grid: Ratio-based layout (XRatio/WidthRatio) — nested rows/columns
    Plot: MarkerBraille (2x X, 4x Y resolution via Unicode braille chars) vs MarkerDot
    Canvas: Arbitrary point/line drawing using braille characters (2x4 dots per cell)
    ParseStyles: [text](fg:red,bg:blue) embedded syntax — powerful theming shortcut
    12 widget types: Paragraph, Gauge, Sparkline, BarChart, PieChart, List, Table, TabPane, Tree, Plot, Image, Canvas
    Strength: Low-level primitives + rich widget set + unique braille canvas

Unified Dashboard Architecture

The plan synthesizes all 7 into SugarDash with 18 sub-packages:
Category 	Packages 	Key Patterns
Layout 	Grid, Boxer, Layout, RatioGrid 	3 distinct layout engines (column, constraint, ratio)
Components 	GridTable, Tree, StatusBar, Modal, Select, Toast, Tabs, Plot 	Full dashboard component suite
Foundation 	Drawable 	Universal drawable interface + Buffer + ParseStyles
Extensibility 	Module, Registry, Plugin, Modules 	Constructor registry + JSON plugin protocol
Utilities 	Keys, Theme, Position, Output 	Key registry, theme system, positioning, rendering helpers
