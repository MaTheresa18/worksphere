<template>
    <div
        class="flex flex-col h-full bg-(--surface-primary)"
    >
        <!-- Toolbar Row 1: Actions & Search -->
        <div
            class="flex items-center gap-2 p-2 border-b border-(--border-default) bg-(--surface-secondary)"
        >
            <!-- Left: Checkbox & Bulk Actions -->
            <div class="flex items-center gap-1.5 shrink-0">
                <button
                    @click="$emit('toggle-sidebar')"
                    class="md:hidden p-1.5 text-(--text-secondary) hover:text-(--text-primary)"
                >
                    <MenuIcon class="w-5 h-5" />
                </button>

                <input
                    type="checkbox"
                    :checked="
                        selectedEmailIds.size > 0 &&
                        selectedEmailIds.size === filteredEmails.length
                    "
                    :indeterminate="
                        selectedEmailIds.size > 0 &&
                        selectedEmailIds.size < filteredEmails.length
                    "
                    @change="toggleSelectAll"
                    class="h-4 w-4 text-(--interactive-primary) focus:ring-(--interactive-primary) border-(--border-default) rounded"
                />

                <div v-if="selectedEmailIds.size > 0" class="flex gap-0.5">
                    <button
                        @click="deleteSelected"
                        class="p-1.5 text-(--text-secondary) hover:text-(--text-primary) hover:bg-(--surface-tertiary) rounded transition-colors"
                        title="Delete"
                    >
                        <TrashIcon class="w-4 h-4" />
                    </button>
                    <button
                        @click="markSelectedRead(true)"
                        class="p-1.5 text-(--text-secondary) hover:text-(--text-primary) hover:bg-(--surface-tertiary) rounded transition-colors"
                        title="Mark Read"
                    >
                        <MailOpenIcon class="w-4 h-4" />
                    </button>
                    <button
                        @click="markSelectedRead(false)"
                        class="p-1.5 text-(--text-secondary) hover:text-(--text-primary) hover:bg-(--surface-tertiary) rounded transition-colors"
                        title="Mark Unread"
                    >
                        <MailIcon class="w-4 h-4" />
                    </button>

                    <MoveToFolderDropdown
                        :emailIds="Array.from(selectedEmailIds)"
                        align="start"
                    />

                    <span
                        class="text-xs text-(--text-muted) self-center ml-1"
                        >{{ selectedEmailIds.size }}</span
                    >
                </div>
            </div>

            <!-- Right: Search -->
            <div class="flex-1 relative min-w-0">
                <SearchIcon
                    class="absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-(--text-muted)"
                />
                <input
                    type="text"
                    v-model="searchQuery"
                    placeholder="Search emails..."
                    class="w-full pl-8 pr-3 py-1.5 text-sm border border-(--border-default) rounded-lg bg-(--surface-elevated) focus:outline-none focus:ring-2 focus:ring-(--interactive-primary)/50 focus:border-(--interactive-primary) text-(--text-primary) placeholder-(--text-muted) transition-all"
                />
            </div>
        </div>

        <!-- Toolbar Row 2: Sort & Filters -->
        <div
            class="flex items-center justify-between gap-2 px-2 py-1.5 border-b border-(--border-default) bg-(--surface-primary)"
        >
            <!-- Sort Toggle Button -->
            <!-- Sort Controls -->
            <div
                class="flex items-center rounded-lg bg-(--surface-elevated) border border-(--border-default) shadow-sm h-7"
            >
                <!-- Direction Toggle -->
                <button
                    @click="store.toggleSort(sortField)"
                    class="flex items-center justify-center w-7 h-full text-(--text-secondary) hover:text-(--text-primary) hover:bg-(--surface-tertiary) rounded-l-lg border-r border-(--border-default) transition-colors"
                    :title="sortOrder === 'asc' ? 'Switch to Descending' : 'Switch to Ascending'"
                >
                    <component
                        :is="sortOrder === 'asc' ? ArrowUpIcon : ArrowDownIcon"
                        class="w-3.5 h-3.5 text-(--interactive-primary)"
                    />
                </button>

                <!-- Field Selector -->
                <Dropdown :items="sortItems" align="start">
                    <template #trigger>
                        <button
                            class="flex items-center gap-1.5 px-2.5 h-full text-xs font-semibold text-(--text-secondary) hover:text-(--text-primary) hover:bg-(--surface-tertiary) rounded-r-lg transition-colors"
                            title="Change sort field"
                        >
                            <span>{{ sortFieldLabel }}</span>
                        </button>
                    </template>
                </Dropdown>
            </div>

            <!-- Filter Button -->
            <button
                @click="showFilters = !showFilters"
                class="flex items-center gap-1 px-2 py-1 text-xs font-medium rounded-md transition-colors"
                :class="
                    hasActiveFilters
                        ? 'bg-(--interactive-primary)/10 text-(--interactive-primary)'
                        : 'text-(--text-secondary) hover:text-(--text-primary) hover:bg-(--surface-tertiary)'
                "
            >
                <FilterIcon class="w-3.5 h-3.5" />
                Filters
                <span
                    v-if="hasActiveFilters"
                    class="w-1.5 h-1.5 rounded-full bg-(--interactive-primary)"
                ></span>
            </button>
        </div>

        <!-- Filter Panel (Collapsible) -->
        <Transition name="slide">
            <div
                v-if="showFilters"
                class="px-3 py-2 border-b border-(--border-default) bg-(--surface-secondary) space-y-2"
            >
                <div class="flex items-center gap-2">
                    <label
                        class="text-xs text-(--text-muted) w-10 shrink-0"
                        >From</label
                    >
                    <input
                        type="date"
                        v-model="filterDateFrom"
                        class="flex-1 px-2 py-1 text-xs border border-(--border-default) rounded bg-(--surface-elevated) text-(--text-primary) focus:outline-none focus:ring-1 focus:ring-(--interactive-primary)"
                    />
                </div>
                <div class="flex items-center gap-2">
                    <label
                        class="text-xs text-(--text-muted) w-10 shrink-0"
                        >To</label
                    >
                    <input
                        type="date"
                        v-model="filterDateTo"
                        class="flex-1 px-2 py-1 text-xs border border-(--border-default) rounded bg-(--surface-elevated) text-(--text-primary) focus:outline-none focus:ring-1 focus:ring-(--interactive-primary)"
                    />
                </div>
                <div class="flex justify-end gap-2 pt-1">
                    <button
                        @click="clearFilters"
                        class="px-2 py-1 text-xs text-(--text-muted) hover:text-(--text-primary) transition-colors"
                    >
                        Clear
                    </button>
                    <button
                        @click="showFilters = false"
                        class="px-2 py-1 text-xs bg-(--interactive-primary) text-white rounded hover:bg-(--interactive-primary-hover) transition-colors"
                    >
                        Apply
                    </button>
                </div>
            </div>
        </Transition>

        <!-- Health Banners -->
        <div
            v-if="
                accountStatus?.status === 'failed' && !accountStatus.needsReauth
            "
            class="bg-red-50 text-red-700 px-4 py-3 text-xs flex items-center gap-3 border-b border-red-100 animate-in slide-in-from-top duration-300"
        >
            <AlertOctagonIcon class="w-4 h-4 shrink-0" />
            <div class="flex-1 min-w-0">
                <p class="font-semibold">Sync Failed</p>
                <p class="truncate opacity-80">{{ accountStatus.error }}</p>
            </div>
        </div>
        <div
            v-else-if="
                accountStatus?.status === 'syncing' ||
                accountStatus?.status === 'seeding'
            "
            class="bg-blue-50 text-blue-700 px-4 py-3 text-xs flex items-center gap-3 border-b border-blue-100 animate-in slide-in-from-top duration-300"
        >
            <LoaderIcon class="w-4 h-4 animate-spin shrink-0" />
            <div class="flex-1 min-w-0">
                <p class="font-semibold">Syncing Mailbox</p>
                <p class="truncate opacity-80">Fetching your emails from the provider...</p>
            </div>
        </div>

        <!-- Blocking View for Re-auth -->
        <div
            v-if="accountStatus?.needsReauth"
            class="flex-1 flex flex-col items-center justify-center p-6 text-center bg-(--surface-primary)"
        >
            <AlertOctagonIcon class="w-12 h-12 text-red-500 mb-2" />
            <h3 class="font-bold text-slate-900 mb-1">Account Disconnected</h3>
            <p class="text-sm text-slate-500 mb-4">{{ accountStatus.error }}</p>
            <router-link
                to="/email/settings"
                class="px-3 py-1.5 bg-red-600 text-white rounded-md text-sm font-medium hover:bg-red-700"
            >
                Reconnect Account
            </router-link>
        </div>

        <!-- Email List -->
        <div
            v-else
            ref="listRef"
            class="flex-1 overflow-y-auto min-h-0 relative"
        >
            <!-- New Email Toast -->
            <button
                v-if="newEmailCount > 0"
                @click="store.loadNewEmails()"
                class="absolute top-2 left-1/2 -translate-x-1/2 z-10 bg-(--interactive-primary) text-white px-3 py-1.5 rounded-full text-xs font-medium shadow-lg hover:bg-(--interactive-primary-hover) transition flex items-center gap-1 cursor-pointer"
            >
                <ArrowUpDownIcon class="w-3 h-3" />
                {{ newEmailCount }} new email(s)
            </button>
            <!-- Loading Skeleton -->
            <div v-if="loading" class="p-4 space-y-4">
                <div v-for="i in 6" :key="i" class="flex gap-3 animate-pulse">
                    <div
                        class="w-5 h-5 bg-(--surface-tertiary) rounded shrink-0"
                    ></div>
                    <div class="flex-1 space-y-2">
                        <div
                            class="h-4 bg-(--surface-tertiary) rounded w-3/4"
                        ></div>
                        <div
                            class="h-3 bg-(--surface-tertiary) rounded w-1/2"
                        ></div>
                    </div>
                </div>
            </div>

            <!-- Empty State for Syncing -->
            <div
                v-else-if="
                    sortedEmails.length === 0 &&
                    (accountStatus?.status === 'syncing' ||
                        accountStatus?.status === 'seeding')
                "
                class="flex flex-col items-center justify-center h-full p-8 text-center"
            >
                <div class="relative mb-4">
                    <div
                        class="absolute inset-0 bg-(--interactive-primary)/10 rounded-full animate-ping"
                    ></div>
                    <div
                        class="relative bg-(--interactive-primary)/20 p-4 rounded-full"
                    >
                        <LoaderIcon
                            class="w-10 h-10 text-(--interactive-primary) animate-spin"
                        />
                    </div>
                </div>
                <h3 class="text-lg font-bold text-(--text-primary) mb-2">
                    Syncing Mailbox
                </h3>
                <p class="text-sm text-(--text-secondary) max-w-[200px]">
                    We're fetching your emails for the first time. This may take
                    a few moments...
                </p>
            </div>

            <div
                v-else-if="sortedEmails.length === 0"
                class="flex flex-col items-center justify-center h-full p-8 text-center"
            >
                <MailIcon class="w-12 h-12 text-(--text-muted) mb-4" />
                <h3 class="text-lg font-bold text-(--text-primary) mb-1">
                    No emails found
                </h3>
                <p class="text-sm text-(--text-secondary)">
                    This folder is empty.
                </p>
            </div>

            <ul v-else class="divide-y divide-(--border-subtle)">
                <li
                    v-for="email in sortedEmails"
                    :key="email.id"
                    @click="handleSelect(email)"
                    class="email-item relative flex items-start px-4 py-3 cursor-pointer group transition-colors duration-150"
                    :class="[
                        selectedEmailId === email.id
                            ? 'bg-(--surface-tertiary)'
                            : 'hover:bg-(--surface-secondary)',
                        !email.is_read
                            ? 'bg-(--surface-elevated)/50'
                            : 'bg-(--surface-primary)',
                    ]"
                >
                    <!-- Unread Accent Bar -->
                    <div
                        v-if="!email.is_read"
                        class="absolute left-0 top-0 bottom-0 w-1 bg-(--interactive-primary)"
                    ></div>

                    <!-- Checkbox -->
                    <div class="flex items-center h-5">
                        <input
                            @click.stop
                            type="checkbox"
                            :checked="selectedEmailIds.has(email.id)"
                            @change="toggleSelection(email.id)"
                            class="h-4 w-4 text-(--interactive-primary) focus:ring-(--interactive-primary) border-(--border-default) rounded opacity-0 group-hover:opacity-100 transition-opacity"
                            :class="{
                                'opacity-100': selectedEmailIds.has(email.id),
                            }"
                        />
                    </div>

                    <!-- Star -->
                    <div
                        @click.stop="store.toggleStar(email.id)"
                        class="ml-2 mr-3 pt-0.5 transform active:scale-125 transition-transform"
                    >
                        <StarIcon
                            :class="[
                                email.is_starred
                                    ? 'text-yellow-400 fill-current'
                                    : 'text-(--text-muted) hover:text-yellow-400',
                                'w-4 h-4 transition-colors',
                            ]"
                        />
                    </div>

                    <!-- Content -->
                    <div class="min-w-0 flex-1">
                        <div class="flex justify-between items-baseline mb-1">
                            <p
                                class="text-sm text-(--text-primary) truncate pr-2 flex items-center gap-1.5"
                                :class="{ 'font-bold': !email.is_read }"
                            >
                                <span
                                    v-if="!email.is_read"
                                    class="w-2 h-2 shrink-0 rounded-full bg-(--interactive-primary) shadow-[0_0_8px_var(--interactive-primary)] animate-pulse inline-block"
                                ></span>
                                {{
                                    email.from_name ||
                                    email.from_email ||
                                    "Unknown"
                                }}
                            </p>
                            <span
                                class="text-xs text-(--text-muted) whitespace-nowrap"
                            >
                                <span
                                    v-if="email.thread_count > 1"
                                    class="mr-1 inline-flex items-center justify-center bg-(--surface-tertiary) text-(--text-secondary) rounded-full px-1.5 min-w-5 h-5 text-[10px] font-bold"
                                >
                                    {{ email.thread_count }}
                                </span>
                                {{ formatDate(email.date) }}
                            </span>
                        </div>
                        <p
                            class="text-sm text-(--text-secondary) truncate"
                            :class="!email.is_read ? 'font-bold' : 'font-medium'"
                        >
                            {{ email.subject }}
                        </p>
                        <div class="flex items-center gap-1 mt-0.5">
                            <PaperclipIcon
                                v-if="email.has_attachments"
                                class="w-3 h-3 text-(--text-secondary) shrink-0"
                            />
                            <p
                                class="text-xs text-(--text-tertiary) line-clamp-2"
                            >
                                {{ email.preview }}
                            </p>
                        </div>
                        <!-- Labels -->
                        <div
                            v-if="email.labels && email.labels.length"
                            class="mt-2 flex flex-wrap gap-1"
                        >
                            <span
                                v-for="label in email.labels"
                                :key="label"
                                class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-(--color-info-bg) text-(--color-info-fg)"
                            >
                                {{ label }}
                            </span>
                        </div>
                    </div>
                </li>

                <!-- Sentinel for Infinite Scroll -->
                <li
                    ref="sentinel"
                    class="p-4 flex justify-center"
                    v-if="!loading && sortedEmails.length > 0"
                >
                    <div
                        v-if="isLoadingMore"
                        class="w-5 h-5 border-2 border-(--text-muted) border-t-(--interactive-primary) rounded-full animate-spin"
                    ></div>
                    <span v-else class="h-1 w-full"></span>
                </li>
            </ul>
        </div>

        <!-- Sticky Bottom Stats Bar -->
        <div
            class="px-3 py-2 border-t border-(--border-default) bg-(--surface-secondary) text-xs text-(--text-muted) flex items-center justify-between shrink-0"
        >
            <span
                >{{ filteredEmails.length }} of
                {{ totalEmails || filteredEmails.length }}
                emails</span
            >
            <div class="flex items-center gap-3">
                <span v-if="unreadCount > 0">{{ unreadCount }} unread</span>
                <button
                    @click="$emit('compose')"
                    :disabled="!selectedAccount"
                    class="md:hidden flex items-center gap-1 px-2.5 py-1.5 bg-(--interactive-primary) text-white rounded-md font-semibold hover:bg-(--interactive-primary-hover) transition-all active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed disabled:scale-100"
                >
                    <PlusIcon class="w-3.5 h-3.5" />
                    Compose
                </button>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import {
    SearchIcon,
    TrashIcon,
    MailOpenIcon,
    MailIcon,
    MenuIcon,
    StarIcon,
    FilterIcon,
    PaperclipIcon,
    AlertOctagonIcon,
    LoaderIcon,
    PlusIcon,
    ArrowUpIcon,
    ArrowDownIcon,
    ArrowUpDownIcon,
} from "lucide-vue-next";
import { useEmailStore } from "@/stores/emailStore";
import { storeToRefs } from "pinia";
import { isToday, format } from "date-fns";
import {
    ref,
    computed,
    onMounted,
    watch,
    nextTick,
    onBeforeUnmount,
} from "vue";
import { animate, stagger } from "animejs";
import Dropdown from "@/components/ui/Dropdown.vue";
import MoveToFolderDropdown from "./MoveToFolderDropdown.vue";
import { debounce } from "lodash";

type SortField = "date" | "sender" | "subject";
type SortOrder = "asc" | "desc";
const emit = defineEmits(["toggle-sidebar", "select", "compose"]);

const store = useEmailStore();
const {
    filteredEmails,
    selectedAccount,
    selectedEmailId,
    selectedEmailIds,
    loading,
    searchQuery,
    filterDateFrom,
    filterDateTo,
    hasActiveFilters,
    isLoadingMore,
    newEmailCount,
    accountStatus,
    totalEmails,
    sortField,
    sortOrder,
    sortedEmails,
} = storeToRefs(store);

// Track total email count from store

const listRef = ref<HTMLElement | null>(null);
let animation: any = null;

const showFilters = ref(false);

// Filter Watcher
const debouncedFilter = debounce(() => {
    store.applyFilters();
}, 500);

watch([searchQuery, filterDateFrom, filterDateTo], () => {
    debouncedFilter();
});

const sortFieldLabel = computed(() => {
    const fieldMap = { date: "Date", sender: "Sender", subject: "Subject" };
    return fieldMap[sortField.value];
});

const unreadCount = computed(
    () => filteredEmails.value.filter((e) => !e.is_read).length,
);

const sortItems = computed(() => [
    {
        label: "Date",
        icon: sortField.value === "date" ? (sortOrder.value === "desc" ? ArrowUpIcon : ArrowDownIcon) : undefined,
        action: () => store.toggleSort("date")
    },
    {
        label: "Sender",
        icon: sortField.value === "sender" ? (sortOrder.value === "asc" ? ArrowUpIcon : ArrowDownIcon) : undefined,
        action: () => store.toggleSort("sender")
    },
    {
        label: "Subject",
        icon: sortField.value === "subject" ? (sortOrder.value === "asc" ? ArrowUpIcon : ArrowDownIcon) : undefined,
        action: () => store.toggleSort("subject")
    },
]);


function clearFilters() {
    store.filterDateFrom = "";
    store.filterDateTo = "";
}

function formatDate(dateString: string) {
    if (!dateString) return "";
    const date = new Date(dateString);
    if (isNaN(date.getTime())) return "Invalid Date";
    return isToday(date) ? format(date, "h:mm a") : format(date, "MMM d");
}

function toggleSelectAll() {
    if (
        selectedEmailIds.value.size === filteredEmails.value.length &&
        filteredEmails.value.length > 0
    ) {
        selectedEmailIds.value.clear();
    } else {
        filteredEmails.value.forEach((e) => selectedEmailIds.value.add(e.id));
    }
}

function toggleSelection(id: string) {
    if (selectedEmailIds.value.has(id)) {
        selectedEmailIds.value.delete(id);
    } else {
        selectedEmailIds.value.add(id);
    }
}

function deleteSelected() {
    if (confirm(`Delete ${selectedEmailIds.value.size} emails?`)) {
        store.deleteEmails(Array.from(selectedEmailIds.value));
    }
}

function markSelectedRead(isRead: boolean = true) {
    store.markEmailsAsRead(Array.from(selectedEmailIds.value), isRead);
}

function handleSelect(email: any) {
    store.selectedEmailId = email.id;
    // Mark as read immediately for UI feedback AND persist
    if (!email.is_read) {
        email.is_read = true;
        store.markAsRead(email.id, true);
    }
    emit("select", email);
}

function animateList(isAppend = false) {
    if (!listRef.value) return;

    const allItems = listRef.value.querySelectorAll(".email-item");
    if (allItems.length === 0) return;

    let targets: NodeListOf<Element> | Element[];
    
    if (isAppend) {
        // Only target the last 20 items (default per_page)
        const batchSize = 20;
        targets = Array.from(allItems).slice(-batchSize);
    } else {
        // Full list animation (e.g. folder change)
        if (animation) animation.pause();
        targets = allItems;
    }

    animation = animate(targets, {
        opacity: [0, 1],
        translateY: [10, 0],
        duration: 300,
        delay: stagger(30),
        easing: "easeOutQuad",
    });
}

// Watchers
watch(sortedEmails, (newVal, oldVal) => {
    // Detect if this was an append or a reset
    const isAppend = oldVal && newVal.length > oldVal.length && newVal[0]?.id === oldVal[0]?.id;
    
    nextTick(() => animateList(isAppend));
});

watch(loading, (newVal) => {
    if (!newVal) {
        nextTick(() => animateList());
    }
});

const sentinel = ref<HTMLElement | null>(null);
let observer: IntersectionObserver | null = null;

function setupObserver() {
    if (observer) observer.disconnect();

    observer = new IntersectionObserver(
        (entries) => {
            const entry = entries[0];
            if (entry.isIntersecting) {
                store.loadMore();
            }
        },
        {
            root: listRef.value,
            threshold: 0.1,
            rootMargin: "100px",
        },
    );

    if (sentinel.value) {
        observer.observe(sentinel.value);
    }
}

watch(sentinel, (el) => {
    if (el) setupObserver();
});

onMounted(() => {
    store.fetchEmails();
    animateList();
});

onBeforeUnmount(() => {
    if (animation) animation.pause();
});
</script>

<style scoped>
.slide-enter-active,
.slide-leave-active {
    transition: all 0.2s ease;
    overflow: hidden;
}
.slide-enter-from,
.slide-leave-to {
    opacity: 0;
    max-height: 0;
    padding-top: 0;
    padding-bottom: 0;
}
.slide-enter-to,
.slide-leave-from {
    max-height: 100px;
}
</style>
