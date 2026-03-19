<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
    /**
     * Array of data points. Each item: { label: string, value: number }
     * label = day number string (e.g. "1", "15")
     * value = numeric metric for that day
     */
    data: {
        type: Array,
        required: true,
    },
    /** Human-readable series label shown in the chart header. */
    label: {
        type: String,
        default: '',
    },
    /** Tailwind / hex colour for the line and area. */
    color: {
        type: String,
        default: '#6366f1',
    },
    /** Optional second series to overlay (same shape as data). */
    secondaryData: {
        type: Array,
        default: null,
    },
    secondaryLabel: {
        type: String,
        default: '',
    },
    secondaryColor: {
        type: String,
        default: '#10b981',
    },
    /** Function to format tooltip values. */
    formatValue: {
        type: Function,
        default: (v) => v.toLocaleString('fr-FR'),
    },
    /** Whether values can be negative (shifts the zero-line rendering). */
    allowNegative: {
        type: Boolean,
        default: false,
    },
});

// SVG layout constants
const SVG_WIDTH = 900;
const SVG_HEIGHT = 220;
const PADDING_LEFT = 64;
const PADDING_RIGHT = 20;
const PADDING_TOP = 20;
const PADDING_BOTTOM = 38;
const CHART_WIDTH = SVG_WIDTH - PADDING_LEFT - PADDING_RIGHT;
const CHART_HEIGHT = SVG_HEIGHT - PADDING_TOP - PADDING_BOTTOM;
const GRID_LINES = 5;

// Tooltip state
const tooltipVisible = ref(false);
const tooltipX = ref(0);
const tooltipY = ref(0);
const tooltipDayIndex = ref(null);

const allValues = computed(() => {
    const primary = props.data.map((d) => d.value);
    const secondary = props.secondaryData ? props.secondaryData.map((d) => d.value) : [];
    return [...primary, ...secondary];
});

const minValue = computed(() => {
    if (!props.allowNegative) {
        return 0;
    }
    return Math.min(0, ...allValues.value);
});

const maxValue = computed(() => {
    const max = Math.max(...allValues.value, 1);
    // Add 10% headroom
    return max + max * 0.1;
});

const valueRange = computed(() => maxValue.value - minValue.value);

function xForIndex(index) {
    const count = props.data.length;
    if (count <= 1) {
        return PADDING_LEFT + CHART_WIDTH / 2;
    }
    return PADDING_LEFT + (index / (count - 1)) * CHART_WIDTH;
}

function yForValue(value) {
    if (valueRange.value === 0) {
        return PADDING_TOP + CHART_HEIGHT / 2;
    }
    const ratio = (value - minValue.value) / valueRange.value;
    return PADDING_TOP + CHART_HEIGHT - ratio * CHART_HEIGHT;
}

function buildPolylinePoints(series) {
    return series.map((d, i) => `${xForIndex(i)},${yForValue(d.value)}`).join(' ');
}

function buildAreaPath(series) {
    if (series.length === 0) {
        return '';
    }
    const zeroY = yForValue(0);
    const points = series.map((d, i) => `${xForIndex(i)},${yForValue(d.value)}`).join(' ');
    const firstX = xForIndex(0);
    const lastX = xForIndex(series.length - 1);
    return `M${firstX},${zeroY} L${points.replaceAll(',', ' L').replaceAll(' L', ',').replaceAll(',', ' ').split(' ').reduce((acc, p, i) => {
        // rebuild properly
        return acc;
    }, '')} L${lastX},${zeroY} Z`;
}

// Simpler area path builder
function buildAreaPathSimple(series) {
    if (series.length === 0) {
        return '';
    }
    const zeroY = yForValue(Math.max(0, minValue.value));
    let d = `M ${xForIndex(0)} ${zeroY}`;
    series.forEach((point, i) => {
        d += ` L ${xForIndex(i)} ${yForValue(point.value)}`;
    });
    d += ` L ${xForIndex(series.length - 1)} ${zeroY} Z`;
    return d;
}

const primaryPolyline = computed(() => buildPolylinePoints(props.data));
const primaryAreaPath = computed(() => buildAreaPathSimple(props.data));

const secondaryPolyline = computed(() => {
    if (!props.secondaryData) {
        return '';
    }
    return buildPolylinePoints(props.secondaryData);
});

const secondaryAreaPath = computed(() => {
    if (!props.secondaryData) {
        return '';
    }
    return buildAreaPathSimple(props.secondaryData);
});

// Y-axis grid values
const gridValues = computed(() => {
    return Array.from({ length: GRID_LINES + 1 }, (_, i) => {
        return minValue.value + (valueRange.value / GRID_LINES) * (GRID_LINES - i);
    });
});

const zeroLineY = computed(() => yForValue(0));
const showZeroLine = computed(() => props.allowNegative && minValue.value < 0);

function formatAxisValue(value) {
    const abs = Math.abs(value);
    if (abs >= 1_000_000) {
        return (value / 1_000_000).toFixed(1) + 'M';
    }
    if (abs >= 1_000) {
        return (value / 1_000).toFixed(0) + 'k';
    }
    return value.toFixed(0);
}

// Show every nth label to avoid overlap on 31-day charts
const xLabelStep = computed(() => {
    const count = props.data.length;
    if (count <= 10) {
        return 1;
    }
    if (count <= 20) {
        return 2;
    }
    return 5;
});

function handleMouseMove(event) {
    const svgEl = event.currentTarget;
    const rect = svgEl.getBoundingClientRect();
    const svgX = ((event.clientX - rect.left) / rect.width) * SVG_WIDTH;

    const count = props.data.length;
    if (count === 0) {
        return;
    }

    // Find the closest data point
    let closestIndex = 0;
    let minDist = Infinity;
    for (let i = 0; i < count; i++) {
        const dist = Math.abs(xForIndex(i) - svgX);
        if (dist < minDist) {
            minDist = dist;
            closestIndex = i;
        }
    }

    tooltipDayIndex.value = closestIndex;
    tooltipX.value = (xForIndex(closestIndex) / SVG_WIDTH) * 100;
    tooltipY.value = 0;
    tooltipVisible.value = true;
}

function handleMouseLeave() {
    tooltipVisible.value = false;
}

const tooltipData = computed(() => {
    if (tooltipDayIndex.value === null) {
        return null;
    }
    const primary = props.data[tooltipDayIndex.value];
    const secondary = props.secondaryData ? props.secondaryData[tooltipDayIndex.value] : null;
    return { primary, secondary, index: tooltipDayIndex.value };
});
</script>

<template>
    <div class="stat-chart-wrapper">
        <div class="chart-container" @mouseleave="handleMouseLeave">
            <svg
                :viewBox="`0 0 ${SVG_WIDTH} ${SVG_HEIGHT}`"
                preserveAspectRatio="xMidYMid meet"
                class="chart-svg"
                @mousemove="handleMouseMove"
            >
                <defs>
                    <linearGradient :id="`area-gradient-primary-${label}`" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" :stop-color="color" stop-opacity="0.3" />
                        <stop offset="100%" :stop-color="color" stop-opacity="0.02" />
                    </linearGradient>
                    <linearGradient
                        v-if="secondaryData"
                        :id="`area-gradient-secondary-${label}`"
                        x1="0"
                        y1="0"
                        x2="0"
                        y2="1"
                    >
                        <stop offset="0%" :stop-color="secondaryColor" stop-opacity="0.2" />
                        <stop offset="100%" :stop-color="secondaryColor" stop-opacity="0.02" />
                    </linearGradient>
                </defs>

                <!-- Grid lines & Y-axis labels -->
                <g v-for="(gridValue, gridIndex) in gridValues" :key="gridIndex">
                    <line
                        :x1="PADDING_LEFT"
                        :y1="yForValue(gridValue)"
                        :x2="SVG_WIDTH - PADDING_RIGHT"
                        :y2="yForValue(gridValue)"
                        stroke="#e2e8f0"
                        stroke-width="1"
                        stroke-dasharray="4,4"
                    />
                    <text
                        :x="PADDING_LEFT - 6"
                        :y="yForValue(gridValue) + 4"
                        text-anchor="end"
                        font-size="10"
                        fill="#94a3b8"
                    >
                        {{ formatAxisValue(gridValue) }}
                    </text>
                </g>

                <!-- Zero line (only when chart can go negative) -->
                <line
                    v-if="showZeroLine"
                    :x1="PADDING_LEFT"
                    :y1="zeroLineY"
                    :x2="SVG_WIDTH - PADDING_RIGHT"
                    :y2="zeroLineY"
                    stroke="#64748b"
                    stroke-width="1.5"
                />

                <!-- Area fills -->
                <path
                    v-if="primaryAreaPath"
                    :d="primaryAreaPath"
                    :fill="`url(#area-gradient-primary-${label})`"
                />
                <path
                    v-if="secondaryData && secondaryAreaPath"
                    :d="secondaryAreaPath"
                    :fill="`url(#area-gradient-secondary-${label})`"
                />

                <!-- Lines -->
                <polyline
                    v-if="primaryPolyline"
                    :points="primaryPolyline"
                    :stroke="color"
                    stroke-width="2"
                    fill="none"
                    stroke-linejoin="round"
                    stroke-linecap="round"
                />
                <polyline
                    v-if="secondaryData && secondaryPolyline"
                    :points="secondaryPolyline"
                    :stroke="secondaryColor"
                    stroke-width="2"
                    fill="none"
                    stroke-linejoin="round"
                    stroke-linecap="round"
                />

                <!-- Hover dot on primary series -->
                <circle
                    v-if="tooltipVisible && tooltipDayIndex !== null"
                    :cx="xForIndex(tooltipDayIndex)"
                    :cy="yForValue(data[tooltipDayIndex].value)"
                    r="5"
                    :fill="color"
                    stroke="white"
                    stroke-width="2"
                />
                <!-- Hover dot on secondary series -->
                <circle
                    v-if="tooltipVisible && tooltipDayIndex !== null && secondaryData"
                    :cx="xForIndex(tooltipDayIndex)"
                    :cy="yForValue(secondaryData[tooltipDayIndex].value)"
                    r="5"
                    :fill="secondaryColor"
                    stroke="white"
                    stroke-width="2"
                />

                <!-- Vertical hover line -->
                <line
                    v-if="tooltipVisible && tooltipDayIndex !== null"
                    :x1="xForIndex(tooltipDayIndex)"
                    :y1="PADDING_TOP"
                    :x2="xForIndex(tooltipDayIndex)"
                    :y2="SVG_HEIGHT - PADDING_BOTTOM"
                    stroke="#cbd5e1"
                    stroke-width="1"
                    stroke-dasharray="3,3"
                />

                <!-- X-axis labels -->
                <g v-for="(point, pointIndex) in data" :key="`xlabel-${pointIndex}`">
                    <text
                        v-if="pointIndex % xLabelStep === 0"
                        :x="xForIndex(pointIndex)"
                        :y="SVG_HEIGHT - PADDING_BOTTOM + 16"
                        text-anchor="middle"
                        font-size="10"
                        fill="#94a3b8"
                    >
                        {{ point.label }}
                    </text>
                </g>

                <!-- X axis baseline -->
                <line
                    :x1="PADDING_LEFT"
                    :y1="SVG_HEIGHT - PADDING_BOTTOM"
                    :x2="SVG_WIDTH - PADDING_RIGHT"
                    :y2="SVG_HEIGHT - PADDING_BOTTOM"
                    stroke="#e2e8f0"
                    stroke-width="1"
                />
            </svg>

            <!-- Tooltip -->
            <div
                v-if="tooltipVisible && tooltipData"
                class="chart-tooltip"
                :style="{ left: `${tooltipX}%` }"
            >
                <div class="tooltip-day">Jour {{ tooltipData.primary.label }}</div>
                <div class="tooltip-row">
                    <span class="tooltip-dot" :style="{ background: color }"></span>
                    <span class="tooltip-label">{{ label }} :</span>
                    <span class="tooltip-value">{{ formatValue(tooltipData.primary.value) }}</span>
                </div>
                <div v-if="secondaryData && tooltipData.secondary" class="tooltip-row">
                    <span class="tooltip-dot" :style="{ background: secondaryColor }"></span>
                    <span class="tooltip-label">{{ secondaryLabel }} :</span>
                    <span class="tooltip-value">{{ formatValue(tooltipData.secondary.value) }}</span>
                </div>
            </div>
        </div>

        <!-- Legend -->
        <div v-if="secondaryData" class="chart-legend">
            <span class="legend-item">
                <span class="legend-dot" :style="{ background: color }"></span>
                {{ label }}
            </span>
            <span class="legend-item">
                <span class="legend-dot" :style="{ background: secondaryColor }"></span>
                {{ secondaryLabel }}
            </span>
        </div>
    </div>
</template>

<style scoped>
.stat-chart-wrapper {
    position: relative;
    width: 100%;
}

.chart-container {
    position: relative;
    width: 100%;
}

.chart-svg {
    width: 100%;
    height: auto;
    display: block;
}

.chart-tooltip {
    position: absolute;
    top: 8px;
    transform: translateX(-50%);
    background: rgba(15, 23, 42, 0.92);
    color: #f8fafc;
    border-radius: 8px;
    padding: 8px 12px;
    font-size: 12px;
    pointer-events: none;
    z-index: 10;
    min-width: 160px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
    backdrop-filter: blur(4px);
}

.tooltip-day {
    font-weight: 600;
    margin-bottom: 4px;
    color: #94a3b8;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.tooltip-row {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 3px;
}

.tooltip-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}

.tooltip-label {
    color: #94a3b8;
    font-size: 11px;
}

.tooltip-value {
    font-weight: 600;
    font-size: 12px;
    margin-left: auto;
}

.chart-legend {
    display: flex;
    gap: 16px;
    justify-content: center;
    margin-top: 4px;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: #64748b;
}

.legend-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
}
</style>
