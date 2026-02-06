<template>
    <div
        class="flex-1 flex flex-col h-full bg-[var(--surface-primary)] overflow-hidden min-h-0"
    >
        <!-- Email Content Area -->
        <div class="flex-1 overflow-hidden min-h-0 relative flex flex-col">
            <!-- Loading State -->
            <div v-if="loadingThread" class="p-8 flex justify-center">
                <LoaderIcon
                    class="w-6 h-6 animate-spin text-[var(--text-muted)]"
                />
            </div>

            <!-- Thread View -->
            <div
                v-else-if="threadMessages.length > 0 && activeTab === 'read'"
                class="flex-1 overflow-y-auto"
            >
                <div
                    v-for="(msg, index) in threadMessages"
                    :key="msg.id"
                    class="border-b border-[var(--border-default)] last:border-0"
                >
                    <!-- Collapsed Header -->
                    <div
                        v-if="!msg.isExpanded"
                        @click="toggleExpand(index)"
                        class="px-6 py-3 bg-[var(--surface-secondary)] hover:bg-[var(--surface-tertiary)] cursor-pointer flex items-center gap-4 transition-colors"
                    >
                        <p
                            class="font-medium text-sm text-[var(--text-primary)] w-48 truncate"
                        >
                            {{ msg.from_name }}
                        </p>
                        <p
                            class="text-sm text-[var(--text-secondary)] flex-1 truncate"
                        >
                            {{ msg.preview }}
                        </p>
                        <span
                            class="text-xs text-[var(--text-muted)] whitespace-nowrap"
                            >{{ formatDate(msg.date) }}</span
                        >
                    </div>

                    <!-- Expanded Content -->
                    <EmailPreviewContent
                        v-else
                        :email="msg"
                        @reply="openTab('reply', msg)"
                        @reply-all="openTab('reply-all', msg)"
                        @forward="openTab('forward', msg)"
                        @forward-as-attachment="
                            openTab('forward-as-attachment', msg)
                        "
                    />
                </div>
            </div>

            <!-- Inline Composer -->
            <EmailInlineComposer
                v-else-if="activeTab !== 'read'"
                :mode="activeTab"
                :reply-to="replyTargetEmail || email"
                @close="closeActiveTab"
                @send="handleSend"
            />

            <!-- Empty State -->
            <div
                v-else
                class="flex-1 flex flex-col items-center justify-center text-[var(--text-muted)] h-full"
            >
                <MailIcon class="w-16 h-16 mb-4 text-[var(--text-tertiary)]" />
                <p>Select an email to read</p>
            </div>
        </div>

        <!-- Anchored Attachments Bar (Pinned above Action Bar) -->
        <div
            v-if="
                props.email &&
                activeTab === 'read' &&
                visibleAttachments.length > 0
            "
            class="px-4 py-2 bg-(--surface-secondary) border-t border-(--border-default) flex items-center gap-2 overflow-x-auto scrollbar-none"
        >
            <div
                v-for="att in visibleAttachments"
                :key="att.id"
                class="flex items-center gap-2 px-2 py-1 bg-(--surface-primary) border border-(--border-default) rounded-md text-xs text-(--text-secondary) whitespace-nowrap hover:bg-(--surface-tertiary) transition-colors cursor-default group"
                :title="att.name"
            >
                <component
                    :is="getAttachmentIcon(att.type)"
                    class="w-3.5 h-3.5 text-blue-500"
                />
                <span class="max-w-[120px] truncate">{{ att.name }}</span>
                <span class="text-[10px] text-(--text-muted)">{{
                    formatSize(att.size)
                }}</span>
                <button
                    v-if="att.is_downloaded === false"
                    class="ml-1 p-0.5 hover:text-(--interactive-primary) transition-colors"
                >
                    <DownloadIcon class="w-3 h-3" />
                </button>
            </div>
        </div>

        <!-- Docked Action Bar (Clamped on top of Tab Bar) -->
        <div
            v-if="props.email && activeTab === 'read'"
            class="px-4 py-2.5 border-t border-(--border-default) bg-(--surface-primary) flex items-center justify-between"
        >
            <div class="flex items-center gap-1.5">
                <!-- Main Replies -->
                <div
                    class="flex items-center gap-1 p-0.5 bg-(--surface-secondary) rounded-lg border border-(--border-default)"
                >
                    <button
                        @click="
                            openTab('reply', replyTargetEmail || props.email)
                        "
                        class="flex items-center gap-1.5 px-3 py-1.5 rounded-md text-[11px] font-bold tracking-tight text-white bg-(--interactive-primary) hover:bg-(--interactive-primary-hover) transition-all shadow-sm"
                    >
                        <ReplyIcon class="w-3.5 h-3.5" />
                        REPLY
                    </button>
                    <button
                        @click="
                            openTab(
                                'reply-all',
                                replyTargetEmail || props.email,
                            )
                        "
                        class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-md text-[11px] font-semibold text-(--text-primary) hover:bg-(--surface-tertiary) transition-all shadow-sm border border-transparent hover:border-(--border-default)"
                        title="Reply All"
                    >
                        <ReplyAllIcon class="w-3.5 h-3.5" />
                        REP-ALL
                    </button>
                    <button
                        @click="
                            openTab('forward', replyTargetEmail || props.email)
                        "
                        class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-md text-[11px] font-semibold text-(--text-primary) hover:bg-(--surface-tertiary) transition-all shadow-sm border border-transparent hover:border-(--border-default)"
                        title="Forward"
                    >
                        <ForwardIcon class="w-3.5 h-3.5" />
                        FWD
                    </button>
                </div>

                <!-- Secondary Actions -->
                <div
                    class="flex items-center gap-1 px-1 border-l border-(--border-default) ml-1"
                >
                    <button
                        @click="
                            openTab(
                                'forward-as-attachment',
                                replyTargetEmail || props.email,
                            )
                        "
                        class="p-2 text-(--text-secondary) hover:bg-(--surface-secondary) hover:text-(--text-primary) rounded-md transition-colors"
                        title="Forward as attachment"
                    >
                        <PaperclipIcon class="w-3.5 h-3.5" />
                    </button>
                    <button
                        @click="exportAsEml"
                        class="p-2 text-(--text-secondary) hover:bg-(--surface-secondary) hover:text-(--text-primary) rounded-md transition-colors"
                        title="Download as .eml"
                    >
                        <DownloadIcon class="w-3.5 h-3.5" />
                    </button>
                </div>
            </div>

            <div class="flex items-center gap-1">
                <button
                    class="p-2 text-(--text-secondary) hover:bg-(--color-error)/10 hover:text-(--color-error) rounded-md transition-colors"
                    title="Delete"
                >
                    <TrashIcon class="w-3.5 h-3.5" />
                </button>
                <div class="w-px h-4 bg-(--border-default) mx-1"></div>
                <button
                    class="p-2 text-(--text-secondary) hover:bg-(--surface-secondary) rounded-md transition-colors"
                    title="More actions"
                >
                    <MoreHorizontalIcon class="w-3.5 h-3.5" />
                </button>
            </div>
        </div>

        <!-- Tab Bar -->
        <div
            v-if="tabs.length > 0"
            class="border-t border-[var(--border-default)] bg-[var(--surface-secondary)]"
        >
            <div class="flex items-center gap-1 px-2 py-1.5 overflow-x-auto">
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    @click="activeTab = tab.id"
                    class="group flex items-center gap-2 px-3 py-1.5 text-sm rounded-md transition-all duration-150"
                    :class="[
                        activeTab === tab.id
                            ? 'bg-[var(--interactive-primary)] text-white shadow-sm'
                            : 'text-[var(--text-secondary)] hover:bg-[var(--surface-tertiary)] hover:text-[var(--text-primary)]',
                    ]"
                >
                    <component :is="tab.icon" class="w-3.5 h-3.5" />
                    <span class="truncate max-w-[120px]">{{ tab.label }}</span>
                    <button
                        v-if="tab.closable"
                        @click.stop="closeTab(tab.id)"
                        class="ml-1 p-0.5 rounded hover:bg-white/20 transition-colors"
                        :class="
                            activeTab === tab.id
                                ? 'text-white/70 hover:text-white'
                                : 'text-[var(--text-muted)] hover:text-[var(--text-primary)]'
                        "
                    >
                        <XIcon class="w-3 h-3" />
                    </button>
                </button>

                <!-- New Compose Button -->
                <button
                    @click="openTab('compose')"
                    class="flex items-center gap-1.5 px-3 py-1.5 text-sm text-[var(--interactive-primary)] hover:bg-[var(--surface-tertiary)] rounded-md transition-colors"
                    title="New Email"
                >
                    <PlusIcon class="w-3.5 h-3.5" />
                    <span class="hidden sm:inline">New</span>
                </button>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, watch, markRaw, onMounted, onUnmounted } from "vue";
import {
    MailIcon,
    XIcon,
    PlusIcon,
    ReplyIcon,
    ReplyAllIcon,
    ForwardIcon,
    PaperclipIcon,
    PencilIcon,
    InboxIcon,
    LoaderIcon,
    TrashIcon,
    MoreHorizontalIcon,
    DownloadIcon,
    FileIcon,
    ImageIcon,
    ExternalLinkIcon,
} from "lucide-vue-next";
import EmailPreviewContent from "./EmailPreviewContent.vue";
import EmailInlineComposer from "./EmailInlineComposer.vue";
import type { Email } from "@/types/models/email";
import { useEmailStore } from "@/stores/emailStore";
import { isToday, format } from "date-fns";

interface Tab {
    id: string;
    label: string;
    icon: any;
    closable: boolean;
}

const props = defineProps<{
    email: Email | null;
}>();

const emit = defineEmits<{
    compose: [];
    "tab-closed": [tabId: string];
}>();

const store = useEmailStore();
const activeTab = ref<string>("read");
const tabs = ref<Tab[]>([
    { id: "read", label: "Read", icon: markRaw(InboxIcon), closable: false },
]);
// --- Export as EML ---
function exportAsEml() {
    if (!props.email) return;
    window.open(`/api/emails/${props.email.id}/export`, "_blank");
}

const visibleAttachments = computed(() => {
    if (!props.email?.attachments) return [];
    return props.email.attachments.filter((att: any) => !att.is_inline);
});

function getAttachmentIcon(type: string) {
    if (type?.startsWith("image/")) return ImageIcon;
    return FileIcon;
}

function formatSize(bytes: number) {
    if (!bytes) return "";
    const k = 1024;
    const sizes = ["B", "KB", "MB", "GB"];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + " " + sizes[i];
}

// Threading State
const threadMessages = ref<any[]>([]);
const loadingThread = ref(false);
const replyTargetEmail = ref<Email | null>(null); // Specific email being replied to in thread

// Watch for email changes to fetch thread
watch(
    () => props.email,
    async (newEmail) => {
        if (newEmail) {
            activeTab.value = "read";

            loadingThread.value = true;
            const threadId = newEmail.thread_id || newEmail.id;

            try {
                const messages = await store.fetchThread(threadId);

                const messageList = Array.isArray(messages) ? messages : (messages?.data || []);
                
                threadMessages.value = messageList.map(
                    (msg: any, index: number) => ({
                        ...msg,
                        isExpanded: index === messageList.length - 1,
                    }),
                );
            } catch (e) {
                // Fallback to single email if fetch fails
                threadMessages.value = [{ ...newEmail, isExpanded: true }];
            } finally {
                loadingThread.value = false;
            }
        } else {
            threadMessages.value = [];
        }
    },
    { immediate: true },
);

function toggleExpand(index: number) {
    if (threadMessages.value[index]) {
        threadMessages.value[index].isExpanded =
            !threadMessages.value[index].isExpanded;
    }
}

function formatDate(dateString: string) {
    if (!dateString) return "";
    const date = new Date(dateString);
    if (isNaN(date.getTime())) return "";
    return isToday(date) ? format(date, "h:mm a") : format(date, "MMM d");
}

// Listen for postMessage from popup windows
onMounted(() => {
    window.addEventListener("message", handlePopupMessage);
});

onUnmounted(() => {
    window.removeEventListener("message", handlePopupMessage);
});

function handlePopupMessage(event: MessageEvent) {
    if (
        event.data?.type &&
        ["reply", "reply-all", "forward", "forward-as-attachment"].includes(
            event.data.type,
        )
    ) {
        // Default to latest message if not specified?
        openTab(event.data.type as any, props.email);
    }
}

function openTab(
    type:
        | "reply"
        | "reply-all"
        | "forward"
        | "compose"
        | "forward-as-attachment",
    targetEmail: Email | null = null,
) {
    const emailToUse = targetEmail || props.email;
    replyTargetEmail.value = emailToUse; // Set context for composer

    const id = `${type}-${Date.now()}`;
    const labels = {
        reply: `Re: ${emailToUse?.subject || "Reply"}`,
        "reply-all": `Re All: ${emailToUse?.subject || "Reply All"}`,
        forward: `Fwd: ${emailToUse?.subject || "Forward"}`,
        "forward-as-attachment": `Fwd(Att): ${emailToUse?.subject || "Forward"}`,
        compose: "New Email",
    };
    const icons = {
        reply: markRaw(ReplyIcon),
        "reply-all": markRaw(ReplyAllIcon),
        forward: markRaw(ForwardIcon),
        "forward-as-attachment": markRaw(PaperclipIcon),
        compose: markRaw(PencilIcon),
    };

    tabs.value.push({
        id,
        label: labels[type],
        icon: icons[type],
        closable: true,
    });

    activeTab.value = id;
}

function closeTab(id: string) {
    const index = tabs.value.findIndex((t) => t.id === id);
    if (index > -1) {
        tabs.value.splice(index, 1);
        // If closing active tab, switch to read
        if (activeTab.value === id) {
            activeTab.value = "read";
            replyTargetEmail.value = null;
        }
        // Notify parent that a tab was closed
        emit("tab-closed", id);

        // Refresh?
    }
}

function closeActiveTab() {
    if (activeTab.value !== "read") {
        closeTab(activeTab.value);
    }
}

function handleSend() {
    // TODO: Implement send logic
    closeActiveTab();
}

// Expose for parent component
defineExpose({
    openTab,
});
</script>
