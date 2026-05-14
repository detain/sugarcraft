#!/bin/bash
set -e
cd /home/sites/sugarcraft/sugar-dash

# Chart files to move
charts=("Chart.php" "AreaChart.php" "Area.php" "AreaPoint.php" "SparkArea.php" "Bar.php" "Bubble.php" "BubblePoint.php" "CandlestickChart.php" "Candlestick.php" "Donut.php" "FunnelChart.php" "Funnel.php" "GaugeChart.php" "GaugeCircle.php" "Gauge.php" "Meter.php" "HeatMapChart.php" "Heatmap.php" "OHLC.php" "OHLCPoint.php" "Partition.php" "PartitionSegment.php" "RadarChart.php" "Sparkline.php" "SparklineArea.php" "SparklineBar.php" "Waterfall.php" "WaterfallItem.php" "WaterfallBarType.php" "WordCloud.php" "DotMatrix.php")

for f in "${charts[@]}"; do
    if [ -f "src/Grid/$f" ]; then
        sed 's/namespace SugarCraft\\Dash\\Grid;/namespace SugarCraft\\Dash\\Plot\\Chart;/g; s/SugarCraft\\Dash\\Grid\\/SugarCraft\\Dash\\Plot\\Chart\\/g' "src/Grid/$f" > "src/Plot/Chart/$f"
        echo "Moved: $f"
    fi
done

# Move Graph.php
if [ -f "src/Grid/Graph.php" ]; then
    sed 's/namespace SugarCraft\\Dash\\Grid;/namespace SugarCraft\\Dash\\Plot\\Graph;/g; s/SugarCraft\\Dash\\Grid\\/SugarCraft\\Dash\\Plot\\Graph\\/g' "src/Grid/Graph.php" > "src/Plot/Graph/Graph.php"
    echo "Moved: Graph.php"
fi

# Move Canvas.php
if [ -f "src/Grid/Canvas.php" ]; then
    sed 's/namespace SugarCraft\\Dash\\Grid;/namespace SugarCraft\\Dash\\Plot\\Canvas;/g; s/SugarCraft\\Dash\\Grid\\/SugarCraft\\Dash\\Plot\\Canvas\\/g' "src/Grid/Canvas.php" > "src/Plot/Canvas/Canvas.php"
    echo "Moved: Canvas.php"
fi

echo "Migration complete"