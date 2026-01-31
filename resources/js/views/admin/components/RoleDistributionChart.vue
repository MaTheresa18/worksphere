<script setup>
import { computed } from "vue";
import { Chart as ChartJS, ArcElement, Tooltip, Legend } from "chart.js";
import { Doughnut } from "vue-chartjs";

ChartJS.register(ArcElement, Tooltip, Legend);

const props = defineProps({
    data: {
        type: Object,
        required: true,
        default: () => ({}),
    },
    loading: {
        type: Boolean,
        default: false,
    },
});

const chartData = computed(() => {
    // Expected data format: { 'administrator': 5, 'user': 20, ... }
    const labels = Object.keys(props.data).map(
        (role) =>
            role.replace("_", " ").replace(/\b\w/g, (l) => l.toUpperCase()), // Capitalize
    );
    const values = Object.values(props.data);

    return {
        labels,
        datasets: [
            {
                backgroundColor: [
                    "#3b82f6", // Blue
                    "#10b981", // Green
                    "#f59e0b", // Amber
                    "#ef4444", // Red
                    "#8b5cf6", // Violet
                    "#ec4899", // Pink
                ],
                data: values,
                borderWidth: 0,
                hoverOffset: 4,
            },
        ],
    };
});

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    cutout: "75%", // Thinner donut
    plugins: {
        legend: {
            position: "right",
            labels: {
                usePointStyle: true,
                boxWidth: 8,
                color: "#9ca3af", // gray-400
                font: {
                    size: 11,
                },
            },
        },
        tooltip: {
            backgroundColor: "rgba(0, 0, 0, 0.8)",
            padding: 12,
            cornerRadius: 8,
            callbacks: {
                label: function (context) {
                    const label = context.label || "";
                    const value = context.raw || 0;
                    const total =
                        context.chart._metasets[context.datasetIndex].total;
                    const percentage = Math.round((value / total) * 100) + "%";
                    return `${label}: ${value} (${percentage})`;
                },
            },
        },
    },
};
</script>

<template>
    <div
        class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] p-4 h-64 relative flex flex-col"
    >
        <div class="flex items-center justify-between mb-2">
            <p class="text-sm font-medium text-[var(--text-secondary)]">
                Role Distribution
            </p>
        </div>

        <div
            class="flex-1 w-full min-h-0 relative flex items-center justify-center"
        >
            <div
                v-if="loading"
                class="absolute inset-0 flex items-center justify-center bg-[var(--surface-elevated)] z-10 opacity-50"
            >
                <div
                    class="h-24 w-24 rounded-full border-4 border-[var(--surface-tertiary)] border-t-[var(--interactive-primary)] animate-spin"
                ></div>
            </div>
            <div
                v-else-if="Object.keys(data).length === 0"
                class="text-sm text-[var(--text-tertiary)] flex flex-col items-center"
            >
                <span>No data available</span>
            </div>
            <Doughnut v-else :data="chartData" :options="chartOptions" />

            <!-- Center Text (Total Users optionally) -->
            <div
                v-if="!loading && Object.keys(data).length > 0"
                class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none pr-[100px]"
            >
                <!-- pr to offset legend -->
                <span class="text-2xl font-bold text-[var(--text-primary)]">{{
                    Object.values(data).reduce((a, b) => a + b, 0)
                }}</span>
                <span
                    class="text-[10px] uppercase tracking-wider text-[var(--text-tertiary)]"
                    >Total</span
                >
            </div>
        </div>
    </div>
</template>
