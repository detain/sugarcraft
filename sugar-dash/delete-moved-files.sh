#!/bin/bash
# This script deletes the chart/graph/canvas files from Grid/ that were moved to Plot/
cd /home/sites/sugarcraft/sugar-dash

# Delete chart files
rm -f src/Grid/Chart.php src/Grid/AreaChart.php src/Grid/Area.php src/Grid/AreaPoint.php src/Grid/SparkArea.php
rm -f src/Grid/Bar.php src/Grid/Bubble.php src/Grid/BubblePoint.php
rm -f src/Grid/CandlestickChart.php src/Grid/Candlestick.php
rm -f src/Grid/Donut.php src/Grid/FunnelChart.php src/Grid/Funnel.php
rm -f src/Grid/GaugeChart.php src/Grid/GaugeCircle.php src/Grid/Gauge.php src/Grid/Meter.php
rm -f src/Grid/HeatMapChart.php src/Grid/Heatmap.php
rm -f src/Grid/OHLC.php src/Grid/OHLCPoint.php
rm -f src/Grid/Partition.php src/Grid/PartitionSegment.php
rm -f src/Grid/RadarChart.php
rm -f src/Grid/Sparkline.php src/Grid/SparklineArea.php src/Grid/SparklineBar.php
rm -f src/Grid/Waterfall.php src/Grid/WaterfallItem.php src/Grid/WaterfallBarType.php
rm -f src/Grid/WordCloud.php src/Grid/DotMatrix.php
rm -f src/Grid/Canvas.php src/Grid/Graph.php

# Run composer dump-autoload
composer dump-autoload

# Run tests
vendor/bin/phpunit
