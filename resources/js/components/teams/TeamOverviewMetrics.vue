<script setup>
import { ref, onMounted } from "vue";
import axios from "axios";
import { useRoute } from "vue-router";
import Card from "@/components/ui/Card.vue";
import { CheckCircle, Clock, AlertCircle, PlayCircle } from "lucide-vue-next";

const route = useRoute();
const stats = ref(null);
const loading = ref(true);

const fetchOverviewStats = async () => {
    loading.value = true;
    try {
        const response = await axios.get(
            `/api/teams/${route.params.public_id}/stats/analytics-overview`,
        );
        stats.value = response.data;
    } catch (error) {
        console.error("Error fetching overview stats:", error);
    } finally {
        loading.value = false;
    }
};

onMounted(() => {
    fetchOverviewStats();
});
</script>

<template>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <!-- Adherence Rate -->
        <Card
            class="p-4 flex flex-col justify-between h-full bg-gradient-to-br from-[var(--bg-secondary)] to-[var(--bg-primary)] border-[var(--border-color)]"
        >
            <div class="flex items-start justify-between mb-4">
                <div>
                    <h4
                        class="text-sm font-medium text-[var(--text-secondary)]"
                    >
                        Adherence Rate
                    </h4>
                    <p class="text-xs text-[var(--text-muted)] mt-1">
                        On-time completion
                    </p>
                </div>
                <div class="p-2 bg-blue-500/10 rounded-lg text-blue-500">
                    <CheckCircle class="w-5 h-5" />
                </div>
            </div>
            <div class="flex items-end gap-2" v-if="!loading && stats">
                <span class="text-2xl font-bold text-[var(--text-primary)]"
                    >{{ stats.adherence_rate }}%</span
                >
                <span class="text-xs text-[var(--text-secondary)] mb-1"
                    >target 90%</span
                >
            </div>
            <div
                v-else
                class="h-8 w-24 bg-[var(--bg-tertiary)] animate-pulse rounded"
            ></div>
        </Card>

        <!-- Avg Cycle Time -->
        <Card
            class="p-4 flex flex-col justify-between h-full bg-gradient-to-br from-[var(--bg-secondary)] to-[var(--bg-primary)] border-[var(--border-color)]"
        >
            <div class="flex items-start justify-between mb-4">
                <div>
                    <h4
                        class="text-sm font-medium text-[var(--text-secondary)]"
                    >
                        Avg. Cycle Time
                    </h4>
                    <p class="text-xs text-[var(--text-muted)] mt-1">
                        Start to Finish
                    </p>
                </div>
                <div class="p-2 bg-purple-500/10 rounded-lg text-purple-500">
                    <Clock class="w-5 h-5" />
                </div>
            </div>
            <div class="flex items-end gap-2" v-if="!loading && stats">
                <span class="text-2xl font-bold text-[var(--text-primary)]">{{
                    stats.avg_cycle_time_days
                }}</span>
                <span
                    class="text-sm font-medium text-[var(--text-secondary)] mb-1"
                    >days</span
                >
            </div>
            <div
                v-else
                class="h-8 w-24 bg-[var(--bg-tertiary)] animate-pulse rounded"
            ></div>
        </Card>

        <!-- Due This Week -->
        <Card
            class="p-4 flex flex-col justify-between h-full bg-gradient-to-br from-[var(--bg-secondary)] to-[var(--bg-primary)] border-[var(--border-color)]"
        >
            <div class="flex items-start justify-between mb-4">
                <div>
                    <h4
                        class="text-sm font-medium text-[var(--text-secondary)]"
                    >
                        Due This Week
                    </h4>
                    <p class="text-xs text-[var(--text-muted)] mt-1">
                        Upcoming Deadlines
                    </p>
                </div>
                <div class="p-2 bg-orange-500/10 rounded-lg text-orange-500">
                    <AlertCircle class="w-5 h-5" />
                </div>
            </div>
            <div class="flex items-end gap-2" v-if="!loading && stats">
                <span class="text-2xl font-bold text-[var(--text-primary)]">{{
                    stats.due_this_week
                }}</span>
                <span
                    class="text-sm font-medium text-[var(--text-secondary)] mb-1"
                    >tasks</span
                >
            </div>
            <div
                v-else
                class="h-8 w-24 bg-[var(--bg-tertiary)] animate-pulse rounded"
            ></div>
        </Card>

        <!-- Active Projects -->
        <Card
            class="p-4 flex flex-col justify-between h-full bg-gradient-to-br from-[var(--bg-secondary)] to-[var(--bg-primary)] border-[var(--border-color)]"
        >
            <div class="flex items-start justify-between mb-4">
                <div>
                    <h4
                        class="text-sm font-medium text-[var(--text-secondary)]"
                    >
                        Active Projects
                    </h4>
                    <p class="text-xs text-[var(--text-muted)] mt-1">
                        Currently Running
                    </p>
                </div>
                <div class="p-2 bg-green-500/10 rounded-lg text-green-500">
                    <PlayCircle class="w-5 h-5" />
                </div>
            </div>
            <div class="flex items-end gap-2" v-if="!loading && stats">
                <span class="text-2xl font-bold text-[var(--text-primary)]">{{
                    stats.active_projects_count
                }}</span>
                <span
                    class="text-sm font-medium text-[var(--text-secondary)] mb-1"
                    >projects</span
                >
            </div>
            <div
                v-else
                class="h-8 w-24 bg-[var(--bg-tertiary)] animate-pulse rounded"
            ></div>
        </Card>
    </div>
</template>
