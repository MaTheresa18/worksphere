<template>
    <div
        class="flex flex-col overflow-hidden"
        :class="[
            embedded ? 'w-full flex-1 min-h-full' : 'h-full',
            !isPopup && !embedded ? 'max-h-[calc(100vh-110px)]' : '',
        ]"
    >
        <!-- Header -->
        <!-- Default Header (Inline Mode) -->
        <!-- Default Header (Inline Mode) -->
        <div
            v-if="!isPopup"
            class="border-b border-(--border-default) bg-linear-to-b from-(--surface-secondary) to-transparent preview-animate-item transition-all duration-200"
            :class="isHeaderExpanded ? 'p-6' : 'p-3'"
        >
            <div class="flex justify-between items-start gap-4">
                <div class="flex items-center gap-3 flex-1 min-w-0">
                    <button
                        @click="isHeaderExpanded = !isHeaderExpanded"
                        class="p-1.5 rounded-lg hover:bg-(--surface-tertiary) text-(--text-secondary) transition-colors shrink-0"
                        :title="isHeaderExpanded ? 'Collapse' : 'Expand'"
                    >
                        <ChevronDownIcon
                            class="w-5 h-5 transition-transform duration-200"
                            :class="{ '-rotate-90': !isHeaderExpanded }"
                        />
                    </button>

                    <h1
                        v-if="isHeaderExpanded"
                        class="text-2xl font-semibold text-(--text-primary) leading-tight truncate"
                        :title="email.subject"
                    >
                        {{ email.subject }}
                    </h1>
                    <div v-else class="flex items-center gap-3 truncate">
                        <span
                            class="font-semibold text-sm text-(--text-primary) truncate"
                            >{{ email.from_name }}</span
                        >
                        <span
                            class="text-xs text-(--text-muted) px-2 py-0.5 rounded bg-(--surface-tertiary)"
                            >Subject</span
                        >
                        <span
                            class="text-sm text-(--text-secondary) truncate"
                            :title="email.subject"
                            >{{ email.subject }}</span
                        >
                    </div>
                </div>

                <div class="flex space-x-1 shrink-0">
                    <button
                        v-if="isHeaderExpanded"
                        class="p-2 text-(--text-secondary) hover:bg-(--surface-tertiary) hover:text-(--text-primary) rounded-lg transition-colors"
                        title="Show Details"
                        @click="showMetadata = !showMetadata"
                        :class="{
                            'bg-(--surface-tertiary) text-(--text-primary)':
                                showMetadata,
                        }"
                    >
                        <InfoIcon class="w-5 h-5" />
                    </button>
                    <button
                        class="p-2 text-(--text-secondary) hover:bg-(--surface-tertiary) hover:text-(--text-primary) rounded-lg transition-colors"
                        title="Print"
                        @click="printEmail"
                    >
                        <PrinterIcon class="w-5 h-5" />
                    </button>
                    <button
                        v-if="isHeaderExpanded"
                        class="p-2 text-(--text-secondary) hover:bg-(--surface-tertiary) hover:text-(--text-primary) rounded-lg transition-colors"
                        title="Expand"
                        @click="expandEmail"
                    >
                        <Maximize2Icon class="w-5 h-5" />
                    </button>
                    <div
                        v-else
                        class="flex items-center ml-2 pl-2 border-l border-(--border-default)"
                    >
                        <span
                            class="text-xs text-(--text-muted) whitespace-nowrap"
                            >{{ formatDate(email.date) }}</span
                        >
                    </div>
                </div>
            </div>

            <!-- Redesigned Metadata Panel (Gmail Style) -->
            <div
                v-if="showMetadata && isHeaderExpanded"
                class="mt-4 p-5 rounded-2xl bg-(--surface-secondary) border border-(--border-default) shadow-sm animate-in fade-in slide-in-from-top-2 duration-200"
            >
                <div class="flex justify-between items-start mb-4">
                    <h3 class="text-xs font-bold text-(--text-primary) uppercase tracking-wider">Original Message Details</h3>
                    <button 
                        @click="openShowOriginal"
                        class="text-[10px] font-bold text-(--interactive-primary) hover:underline flex items-center gap-1"
                    >
                        <FileCodeIcon class="w-3 h-3" />
                        SHOW ORIGINAL
                    </button>
                </div>

                <div class="grid grid-cols-[110px_1fr] gap-y-3 text-[13px] leading-relaxed">
                    <div class="text-(--text-muted) font-medium">Message ID</div>
                    <div class="text-(--text-primary) break-all font-mono text-[11px] select-all">{{ email.message_id }}</div>
                    
                    <div class="text-(--text-muted) font-medium">Created at</div>
                    <div v-if="email.date" class="text-(--text-primary)">
                        {{ format(new Date(email.date), 'EEE, MMM d, yyyy') }} at {{ format(new Date(email.date), 'h:mm a') }}
                        <span class="text-(--text-muted) ml-1">({{ formatDistanceToNow(new Date(email.date)) }} ago)</span>
                    </div>

                    <div class="text-(--text-muted) font-medium">From</div>
                    <div class="text-(--text-primary)">
                        <span class="font-semibold">{{ email.from_name }}</span>
                        <span class="text-(--text-muted) ml-1">&lt;{{ email.from_email }}&gt;</span>
                    </div>

                    <div class="text-(--text-muted) font-medium">To</div>
                    <div class="text-(--text-primary)">
                        <div v-for="recipient in email.to" :key="recipient.email" class="flex items-center gap-1">
                            <span v-if="recipient.name" class="font-medium">{{ recipient.name }}</span>
                            <span class="text-(--text-muted)">&lt;{{ recipient.email }}&gt;</span>
                        </div>
                    </div>

                    <div class="text-(--text-muted) font-medium">Subject</div>
                    <div class="text-(--text-primary) font-semibold">{{ email.subject }}</div>

                    <!-- Security Headers -->
                    <template v-if="authInfo.spf || authInfo.dkim || authInfo.dmarc">
                        <div class="col-span-2 my-1 border-t border-(--border-default)/50"></div>

                        <div class="text-(--text-muted) font-medium">SPF</div>
                        <div class="flex items-center gap-2">
                            <span 
                                class="px-1.5 py-0.5 rounded text-[10px] font-bold uppercase tracking-tight"
                                :class="authInfo.spf === 'pass' ? 'bg-green-500/10 text-green-600' : 'bg-(--surface-tertiary) text-(--text-muted)'"
                            >
                                {{ authInfo.spf || 'NEUTRAL' }}
                            </span>
                            <span v-if="authInfo.spfDetails" class="text-xs text-(--text-secondary)">{{ authInfo.spfDetails }}</span>
                        </div>

                        <div class="text-(--text-muted) font-medium">DKIM</div>
                        <div class="flex items-center gap-2">
                            <span 
                                class="px-1.5 py-0.5 rounded text-[10px] font-bold uppercase tracking-tight"
                                :class="authInfo.dkim === 'pass' ? 'bg-green-500/10 text-green-600' : 'bg-(--surface-tertiary) text-(--text-muted)'"
                            >
                                {{ authInfo.dkim || 'NEUTRAL' }}
                            </span>
                            <span v-if="authInfo.dkimDetails" class="text-xs text-(--text-secondary)">{{ authInfo.dkimDetails }}</span>
                        </div>

                        <div class="text-(--text-muted) font-medium">DMARC</div>
                        <div class="flex items-center gap-2">
                            <span 
                                class="px-1.5 py-0.5 rounded text-[10px] font-bold uppercase tracking-tight"
                                :class="authInfo.dmarc === 'pass' ? 'bg-green-500/10 text-green-600' : 'bg-(--surface-tertiary) text-(--text-muted)'"
                            >
                                {{ authInfo.dmarc || 'NEUTRAL' }}
                            </span>
                        </div>
                    </template>
                </div>
            </div>

            <div
                v-if="isHeaderExpanded"
                class="mt-4 flex items-center justify-between"
            >
                <div class="flex items-center">
                    <div class="relative">
                        <img
                            :src="`https://ui-avatars.com/api/?name=${encodeURIComponent(email.from_name)}&background=6366f1&color=fff`"
                            class="w-11 h-11 rounded-full ring-2 ring-(--border-default) ring-offset-2 ring-offset-(--surface-primary)"
                            alt=""
                        />
                        <div
                            class="absolute -bottom-0.5 -right-0.5 w-3.5 h-3.5 bg-green-500 rounded-full border-2 border-(--surface-primary)"
                        ></div>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-semibold text-(--text-primary)">
                            {{ email.from_name }}
                        </p>
                        <p class="text-xs text-(--text-muted)">
                            <span>&lt;{{ email.from_email }}&gt;</span>
                        </p>
                    </div>
                </div>
                <div class="text-right">
                    <p class="text-sm text-(--text-secondary)">
                        {{ formatDate(email.date) }}
                    </p>
                    <p class="text-xs text-(--text-muted)">
                        {{ formatRelative(email.date) }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Popup Header -->
        <div
            v-else
            class="p-4 border-b border-(--border-default) bg-(--surface-primary) sticky top-0 z-10 shadow-sm"
        >
            <div class="flex justify-between items-start mb-4">
                <h1
                    class="text-xl font-semibold text-(--text-primary) leading-tight truncate pr-4"
                >
                    {{ email.subject }}
                </h1>
                <div class="flex items-center gap-2">
                    <button
                        @click="printEmail"
                        class="p-2 text-(--text-secondary) hover:bg-(--surface-secondary) rounded-lg"
                        title="Print"
                    >
                        <PrinterIcon class="w-4 h-4" />
                    </button>
                </div>
            </div>

            <!-- Meta & Actions Row -->
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <img
                        :src="`https://ui-avatars.com/api/?name=${encodeURIComponent(email.from_name)}&background=6366f1&color=fff`"
                        class="w-8 h-8 rounded-full"
                        alt=""
                    />
                    <div>
                        <div class="text-sm font-medium text-(--text-primary)">
                            {{ email.from_name }}
                        </div>
                        <div class="text-xs text-(--text-secondary)">
                            {{ formatDate(email.date) }}
                        </div>
                    </div>
                </div>

                <div class="flex gap-2">
                    <button
                        @click="emit('reply')"
                        class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-(--interactive-primary) text-white hover:bg-(--interactive-primary)/90 transition-colors"
                    >
                        <ReplyIcon class="w-3.5 h-3.5" /> Reply
                    </button>
                    <button
                        @click="emit('reply-all')"
                        class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium border border-(--border-default) text-(--text-primary) hover:bg-(--surface-secondary) transition-colors"
                    >
                        <ReplyAllIcon class="w-3.5 h-3.5" /> Reply All
                    </button>
                    <button
                        @click="emit('forward')"
                        class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium border border-(--border-default) text-(--text-primary) hover:bg-(--surface-secondary) transition-colors"
                    >
                        <ForwardIcon class="w-3.5 h-3.5" /> Forward
                    </button>
                    <div class="h-4 w-px bg-(--border-default) mx-1"></div>
                    <button
                        @click="exportAsEml"
                        class="p-1.5 text-(--text-secondary) hover:bg-(--surface-secondary) rounded-lg transition-colors"
                        title="Export .eml"
                    >
                        <DownloadIcon class="w-4 h-4" />
                    </button>
                </div>
            </div>
        </div>

        <!-- Body -->
        <div
            class="flex-1 preview-animate-item"
            :class="[
                embedded
                    ? 'p-0 overflow-visible'
                    : 'p-6 overflow-auto scrollbar-thin',
            ]"
        >
            <!-- Security / Trust Banner -->
            <div
                v-if="(!isTrustedSource && !isUntrustedDismissed) || hasBlockedImages"
                class="mb-6 space-y-4 p-3"
            >
                <div
                    v-if="!isTrustedSource && !isUntrustedDismissed"
                    class="rounded-xl bg-amber-500/10 border border-amber-500/20 p-4 flex items-start gap-4 relative group"
                >
                    <AlertTriangleIcon
                        class="w-5 h-5 text-amber-500 shrink-0 mt-0.5"
                    />
                    <div class="flex-1">
                        <p class="text-sm font-medium text-amber-500">
                            Untrusted Source
                        </p>
                        <p class="text-xs text-amber-500/80 mt-1">
                            Use caution with links and attachments.
                        </p>
                    </div>
                    <button 
                        @click="dismissUntrusted"
                        class="text-amber-500/60 hover:text-amber-500 hover:bg-amber-500/10 p-1.5 rounded-lg transition-colors"
                        title="Dismiss for this email"
                    >
                        <XIcon class="w-4 h-4" />
                    </button>
                </div>

                <div
                    v-if="hasBlockedImages"
                    class="rounded-xl bg-(--surface-secondary) border border-(--border-default) p-4 flex items-center justify-between gap-4 shadow-sm animate-in fade-in slide-in-from-top-2 duration-300"
                >
                    <div class="flex items-center gap-3">
                        <ImageIcon
                            class="w-5 h-5 text-(--interactive-primary)"
                        />
                        <div class="text-sm text-(--text-secondary)">
                            Tracking images are hidden for your privacy.
                        </div>
                    </div>
                    <button
                        @click="showImages = true"
                        class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-(--surface-elevated) border border-(--border-default) text-(--text-primary) hover:border-(--interactive-primary)/30 transition-all shadow-sm"
                    >
                        Show Images
                    </button>
                </div>
            </div>

            <!-- Loading State -->
            <div v-if="loadingBody" class="flex flex-col items-center justify-center py-20 animate-in fade-in duration-300">
                <div class="w-8 h-8 border-3 border-(--surface-tertiary) border-t-(--interactive-primary) rounded-full animate-spin"></div>
                <p class="mt-3 text-sm text-(--text-muted)">Loading content...</p>
            </div>

            <!-- Email Content (Shadow DOM for Style Isolation) -->
            <div
                v-else
                class="email-content-wrapper w-full flex-1 overflow-hidden"
                ref="shadowHost"
            ></div>
        </div>
    </div>

    <!-- External Link Warning Modal -->
    <Modal
        v-model:open="showLinkWarning"
        title="Security Warning"
        description="You are about to leave the application and open an external link."
        size="md"
    >
        <div class="flex flex-col items-center py-4 text-center">
            <div class="p-3 rounded-full bg-amber-500/10 mb-4 animate-pulse">
                <ShieldAlertIcon class="w-12 h-12 text-amber-500" />
            </div>

            <h3 class="text-lg font-semibold text-(--text-primary) mb-2">
                Leaving WorkSphere
            </h3>
            <p class="text-sm text-(--text-secondary) mb-6 max-w-sm">
                For your security, please verify that you trust this link before
                proceeding. External links can sometimes lead to malicious
                websites.
            </p>

            <div
                class="w-full p-3 rounded-xl bg-(--surface-secondary) border border-(--border-default) mb-6 overflow-hidden"
            >
                <p
                    class="text-xs font-mono text-(--text-primary) break-all text-left line-clamp-2"
                    :title="pendingLink"
                >
                    {{ pendingLink }}
                </p>
            </div>
        </div>

        <template #footer>
            <div class="flex gap-3 w-full">
                <Button
                    variant="ghost"
                    class="flex-1"
                    @click="showLinkWarning = false"
                    >Cancel</Button
                >
                <Button variant="primary" class="flex-1" @click="proceedToLink">
                    Proceed to Link
                    <ExternalLinkIcon class="w-4 h-4 ml-2" />
                </Button>
            </div>
        </template>
    </Modal>

    <!-- Show Original Modal -->
    <Modal
        v-model:open="showOriginalModal"
        title="Original Message"
        size="xl"
    >
        <div class="bg-(--surface-secondary) p-4 rounded-xl border border-(--border-default) overflow-hidden h-[70vh] flex flex-col">
            <div class="flex items-center justify-between mb-4 shrink-0">
                <span class="text-xs font-semibold text-(--text-muted) uppercase tracking-wider">Raw Message Source (RFC822)</span>
                <Button variant="ghost" size="sm" @click="copyToClipboard(rawSource)" :disabled="loadingSource || !rawSource">
                    Copy to Clipboard
                </Button>
            </div>
            
            <div v-if="loadingSource" class="flex-1 flex items-center justify-center">
                 <div class="w-8 h-8 border-3 border-(--surface-tertiary) border-t-(--interactive-primary) rounded-full animate-spin"></div>
            </div>
            <pre v-else class="flex-1 overflow-auto text-[11px] font-mono text-(--text-secondary) leading-relaxed select-all whitespace-pre-wrap break-all"><code>{{ rawSource }}</code></pre>
        </div>
    </Modal>
</template>

<script setup lang="ts">
import {
    computed,
    ref,
    watch,
    nextTick,
    onBeforeUnmount,
    onMounted,
} from "vue";
import {
    PrinterIcon,
    Maximize2Icon,
    FileIcon,
    AlertTriangleIcon,
    ReplyIcon,
    ForwardIcon,
    TrashIcon,
    MoreHorizontalIcon,
    ReplyAllIcon,
    ImageIcon,
    ChevronRightIcon,
    ChevronDownIcon,
    InfoIcon,
    PaperclipIcon,
    DownloadIcon,
    ExternalLinkIcon,
    ShieldAlertIcon,
    XIcon,
    FileCodeIcon,
} from "lucide-vue-next";
import { format, formatDistanceToNow } from "date-fns";
import type { Email } from "@/types/models/email";
import { animate, stagger } from "animejs";
import { sanitizeHtml } from "@/utils/sanitize";
import { useEmailStore } from "@/stores/emailStore";
import Modal from "@/components/ui/Modal.vue";
import Button from "@/components/ui/Button.vue";

const props = defineProps<{
    email: Email;
    isPopup?: boolean;
    embedded?: boolean;
}>();

const store = useEmailStore();

const emit = defineEmits<{
    reply: [];
    "reply-all": [];
    forward: [];
    "forward-as-attachment": [];
}>();

// --- Security & Privacy ---
const showImages = ref(false);
const hasBlockedImages = ref(false);
const selectedAttachments = ref<Set<string>>(new Set());
const isAttachmentsExpanded = ref(false);
const isHeaderExpanded = ref(
    localStorage.getItem("email_header_expanded") !== "false",
);

watch(isHeaderExpanded, (val) => {
    localStorage.setItem("email_header_expanded", String(val));
});
const showMetadata = ref(false);
const showOriginalModal = ref(false);
const shadowHost = ref<HTMLElement | null>(null);
const shadowRoot = ref<ShadowRoot | null>(null);

// Authentication Header Parsing
const authInfo = computed(() => {
    const headers = props.email.headers || {};
    // authentication-results header is the standard way to check SPF/DKIM/DMARC
    const authResults = headers['authentication-results'] || headers['Authentication-Results'] || '';
    
    // Extract SPF
    const spfMatch = authResults.match(/spf=([a-z]+)/i) || [];
    const spfDetailsMatch = authResults.match(/spf=[a-z]+\s+\(([^)]+)\)/i) || [];
    
    // Extract DKIM
    const dkimMatch = authResults.match(/dkim=([a-z]+)/i) || [];
    const dkimDetailsMatch = authResults.match(/dkim=[a-z]+\s+header\.d=([^\s;]+)/i) || [];
    
    // Extract DMARC
    const dmarcMatch = authResults.match(/dmarc=([a-z]+)/i) || [];

    return {
        spf: spfMatch[1]?.toLowerCase(),
        spfDetails: spfDetailsMatch[1],
        dkim: dkimMatch[1]?.toLowerCase(),
        dkimDetails: dkimDetailsMatch[1],
        dmarc: dmarcMatch[1]?.toLowerCase()
    };
});

const rawSource = ref("");
const loadingSource = ref(false);

async function openShowOriginal() {
    showOriginalModal.value = true;
    
    // If we already have it (maybe cache locally too? nah, allow refetch for now or rely on browser/redis)
    if (rawSource.value && rawSource.value !== "Failed to load original message source.") return;

    loadingSource.value = true;
    rawSource.value = ""; 

    try {
        const response = await fetch(`/api/emails/${props.email.id}/source`, {
             headers: {
                 "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "",
                 Accept: "application/json",
             },
        });
        if (!response.ok) throw new Error("Failed to fetch source");
        const data = await response.json();
        rawSource.value = data.source;
    } catch (e) {
        console.error("Failed to fetch source", e);
        rawSource.value = "Failed to load original message source.";
    } finally {
        loadingSource.value = false;
    }
}

function copyToClipboard(text: string) {
    if (!text) return;
    navigator.clipboard.writeText(text).then(() => {
        // Maybe a toast here? For now just assume it works
    });
}

// External Link Warning
const showLinkWarning = ref(false);
const pendingLink = ref("");

// On-Demand Downloading State
const isDownloading = ref<Record<string, boolean>>({});

async function downloadOnDemand(att: any, index: number) {
    if (isDownloading.value[att.id]) return;

    isDownloading.value[att.id] = true;
    try {
        const response = await fetch(
            `/api/emails/${props.email.id}/attachments/${att.placeholder_index}/download`,
            {
                method: "POST",
                headers: {
                    "X-CSRF-TOKEN":
                        document
                            .querySelector('meta[name="csrf-token"]')
                            ?.getAttribute("content") || "",
                    Accept: "application/json",
                },
            },
        );

        if (!response.ok) throw new Error("Download failed");

        const data = await response.json();

        // Update the attachment in the local list with the real media data
        if (data.attachment) {
            Object.assign(att, data.attachment);
            // Optionally auto-open or just show success state?
            // For now, it will just update UI to show as downloaded
        }
    } catch (e) {
        console.error("Failed to download attachment", e);
        // Toast notification would be good here
    } finally {
        isDownloading.value[att.id] = false;
    }
}

// On-Demand Body Fetching
const loadingBody = ref(false);

async function checkAndFetchBody() {
    if (!props.email) return;

    // If we have no body content, fetch it
    if (!props.email.body_html && !props.email.body_plain) {
        loadingBody.value = true;
        try {
             const data = await store.fetchEmailBody(props.email.id);
             if (data && (data.body_html || data.body_plain)) {
                 // Update local object to trigger reactivity in this component
                 props.email.body_html = data.body_html;
                 props.email.body_plain = data.body_plain;
             }
        } catch (e) {
            console.error("Failed to fetch email body", e);
        } finally {
            loadingBody.value = false;
        }
    } else {
        loadingBody.value = false;
    }
}

// Reset image state when email changes
watch(
    () => props.email?.id,
    () => {
        showImages.value = false;
        hasBlockedImages.value = false;
        selectedAttachments.value.clear();
        checkAndFetchBody();
    },
    { immediate: true }
);

const visibleAttachments = computed(() => {
    if (!props.email?.attachments) return [];
    return props.email.attachments.filter((att: any) => !att.is_inline);
});

// Check if any attachments are still in cloud (placeholders)
const hasPlaceholderAttachments = computed(() => {
    if (!visibleAttachments.value.length) return false;
    return visibleAttachments.value.some(
        (att: any) => att.is_downloaded === false,
    );
});

// Check if all selected attachments are downloaded
const allSelectedDownloaded = computed(() => {
    if (selectedAttachments.value.size === 0) return true;
    return Array.from(selectedAttachments.value).every((id) => {
        const att = visibleAttachments.value.find((a: any) => a.id === id);
        return (att as any)?.is_downloaded !== false;
    });
});

const sanitizedBody = computed(() => {
    if (!props.email?.body_html) return "";

    // Step 1: Replace cid: references with actual attachment URLs (Fallback for existing emails)
    let html = props.email.body_html;
    if (props.email.attachments?.length) {
        props.email.attachments.forEach((att) => {
            if (att.content_id && att.url) {
                const escapedCid = att.content_id.replace(
                    /[.*+?^${}()|[\]\\]/g,
                    "\\$&",
                );
                const cleanCid = escapedCid.replace(/^<|>$/g, "");

                // Matches src="cid:...", src='cid:...', src=\"cid:...\", or src=cid:...
                const patterns = [
                    new RegExp(
                        `src=[\\\\"]*cid:(?:&lt;|<)?${cleanCid}(?:&gt;|>)?([\\\\"]*)`,
                        "gi",
                    ),
                    new RegExp(
                        `src=[']cid:(?:&lt;|<)?${cleanCid}(?:&gt;|>)?[']`,
                        "gi",
                    ),
                ];

                patterns.forEach((pattern) => {
                    html = html.replace(pattern, `src="${att.url}"$1`);
                });
            }
        });
    }

    // Step 2: Sanitize with DOMPurify
    const clean = sanitizeHtml(
        html,
        {
            USE_PROFILES: { html: true },
            ADD_TAGS: ["img"],
            ADD_ATTR: ["src", "alt", "style", "data-original-src"],
        },
        {
            beforeSanitizeElements: (currentNode) => {
                if (currentNode instanceof HTMLImageElement) {
                    // Support both 'src' and 'data-original-src' (from backend sanitization)
                    let src = currentNode.getAttribute("src") || "";
                    const originalSrc =
                        currentNode.getAttribute("data-original-src");

                    if (!src && originalSrc) {
                        src = originalSrc;
                    }

                    // Determine if source is external
                    const isExternal =
                        src &&
                        !src.startsWith("data:") &&
                        !src.startsWith("blob:") &&
                        !src.startsWith("cid:") &&
                        !src.startsWith(window.location.origin) &&
                        !src.startsWith("/") &&
                        !src.includes("/storage/") &&
                        !src.includes("/api/media/");

                    if (isExternal) {
                        if (showImages.value) {
                            // User clicked "Show Images" - restore the source
                            currentNode.setAttribute("src", src);
                            currentNode.removeAttribute("data-original-src");
                        } else {
                            // Block external images - show placeholder
                            currentNode.setAttribute("data-blocked-src", src);
                            currentNode.setAttribute(
                                "src",
                                'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="200" height="100" viewBox="0 0 200 100"%3E%3Crect fill="%23e5e7eb" width="200" height="100"/%3E%3Ctext x="50%25" y="50%25" dominant-baseline="middle" text-anchor="middle" fill="%236b7280" font-family="sans-serif" font-size="12"%3EImage blocked%3C/text%3E%3C/svg%3E',
                            );
                            currentNode.setAttribute("data-blocked", "true");
                            currentNode.style.maxWidth = "200px";
                            currentNode.style.border = "1px dashed #d1d5db";
                            currentNode.style.borderRadius = "4px";
                        }
                    }
                }
                return currentNode;
            },
        },
    );

    return clean;
});

// We need to update `hasBlockedImages` separately to avoid computed side-effects
// Image Analysis
const imageAnalysis = computed(() => {
    if (!props.email?.body_html) return { hasCid: false, hasExternal: false };

    const html = props.email.body_html;
    // Check for CID images in <img> tags
    const hasCid = /<img[^>]+src=["']cid:/i.test(html);

    // Check for external images in <img> tags (http/https or proto-relative)
    // We target only img tags and ignore data: / blob: / cid: paths
    // We also exclude local origin URLs to avoid false positives
    const hasExternal =
        /<img[^>]+src=["'](https?:\/\/|\/\/)/i.test(html) &&
        !html.includes('src="' + window.location.origin);

    return { hasCid, hasExternal };
});

watch(
    [() => props.email?.id, () => imageAnalysis.value, () => showImages.value],
    () => {
        // We only block if there are EXTERNAL images and the user hasn't opted to show them
        if (imageAnalysis.value.hasExternal && !showImages.value) {
            hasBlockedImages.value = true;
        } else {
            hasBlockedImages.value = false;
        }
    },
    { immediate: true },
);

const isTrustedSource = computed(() => {
    // For now, trust the user's domain and common providers, or just return true to unblock UI
    if (!props.email?.from_email) return false;
    const trustedDomains = [
        "link-technologies.info",
        "gmail.com",
        "outlook.com",
        "hotmail.com",
    ];
    const emailDomain = props.email.from_email.split("@")[1];
    return trustedDomains.includes(emailDomain);
});

// Untrusted Source Dismissal
const isUntrustedDismissed = ref(false);

function checkDismissedState() {
    if (!props.email?.id) return;
    const key = `untrusted_dismissed_${props.email.id}`;
    isUntrustedDismissed.value = localStorage.getItem(key) === 'true';
}

function dismissUntrusted() {
    if (!props.email?.id) return;
    const key = `untrusted_dismissed_${props.email.id}`;
    localStorage.setItem(key, 'true');
    isUntrustedDismissed.value = true;
}

watch(() => props.email?.id, checkDismissedState, { immediate: true });

// --- Shadow DOM Injection ---
watch(
    [() => sanitizedBody.value, () => shadowHost.value, () => showImages.value],
    async ([html, host]) => {
        if (!host || !html) return;

        // Initialize Shadow DOM if not already done
        if (!shadowRoot.value) {
            shadowRoot.value = host.attachShadow({ mode: "open" });
        }

        // Reset Styles & Default Styles for Email Content (Force Light Theme like Gmail)
        const style = `
            :host {
                display: block !important;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif !important;
                color: #1f2937 !important; /* Force dark text */
                background-color: #ffffff !important; /* Force white background */
                line-height: 1.5 !important;
                overflow-wrap: break-word !important; /* Prevent horizontal overflow */
                word-wrap: break-word !important;
                text-align: left !important;
                min-height: 100% !important; /* Ensure host takes full height */
            }
            #email-body {
                padding: 24px !important;
                min-height: 100% !important;
                color: #1f2937 !important;
                background-color: #ffffff !important;
            }
                color: #1f2937 !important;
                background-color: #ffffff !important;
            }
            /* Reset all colors to inherit from our host unless explicitly set in the style tag */
            * {
                color: inherit;
            }
            #email-body * {
                box-sizing: border-box !important;
            }
            img, video, iframe, svg { max-width: 100%; height: auto; }
            a { color: #2563eb !important; text-decoration: underline !important; }
            blockquote { margin: 1em 0; border-left: 4px solid #e5e7eb; padding-left: 1em; color: #6b7280 !important; }
            pre { background: #f3f4f6; padding: 1em; overflow-x: auto; border-radius: 0.5em; color: #1f2937 !important; }
            p { margin-bottom: 1em; }
            
            /* Scrollbar styling for shadow DOM - keeping it subtle */
            ::-webkit-scrollbar { width: 8px; height: 8px; }
            ::-webkit-scrollbar-track { background: transparent; }
            ::-webkit-scrollbar-thumb { background-color: #d1d5db; border-radius: 4px; }
        `;

        // Inject content
        if (shadowRoot.value) {
            shadowRoot.value.innerHTML = `<style>${style}</style><div id="email-body">${html}</div>`;

            // Adjust height to fit content
            nextTick(() => {
                // ResizeObserver removed to rely on natural CSS block layout.
                // The host will size to content because :host { display: block }.

                // Make all links open in new tab (safety fallback)

                // Make all links open in new tab (safety fallback)
                shadowRoot.value?.querySelectorAll("a").forEach((link) => {
                    link.setAttribute("target", "_blank");
                    link.setAttribute("rel", "noopener noreferrer");
                });

                // Add click listener for external link warning
                shadowRoot.value?.addEventListener("click", handleLinkClick);

                // Add click listener for Image Preview (MediaViewer)
                shadowRoot.value?.addEventListener("click", handleImageClick);
            });
        }
    },
    { immediate: true },
);

function handleImageClick(event: MouseEvent) {
    const target = event.target as HTMLElement;
    if (target.tagName === "IMG") {
        const img = target as HTMLImageElement;

        // Skip placeholders or blocked images
        if (img.getAttribute("data-blocked") === "true") return;

        // Skip tiny icons/tracking pixels
        if (img.width < 20 || img.height < 20) return;

        // Collect all valid images in the shadow DOM for the gallery
        const allImages = Array.from(
            shadowRoot.value?.querySelectorAll("img") || [],
        ).filter(
            (i) =>
                i.width >= 20 &&
                i.height >= 20 &&
                i.getAttribute("data-blocked") !== "true",
        );

        const index = allImages.indexOf(img);

        const media = allImages.map((i) => ({
            src: i.src, // This corresponds to the resolved URL (including CID pointers)
            download: i.src,
            type: "image",
        }));

        // Dispatch event for Global MediaViewer
        window.dispatchEvent(
            new CustomEvent("media-viewer:open", {
                detail: { media, index },
            }),
        );
    }
}

function handleLinkClick(event: MouseEvent) {
    const target = event.target as HTMLElement;
    const link = target.closest("a");
    if (link) {
        const href = link.getAttribute("href");
        // Intercept all external links (zero-trust)
        // We skip anchors and common non-web schemes
        if (
            href &&
            !href.startsWith("#") &&
            !href.startsWith("mailto:") &&
            !href.startsWith("tel:")
        ) {
            event.preventDefault();
            pendingLink.value = href;
            showLinkWarning.value = true;
        }
    }
}

function proceedToLink() {
    if (pendingLink.value) {
        window.open(pendingLink.value, "_blank");
        showLinkWarning.value = false;
        pendingLink.value = "";
    }
}

function formatDate(dateStr: string) {
    if (!dateStr) return "";
    const date = new Date(dateStr);
    if (isNaN(date.getTime())) return "Invalid Date";
    return format(date, "MMM d, yyyy, h:mm a");
}

function formatRelative(dateStr: string) {
    if (!dateStr) return "";
    const date = new Date(dateStr);
    if (isNaN(date.getTime())) return "Unknown Date";
    return formatDistanceToNow(date, { addSuffix: true });
}

// --- Attachments ---

function toggleAttachment(id: string) {
    if (selectedAttachments.value.has(id)) {
        selectedAttachments.value.delete(id);
    } else {
        selectedAttachments.value.add(id);
    }
}

function downloadSelected() {
    const selected = props.email.attachments.filter((a) =>
        selectedAttachments.value.has(a.id),
    );
    if (selected.length === 0) return;

    if (selected.length === 1) {
        // Single file - direct download
        window.open(
            `/api/emails/attachments/${selected[0].id}/download`,
            "_blank",
        );
    } else {
        // Multiple files - batch download as ZIP
        const ids = selected.map((a) => a.id);
        const form = document.createElement("form");
        form.method = "POST";
        form.action = "/api/emails/attachments/download-batch";
        form.target = "_blank";

        // Add CSRF token
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

        // Add IDs
        ids.forEach((id) => {
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
    if (!props.email.attachments?.length) return;

    if (props.email.attachments.length === 1) {
        window.open(
            `/api/emails/attachments/${props.email.attachments[0].id}/download`,
            "_blank",
        );
    } else {
        const ids = props.email.attachments.map((a) => a.id);
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

        ids.forEach((id) => {
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

// --- Print ---
// --- Print ---
function printEmail() {
    if (props.isPopup) {
        window.print();
        return;
    }

    const printWindow = window.open("", "_blank");
    if (!printWindow) return;

    const email = props.email;
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>${email.subject}</title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; padding: 40px; max-width: 800px; margin: 0 auto; }
                .header { border-bottom: 1px solid #ddd; padding-bottom: 20px; margin-bottom: 20px; }
                .subject { font-size: 24px; font-weight: 600; margin-bottom: 10px; }
                .meta { color: #666; font-size: 14px; }
                .meta div { margin: 4px 0; }
                .body { line-height: 1.6; }
                @media print { body { padding: 20px; } }
            </style>
        </head>
        <body>
            <div class="header">
                <div class="subject">${email.subject}</div>
                <div class="meta">
                    <div><strong>From:</strong> ${email.from_name || ""} &lt;${email.from_email}&gt;</div>
                    <div><strong>Date:</strong> ${formatDate(email.date)}</div>
                    <div><strong>To:</strong> ${email.to?.map((t) => t.email).join(", ") || ""}</div>
                </div>
            </div>
            <div class="body">${email.body_html || email.body_plain || ""}</div>
        </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.print();
}

// --- Expand to Popup ---
function expandEmail() {
    const width = 900;
    const height = 700;
    const left = (window.screen.width - width) / 2;
    const top = (window.screen.height - height) / 2;

    window.open(
        `/email/popup/${props.email.id}`,
        "_blank",
        `width=${width},height=${height},left=${left},top=${top},resizable=yes,scrollbars=yes`,
    );
}

// --- Export as EML ---
function exportAsEml() {
    window.open(`/api/emails/${props.email.id}/export`, "_blank");
}

// --- Animation ---
let animation: any = null;

function runEnterAnimation() {
    if (!props.email) return;
    if (animation) animation.pause();

    const targets = document.querySelectorAll(".preview-animate-item");
    if (targets.length === 0) return;

    animation = animate(targets, {
        opacity: [0, 1],
        translateY: [15, 0],
        delay: stagger(80),
        duration: 350,
        easing: "easeOutQuad",
    });
}

watch(
    () => props.email,
    () => {
        nextTick(() => runEnterAnimation());
    },
);

onMounted(() => {
    if (props.email) {
        nextTick(() => runEnterAnimation());
    }
});

onBeforeUnmount(() => {
    if (animation) animation.pause();
});
</script>
