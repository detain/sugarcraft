<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\Chart;

/**
 * Width resolution and data resampling for sparkline renderers.
 *
 * Literal twins extracted from SparklineArea and SparklineBar under E731
 * round-85 per-family follow-through: both copies of each method (and
 * both docblocks) were byte-identical; both consumers are final and the
 * pair reads only the identical private state (`$data`, `$width`,
 * `$widthConstraint`) each class already carries. Precedent:
 * ChartGridGeometry (r7 `79abe8461`).
 *
 * NOT here on purpose: getWidth() fallbacks DIVERGE across the wider
 * family — Sparkline falls back to `$this->widthConstraint ?? 40`
 * (Sparkline.php:201) and Gauge to `$this->widthConstraint ?? 0`
 * (Gauge.php:138), each after the same `$this->width` setSize check;
 * Progress returns the literal 40, Bar derives its
 * width from rendered content — only the sparkline pair shares the
 * `widthConstraint ?? count(data)` leg. normalizeData() intentionally
 * shares its name with the static clamp helper in HeatmapColorScale:
 * different signatures, different jobs, disjoint consumers, so the two
 * traits can never collide inside one class.
 */
trait SparklineScaling
{
    /**
     * Normalize data points to fit the display width.
     *
     * @return list<float>
     */
    private function normalizeData(int $width): array
    {
        $dataCount = count($this->data);

        if ($dataCount === 0) {
            return [];
        }

        if ($dataCount === $width) {
            return $this->data;
        }

        if ($dataCount < $width) {
            // Upscale: interpolate between points
            $result = [];
            for ($i = 0; $i < $width; $i++) {
                $pos = ($i / ($width - 1)) * ($dataCount - 1);
                $index = (int) floor($pos);
                $fraction = $pos - $index;

                if ($index >= $dataCount - 1) {
                    $result[] = $this->data[$dataCount - 1];
                } else {
                    $v1 = $this->data[$index];
                    $v2 = $this->data[$index + 1];
                    $result[] = $v1 + ($v2 - $v1) * $fraction;
                }
            }
            return $result;
        }

        // Downscale: sample at regular intervals
        $result = [];
        $step = $dataCount / $width;
        for ($i = 0; $i < $width; $i++) {
            $index = (int) floor($i * $step);
            if ($index >= $dataCount) {
                $index = $dataCount - 1;
            }
            $result[] = $this->data[$index];
        }
        return $result;
    }

    /**
     * Get the width to use for the sparkline.
     */
    private function getWidth(): int
    {
        if ($this->width !== null && $this->width > 0) {
            return $this->width;
        }
        return $this->widthConstraint ?? count($this->data);
    }
}
