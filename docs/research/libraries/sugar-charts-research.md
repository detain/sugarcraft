# Terminal Chart Libraries Research: sugar-charts

**Date:** 2026-05-13
**Project:** sugar-charts (PHP 8.3+ SugarCraft library)
**Upstream:** NimbleMarkets/ntcharts (Go)
**Source:** `/home/sites/sugarcraft/sugar-charts/src/`

---

## Executive Summary

This research surveys terminal chart/visualization libraries across Go, Rust, Python, and JavaScript to identify patterns and improvements for sugar-charts. The library already has a solid foundation mirroring ntcharts, but opportunities exist in rendering precision, real-time streaming, data aggregation, axis labeling, and color management.

**Key Findings:**
1. **Braille canvas** (2x4 sub-pixel rendering) provides significantly better resolution than cell-based rendering
2. **Real-time streaming** patterns are well-established across all languages (Sparkline-style sliding windows)
3. **Data aggregation** is an underserved feature in most terminal libraries — opportunities for differentiation
4. **Axis labeling** approaches vary from simple fixed ticks to sophisticated "nice numbers" algorithms
5. **Color management** ranges from hardcoded ANSI to full RGB with palette interpolation

---

## 1. Library Analysis by Language

### 1.1 Go Libraries

#### NimbleMarkets/ntcharts (Upstream) ⭐
**Repository:** https://github.com/NimbleMarkets/ntcharts
**Stars:** ~680

| Feature | Implementation |
|---------|---------------|
| Chart Types | Canvas, Bar, HeatMap, Line, OHLC, Scatter, Streamline, TimeSeries, Waveline, Sparkline, Picture |
| Rendering | Cell-based with optional Braille support |
| Styling | LipGloss (Charm ecosystem) |
| Mouse Support | BubbleZone integration |
| Framework | Bubble Tea v2 native |

**Strengths:**
- Full Bubble Tea integration with tea.Model
- Mouse zoom/pan on TimeSeries via BubbleZone
- Picture module with Kitty graphics + Sixel fallback
- ChartPicture embedding (go-analyze/charts → image)
- HeatPicture with Perlin noise at full Kitty resolution

**Patterns to Mirror:**
- `chart.Push()` for streaming data
- `chart.SetZoneManager()` for mouse support
- `DrawBrailleAll()` for high-resolution rendering
- Multi-dataset with `PushDataSet()` and `SetDataSetStyle()`

#### vicanso/go-charts
**Repository:** https://github.com/vicanso/go-charts
**Output:** SVG/PNG (not ASCII)

| Feature | Implementation |
|---------|---------------|
| Chart Types | Line, Bar, Pie, Radar, Funnel |
| Rendering | ECharts-compatible SVG/PNG |
| Themes | light, dark, grafana, ant |
| Options | Functional option pattern (`OptionFunc`) |

**Key Patterns:**
```go
charts.LineRender(values,
    charts.TitleTextOptionFunc("Line"),
    charts.XAxisDataOptionFunc([]string{"Mon", "Tue", ...}),
    charts.LegendLabelsOptionFunc([]string{"Email", ...}, charts.PositionRight),
    charts.MarkLineOptionFunc(0, charts.SeriesMarkDataTypeAverage),
)
```

#### ChartGo
**Repository:** https://github.com/aayanmtn/chartgo
**Stars:** Fork of go-analyze/charts

| Feature | Implementation |
|---------|---------------|
| Chart Types | Line, Bar, Pie, Doughnut, Scatter, Bubble, Radar, Polar Area, Funnel, KPI |
| Rendering | Braille canvas with Bresenham's algorithm |
| Framework | Bubble Tea native (tea.Model) |
| Theme | Cyberpunk aesthetic |

**Notable Patterns:**
- Zero-allocation hot paths
- Bresenham's algorithm for line rendering
- Midpoint circle algorithm for bubbles
- Chainable configuration API

---

### 1.2 Rust Libraries

#### Ratatui/ratatui (formerly tui-rs)
**Repository:** https://github.com/ratatui/ratatui
**Documentation:** https://docs.rs/ratatui-widgets

| Feature | Implementation |
|---------|---------------|
| Chart Types | Line, Scatter, Bar (via Dataset.graph_type) |
| Rendering | Cell-based with marker symbols |
| Widgets | BarChart, Sparkline, Gauge, Canvas |
| Styling | Style + Stylize trait |

**Key Patterns:**
```rust
use ratatui::widgets::{BarChart, BarGroup, Bar, Block};
use ratatui::style::{Style, Stylize};

let chart = BarChart::default()
    .block(Block::bordered().title("Monthly Revenue"))
    .bar_width(5)
    .bar_gap(1)
    .group_gap(3)
    .bar_style(Style::new().yellow())
    .data(BarGroup::default().label("Q1").bars(&[
        Bar::with_label("Jan", 120),
        Bar::with_label("Feb", 95),
    ]));
```

**Sparkline Pattern:**
```rust
let sparkline = Sparkline::default()
    .block(Block::bordered().title("CPU Usage"))
    .data(&data)
    .max(10)
    .direction(RenderDirection::LeftToRight)
    .style(Style::new().green())
    .bar_set(symbols::bar::NINE_LEVELS);
```

#### termplot-rs
**Repository:** https://github.com/sabbat-cloud/termplot
**Focus:** High-performance real-time rendering

| Feature | Implementation |
|---------|---------------|
| Rendering | Braille canvas (2x4 sub-pixels) |
| Performance | 1600+ FPS in release mode |
| Canvas | Zero-allocation rendering loop |
| Clipping | Cohen-Sutherland algorithm |

**Key Innovations:**
- `render_to(&mut buffer)` for zero-allocation updates
- Cartesian vs Screen coordinate modes
- Color blending policies (Overwrite vs KeepFirst)
- Auto-range calculation with configurable padding

#### textplots
**Repository:** https://crates.io/crates/textplots

| Feature | Implementation |
|---------|---------------|
| Chart Types | Line only |
| Rendering | Braille canvas |
| API | Simple `Chart::default().lineplot(&Shape::Continuous(...)).display()` |

#### termichart
**Repository:** https://docs.rs/termichart-charts

| Feature | Implementation |
|---------|---------------|
| Chart Types | LineChart, CandlestickChart, BarChart, Histogram, Dashboard |
| Rendering | CellGrid, BrailleCanvas, BlockCanvas |
| Axes | AxisRenderer with nice ticks |
| Interactive | Keyboard-driven scroll/zoom |

---

### 1.3 Python Libraries

#### plotext
**Repository:** https://github.com/piccolomo/plotext
**PyPI:** https://pypi.org/project/plotext/
**Stars:** ~2K

| Feature | Implementation |
|---------|---------------|
| Chart Types | Scatter, Line, Bar, Histogram, Datetime, Candlestick, Heatmap, Matrix, Error bars |
| Rendering | Direct terminal (no dependency) |
| Output | ANSI colored text or HTML |
| Tools | CLI, Themes, Subplots |

**Key Patterns:**
```python
import plotext as plt

# Basic line
plt.plot(y)
plt.title("Title")
plt.show()

# Multiple datasets with labels
plt.plot(x, y1, label="data1")
plt.plot(x, y2, label="data2")
plt.legend()  # auto-placed

# Themes
plt.theme("caliche")  # or "textual-design-dark"

# Streaming
plt.clt()  # clear terminal
plt.cld()  # clear data only
plt.scatter(data)
plt.sleep(0.001)
plt.show()
```

**Streaming Pattern:**
```python
for i in range(frames):
    plt.clt()
    plt.cld()
    y = plt.sin(periods=2, length=l, phase=2 * i / frames)
    plt.scatter(y)
    plt.show()
```

#### textual-plotext
**Repository:** https://github.com/Textualize/textual-plotext

Widget wrapper for Plotext in Textual apps:
```python
from textual.app import App, ComposeResult
from textual_plotext import PlotextPlot

class ScatterApp(App[None]):
    def compose(self) -> ComposeResult:
        yield PlotextPlot()

    def on_mount(self) -> None:
        plt = self.query_one(PlotextPlot).plt
        plt.scatter(self.plt.sin())
        plt.title("Scatter Plot")
```

#### textual-plot
**Repository:** https://github.com/davidfokkema/textual-plot

Native Textual plotting widget with:
- Scatter, Line, Bar plots
- Error bars
- High-resolution modes (half blocks, quadrants, braille)
- Mouse zoom/pan
- Automatic "nice" tick placement

#### asciichartpy
**Repository:** https://github.com/kroitor/asciichart
**PyPI:** https://pypi.org/project/asciichartpy/

| Feature | Implementation |
|---------|---------------|
| Chart Types | Line only |
| Rendering | Unicode box-drawing characters |
| Height | Configurable |
| Colors | ANSI escape codes |

**Key Pattern:**
```python
from asciichartpy import plot, blue, green

series = [1, 4, 2, 7, 5]
print(plot(series))  # auto-sizes to data

# With config
config = {'height': 10, 'colors': [blue, green]}
print(plot([arr1, arr2], config))
```

---

### 1.4 JavaScript Libraries

#### yaronn/blessed-contrib
**Repository:** https://github.com/yaronn/blessed-contrib
**Framework:** Blessed (curses-like)

| Feature | Implementation |
|---------|---------------|
| Chart Types | Line, Bar, Sparkline, Gauge, Map |
| Rendering | ASCII/ANSI art |
| API | Widget-based with `setData()` |
| Colors | 256-color support |

**Key Patterns:**
```javascript
var line = contrib.line({
    style: { line: "yellow", text: "green", baseline: "black" },
    xLabelPadding: 3,
    xPadding: 5,
    showLegend: true,
    wholeNumbersOnly: false,
    label: 'Title'
});

var series1 = { title: 'apples', x: ['t1', 't2', 't3'], y: [5, 1, 7] };
screen.append(line);
line.setData([series1, series2]);
```

#### asciichart (JavaScript original)
**Repository:** https://github.com/kroitor/asciichart

Same API as Python port — single `plot()` function with config:
```javascript
var asciichart = require('asciichart');
console.log(asciichart.plot(s0, { height: 10, colors: [asciichart.blue] }));
```

---

## 2. Comparative Analysis

### 2.1 Chart Types Coverage

| Library | Line | Bar | Scatter | Heatmap | OHLC | Sparkline | Pie | Area |
|---------|------|-----|---------|---------|------|-----------|-----|------|
| **sugar-charts** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| ntcharts | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| ratatui | ✅ | ✅ | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ |
| plotext | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ✅ |
| blessed-contrib | ✅ | ✅ | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ |
| go-charts | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ |
| termplot-rs | ✅ | ✅ | ✅ | ❌ | ✅ | ❌ | ✅ | ❌ |
| asciichart | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |

**Gap Analysis for sugar-charts:**
- **Missing:** Pie/Donut, Area fill, Radar, Bubble
- **Opportunity:** These are highly usable in terminals with Unicode ring characters

### 2.2 Rendering Approaches

#### Cell-Based (sugar-charts, ntcharts, ratatui)
- One character = one data point
- Simple, fast, predictable
- Limited resolution (width × height maximum points)

#### Braille-Based (termplot-rs, ChartGo, textual-plot, plotext optional)
- One character = 2×4 sub-pixels (8 dots)
- 2x horizontal, 4x vertical resolution improvement
- Requires careful line algorithms (Bresenham)
- Better for smooth lines and dense data

#### Half-Block (ratatui Sparkline)
- One character = 2 vertical positions
- Good for sparklines, compact charts
- Simpler than braille

**Recommendation:** Consider adding optional BrailleCanvas rendering mode for LineChart and Scatter when high resolution is needed.

### 2.3 Real-Time Streaming

| Library | Streaming Pattern | Window Size | Update Method |
|---------|-------------------|-------------|---------------|
| **sugar-charts Sparkline** | `push()`, `pushAll()` | Fixed width, slides | New instance |
| ntcharts Streamline | `Push()` | Auto | Model update |
| plotext | `plt.cld()` + re-plot | Configurable | Clear + redraw |
| termplot-rs | `render_to()` | Fixed buffer | Zero-allocation loop |
| textual-plot | Worker API | Automatic | Reactive |

**sugar-charts Current State:**
- Sparkline: ✅ Full push/shift window
- LineChart/TimeSeries: Animation progress support but no streaming window
- Streamline: Exists in ntcharts port but implementation differs

**Improvement:** Add sliding window streaming to TimeSeries and LineChart, similar to Sparkline's `push()` pattern.

### 2.4 Data Aggregation

This is an **underserved feature** across most terminal libraries:

| Library | Aggregation | Grouping | Downsampling |
|---------|-------------|----------|--------------|
| ratatui BarChart | None | `BarGroup` | Manual |
| ntcharts | None | Manual | Manual |
| plotext | None | Manual | Manual |
| termichart | None | Dashboard | Manual |

**Opportunity:** sugar-charts could differentiate by adding:
- Automatic data bucketing (time-based for TimeSeries)
- Moving average overlay support
- Min/Max/Average marker lines (like go-charts MarkLine)
- Auto-sampling for large datasets

### 2.5 Axis Labeling

#### Approaches Compared

**1. Fixed Count (sugar-charts, most)**
```php
// Current sugar-charts
$ticks = $this->yLabelTicks > 0 ? $this->yLabelTicks : 4;
```
- Simple, predictable
- May produce "ugly" numbers (e.g., 3.333, 6.666)

**2. Nice Numbers Algorithm (textual-plot, termichart)**
```rust
// textual-plot tick placement
// Generates ticks at "nice" intervals: 1, 2, 5, 10, 20, 50...
```
- Produces clean, readable labels
- Standard in desktop/web charting
- More complex implementation

**3. Explicit Labels (ratatui, plotext)**
```rust
// ratatui
.label("Q1")
// plotext
plt.xlabel("Time")
plt.ylabel("Value")
```
- User controls exactly what shows
- Flexible but requires more setup

**4. Auto-Range with Padding (termplot-rs, go-charts)**
```rust
// termplot-rs
let padding = 0.1;  // 10% headroom
ChartContext::get_auto_range(&points, padding);
```
- Adds breathing room at edges
- Looks more professional

**Recommendation:** Implement "nice ticks" for Y-axis labels:
```php
// Suggested implementation
public function withNiceYTicks(int $count = 4): self
{
    $nice = self::niceNumbers($this->min, $this->max, $count);
    return $this->withYLabels($nice);
}
```

### 2.6 Legend Handling

#### Approaches

**Border Box (sugar-charts current)**
```
┌─────────────────────┐
│ █ Series A  █ Series B │
└─────────────────────┘
```
- Clear separation from chart
- Takes vertical space

**Inline (plotext, blessed-contrib)**
```
█ Series A  █ Series B
```
- Compact, horizontal
- Integrated with chart area

**Position Variants:**
- Top, Bottom, Left, Right (all libraries)
- Overlay/Float (plotext themes)

**Opportunities:**
1. Collapsible legend for many series
2. Legend click to toggle series visibility
3. Multi-row legend for many series

### 2.7 Color Schemes

| Library | Colors | Palette | Interpolation |
|---------|--------|---------|---------------|
| **sugar-charts** | Named (red, green...) | Linear cold→hot | RGB blend |
| ntcharts | LipGloss compatible | User-defined | N/A |
| ratatui | 256-color + RGB | N/A | N/A |
| plotext | Named + RGB | Theme-based | N/A |
| go-charts | Hex/Theme | ECharts themes | N/A |
| asciichart | ANSI codes | Fixed set | N/A |
| termplot-rs | Named + RGB | Color enum | N/A |

**Heatmap Color Comparison:**

**sugar-charts (current):**
```php
// Two-stop blend
coldColor: Color::hex('#000050'),  // deep blue
hotColor:  Color::hex('#ff4040'),  // red
```
- Simple, predictable
- Limited expressiveness

**plotext (more sophisticated):**
```python
# Uses themes with full palette
plt.colorize()  # applies current theme
# Or per-point colors
plt.scatter(y, color="red")
```

**Recommendation:** Add named color themes ( Dracula, One Dark, Solarized, TokyoNight ) matching the SugarCraft ecosystem.

---

## 3. Specific Improvements for sugar-charts

### 3.1 High Priority (Low Effort, High Impact)

#### 3.1.1 Add "Nice Numbers" Axis Labeling
**Effort:** Medium
**Impact:** High

Implement `niceNumbers(float $min, float $max, int $count): array` for Y-axis:

```php
// Suggested addition to Graph class
public static function niceNumbers(float $min, float $max, int $count): array
{
    $range = self::niceRange($min, $max, $count);
    $step = self::niceStep($range, $count);
    $start = ceil($min / $step) * $step;
    
    $labels = [];
    for ($v = $start; $v <= $max; $v += $step) {
        $labels[] = $v == (int) $v ? (string) (int) $v : number_format($v, 2, '.', '');
    }
    return $labels;
}
```

#### 3.1.2 Add Sliding Window Streaming to LineChart/TimeSeries
**Effort:** Low
**Impact:** High

Mirror Sparkline's push pattern:

```php
// Add to LineChart
public function push(int|float $value): self
{
    $next = $this->data;
    $next[] = $value;
    // If exceeds width, drop oldest
    if (count($next) > $this->width) {
        $next = array_slice($next, -$this->width);
    }
    return $this->lineChartCopy(data: $next);
}
```

#### 3.1.3 Add Area Fill Option to LineChart
**Effort:** Low
**Impact:** Medium

```php
public function withFill(bool $fill = true, ?string $fillChar = '▓'): self
{
    return $this->lineChartCopy(fill: $fill, fillChar: $fillChar);
}
```

### 3.2 Medium Priority (Medium Effort, Medium Impact)

#### 3.2.1 Optional Braille Canvas Rendering
**Effort:** High
**Impact:** High

Add BrailleCanvas class for high-resolution rendering:

```php
final class BrailleCanvas
{
    private const BLOCK_COLS = 2;
    private const BLOCK_ROWS = 4;
    
    public function __construct(
        public readonly int $charWidth,
        public readonly int $charHeight,
    ) {
        $this->pxWidth = $charWidth * self::BLOCK_COLS;
        $this->pxHeight = $charHeight * self::BLOCK_ROWS;
    }
    
    public function setDot(int $px, int $py, ?Color $color): void { ... }
    public function render(?ColorProfile $profile = null): string { ... }
}
```

Then add to LineChart:
```php
public function withBrailleRendering(bool $braille = true): self
{
    return $this->lineChartCopy(useBraille: $braille);
}
```

#### 3.2.2 Add Min/Max/Average Mark Lines
**Effort:** Low
**Impact:** Medium

Like go-charts MarkLine:

```php
public function withMarkLine(MarkLineType $type, ?string $label = null): self
// MarkLineType::Min, MarkLineType::Max, MarkLineType::Average
```

#### 3.2.3 Data Aggregation Helpers
**Effort:** Medium
**Impact:** Medium

```php
// In a new Aggregator class
public static function bucketByTime(array $data, string $interval): array;
public static function movingAverage(array $data, int $window): array;
public static function resample(array $data, int $targetCount): array;
```

### 3.3 Lower Priority (Higher Effort, Nice to Have)

#### 3.3.1 Multiple Y-Axes (plotext pattern)
**Effort:** High
**Impact:** Low

For comparing datasets with different scales.

#### 3.3.2 Interactive Zoom/Pan
**Effort:** High
**Impact:** Low

Requires terminal mouse support — complex.

#### 3.3.3 Pie/Donut Chart
**Effort:** Medium
**Impact:** Low

```php
final class PieChart
{
    // Ring characters: ╭ ╮ ╯ ╰ │
    public function renderChart(): string
    {
        // Calculate angles, render segments
    }
}
```

---

## 4. Priority Recommendations

### Immediate (Next Sprint)

| Improvement | Effort | Files Changed |
|-------------|--------|---------------|
| Add `niceNumbers()` to Graph | Medium | `src/Canvas/Graph.php` |
| Add sliding window to LineChart | Low | `src/LineChart/LineChart.php` |
| Add `withFill()` area fill | Low | `src/LineChart/LineChart.php` |

### Short Term (Next Release)

| Improvement | Effort | Files Changed |
|-------------|--------|---------------|
| Add MarkLine support | Low | `src/LineChart/LineChart.php`, `src/Chart/Chart.php` |
| Add color themes | Medium | `src/Legend/Legend.php`, new `src/Theme/` |
| Add aggregation helpers | Medium | New `src/Util/Aggregator.php` |

### Medium Term (Future)

| Improvement | Effort | Files Changed |
|-------------|--------|---------------|
| BrailleCanvas | High | New `src/Canvas/BrailleCanvas.php` |
| Multiple Y-axes | High | Chart base class + LineChart |
| Interactive zoom | High | Requires mouse handling |

---

## 5. Implementation Pattern Examples

### 5.1 Streaming Pattern (Recommended)

```php
// From Sparkline - adapt to LineChart
final class LineChart
{
    /**
     * Append a single sample, sliding the window.
     */
    public function push(int|float $value): self
    {
        $next = $this->data;
        $next[] = $value;
        if (count($next) > $this->width) {
            $next = array_slice($next, -$this->width);
        }
        return $this->lineChartCopy(data: $next);
    }
    
    public function pushAll(array $values): self
    {
        $next = $this;
        foreach ($values as $v) {
            $next = $next->push($v);
        }
        return $next;
    }
}
```

### 5.2 Nice Numbers Algorithm

```php
// In Graph class
public static function niceStep(float $range, int $targetSteps): float
{
    $rough = $range / $targetSteps;
    $magnitude = pow(10, floor(log10($rough)));
    $residual = $rough / $magnitude;
    
    return match (true) {
        $residual > 5 => 10 * $magnitude,
        $residual > 2 => 5 * $magnitude,
        $residual > 1 => 2 * $magnitude,
        default => $magnitude,
    };
}
```

### 5.3 Area Fill Rendering

```php
// In renderChart() of LineChart
if ($this->fill) {
    $fillChar = $this->fillChar ?? '▓';
    $zeroRow = $gutterLeft + $plotH - 1; // Y-axis baseline
    for ($col = $gutterLeft; $col < $gutterLeft + count($coords); $col++) {
        $pointRow = $coords[$col - $gutterLeft][1];
        for ($row = min($pointRow, $zeroRow); $row <= max($pointRow, $zeroRow); $row++) {
            $canvas->setCell($col, $row, $fillChar, $fillStyle);
        }
    }
}
```

---

## 6. References

### Source Files Examined

| File | Purpose |
|------|---------|
| `sugar-charts/src/Chart/Chart.php` | Base chart class |
| `sugar-charts/src/LineChart/LineChart.php` | Line chart implementation |
| `sugar-charts/src/BarChart/BarChart.php` | Bar chart implementation |
| `sugar-charts/src/Sparkline/Sparkline.php` | Sparkline implementation |
| `sugar-charts/src/Heatmap/Heatmap.php` | Heatmap implementation |
| `sugar-charts/src/Scatter/Scatter.php` | Scatter implementation |
| `sugar-charts/src/OHLC/OHLCChart.php` | OHLC implementation |
| `sugar-charts/src/Legend/Legend.php` | Legend rendering |
| `sugar-charts/src/Canvas/Canvas.php` | Grid canvas |
| `sugar-charts/src/Canvas/Graph.php` | Drawing primitives |
| `sugar-charts/composer.json` | Dependencies |

### External Sources

| Source | URL |
|--------|-----|
| ntcharts | https://github.com/NimbleMarkets/ntcharts |
| go-charts | https://github.com/vicanso/go-charts |
| ratatui | https://github.com/ratatui/ratatui |
| ratatui-widgets | https://docs.rs/ratatui-widgets |
| termplot-rs | https://github.com/sabbat-cloud/termplot |
| termichart | https://docs.rs/termichart-charts |
| plotext | https://github.com/piccolomo/plotext |
| textual-plotext | https://github.com/Textualize/textual-plotext |
| textual-plot | https://github.com/davidfokkema/textual-plot |
| asciichart | https://github.com/kroitor/asciichart |
| asciichartpy | https://pypi.org/project/asciichartpy/ |
| blessed-contrib | https://github.com/yaronn/blessed-contrib |
| ChartGo | https://github.com/aayanmtn/chartgo |

---

## 7. Appendix: Library Feature Matrix

| Feature | sugar-charts | ntcharts | ratatui | plotext | blessed-contrib |
|---------|--------------|----------|---------|---------|-----------------|
| Line | ✅ | ✅ | ✅ | ✅ | ✅ |
| Bar | ✅ | ✅ | ✅ | ✅ | ✅ |
| Sparkline | ✅ | ✅ | ✅ | ✅ | ✅ |
| Scatter | ✅ | ✅ | ✅ | ✅ | ❌ |
| Heatmap | ✅ | ✅ | ❌ | ✅ | ❌ |
| OHLC | ✅ | ✅ | ❌ | ✅ | ❌ |
| TimeSeries | ✅ | ✅ | ❌ | ✅ | ❌ |
| Streamline | ✅ | ✅ | ❌ | ❌ | ❌ |
| Picture | ✅ | ✅ | ❌ | ❌ | ❌ |
| Braille rendering | ❌ | Partial | ❌ | Optional | ❌ |
| Streaming push | Sparkline | All | ❌ | ✅ | ❌ |
| Mouse zoom/pan | ❌ | ✅ | ❌ | ❌ | ❌ |
| Nice axis ticks | ❌ | ❌ | ❌ | ❌ | ❌ |
| Area fill | ❌ | ❌ | ❌ | ✅ | ❌ |
| Multi-Y-axis | ❌ | ❌ | ❌ | ✅ | ❌ |
| Mark lines | ❌ | ❌ | ❌ | ❌ | ❌ |
| Color themes | ❌ | LipGloss | Style | Themes | 256-color |
| Aggregation | ❌ | ❌ | ❌ | ❌ | ❌ |
