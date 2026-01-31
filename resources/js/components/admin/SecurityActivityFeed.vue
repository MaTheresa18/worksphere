<script setup>
import { ref, onMounted } from "vue";
import api from "@/lib/api";
import { formatDistanceToNow } from "date-fns";

// Alias api to axios
const axios = api;
import {
    ShieldExclamationIcon,
    UserIcon,
    GlobeAltIcon,
    ExclamationCircleIcon,
} from "@heroicons/vue/24/outline";

const activities = ref([]);
const loading = ref(true);

const fetchActivity = async () => {
    loading.value = true;
    try {
        const response = await axios.get("/api/admin/security/activity", {
            params: { limit: 5 },
        });
        activities.value = response.data.data || [];
    } catch (error) {
        console.error("Failed to fetch security activity", error);
    } finally {
        loading.value = false;
    }
};

onMounted(() => {
    fetchActivity();
});

const getActionColor = (severity) => {
    switch (severity) {
        case "critical":
            return "text-red-500 bg-red-500/10";
        case "warning":
            return "text-orange-500 bg-orange-500/10";
        case "notice":
            return "text-blue-500 bg-blue-500/10";
        default:
            return "text-gray-500 bg-gray-500/10";
    }
};

const formatAction = (action) => {
    return action.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
};
</script>

<template>
    <div class="space-y-0">
        <div v-if="loading" class="p-4 text-center text-[var(--text-tertiary)]">
            Loading activity...
        </div>

        <div
            v-else-if="activities.length === 0"
            class="p-8 text-center text-[var(--text-tertiary)]"
        >
            <ShieldExclamationIcon class="w-12 h-12 mx-auto mb-3 opacity-50" />
            <p>No recent security incidents found.</p>
        </div>

        <div
            v-else
            v-for="log in activities"
            :key="log.id"
            class="flex items-start gap-5 p-6 border-b border-[var(--border-default)] last:border-0 hover:bg-[var(--surface-hover)] transition-all duration-200"
        >
            <div
                class="p-2 rounded-full shrink-0"
                :class="getActionColor(log.severity)"
            >
                <ExclamationCircleIcon class="w-5 h-5" />
            </div>

            <div class="flex-1 min-w-0">
                <div class="flex items-center justify-between mb-1">
                    <p class="text-sm font-medium text-[var(--text-primary)]">
                        {{ formatAction(log.action) }}
                    </p>
                    <span class="text-xs text-[var(--text-tertiary)]">
                        {{ formatDistanceToNow(new Date(log.created_at), { addSuffix: true }) }}
                    </span>
                </div>

                <div class="flex items-center gap-4 text-sm text-[var(--text-secondary)]">
                    <div class="flex items-center gap-1">
                        <UserIcon class="w-4 h-4" />
                        <span>
                            {{ log.user_name || log.metadata?.email || "Unknown User" }}
                        </span>
                    </div>
                    <div class="flex items-center gap-1" v-if="log.ip_address">
                        <GlobeAltIcon class="w-4 h-4" />
                        <span>{{ log.ip_address }}</span>
                    </div>
                </div>
                 <div v-if="log.metadata?.reason" class="mt-1 text-sm text-[var(--text-tertiary)] truncate">
                    Reason: {{ log.metadata.reason }}
                </div>
            </div>
        </div>
    </div>
</template>
