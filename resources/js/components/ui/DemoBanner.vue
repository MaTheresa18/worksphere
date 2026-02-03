<script setup lang="ts">
import { computed } from "vue";
import { useAuthStore } from "@/stores/auth";
import { ShieldAlert, Info } from "lucide-vue-next";

const authStore = useAuthStore();
const isDemoMode = computed(() => authStore.user?.is_demo_mode || false);
</script>

<template>
    <div
        v-if="isDemoMode"
        class="bg-[var(--interactive-primary)] text-white py-1 px-4 text-center text-xs font-medium flex items-center justify-center gap-2 shadow-sm z-[100] relative"
    >
        <ShieldAlert class="h-3.5 w-3.5" />
        <span>
            <strong>Demo Mode Active:</strong> Some destructive actions and sensitive settings are restricted.
        </span>
        <Info class="h-3 w-3 opacity-70 ml-1 cursor-help" title="In Demo Mode, system settings and deletion of core data are disabled to maintain the integrity of the demonstration environment." />
    </div>
</template>

<style scoped>
/* Optional: add a subtle pulse or animation if desired */
div {
    animation: slideDown 0.3s ease-out;
}

@keyframes slideDown {
    from {
        transform: translateY(-100%);
    }
    to {
        transform: translateY(0);
    }
}
</style>
