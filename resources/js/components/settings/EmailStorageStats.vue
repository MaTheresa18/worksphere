<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-medium text-[var(--text-primary)]">
                Storage Usage
            </h3>
            <div v-if="loading" class="text-sm text-[var(--text-muted)]">
                Calculating...
            </div>
            <div v-else class="text-sm text-[var(--text-muted)]">
                {{ formatBytes(usage.total_bytes) }} used
                <span v-if="usage.limit_bytes">
                    of {{ formatBytes(usage.limit_bytes) }}</span
                >
            </div>
        </div>

        <!-- Progress Bar -->
        <div
            class="h-4 w-full bg-[var(--surface-tertiary)] rounded-full overflow-hidden flex relative"
        >
            <!-- Emails Segment -->
            <div
                class="h-full bg-blue-600"
                :style="{
                    width: getPercent(usage.emails_bytes) + '%',
                    minWidth: usage.emails_bytes > 0 ? '8px' : '0',
                }"
                :title="`Emails: ${formatBytes(usage.emails_bytes)}`"
            ></div>

            <!-- Attachments Segment -->
            <div
                class="h-full bg-[var(--color-warning)]"
                :style="{ width: getPercent(usage.attachments_bytes) + '%' }"
                :title="`Attachments: ${formatBytes(usage.attachments_bytes)}`"
            ></div>

            <!-- Other/Space filler if needed -->
        </div>

        <!-- Legend -->
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
            <div class="flex items-start space-x-2">
                <div
                    class="w-3 h-3 mt-1 rounded-full bg-blue-600 shrink-0"
                ></div>
                <div>
                    <div class="text-sm font-medium text-[var(--text-primary)]">
                        Emails
                    </div>
                    <div class="text-xs text-[var(--text-muted)]">
                        {{ formatBytes(usage.emails_bytes) }} ({{
                            usage.emails_count
                        }}
                        emails)
                    </div>
                </div>
            </div>

            <div class="flex items-start space-x-2">
                <div
                    class="w-3 h-3 mt-1 rounded-full bg-[var(--color-warning)] shrink-0"
                ></div>
                <div>
                    <div class="text-sm font-medium text-[var(--text-primary)]">
                        Attachments
                    </div>
                    <div class="text-xs text-[var(--text-muted)]">
                        {{ formatBytes(usage.attachments_bytes) }} ({{
                            usage.attachments_count
                        }}
                        files)
                    </div>
                </div>
            </div>

            <div v-if="usage.limit_bytes" class="flex items-start space-x-2">
                <div
                    class="w-3 h-3 mt-1 rounded-full bg-[var(--surface-tertiary)] shrink-0"
                ></div>
                <div>
                    <div class="text-sm font-medium text-[var(--text-primary)]">
                        Free Space
                    </div>
                    <div class="text-xs text-[var(--text-muted)]">
                        {{
                            formatBytes(
                                Math.max(
                                    0,
                                    usage.limit_bytes - usage.total_bytes,
                                ),
                            )
                        }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted, computed, watch } from "vue";
import api from "@/lib/api";

const props = defineProps({
    accountId: {
        type: [String, Number],
        required: true,
    },
});

const loading = ref(true);
const usage = ref({
    total_bytes: 0,
    emails_bytes: 0,
    attachments_bytes: 0,
    other_bytes: 0,
    emails_count: 0,
    attachments_count: 0,
    limit_bytes: null,
});

const fetchUsage = async () => {
    if (!props.accountId) return;

    loading.value = true;
    try {
        const response = await api.get(
            `/api/email-accounts/${props.accountId}/storage-usage`,
        );
        usage.value = response.data.data;
    } catch (e) {
        console.error("Failed to fetch storage usage", e);
    } finally {
        loading.value = false;
    }
};

onMounted(() => {
    fetchUsage();
});

watch(
    () => props.accountId,
    () => {
        fetchUsage();
    },
);

const getPercent = (bytes) => {
    if (!usage.value.total_bytes && !usage.value.limit_bytes) return 0;

    const base = usage.value.limit_bytes || usage.value.total_bytes;
    if (base === 0) return 0;

    return Math.min(100, (bytes / base) * 100);
};

const formatBytes = (bytes, decimals = 2) => {
    if (!+bytes) return "0 Bytes";

    const k = 1024;
    const dm = decimals < 0 ? 0 : decimals;
    const sizes = ["Bytes", "KB", "MB", "GB", "TB", "PB", "EB", "ZB", "YB"];

    const i = Math.floor(Math.log(bytes) / Math.log(k));

    return `${parseFloat((bytes / Math.pow(k, i)).toFixed(dm))} ${sizes[i]}`;
};
</script>
