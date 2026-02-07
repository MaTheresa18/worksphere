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
                class="flex-1 overflow-y-auto min-h-0 flex flex-col"
            >
                <div
                    v-for="(msg, index) in threadMessages"
                    :key="msg.id"
                    class="border-b border-[var(--border-default)] last:border-0 flex flex-col last:flex-1"
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
                        :embedded="true"
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

        <!-- Anchored Attachments Bar (Clamped above Action Bar) -->
        <div
            v-if="
                props.email &&
                activeTab === 'read' &&
                visibleAttachments.length > 0
            "
            class="border-t border-(--border-default) bg-(--surface-secondary) backdrop-blur-sm"
        >
            <div
                class="px-4 py-2 flex items-center justify-between border-b border-(--border-default)/50"
            >
                <button
                    @click="isAttachmentsExpanded = !isAttachmentsExpanded"
                    class="text-[11px] font-bold tracking-tight text-(--text-primary) flex items-center gap-1.5 hover:text-(--interactive-primary) transition-colors select-none"
                >
                    <ChevronRightIcon
                        class="w-3.5 h-3.5 transition-transform duration-200"
                        :class="{ 'rotate-90': isAttachmentsExpanded }"
                    />
                    ATTACHMENTS
                    <span
                        class="inline-flex items-center justify-center px-1.5 py-0.5 rounded-full text-[10px] bg-red-500 text-white"
                    >
                        {{ visibleAttachments.length }}
                    </span>
                </button>

                <div class="flex items-center gap-3">
                    <button
                        v-if="selectedAttachments.size > 0"
                        @click="downloadSelected"
                        class="text-[10px] font-semibold text-(--interactive-primary) hover:underline"
                    >
                        {{
                            isDownloadingCloud
                                ? `Downloading ${downloadProgress.current}/${downloadProgress.total}...`
                                : hasCloudSelected
                                  ? "Download from Cloud"
                                  : "Download Selected"
                        }}
                        ({{ selectedAttachments.size }})
                    </button>
                    <button
                        @click="downloadAll"
                        class="text-[10px] font-semibold text-(--text-secondary) hover:text-(--text-primary) hover:underline"
                    >
                        Download All
                    </button>
                </div>
            </div>

            <div
                v-if="isAttachmentsExpanded"
                class="px-4 py-3 grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-[180px] overflow-y-auto scrollbar-thin"
            >
                <div
                    v-for="(att, idx) in visibleAttachments"
                    :key="att.id"
                    class="group relative flex items-center p-2 border border-(--border-default) rounded-lg bg-(--surface-primary) hover:bg-(--surface-tertiary) transition-all cursor-pointer select-none"
                    :class="{
                        'ring-1 ring-inset ring-(--interactive-primary) bg-(--interactive-primary)/5':
                            selectedAttachments.has(att.id),
                    }"
                    @click="handleAttachmentClick(att)"
                >
                    <div class="p-1.5 rounded-md bg-(--interactive-primary)/10">
                        <component
                            :is="getAttachmentIcon(att.type)"
                            class="w-4 h-4 text-(--interactive-primary)"
                        />
                    </div>
                    <div class="ml-2.5 flex-1 min-w-0 pr-6">
                        <p
                            class="text-[11px] font-medium text-(--text-primary) truncate"
                            :title="att.name"
                        >
                            {{ att.name }}
                        </p>
                        <p class="text-[10px] text-(--text-muted)">
                            {{ formatSize(att.size) }}
                            <span
                                v-if="att.is_downloaded === false"
                                class="ml-1 text-amber-500 opacity-80"
                                >(Cloud)</span
                            >
                        </p>
                    </div>

                    <!-- Individual Download/Select Checkbox -->
                    <div class="absolute right-2 flex items-center gap-1.5">
                        <button
                            v-if="att.is_downloaded === false"
                            @click.stop="downloadOnDemand(att)"
                            class="p-1 text-(--interactive-primary) hover:bg-(--interactive-primary)/10 rounded transition-colors"
                        >
                            <LoaderIcon
                                v-if="isDownloading[att.id]"
                                class="w-3 h-3 animate-spin"
                            />
                            <DownloadIcon v-else class="w-3 h-3" />
                        </button>
                        <input
                            type="checkbox"
                            :checked="selectedAttachments.has(att.id)"
                            @change.stop="toggleAttachment(att.id)"
                            @click.stop
                            class="rounded border-(--border-default) text-(--interactive-primary) focus:ring-(--interactive-primary)/50 h-3.5 w-3.5"
                        />
                    </div>
                </div>
            </div>
        </div>

        <!-- Docked Action Bar (Clamped on top of Tab Bar) -->
        <div
            v-if="props.email && activeTab === 'read'"
            class="px-4 py-2.5 border-t border-(--border-default) bg-(--surface-primary) flex items-center gap-3 overflow-x-auto"
        >
            <div class="flex items-center gap-1.5 flex-shrink-0">
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
                        Reply All
                    </button>
                    <button
                        @click="
                            openTab('forward', replyTargetEmail || props.email)
                        "
                        class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-md text-[11px] font-semibold text-(--text-primary) hover:bg-(--surface-tertiary) transition-all shadow-sm border border-transparent hover:border-(--border-default)"
                        title="Forward"
                    >
                        <ForwardIcon class="w-3.5 h-3.5" />
                        Forward
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

            <!-- Toggles (Moved from Right) -->
            <div class="flex items-center gap-1 flex-shrink-0">
                <!-- Star Action -->
                <button
                    @click="store.toggleStar(props.email.id)"
                    class="p-2 rounded-md transition-colors"
                    :class="
                        props.email.is_starred
                            ? 'text-amber-400 hover:bg-amber-400/10'
                            : 'text-(--text-secondary) hover:bg-(--surface-secondary) hover:text-(--text-primary)'
                    "
                    :title="props.email.is_starred ? 'Unstar' : 'Star'"
                >
                    <StarIcon
                        class="w-3.5 h-3.5"
                        :class="{ 'fill-current': props.email.is_starred }"
                    />
                </button>

                <!-- Read/Unread Action -->
                <button
                    @click="
                        store.markAsRead(props.email.id, !props.email.is_read)
                    "
                    class="p-2 text-(--text-secondary) hover:bg-(--surface-secondary) hover:text-(--text-primary) rounded-md transition-colors"
                    :title="
                        props.email.is_read ? 'Mark as Unread' : 'Mark as Read'
                    "
                >
                    <MailIcon v-if="props.email.is_read" class="w-3.5 h-3.5" />
                    <MailOpenIcon v-else class="w-3.5 h-3.5" />
                </button>

                <div class="w-px h-4 bg-(--border-default) mx-1"></div>

                <button
                    @click="deleteEmail"
                    class="p-2 text-(--text-secondary) hover:bg-(--color-error)/10 hover:text-(--color-error) rounded-md transition-colors"
                    title="Delete"
                >
                    <TrashIcon class="w-3.5 h-3.5" />
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
import { ref, watch, computed, markRaw, onMounted, onUnmounted } from "vue";
import {
    MailIcon,
    MailOpenIcon,
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
    ChevronRightIcon,
    StarIcon,
    MailIcon as MailUnreadIcon,
} from "lucide-vue-next";
import axios from "axios";
import EmailPreviewContent from "./EmailPreviewContent.vue";
import EmailInlineComposer from "./EmailInlineComposer.vue";
import Dropdown from "@/components/ui/Dropdown.vue";
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

function formatSize(bytes: any) {
    const b = parseInt(String(bytes), 10);
    if (isNaN(b) || b <= 0) return "";

    const k = 1024;
    const sizes = ["B", "KB", "MB", "GB", "TB"];
    const i = Math.floor(Math.log(b) / Math.log(k));

    // Safety check for index
    if (i < 0) return b + " B";
    if (i >= sizes.length)
        return (
            (b / Math.pow(k, sizes.length - 1)).toFixed(1) +
            " " +
            sizes[sizes.length - 1]
        );

    return parseFloat((b / Math.pow(k, i)).toFixed(1)) + " " + sizes[i];
}

const threadMessages = ref<any[]>([]);
const loadingThread = ref(false);
const replyTargetEmail = ref<Email | null>(null); // Specific email being replied to in thread

// Attachment State
const isAttachmentsExpanded = ref(
    localStorage.getItem("email_attachments_expanded") !== "false",
); // Default true
const selectedAttachments = ref(new Set<string>());
const isDownloading = ref<Record<string, boolean>>({});
const isDownloadingCloud = ref(false);
const downloadProgress = ref({ current: 0, total: 0 });

watch(isAttachmentsExpanded, (val) => {
    localStorage.setItem("email_attachments_expanded", String(val));
});

function toggleAttachment(id: string) {
    if (selectedAttachments.value.has(id)) {
        selectedAttachments.value.delete(id);
    } else {
        selectedAttachments.value.add(id);
    }
}

const hasCloudSelected = computed(() => {
    return Array.from(selectedAttachments.value).some((id) => {
        const att = visibleAttachments.value.find((a: any) => a.id === id);
        return att?.is_downloaded === false;
    });
});

function handleAttachmentClick(att: any) {
    const isImage =
        att.type?.startsWith("image/") ||
        /\.(jpg|jpeg|png|gif|webp)$/i.test(att.name);
    const isVideo =
        att.type?.startsWith("video/") || /\.(mp4|webm|ogg)$/i.test(att.name);

    if ((isImage || isVideo) && att.url) {
        // Collect all navigable media
        const mediaList = visibleAttachments.value.filter(
            (a: any) =>
                (a.type?.startsWith("image/") ||
                    /\.(jpg|jpeg|png|gif|webp)$/i.test(a.name) ||
                    a.type?.startsWith("video/") ||
                    /\.(mp4|webm|ogg)$/i.test(a.name)) &&
                a.url,
        );

        const index = mediaList.findIndex((a: any) => a.id === att.id);

        const sources = mediaList.map((a: any) => ({
            src: a.url,
            download: `/api/emails/attachments/${a.id}/download`,
            type:
                a.type?.startsWith("video/") ||
                /\.(mp4|webm|ogg)$/i.test(a.name)
                    ? "video"
                    : "image",
            name: a.name,
            size: a.size,
            id: a.id,
            canDelete: false, // Hide delete button for email attachments
        }));

        window.dispatchEvent(
            new CustomEvent("media-viewer:open", {
                detail: { media: sources, index },
            }),
        );
    } else {
        toggleAttachment(att.id);
    }
}

function deleteEmail() {
    if (!props.email) return;
    if (confirm("Are you sure you want to delete this email?")) {
        store.deleteEmails([props.email.id]);
        // Ideally navigate away or clear selection, handled by store/parent usually
    }
}

async function downloadOnDemand(att: any) {
    if (isDownloading.value[att.id]) return;
    isDownloading.value[att.id] = true;
    try {
        const res = await axios.post(
            `/api/emails/${props.email?.id}/attachments/${att.placeholder_index}/download`,
        );
        if (res.data.attachment) {
            // Force cache bust to ensure image loads if previously 404
            if (res.data.attachment.url) {
                res.data.attachment.url += `?t=${new Date().getTime()}`;
            }
            Object.assign(att, res.data.attachment);
        }
    } catch (e) {
        console.error("Download failed", e);
    } finally {
        isDownloading.value[att.id] = false;
    }
}

async function downloadSelected() {
    // Separate selected into cloud and local
    const selected = Array.from(selectedAttachments.value)
        .map((id) => visibleAttachments.value.find((a: any) => a.id === id))
        .filter(Boolean);

    const cloudItems = selected.filter((a: any) => a.is_downloaded === false);
    const localItems = selected.filter((a: any) => a.is_downloaded !== false);

    // Priority 1: Download from Cloud
    if (cloudItems.length > 0) {
        isDownloadingCloud.value = true;
        downloadProgress.value = { current: 0, total: cloudItems.length };

        // Trigger downloadOnDemand for each
        for (const att of cloudItems) {
            await downloadOnDemand(att);
            downloadProgress.value.current++;
        }

        isDownloadingCloud.value = false;
        return;
    }

    // Priority 2: Download Local (Zip or Single)
    if (localItems.length === 0) return;

    if (localItems.length === 1) {
        // Single file - direct download
        const att = localItems[0];
        if (att.url) {
            // Use API download endpoint to ensure headers force download/save dialog
            window.open(`/api/emails/attachments/${att.id}/download`, "_blank");
        }
    } else {
        // Multiple files - batch download as ZIP
        const ids = localItems.map((a: any) => a.id);
        const form = document.createElement("form");
        form.method = "POST";
        form.action = "/api/emails/attachments/download-batch";
        form.target = "_blank";

        const csrfToken = document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute("content");
        if (csrfToken) {
            const csrfInput = document.createElement("input");
            csrfInput.type = "hidden";
            csrfInput.name = "_token";
            csrfInput.value = csrfToken;
            form.appendChild(csrfInput);
        }

        ids.forEach((id: string) => {
            const input = document.createElement("input");
            input.type = "hidden";
            input.name = "ids[]";
            input.value = id;
            form.appendChild(input);
        });

        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }
}

function downloadAll() {
    visibleAttachments.value.forEach((att) => {
        if (att.url) window.open(att.url, "_blank");
    });
}

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

                const messageList = Array.isArray(messages)
                    ? messages
                    : messages?.data || [];

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
