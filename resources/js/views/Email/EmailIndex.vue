<template>
    <!-- Main Container: Consumes 100% of AppLayout content area -->
    <!-- We use h-full to inherit the fixed height from AppLayout.vue when layoutFixed: true -->
    <div
        class="flex w-full h-full overflow-hidden bg-(--surface-primary) relative"
    >
        <!-- Sidebar Backdrop (Mobile) -->
        <div
            v-if="isMobileSidebarOpen"
            class="fixed inset-0 bg-black/50 z-20 md:hidden backdrop-blur-sm"
            @click="isMobileSidebarOpen = false"
        ></div>

        <!-- 1. Left Sidebar (Folders) -->
        <!-- Mobile: Fixed overlay | Desktop: Static width column -->
        <aside
            class="shrink-0 flex flex-col h-full min-h-0 bg-(--surface-secondary) border-r border-(--border-default) transition-transform duration-300 z-30"
            :class="[
                isMobileSidebarOpen
                    ? 'fixed inset-y-0 left-0 shadow-xl'
                    : 'hidden -translate-x-full md:translate-x-0 md:flex md:static',
            ]"
        >
            <EmailSidebar @compose="handleCompose" />
        </aside>

        <!-- 2. Middle Column (List) -->
        <!-- Flex item that sends events to parent -->
        <div
            class="flex flex-col w-full md:w-[289px] lg:w-[346px] border-r border-(--border-default) h-full min-h-0 shrink-0 bg-(--surface-primary)"
            :class="{
                'hidden md:flex': selectedEmailId || isComposing,
                flex: !selectedEmailId && !isComposing,
            }"
        >
            <EmailList
                @select="handleSelectEmail"
                @toggle-sidebar="isMobileSidebarOpen = !isMobileSidebarOpen"
                @compose="handleCompose"
            />
        </div>

        <!-- 3. Right Column (Preview) -->
        <!-- Flex-1 to take remaining space -->
        <main
            class="flex-1 flex flex-col h-full min-h-0 min-w-0 bg-(--surface-primary)"
            :class="{
                'hidden md:flex': !selectedEmailId && !isComposing,
                flex: selectedEmailId || isComposing,
            }"
        >
            <EmailPreviewPane
                ref="previewPaneRef"
                :email="selectedEmail"
                @tab-closed="handleTabClosed"
                @back="
                    selectedEmailId = null;
                    isComposing = false;
                "
            />
        </main>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, watch } from "vue";
import EmailSidebar from "./components/EmailSidebar.vue";
import EmailList from "./components/EmailList.vue";
import EmailPreviewPane from "./components/EmailPreviewPane.vue";
import { useEmailStore, type Email } from "@/stores/emailStore";
import { storeToRefs } from "pinia";

const store = useEmailStore();
const { emails, selectedEmailId, loading } = storeToRefs(store);

const selectedEmail = computed(() => {
    return emails.value.find((e) => e.id === selectedEmailId.value) || null;
});

// Persistence: Handle case where selected email isn't in the initial fetched list (e.g., on refresh)
watch(
    [selectedEmailId, loading],
    async ([newId, isLoading]) => {
        console.log(
            "[EmailIndex] Watcher: selectedEmailId=",
            newId,
            "loading=",
            isLoading,
            "foundEmail=",
            !!selectedEmail.value,
        );
        if (newId && !isLoading && !selectedEmail.value) {
            console.log("[EmailIndex] Fetching missing email:", newId);
            await store.fetchEmailById(newId);
        }
    },
    { immediate: true },
);

const isMobileSidebarOpen = ref(false);
const isComposing = ref(false);
const previewPaneRef = ref<InstanceType<typeof EmailPreviewPane> | null>(null);

function handleSelectEmail(email: Email) {
    store.selectedEmailId = email.id;
    email.isRead = true;
    isComposing.value = false;
}

function handleCompose() {
    isComposing.value = true;
    // Trigger compose tab in preview pane
    if (previewPaneRef.value) {
        previewPaneRef.value.openTab("compose");
    }
}

function handleCloseCompose() {
    isComposing.value = false;
}

function handleTabClosed(tabId: string) {
    // If all compose tabs are closed, reset composing state
    if (
        tabId.startsWith("compose") ||
        tabId.startsWith("reply") ||
        tabId.startsWith("forward")
    ) {
        // Check if there are any remaining compose tabs
        // For now, just reset - the preview pane will show read tab
        isComposing.value = false;
    }
}
</script>
