<template>
    <div class="flex flex-col h-full w-64" v-bind="$attrs">
        <!-- Accounts Selector (Dropdown) & Sync -->
        <div
            class="px-3 py-3 border-b border-(--border-default) flex items-center gap-2"
        >
            <Dropdown
                :items="accountItems"
                align="start"
                class="flex-1 min-w-0"
            >
                <template #trigger>
                    <button
                        class="flex items-center w-full px-2.5 py-2 text-sm font-medium text-left bg-(--surface-elevated) border border-(--border-default) rounded-xl shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-(--interactive-primary) hover:bg-(--surface-tertiary) transition-all hover:border-(--interactive-primary)/30"
                    >
                        <div
                            class="w-6 h-6 rounded-full mr-2 ring-1 ring-white/20 flex items-center justify-center text-[10px] font-bold text-white shrink-0 relative"
                            :style="{
                                background: selectedAccount
                                    ? '#6366f1'
                                    : '#9ca3af',
                            }"
                        >
                            {{
                                selectedAccount
                                    ? selectedAccount.email
                                          .charAt(0)
                                          .toUpperCase()
                                    : "?"
                            }}
                            <!-- Sync Indicator Badge -->
                            <div
                                v-if="isBackgroundSyncing"
                                class="absolute -top-0.5 -right-0.5 w-2.5 h-2.5 bg-blue-500 rounded-full border-2 border-(--surface-elevated) animate-pulse"
                            ></div>
                        </div>
                        <span
                            class="flex-1 truncate text-(--text-primary) leading-tight"
                            >{{ selectedAccount?.email || "No account" }}</span
                        >
                        <ChevronDownIcon
                            class="w-3.5 h-3.5 text-(--text-muted) shrink-0 ml-1.5"
                        />
                    </button>
                </template>
            </Dropdown>
        </div>

        <!-- Compose Button & Sync -->
        <div class="px-4 py-4 flex items-center gap-2.5">
            <button
                @click="$emit('compose')"
                :disabled="!selectedAccount"
                class="flex-1 flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-bold rounded-xl text-white bg-linear-to-r from-(--interactive-primary) to-indigo-600 hover:from-(--interactive-primary-hover) hover:to-indigo-700 shadow-lg shadow-(--interactive-primary)/20 transition-all hover:scale-[1.02] active:scale-[0.98] cursor-pointer select-none disabled:opacity-50 disabled:cursor-not-allowed disabled:scale-100 disabled:shadow-none"
            >
                <PencilIcon class="w-4 h-4 text-white/90" />
                <span>New Email</span>
            </button>

            <button
                @click="handleSync"
                :disabled="isSyncing || isBackgroundSyncing || !selectedAccount"
                class="flex items-center justify-center w-10 h-10 shrink-0 rounded-xl bg-(--surface-elevated) border border-(--border-default) text-(--text-secondary) hover:text-(--interactive-primary) hover:border-(--interactive-primary)/40 hover:bg-(--surface-tertiary) transition-all disabled:opacity-50 disabled:cursor-not-allowed group relative shadow-sm"
                :title="isBackgroundSyncing ? 'Syncing...' : 'Sync Account'"
            >
                <RotateCwIcon
                    class="w-4 h-4 group-hover:rotate-180 transition-transform duration-700 ease-in-out"
                    :class="{
                        'animate-spin': isSyncing || isBackgroundSyncing,
                    }"
                />
            </button>
        </div>

        <!-- Navigation -->
        <nav class="flex-1 overflow-y-auto min-h-0 px-3 space-y-1">
            <!-- System Folders -->
            <a
                v-for="folder in systemFolders"
                :key="folder.id"
                href="#"
                @click.prevent="handleFolderClick(folder.id)"
                :class="[
                    selectedFolderId === folder.id
                        ? 'bg-(--interactive-primary)/10 text-(--interactive-primary)'
                        : 'text-(--text-secondary) hover:bg-(--surface-tertiary)',
                    'group flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-all relative overflow-hidden',
                ]"
            >
                <!-- Selection Indicator -->
                <div
                    v-if="selectedFolderId === folder.id"
                    class="absolute left-0 top-1.5 bottom-1.5 w-1 bg-(--interactive-primary) rounded-r-full"
                ></div>

                <component
                    :is="folder.icon"
                    :class="[
                        selectedFolderId === folder.id
                            ? 'text-(--interactive-primary)'
                            : 'text-(--text-muted) group-hover:text-(--text-secondary)',
                        'mr-3 shrink-0 h-4.5 w-4.5 transition-colors',
                    ]"
                />
                <span class="flex-1 truncate">{{ folder.name }}</span>
                <span
                    v-if="folder.count"
                    :class="[
                        selectedFolderId === folder.id
                            ? 'bg-(--interactive-primary) text-white'
                            : 'bg-(--surface-tertiary) text-(--text-secondary)',
                        'ml-auto py-0.5 px-2 rounded-full text-[10px] font-bold transition-colors shadow-sm',
                    ]"
                >
                    {{ folder.count }}
                </span>
            </a>

            <!-- Custom Folders -->
            <template v-if="customFolders.length > 0">
                <div class="pt-2 space-y-1">
                    <a
                        v-for="folder in customFolders"
                        :key="folder.id"
                        href="#"
                        @click.prevent="handleFolderClick(folder.id)"
                        :class="[
                            selectedFolderId === folder.id
                                ? 'bg-(--interactive-primary)/10 text-(--interactive-primary)'
                                : 'text-(--text-secondary) hover:bg-(--surface-tertiary)',
                            'group flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-all relative overflow-hidden',
                        ]"
                    >
                        <div
                            v-if="selectedFolderId === folder.id"
                            class="absolute left-0 top-1.5 bottom-1.5 w-1 bg-(--interactive-primary) rounded-r-full"
                        ></div>

                        <component
                            :is="folder.icon"
                            class="mr-3 shrink-0 h-4.5 w-4.5 text-(--text-muted)"
                        />
                        <span class="flex-1 truncate">{{ folder.name }}</span>
                    </a>
                </div>
            </template>

            <!-- Provider Folders (Subscribed Labels) -->
            <template v-if="subscribedRemoteFolders.length > 0">
                <div class="pt-6 space-y-1">
                    <div
                        class="px-3 pb-2 text-[10px] font-bold text-(--text-muted) uppercase tracking-widest flex items-center gap-2"
                    >
                        <div class="h-px bg-(--border-default) flex-1"></div>
                        <span>Provider Folders</span>
                        <div class="h-px bg-(--border-default) flex-1"></div>
                    </div>
                    <a
                        v-for="folder in subscribedRemoteFolders"
                        :key="folder.id"
                        href="#"
                        @click.prevent="handleFolderClick(folder.id)"
                        :class="[
                            selectedFolderId === folder.id
                                ? 'bg-(--interactive-primary)/8 text-(--interactive-primary)'
                                : 'text-(--text-secondary) hover:bg-(--surface-tertiary)',
                            'group flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-all relative overflow-hidden',
                        ]"
                    >
                        <div
                            v-if="selectedFolderId === folder.id"
                            class="absolute left-0 top-1.5 bottom-1.5 w-1 bg-(--interactive-primary) rounded-r-full"
                        ></div>

                        <FolderIcon
                            class="mr-3 shrink-0 h-4 w-4 text-(--text-muted) group-hover:text-(--text-secondary) transition-colors"
                        />
                        <span class="truncate capitalize leading-relaxed">{{ folder.name.toLowerCase() }}</span>
                    </a>
                </div>
            </template>

            <!-- New Folder Button -->
            <button
                @click="showCreateFolderModal = true"
                class="w-full flex items-center gap-2 px-3 py-2 text-sm text-(--text-muted) hover:text-(--text-primary) hover:bg-(--surface-tertiary) rounded-lg transition-colors border-t border-(--border-default) mt-2 pt-2"
            >
                <PlusIcon class="w-4 h-4" />
                New Folder
            </button>

            <!-- Labels Section -->
            <div class="mt-8">
                <div class="flex items-center justify-between px-3 mb-2 gap-2">
                    <div class="h-px bg-(--border-default) flex-1"></div>
                    <h3
                        class="text-[10px] font-bold text-(--text-muted) uppercase tracking-widest whitespace-nowrap"
                    >
                        Labels
                    </h3>
                    <div class="h-px bg-(--border-default) flex-1"></div>
                    <button
                        @click="showCreateLabelModal = true"
                        class="p-1 rounded-md hover:bg-(--surface-tertiary) text-(--text-muted) hover:text-(--text-primary) transition-all active:scale-95"
                        title="Create label"
                    >
                        <PlusIcon class="w-3.5 h-3.5" />
                    </button>
                </div>
                <div class="space-y-0.5" role="group">
                    <a
                        v-for="label in labels"
                        :key="label.id"
                        href="#"
                        @click.prevent="handleLabelClick(label.id)"
                        class="group flex items-center px-3 py-1.5 text-sm font-medium text-(--text-secondary) rounded-lg hover:text-(--text-primary) hover:bg-(--surface-tertiary) transition-all"
                    >
                        <span
                            class="w-2.5 h-2.5 rounded-full mr-3 ring-1 ring-black/5"
                            :class="label.color"
                        ></span>
                        <span class="truncate">{{ label.name }}</span>
                    </a>
                </div>
            </div>
        </nav>
    </div>

    <!-- Create Folder Modal -->
    <CreateFolderModal
        :isOpen="showCreateFolderModal"
        @close="showCreateFolderModal = false"
        @created="handleFolderCreated"
    />

    <!-- Create Label Modal -->
    <CreateLabelModal
        :isOpen="showCreateLabelModal"
        @close="showCreateLabelModal = false"
        @created="handleLabelCreated"
    />
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import {
    ChevronDownIcon,
    PencilIcon,
    UserIcon,
    PlusIcon,
    SettingsIcon,
    RotateCwIcon,
    FolderIcon,
} from "lucide-vue-next";

defineOptions({
    inheritAttrs: false,
});
import Dropdown, { type DropdownItem } from "@/components/ui/Dropdown.vue";
import CreateFolderModal from "./CreateFolderModal.vue";
import CreateLabelModal from "./CreateLabelModal.vue";
import { useEmailStore } from "@/stores/emailStore";
import { storeToRefs } from "pinia";
import {
    emailAccountService,
    type EmailAccount,
} from "@/services/email-account.service";

import { useRouter } from "vue-router";

const router = useRouter();
const emit = defineEmits(["compose"]);

const store = useEmailStore();
const {
    systemFolders,
    customFolders,
    subscribedRemoteFolders,
    labels,
    selectedFolderId,
    accounts,
    selectedAccount,
    accountStatus,
} = storeToRefs(store);

const isBackgroundSyncing = computed(() => {
    return (
        accountStatus.value?.status === "syncing" ||
        accountStatus.value?.status === "seeding"
    );
});

onMounted(() => {
    store.fetchInitialData();
});

const showCreateFolderModal = ref(false);
const showCreateLabelModal = ref(false);

function handleFolderClick(folderId: string) {
    store.selectFolder(folderId);
}

function handleLabelClick(labelId: string) {
    // Treat clicking a label like selecting a folder for filtering
    store.selectFolder(labelId);
}

function handleFolderCreated(folderId: string) {
    store.selectFolder(folderId);
}

function handleLabelCreated(_labelId: string) {
    // Optionally auto-select the new label filter
    // store.selectFolder(labelId);
}

// Dynamic accountItems computed from fetched accounts
const accountItems = computed<DropdownItem[]>(() => {
    const items: DropdownItem[] = (accounts.value || []).map((account) => ({
        label: `${account.name || account.email}`,
        icon: UserIcon,
        action: () => {
            selectAccount(account);
        },
    }));

    // Add static actions
    items.push(
        {
            label: "Add account",
            icon: PlusIcon,
            action: () => router.push("/email/settings?tab=accounts"),
        },
        {
            label: "Settings",
            icon: SettingsIcon,
            action: () => router.push("/email/settings"),
        },
    );

    return items;
});

const isSyncing = ref(false);

async function handleSync() {
    if (!selectedAccount.value || isSyncing.value) return;

    isSyncing.value = true;
    try {
        await emailAccountService.sync(selectedAccount.value.id);
        // Refresh emails after sync trigger (might need polling or reliable socket, but this helps)
        setTimeout(() => {
            store.fetchEmails(1);
        }, 2000);
    } catch (e) {
        console.error("Sync failed", e);
    } finally {
        isSyncing.value = false;
    }
}

function selectAccount(account: any) {
    store.setSelectedAccount(account.id);
}
</script>
