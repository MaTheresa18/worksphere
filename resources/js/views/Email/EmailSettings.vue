<script setup lang="ts">
import { ref, computed, watch, onMounted } from "vue";
import {
    PenToolIcon,
    UsersIcon,
    HardDriveIcon,
    PlusIcon,
    Trash2Icon,
    MailIcon,
    FileTextIcon,
    ImageIcon,
    SearchIcon,
    SettingsIcon,
    CheckIcon,
    AlertCircleIcon,
    ChevronRightIcon,
    Loader2,
    ArrowLeftIcon,
} from "lucide-vue-next";
import { Button, Card, Input } from "@/components/ui";
import { useEmailSignatures } from "./composables/useEmailSignatures";
import { useEmailTemplates } from "./composables/useEmailTemplates";
import { useEditor, EditorContent } from "@tiptap/vue-3";
import EmailAccountsSection from "@/components/settings/EmailAccountsSection.vue";
import { emailAccountService } from "@/services/email-account.service";
import { RichTextEditor } from "@/components/ui";
import { useDebounceFn } from "@vueuse/core";
import api from "@/lib/api";

// Auto-save debounced handlers
const debouncedSaveSignature = useDebounceFn(() => {
    handleSaveSignature();
}, 1000);

const debouncedSaveTemplate = useDebounceFn(() => {
    handleSaveTemplate();
}, 1000);

const tabs = [
    {
        id: "signatures",
        label: "Signatures",
        icon: PenToolIcon,
        description: "Manage email signatures",
    },
    {
        id: "templates",
        label: "Templates",
        icon: FileTextIcon,
        description: "Pre-defined email responses",
    },
    {
        id: "accounts",
        label: "Connected Accounts",
        icon: UsersIcon,
        description: "Manage email providers",
    },
    {
        id: "storage",
        label: "Storage Usage",
        icon: HardDriveIcon,
        description: "View storage quotas",
    },
];

const activeTab = ref("signatures");
const searchQuery = ref("");

// --- Signatures Logic ---
const {
    signatures,
    selectedSignatureId,
    fetchSignatures,
    addSignature,
    updateSignature,
    deleteSignature,
} = useEmailSignatures();

const activeSignature = computed(() =>
    signatures.value.find((s) => s.id === selectedSignatureId.value),
);

const filteredSignatures = computed(() => {
    if (!searchQuery.value) return signatures.value;
    return signatures.value.filter((s) =>
        s.name.toLowerCase().includes(searchQuery.value.toLowerCase()),
    );
});

const signatureName = ref("");
// Editor ref
const signatureEditorRef = ref<any>(null);
const signatureContent = ref("");

// Save State
const saveStatus = ref<"saved" | "saving" | "error">("saved");

// Watch for selection change to update form
watch(selectedSignatureId, (newId) => {
    if (newId && activeSignature.value) {
        signatureName.value = activeSignature.value.name;
        signatureContent.value = activeSignature.value.content || "";
        saveStatus.value = "saved";
    } else {
        signatureName.value = "";
        signatureContent.value = "";
        saveStatus.value = "saved";
    }
});

async function handleNewSignature() {
    const newSig = await addSignature({ name: "New Signature", content: "" });
    selectedSignatureId.value = newSig.id;
    saveStatus.value = "saved";
}

async function handleSaveSignature() {
    if (!selectedSignatureId.value) return;
    saveStatus.value = "saving";
    try {
        await updateSignature(selectedSignatureId.value, {
            name: signatureName.value,
            content: activeSignature.value?.content || "",
        });
        saveStatus.value = "saved";
    } catch (e) {
        saveStatus.value = "error";
    }
}

function handleDeleteSignature(id: string) {
    if (confirm("Delete this signature?")) {
        deleteSignature(id);
        if (selectedSignatureId.value === id) {
            selectedSignatureId.value =
                signatures.value.length > 0 ? signatures.value[0].id : null;
        }
    }
}

// --- Templates Logic ---
const {
    templates,
    selectedTemplateId,
    fetchTemplates,
    addTemplate,
    updateTemplate,
    deleteTemplate,
} = useEmailTemplates();

const activeTemplate = computed(() =>
    templates.value.find((t) => t.id === selectedTemplateId.value),
);

const filteredTemplates = computed(() => {
    if (!searchQuery.value) return templates.value;
    return templates.value.filter(
        (t) =>
            t.name.toLowerCase().includes(searchQuery.value.toLowerCase()) ||
            t.subject.toLowerCase().includes(searchQuery.value.toLowerCase()),
    );
});

const templateName = ref("");
const templateSubject = ref("");
// Editor ref
const templateEditorRef = ref<any>(null);
const templateContent = ref("");

watch(selectedTemplateId, (newId) => {
    if (newId && activeTemplate.value) {
        templateName.value = activeTemplate.value.name;
        templateSubject.value = activeTemplate.value.subject;
        templateContent.value = activeTemplate.value.body || "";
        saveStatus.value = "saved";
    } else {
        templateName.value = "";
        templateSubject.value = "";
        templateContent.value = "";
        saveStatus.value = "saved";
    }
});

async function handleNewTemplate() {
    const newTpl = await addTemplate({
        name: "New Template",
        subject: "",
        body: "",
    });
    selectedTemplateId.value = newTpl.id;
    saveStatus.value = "saved";
}

async function handleSaveTemplate() {
    if (!selectedTemplateId.value) return;
    saveStatus.value = "saving";
    try {
        await updateTemplate(selectedTemplateId.value, {
            name: templateName.value,
            subject: templateSubject.value,
            body: activeTemplate.value?.body || "",
        });
        saveStatus.value = "saved";
    } catch (e) {
        saveStatus.value = "error";
    }
}

function handleDeleteTemplate(id: string) {
    if (confirm("Delete this template?")) {
        deleteTemplate(id);
        if (selectedTemplateId.value === id) {
            selectedTemplateId.value =
                templates.value.length > 0 ? templates.value[0].id : null;
        }
    }
}

// --- Media Manager Logic ---
const showMediaBar = ref(false); // Collapsed by default in this layout
const mediaFiles = ref<any[]>([]);
const mediaLoading = ref(false);
const mediaUploadQueue = ref<any[]>([]);
const isUploading = ref(false);

const fetchMedia = async () => {
    const isSignature = activeTab.value === "signatures";
    const id = isSignature
        ? selectedSignatureId.value
        : selectedTemplateId.value;

    if (!id) {
        mediaFiles.value = [];
        return;
    }

    mediaLoading.value = true;
    try {
        const endpoint = isSignature
            ? `/api/emails/signatures/${id}/media`
            : `/api/emails/templates/${id}/media`;
        const response = await api.get(endpoint);
        mediaFiles.value = response.data.data;
    } catch (e) {
        console.error("Failed to fetch media", e);
    } finally {
        mediaLoading.value = false;
    }
};

// Refresh media when selection changes
watch(
    [activeTab, selectedSignatureId, selectedTemplateId],
    () => {
        if (
            activeTab.value === "signatures" ||
            activeTab.value === "templates"
        ) {
            fetchMedia();
        }
    },
    { immediate: true },
);

const handleMediaUpload = (files) => {
    files.forEach((file) => {
        mediaUploadQueue.value.push({
            id: Math.random().toString(36).substr(2, 9),
            file,
            progress: 0,
            status: "pending",
        });
    });
    processUploadQueue();
};

const removeUpload = (index) => {
    mediaUploadQueue.value.splice(index, 1);
};

const processUploadQueue = async () => {
    if (isUploading.value) return;

    const isSignature = activeTab.value === "signatures";
    const id = isSignature
        ? selectedSignatureId.value
        : selectedTemplateId.value;

    if (!id) return;

    const pendingItems = mediaUploadQueue.value.filter(
        (i) => i.status === "pending",
    );
    if (pendingItems.length === 0) return;

    isUploading.value = true;
    let successCount = 0;

    for (const item of pendingItems) {
        item.status = "uploading";
        const formData = new FormData();
        formData.append("file", item.file);

        try {
            const endpoint = isSignature
                ? `/api/emails/signatures/${id}/media`
                : `/api/emails/templates/${id}/media`;

            await api.post(endpoint, formData, {
                headers: { "Content-Type": "multipart/form-data" },
                onUploadProgress: (progressEvent) => {
                    const percentCompleted = Math.round(
                        (progressEvent.loaded * 100) / progressEvent.total,
                    );
                    item.progress = percentCompleted;
                },
            });
            item.status = "completed";
            item.progress = 100;
            successCount++;
        } catch (error) {
            console.error(`Error uploading ${item.file.name}:`, error);
            item.status = "error";
        }
    }

    if (successCount > 0) {
        fetchMedia();
        // Clear completed
        mediaUploadQueue.value = mediaUploadQueue.value.filter(
            (item) => item.status !== "completed",
        );
    }

    isUploading.value = mediaUploadQueue.value.some(
        (i) => i.status === "uploading",
    );
};

const handleMediaDelete = async (id) => {
    if (!confirm("Delete this image?")) return;

    const isSignature = activeTab.value === "signatures";
    const parentId = isSignature
        ? selectedSignatureId.value
        : selectedTemplateId.value;
    
    try {
        const endpoint = isSignature
             ? `/api/emails/signatures/${parentId}/media/${id}`
             : `/api/emails/templates/${parentId}/media/${id}`;
        
        await api.delete(endpoint);
        fetchMedia();
    } catch (e) {
        console.error("Failed to delete media", e);
    }
};

const insertImage = (media) => {
    const isSignature = activeTab.value === "signatures";
    const editor = isSignature
        ? signatureEditorRef.value?.editor
        : templateEditorRef.value?.editor;

    if (editor && media.url) {
        editor.chain().focus().setImage({ src: media.url }).run();
    }
};

// Drag and drop setup for the drop zone
const isDragging = ref(false);
const handleDrop = (e) => {
    isDragging.value = false;
    const files = Array.from(e.dataTransfer.files);
    if (files.length > 0) {
        handleMediaUpload(files);
    }
};

onMounted(async () => {
    await Promise.all([fetchSignatures(), fetchTemplates()]);

    // Select first item by default if available and nothing selected
    if (
        activeTab.value === "signatures" &&
        signatures.value.length > 0 &&
        !selectedSignatureId.value
    ) {
        selectedSignatureId.value = signatures.value[0].id;
    }
    if (
        activeTab.value === "templates" &&
        templates.value.length > 0 &&
        !selectedTemplateId.value
    ) {
        selectedTemplateId.value = templates.value[0].id;
    }
});

// --- Storage Logic ---
const loadingStorage = ref(false);
const storageAccounts = ref<any[]>([]);

async function fetchStorageData() {
    loadingStorage.value = true;
    try {
        const response = await emailAccountService.list();
        storageAccounts.value = response;
    } catch (e) {
        console.error("Failed to fetch storage data", e);
    } finally {
        loadingStorage.value = false;
    }
}

watch(activeTab, (newTab) => {
    if (newTab === "storage") fetchStorageData();
    searchQuery.value = ""; // Reset search on tab change
});

// Format helpers
function formatBytes(bytes: number | null) {
    if (bytes === null || bytes === undefined) return "0 B";
    if (bytes === 0) return "0 B";
    const k = 1024;
    const sizes = ["B", "KB", "MB", "GB", "TB"];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + " " + sizes[i];
}

function getUsageDetails(account: any) {
    if (!account.storage_limit || account.storage_limit === 0) {
        return { percent: 0, color: "text-gray-500", bg: "bg-gray-200" };
    }
    const percent = Math.min(
        (account.storage_used / account.storage_limit) * 100,
        100,
    );
    let color = "text-[var(--brand-primary)]";
    let bg = "bg-[var(--brand-primary)]";

    if (percent >= 90) {
        color = "text-red-500";
        bg = "bg-red-500";
    } else if (percent >= 75) {
        color = "text-amber-500";
        bg = "bg-amber-500";
    }

    return { percent, color, bg };
}
</script>

<template>
    <div class="p-6 space-y-6 w-full">
        <!-- Header -->
        <div class="flex items-center gap-4">
            <Button
                variant="ghost"
                size="icon"
                class="rounded-full"
                @click="$router.push({ name: 'email' })"
            >
                <ArrowLeftIcon class="w-5 h-5 text-[var(--text-secondary)]" />
            </Button>
            <div>
                <h1 class="text-2xl font-bold text-[var(--text-primary)]">
                    Email Settings
                </h1>
                <p class="text-[var(--text-secondary)]">
                    Manage your email signatures, templates, and connected accounts.
                </p>
            </div>
        </div>

        <div class="flex flex-col lg:flex-row gap-6">
            <!-- Sidebar Navigation -->
            <div class="w-full lg:w-64 shrink-0">
                <nav class="space-y-1">
                    <button
                        v-for="tab in tabs"
                        :key="tab.id"
                        @click="activeTab = tab.id"
                        class="w-full flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-lg transition-colors"
                        :class="[
                            activeTab === tab.id
                                ? 'bg-[var(--surface-elevated)] text-[var(--brand-primary)] shadow-sm border border-[var(--border-default)]'
                                : 'text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)] hover:text-[var(--text-primary)]',
                        ]"
                    >
                        <component :is="tab.icon" class="w-4 h-4" />
                        {{ tab.label }}
                    </button>
                </nav>
            </div>

            <!-- Content Area -->
            <div class="flex-1 min-w-0 space-y-6">
                <!-- Signatures / Templates -->
                <Card
                    v-if="
                        activeTab === 'signatures' || activeTab === 'templates'
                    "
                    class="overflow-hidden min-h-[500px] flex flex-col"
                >
                    <div
                        class="border-b border-[var(--border-default)] p-4 flex items-center justify-between"
                    >
                        <div class="flex items-center gap-3">
                            <h2 class="font-semibold text-[var(--text-primary)]">
                                {{
                                    activeTab === "signatures"
                                        ? "Signatures"
                                        : "Templates"
                                }}
                            </h2>
                            <!-- Status Indicator -->
                             <div
                                v-if="saveStatus === 'saved'"
                                class="flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 text-xs font-medium border border-emerald-500/20"
                            >
                                <CheckIcon class="w-3 h-3" />
                                Saved
                            </div>
                            <div
                                v-else-if="saveStatus === 'saving'"
                                class="flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-[var(--brand-primary)]/10 text-[var(--brand-primary)] text-xs font-medium border border-[var(--brand-primary)]/20"
                            >
                                <div
                                    class="w-3 h-3 border-2 border-[var(--brand-primary)] border-t-transparent rounded-full animate-spin"
                                ></div>
                                Saving...
                            </div>
                            <div
                                v-else-if="saveStatus === 'error'"
                                class="flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-red-500/10 text-red-600 dark:text-red-400 text-xs font-medium border border-red-500/20"
                            >
                                <AlertCircleIcon class="w-3 h-3" />
                            </div>
                        </div>
                        <Button
                            size="sm"
                            @click="
                                activeTab === 'signatures'
                                    ? handleNewSignature()
                                    : handleNewTemplate()
                            "
                        >
                            <PlusIcon class="w-4 h-4 mr-1.5" />
                            Create New
                        </Button>
                    </div>

                    <div class="flex flex-1 flex-col md:flex-row h-full">
                        <!-- List Column -->
                        <div
                            class="w-full md:w-64 border-b md:border-b-0 md:border-r border-[var(--border-default)] bg-[var(--surface-subtle)] flex flex-col shrink-0"
                        >
                            <div class="p-3 border-b border-[var(--border-default)]">
                                <div class="relative">
                                    <SearchIcon
                                        class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-[var(--text-muted)]"
                                    />
                                    <input
                                        v-model="searchQuery"
                                        type="text"
                                        placeholder="Search..."
                                        class="w-full pl-8 pr-3 py-1.5 text-xs bg-[var(--surface-primary)] border border-[var(--border-default)] rounded-md focus:outline-none focus:ring-1 focus:ring-[var(--brand-primary)]"
                                    />
                                </div>
                            </div>
                            <div class="flex-1 overflow-y-auto p-2 space-y-1 max-h-[300px] md:max-h-[600px]">
                                <template v-if="activeTab === 'signatures'">
                                    <button
                                        v-for="sig in filteredSignatures"
                                        :key="sig.id"
                                        @click="selectedSignatureId = sig.id"
                                        class="w-full text-left px-3 py-2 rounded-md text-sm transition-colors flex items-center justify-between group"
                                        :class="
                                            selectedSignatureId === sig.id
                                                ? 'bg-[var(--surface-elevated)] shadow-sm text-[var(--text-primary)] font-medium ring-1 ring-[var(--border-default)]'
                                                : 'text-[var(--text-secondary)] hover:bg-[var(--surface-elevated)] hover:text-[var(--text-primary)]'
                                        "
                                    >
                                        <span class="truncate">{{ sig.name }}</span>
                                        <button
                                            @click.stop="handleDeleteSignature(sig.id)"
                                            class="opacity-0 group-hover:opacity-100 p-1 text-[var(--text-muted)] hover:text-red-500 rounded transition-opacity"
                                        >
                                            <Trash2Icon class="w-3.5 h-3.5" />
                                        </button>
                                    </button>
                                </template>
                                <template v-else>
                                    <button
                                        v-for="tpl in filteredTemplates"
                                        :key="tpl.id"
                                        @click="selectedTemplateId = tpl.id"
                                        class="w-full text-left px-3 py-2 rounded-md text-sm transition-colors flex flex-col gap-0.5 group"
                                        :class="
                                            selectedTemplateId === tpl.id
                                                ? 'bg-[var(--surface-elevated)] shadow-sm text-[var(--text-primary)] font-medium ring-1 ring-[var(--border-default)]'
                                                : 'text-[var(--text-secondary)] hover:bg-[var(--surface-elevated)] hover:text-[var(--text-primary)]'
                                        "
                                    >
                                        <div class="flex items-center justify-between w-full">
                                             <span class="truncate">{{ tpl.name }}</span>
                                              <div
                                                @click.stop="handleDeleteTemplate(tpl.id)"
                                                class="opacity-0 group-hover:opacity-100 p-1 -mr-1 text-[var(--text-muted)] hover:text-red-500 rounded transition-opacity cursor-pointer"
                                            >
                                                <Trash2Icon class="w-3.5 h-3.5" />
                                            </div>
                                        </div>
                                         <span class="text-xs text-[var(--text-muted)] truncate">{{ tpl.subject || '(No Subject)' }}</span>
                                    </button>
                                </template>
                            </div>
                        </div>

                        <!-- Editor Column -->
                        <div class="flex-1 flex flex-col min-w-0 bg-[var(--surface-primary)]">
                            <template
                                v-if="
                                    (activeTab === 'signatures' && selectedSignatureId) ||
                                    (activeTab === 'templates' && selectedTemplateId)
                                "
                            >
                                <div class="p-4 border-b border-[var(--border-default)] space-y-3">
                                    <div>
                                        <label class="block text-xs font-medium text-[var(--text-secondary)] mb-1">Name</label>
                                        <Input
                                            v-if="activeTab === 'signatures'"
                                            v-model="signatureName"
                                            @blur="handleSaveSignature"
                                            placeholder="Signature Name"
                                        />
                                        <Input
                                            v-else
                                            v-model="templateName"
                                            @blur="handleSaveTemplate"
                                            placeholder="Template Name"
                                        />
                                    </div>
                                    <div v-if="activeTab === 'templates'">
                                         <label class="block text-xs font-medium text-[var(--text-secondary)] mb-1">Subject</label>
                                        <Input
                                            v-model="templateSubject"
                                            @blur="handleSaveTemplate"
                                            placeholder="Email Subject"
                                        />
                                    </div>
                                </div>

                                <div class="flex-1 flex flex-col relative min-h-[400px]">
                                     <!-- Toolbar / Media Toggle -->
                                    <div class="px-4 py-2 border-b border-[var(--border-default)] bg-[var(--surface-subtle)] flex justify-end">
                                        <button
                                            @click="showMediaBar = !showMediaBar"
                                            class="flex items-center gap-1.5 text-xs text-[var(--text-secondary)] hover:text-[var(--text-primary)] font-medium transition-colors"
                                        >
                                            <ImageIcon class="w-3.5 h-3.5" />
                                            {{ showMediaBar ? 'Hide Media' : 'Show Media' }}
                                        </button>
                                    </div>

                                    <div class="flex-1 flex">
                                         <!-- Editor -->
                                        <div class="flex-1 flex flex-col">
                                             <RichTextEditor
                                                v-if="activeTab === 'signatures'"
                                                ref="signatureEditorRef"
                                                v-model="activeSignature.content"
                                                :content="signatureContent"
                                                @update:modelValue="debouncedSaveSignature"
                                                placeholder="Write your signature..."
                                                class="flex-1 border-0 rounded-none focus-within:ring-0"
                                            />
                                            <RichTextEditor
                                                v-else
                                                ref="templateEditorRef"
                                                v-model="activeTemplate.body"
                                                :content="templateContent"
                                                @update:modelValue="debouncedSaveTemplate"
                                                placeholder="Write your template..."
                                                class="flex-1 border-0 rounded-none focus-within:ring-0"
                                            />
                                        </div>

                                        <!-- Media Sidebar (Inline) -->
                                        <div 
                                            v-if="showMediaBar"
                                            class="w-64 border-l border-[var(--border-default)] bg-[var(--surface-subtle)] flex flex-col"
                                        >
                                            <div class="p-3 border-b border-[var(--border-default)]">
                                                <h3 class="text-xs font-bold text-[var(--text-secondary)] uppercase">Media Library</h3>
                                            </div>
                                            
                                            <div 
                                                class="flex-1 p-3 overflow-y-auto"
                                                @dragover.prevent="isDragging = true"
                                                @dragleave.prevent="isDragging = false"
                                                @drop.prevent="handleDrop"
                                            >
                                                <!-- Drop Zone Overlay -->
                                                 <div
                                                    v-if="isDragging"
                                                    class="absolute inset-0 bg-[var(--brand-primary)]/10 z-50 flex items-center justify-center border-2 border-dashed border-[var(--brand-primary)] m-2 rounded-lg pointer-events-none"
                                                >
                                                    <span class="text-[var(--brand-primary)] font-bold">Drop files here</span>
                                                </div>

                                                 <!-- Upload Button -->
                                                <label class="flex flex-col items-center justify-center w-full h-20 border-2 border-dashed border-[var(--border-default)] rounded-lg hover:border-[var(--brand-primary)] hover:bg-[var(--surface-elevated)] cursor-pointer transition-all mb-4 group">
                                                    <div class="flex flex-col items-center justify-center pt-5 pb-6">
                                                        <PlusIcon class="w-6 h-6 text-[var(--text-muted)] group-hover:text-[var(--brand-primary)] mb-1" />
                                                        <p class="text-[10px] text-[var(--text-muted)]">Upload Image</p>
                                                    </div>
                                                    <input type="file" class="hidden" multiple accept="image/*" @change="(e) => handleMediaUpload(Array.from(e.target.files))" />
                                                </label>

                                                 <!-- Upload Queue -->
                                                <div v-if="mediaUploadQueue.length > 0" class="space-y-2 mb-4">
                                                    <div v-for="(item, idx) in mediaUploadQueue" :key="idx" class="text-xs bg-[var(--surface-elevated)] p-2 rounded border border-[var(--border-default)]">
                                                        <div class="flex justify-between mb-1">
                                                            <span class="truncate max-w-[100px]">{{ item.file.name }}</span>
                                                            <span :class="{'text-[var(--brand-primary)]': item.status === 'uploading', 'text-red-500': item.status === 'error'}">
                                                                {{ item.status }}
                                                            </span>
                                                        </div>
                                                        <div class="w-full bg-[var(--surface-tertiary)] rounded-full h-1">
                                                            <div class="bg-[var(--brand-primary)] h-1 rounded-full transition-all" :style="{ width: item.progress + '%' }"></div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Grid -->
                                                <div v-if="mediaLoading" class="flex justify-center py-4">
                                                    <Loader2 class="w-5 h-5 animate-spin text-[var(--text-muted)]" />
                                                </div>
                                                
                                                <div v-else class="grid grid-cols-2 gap-2">
                                                    <div
                                                        v-for="media in mediaFiles"
                                                        :key="media.id"
                                                        class="group relative aspect-square rounded-md border border-[var(--border-default)] bg-[var(--surface-primary)] overflow-hidden cursor-pointer hover:ring-2 hover:ring-[var(--brand-primary)]"
                                                        draggable="true"
                                                        @dragstart="(e) => {
                                                            e.dataTransfer.setData('text/plain', media.url);
                                                            e.dataTransfer.setData('text/html', `<img src='${media.url}' />`);
                                                        }"
                                                        @click="insertImage(media)"
                                                    >
                                                        <img
                                                            :src="media.thumbnail_url || media.url"
                                                            class="w-full h-full object-cover"
                                                            loading="lazy"
                                                        />
                                                         <div class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-1">
                                                                <button 
                                                                    @click.stop="handleMediaDelete(media.id)"
                                                                    class="p-1 rounded bg-red-500 text-white hover:bg-red-600"
                                                                    title="Delete"
                                                                >
                                                                    <Trash2Icon class="w-3 h-3" />
                                                                </button>
                                                         </div>
                                                    </div>
                                                </div>

                                                <div v-if="!mediaLoading && mediaFiles.length === 0" class="text-center py-8 text-[var(--text-muted)] text-xs">
                                                    No media found.
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>
                             <div v-else class="flex flex-col items-center justify-center p-12 text-center h-[400px]">
                                <div class="w-12 h-12 rounded-full bg-[var(--surface-secondary)] flex items-center justify-center mb-3">
                                    <SettingsIcon class="w-6 h-6 text-[var(--text-muted)]" />
                                </div>
                                <h3 class="text-[var(--text-primary)] font-medium">Select an item to edit</h3>
                                <p class="text-[var(--text-secondary)] text-sm mt-1">Or create a new one to get started.</p>
                            </div>
                        </div>
                    </div>
                </Card>

                <!-- Accounts Tab -->
                <div v-else-if="activeTab === 'accounts'">
                    <EmailAccountsSection />
                </div>

                <!-- Storage Tab -->
                <Card v-else-if="activeTab === 'storage'">
                    <div class="p-6">
                        <h2 class="text-lg font-bold text-[var(--text-primary)] mb-4">Storage Usage</h2>
                        <div v-if="loadingStorage" class="flex justify-center py-12">
                             <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-[var(--brand-primary)]"></div>
                        </div>
                        <div v-else class="space-y-6">
                            <div
                                v-if="storageAccounts.length === 0"
                                class="text-center py-12"
                            >
                                <div
                                    class="w-16 h-16 mx-auto rounded-full bg-[var(--surface-secondary)] flex items-center justify-center mb-4"
                                >
                                    <HardDriveIcon
                                        class="w-8 h-8 text-[var(--text-muted)]"
                                    />
                                </div>
                                <h3
                                    class="text-lg font-medium text-[var(--text-primary)]"
                                >
                                    No Connected Accounts
                                </h3>
                                <p
                                    class="text-[var(--text-secondary)] mt-1 max-w-sm mx-auto"
                                >
                                    Connect an email account to view storage
                                    usage statistics.
                                </p>
                                <Button
                                    variant="outline"
                                    class="mt-6"
                                    @click="activeTab = 'accounts'"
                                >
                                    Connect Account
                                </Button>
                            </div>
                            <div
                                v-else
                                v-for="account in storageAccounts"
                                :key="account.id"
                                class="p-4 rounded-xl border border-[var(--border-default)] bg-[var(--surface-subtle)]"
                            >
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-full bg-white flex items-center justify-center text-xl shadow-sm">
                                            {{ account.email[0].toUpperCase() }}
                                        </div>
                                        <div>
                                            <div class="font-medium text-[var(--text-primary)]">{{ account.email }}</div>
                                            <div class="text-xs text-[var(--text-secondary)] capitalize">{{ account.provider }}</div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-sm font-bold text-[var(--text-primary)]">
                                            {{ formatBytes(account.storage_used) }}
                                        </div>
                                        <div class="text-xs text-[var(--text-secondary)]">
                                            of {{ formatBytes(account.storage_limit) }}
                                        </div>
                                    </div>
                                </div>
                                 <div class="w-full bg-[var(--surface-tertiary)] rounded-full h-2 overflow-hidden">
                                    <div
                                        class="h-full rounded-full transition-all duration-500"
                                        :class="getUsageDetails(account).bg"
                                        :style="{ width: `${getUsageDetails(account).percent}%` }"
                                    ></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </Card>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Force RichTextEditor to integrate seamlessly */
:deep(.prose) {
    max-width: none;
}

/* Override inner container rounding and borders */
:deep(.rounded-xl) {
    border-radius: 0 !important;
    border: none !important;
}

:deep(.rounded-t-xl) {
    border-radius: 0 !important;
}

/* Ensure editor toolbar border bottom matches */
:deep(.border-b) {
    border-bottom: 1px solid var(--border-default) !important;
}
</style>
