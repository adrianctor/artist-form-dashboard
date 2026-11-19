<script setup lang="ts">
import { computed } from 'vue';
import VChart from 'vue-echarts';
import { BarChart } from 'echarts/charts';
import { GridComponent, TooltipComponent } from 'echarts/components';
import { use } from 'echarts/core';
import type { EChartsOption } from 'echarts';
import { CanvasRenderer } from 'echarts/renderers';

use([CanvasRenderer, BarChart, GridComponent, TooltipComponent]);

const props = defineProps<{
    data: Record<string, number>;
}>();

// Spec: never show an empty chart — tell the user what it is for.
const hasActivity = computed(() =>
    Object.values(props.data).some((count) => count > 0),
);

const option = computed<EChartsOption>(() => {
    const days = Object.keys(props.data).sort();

    return {
        grid: { left: 12, right: 12, top: 16, bottom: 8, containLabel: true },
        tooltip: { trigger: 'axis' },
        xAxis: {
            type: 'category',
            data: days,
            axisLabel: { color: '#888888', hideOverlap: true },
            axisLine: { lineStyle: { color: 'rgba(128, 128, 128, 0.25)' } },
            axisTick: { show: false },
        },
        yAxis: {
            type: 'value',
            minInterval: 1,
            axisLabel: { color: '#888888' },
            splitLine: { lineStyle: { color: 'rgba(128, 128, 128, 0.18)' } },
        },
        series: [
            {
                name: 'Submissions',
                type: 'bar',
                data: days.map((day) => props.data[day]),
                itemStyle: { color: '#6366f1', borderRadius: [4, 4, 0, 0] },
                barMaxWidth: 32,
            },
        ],
    };
});
</script>

<template>
    <VChart
        v-if="hasActivity"
        :option="option"
        autoresize
        class="h-72 w-full"
        aria-label="Daily submissions over the last 30 days"
    />
    <p
        v-else
        class="flex h-72 items-center justify-center rounded-lg border border-dashed text-sm text-muted-foreground"
    >
        No submissions in the last 30 days — send a release to see activity here.
    </p>
</template>
