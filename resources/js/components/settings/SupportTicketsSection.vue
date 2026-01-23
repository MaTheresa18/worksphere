<script setup>
import { computed } from "vue";
import { Button, Input, Switch } from "@/components/ui";
import { Ticket, Clock, Calendar, AlertTriangle } from "lucide-vue-next";

const props = defineProps({
    settings: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(["update:settings"]);

const businessDaysOptions = [
    { value: 1, label: "Mon" },
    { value: 2, label: "Tue" },
    { value: 3, label: "Wed" },
    { value: 4, label: "Thu" },
    { value: 5, label: "Fri" },
    { value: 6, label: "Sat" },
    { value: 0, label: "Sun" },
];

const priorities = [
    { id: "critical", label: "Critical", color: "text-red-600 bg-red-50" },
    { id: "high", label: "High", color: "text-orange-600 bg-orange-50" },
    { id: "medium", label: "Medium", color: "text-blue-600 bg-blue-50" },
    { id: "low", label: "Low", color: "text-gray-600 bg-gray-50" },
];

const toggleBusinessDay = (day) => {
    const currentDays = props.settings["tickets.sla.business_days"] || [];
    const newDays = currentDays.includes(day)
        ? currentDays.filter((d) => d !== day)
        : [...currentDays, day].sort();

    emit("update:settings", {
        ...props.settings,
        "tickets.sla.business_days": newDays,
    });
};

const formatDuration = (hours) => {
    if (!hours) return "0h";
    return `${hours}h`;
};
</script>

<template>
    <div class="divide-y divide-[var(--border-default)]">
        <!-- Header -->
        <div class="p-6">
            <div class="flex items-center gap-3 mb-1">
                <div
                    class="w-8 h-8 rounded-lg bg-indigo-500/10 flex items-center justify-center"
                >
                    <Ticket class="w-4 h-4 text-indigo-600" />
                </div>
                <div>
                    <h3 class="font-medium text-[var(--text-primary)]">
                        Support Tickets & SLA
                    </h3>
                    <p class="text-xs text-[var(--text-muted)]">
                        Configure service level agreements and support settings
                    </p>
                </div>
            </div>
        </div>

        <!-- Main Toggle -->
        <div class="p-6 flex items-center justify-between">
            <div class="space-y-0.5">
                <label class="text-sm font-medium text-[var(--text-primary)]"
                    >Enable SLA Tracking</label
                >
                <p class="text-xs text-[var(--text-muted)]">
                    Track response and resolution times for tickets
                </p>
            </div>
            <Switch
                :checked="settings['tickets.sla.enabled']"
                @update:checked="
                    (val) => (settings['tickets.sla.enabled'] = val)
                "
            />
        </div>

        <template v-if="settings['tickets.sla.enabled']">
            <!-- Business Hours -->
            <div class="p-6 space-y-6">
                <div class="flex items-start gap-4">
                    <Clock class="w-5 h-5 text-[var(--text-muted)] mt-0.5" />
                    <div class="flex-1 space-y-4">
                        <div class="flex items-center justify-between">
                            <div class="space-y-0.5">
                                <label
                                    class="text-sm font-medium text-[var(--text-primary)]"
                                    >Business Hours</label
                                >
                                <p class="text-xs text-[var(--text-muted)]">
                                    Only count time during business hours
                                </p>
                            </div>
                            <Switch
                                :checked="
                                    settings[
                                        'tickets.sla.business_hours_enabled'
                                    ]
                                "
                                @update:checked="
                                    (val) =>
                                        (settings[
                                            'tickets.sla.business_hours_enabled'
                                        ] = val)
                                "
                            />
                        </div>

                        <div
                            v-if="
                                settings['tickets.sla.business_hours_enabled']
                            "
                            class="grid grid-cols-2 gap-4 pt-2"
                        >
                            <div class="space-y-1.5">
                                <label
                                    class="text-xs text-[var(--text-secondary)]"
                                    >Start Time</label
                                >
                                <Input
                                    type="time"
                                    v-model="
                                        settings[
                                            'tickets.sla.business_hours_start'
                                        ]
                                    "
                                />
                            </div>
                            <div class="space-y-1.5">
                                <label
                                    class="text-xs text-[var(--text-secondary)]"
                                    >End Time</label
                                >
                                <Input
                                    type="time"
                                    v-model="
                                        settings[
                                            'tickets.sla.business_hours_end'
                                        ]
                                    "
                                />
                            </div>
                            <div class="col-span-2 space-y-1.5">
                                <label
                                    class="text-xs text-[var(--text-secondary)]"
                                    >Working Days</label
                                >
                                <div class="flex gap-2">
                                    <button
                                        v-for="day in businessDaysOptions"
                                        :key="day.value"
                                        @click="toggleBusinessDay(day.value)"
                                        type="button"
                                        :class="[
                                            'w-8 h-8 rounded-full text-xs font-medium transition-colors',
                                            (
                                                settings[
                                                    'tickets.sla.business_days'
                                                ] || []
                                            ).includes(day.value)
                                                ? 'bg-indigo-600 text-white'
                                                : 'bg-[var(--surface-secondary)] text-[var(--text-secondary)] hover:bg-[var(--surface-hover)]',
                                        ]"
                                    >
                                        {{ day.label.charAt(0) }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Holidays -->
            <div class="p-6 space-y-6 border-t border-[var(--border-default)]">
                <div class="flex items-start gap-4">
                    <Calendar class="w-5 h-5 text-[var(--text-muted)] mt-0.5" />
                    <div class="flex-1 space-y-4">
                        <div class="flex items-center justify-between">
                            <div class="space-y-0.5">
                                <label
                                    class="text-sm font-medium text-[var(--text-primary)]"
                                    >Holidays</label
                                >
                                <p class="text-xs text-[var(--text-muted)]">
                                    Exclude public holidays from SLA calculation
                                </p>
                            </div>
                            <Switch
                                :checked="
                                    settings['tickets.sla.exclude_holidays']
                                "
                                @update:checked="
                                    (val) =>
                                        (settings[
                                            'tickets.sla.exclude_holidays'
                                        ] = val)
                                "
                            />
                        </div>

                        <div
                            v-if="settings['tickets.sla.exclude_holidays']"
                            class="pt-2"
                        >
                            <div class="space-y-1.5">
                                <label
                                    class="text-xs text-[var(--text-secondary)]"
                                    >Country Code (Base on holiday API)</label
                                >
                                <Input
                                    v-model="
                                        settings['tickets.sla.holiday_country']
                                    "
                                    placeholder="US, GB, DE..."
                                    class="uppercase"
                                    maxlength="2"
                                />
                                <p class="text-[10px] text-[var(--text-muted)]">
                                    Use ISO 3166-1 alpha-2 country code
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Warning Threshold -->
            <div class="p-6 space-y-6 border-t border-[var(--border-default)]">
                <div class="flex items-start gap-4">
                    <AlertTriangle
                        class="w-5 h-5 text-[var(--text-muted)] mt-0.5"
                    />
                    <div class="flex-1 space-y-4">
                        <div class="flex items-center justify-between">
                            <div class="space-y-0.5">
                                <label
                                    class="text-sm font-medium text-[var(--text-primary)]"
                                    >Warning Threshold</label
                                >
                                <p class="text-xs text-[var(--text-muted)]">
                                    Notify when SLA time reaches this percentage
                                </p>
                            </div>
                            <span
                                class="text-sm font-mono bg-[var(--surface-secondary)] px-2 py-1 rounded"
                                >{{
                                    settings["tickets.sla.warning_threshold"]
                                }}%</span
                            >
                        </div>
                        <input
                            type="range"
                            v-model.number="
                                settings['tickets.sla.warning_threshold']
                            "
                            min="50"
                            max="95"
                            step="5"
                            class="w-full accent-indigo-600"
                        />
                    </div>
                </div>
            </div>

            <!-- Default Values -->
            <div class="p-6 space-y-4 border-t border-[var(--border-default)]">
                <h4 class="text-sm font-medium text-[var(--text-primary)]">
                    Default SLA Policies
                </h4>
                <div
                    class="overflow-hidden rounded-lg border border-[var(--border-default)]"
                >
                    <table class="w-full text-sm text-left">
                        <thead
                            class="bg-[var(--surface-secondary)] text-[var(--text-secondary)]"
                        >
                            <tr>
                                <th class="px-4 py-3 font-medium">Priority</th>
                                <th class="px-4 py-3 font-medium">
                                    Response Time (Hours)
                                </th>
                                <th class="px-4 py-3 font-medium">
                                    Resolution Time (Hours)
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--border-default)]">
                            <tr
                                v-for="priority in priorities"
                                :key="priority.id"
                            >
                                <td class="px-4 py-3">
                                    <span
                                        :class="[
                                            'px-2 py-1 rounded-md text-xs font-medium',
                                            priority.color,
                                        ]"
                                    >
                                        {{ priority.label }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <Input
                                            type="number"
                                            v-model.number="
                                                settings[
                                                    `tickets.sla.default_response_hours.${priority.id}`
                                                ]
                                            "
                                            class="w-20 h-8"
                                            min="1"
                                        />
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <Input
                                            type="number"
                                            v-model.number="
                                                settings[
                                                    `tickets.sla.default_resolution_hours.${priority.id}`
                                                ]
                                            "
                                            class="w-20 h-8"
                                            min="1"
                                        />
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </div>
</template>
