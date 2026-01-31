<script setup>
import { computed } from "vue";
import {
    ArrowUpIcon,
    ArrowDownIcon,
    MinusIcon,
} from "@heroicons/vue/20/solid";
import * as HeroIcons from "@heroicons/vue/24/outline";

const props = defineProps({
    title: {
        type: String,
        required: true,
    },
    value: {
        type: [String, Number],
        required: true,
    },
    icon: {
        type: String,
        default: "ChartBarIcon",
    },
    iconName: { // Alias for icon
        type: String,
        default: null,
    },
    change: {
        type: [String, Number],
        default: null,
    },
    changeType: {
        type: String,
        default: "neutral", // neutral, increase, decrease
    },
    color: {
        type: String,
        default: "text-[var(--interactive-primary)]",
    },
    bgColor: {
        type: String,
        default: "bg-[var(--surface-tertiary)]",
    },
});

const iconComponent = computed(() => {
    const name = props.iconName || props.icon;
    return HeroIcons[name] || HeroIcons["ChartBarIcon"];
});

const changeColorClass = computed(() => {
    if (props.changeType === "increase") return "text-green-600 dark:text-green-400";
    if (props.changeType === "decrease") return "text-red-600 dark:text-red-400";
    return "text-[var(--text-tertiary)]";
});

const ChangeIcon = computed(() => {
    if (props.changeType === "increase") return ArrowUpIcon;
    if (props.changeType === "decrease") return ArrowDownIcon;
    return MinusIcon;
});
</script>

<template>
    <div class="card p-6 flex items-start justify-between transition-all duration-200 hover:shadow-md">
        <div>
            <p class="text-sm font-medium text-[var(--text-secondary)] truncate">
                {{ title }}
            </p>
            <p class="mt-2 text-3xl font-semibold text-[var(--text-primary)] tracking-tight">
                {{ value }}
            </p>
            <div v-if="change" class="mt-2 flex items-center text-sm">
                <component
                    :is="ChangeIcon"
                    class="mr-1 h-4 w-4 shrink-0"
                    :class="changeColorClass"
                    aria-hidden="true"
                />
                <span :class="['font-medium', changeColorClass]">
                    {{ change }}
                </span>
                <span class="ml-1 text-[var(--text-tertiary)]">from last period</span>
            </div>
        </div>
        <div
            class="p-3 rounded-xl shrink-0"
            :class="bgColor"
        >
            <component
                :is="iconComponent"
                class="h-6 w-6"
                :class="color"
                aria-hidden="true"
            />
        </div>
    </div>
</template>
