<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import api from "@/lib/api";
import { CheckCircle, Circle, Plus, Trash2, Loader2 } from "lucide-vue-next";

interface PersonalTask {
    public_id: string;
    title: string;
    description?: string;
    status: "todo" | "done";
    priority: number;
    due_date?: string;
    completed_at?: string;
}

const tasks = ref<PersonalTask[]>([]);
const loading = ref(false);
const newTaskTitle = ref("");
const creating = ref(false);

const pendingCount = computed(
    () => tasks.value?.filter((t) => t.status === "todo").length || 0,
);

const fetchTasks = async () => {
    loading.value = true;
    try {
        const response = await api.get("/api/personal-tasks");
        tasks.value = response.data?.data || [];
    } catch (error) {
        console.error("Failed to fetch personal tasks", error);
    } finally {
        loading.value = false;
    }
};

const addTask = async () => {
    if (!newTaskTitle.value.trim()) return;

    creating.value = true;
    try {
        const response = await api.post("/api/personal-tasks", {
            title: newTaskTitle.value,
        });
        tasks.value.unshift(response.data.data);
        newTaskTitle.value = "";
    } catch (error) {
        console.error("Failed to create task", error);
    } finally {
        creating.value = false;
    }
};

const toggleTask = async (task: PersonalTask) => {
    const originalStatus = task.status;
    const newStatus = originalStatus === "todo" ? "done" : "todo";

    // Optimistic update
    task.status = newStatus;

    try {
        await api.put(`/api/personal-tasks/${task.public_id}`, {
            status: newStatus,
        });
    } catch (error) {
        // Revert on failure
        task.status = originalStatus;
        console.error("Failed to update task", error);
    }
};

const deleteTask = async (task: PersonalTask) => {
    if (!confirm("Are you sure you want to delete this task?")) return;

    const index = tasks.value.findIndex((t) => t.public_id === task.public_id);
    if (index === -1) return;

    // Optimistic remove
    const removed = tasks.value.splice(index, 1)[0];

    try {
        await api.delete(`/api/personal-tasks/${task.public_id}`);
    } catch (error) {
        // Revert
        tasks.value.splice(index, 0, removed);
        console.error("Failed to delete task", error);
    }
};

onMounted(() => {
    fetchTasks();
});
</script>

<template>
    <div
        class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-sm overflow-hidden"
    >
        <div class="p-6">
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center gap-3">
                    <div
                        class="p-2 rounded-lg bg-orange-100 dark:bg-orange-900/20 text-orange-600 dark:text-orange-500"
                    >
                        <CheckCircle class="w-5 h-5" />
                    </div>
                    <div>
                        <h3
                            class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 leading-none"
                        >
                            My Tasks
                        </h3>
                        <p
                            class="text-sm text-zinc-500 dark:text-zinc-400 mt-1"
                        >
                            Personal To-Do List
                        </p>
                    </div>
                </div>
                <div
                    v-if="pendingCount > 0"
                    class="px-2.5 py-1 rounded-full bg-zinc-100 dark:bg-zinc-800 text-xs font-medium text-zinc-600 dark:text-zinc-400 border border-zinc-200 dark:border-zinc-700"
                >
                    {{ pendingCount }} pending
                </div>
            </div>

            <!-- Add Task Input -->
            <div class="relative group mb-6">
                <div
                    class="absolute inset-y-0 left-3 flex items-center pointer-events-none"
                >
                    <Plus
                        class="w-5 h-5 text-zinc-400 group-focus-within:text-orange-500 transition-colors"
                    />
                </div>
                <input
                    v-model="newTaskTitle"
                    @keydown.enter="addTask"
                    type="text"
                    placeholder="Add a new task..."
                    class="w-full pl-10 pr-12 py-3 bg-zinc-50 dark:bg-zinc-900/50 border border-zinc-200 dark:border-zinc-700 rounded-xl text-sm focus:ring-2 focus:ring-orange-500/20 focus:border-orange-500 transition-all placeholder:text-zinc-400 text-zinc-900 dark:text-zinc-100"
                    :disabled="creating"
                />
                <button
                    @click="addTask"
                    :disabled="!newTaskTitle.trim() || creating"
                    class="absolute right-2 top-2 p-1.5 rounded-lg bg-orange-500 text-white hover:bg-orange-600 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                >
                    <Loader2 v-if="creating" class="w-4 h-4 animate-spin" />
                    <Plus v-else class="w-4 h-4" />
                </button>
            </div>

            <!-- Task List -->
            <div class="space-y-1">
                <div
                    v-if="loading && tasks.length === 0"
                    class="py-8 flex justify-center text-zinc-400"
                >
                    <Loader2 class="w-6 h-6 animate-spin" />
                </div>

                <div v-else-if="tasks.length === 0" class="py-8 text-center">
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        No tasks yet. Add one above!
                    </p>
                </div>

                <div
                    v-for="task in tasks"
                    :key="task.public_id"
                    class="group flex items-center gap-3 p-3 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors cursor-pointer"
                    @click="toggleTask(task)"
                >
                    <div
                        class="flex-shrink-0 text-zinc-400 group-hover:text-zinc-500 dark:text-zinc-500 dark:group-hover:text-zinc-400 transition-colors"
                        :class="{
                            'text-green-500 dark:text-green-500':
                                task.status === 'done',
                        }"
                    >
                        <CheckCircle
                            v-if="task.status === 'done'"
                            class="w-5 h-5 fill-green-500/10"
                        />
                        <Circle v-else class="w-5 h-5" />
                    </div>

                    <span
                        class="flex-grow text-sm transition-all"
                        :class="
                            task.status === 'done'
                                ? 'text-zinc-400 dark:text-zinc-500 line-through'
                                : 'text-zinc-700 dark:text-zinc-300'
                        "
                    >
                        {{ task.title }}
                    </span>

                    <button
                        @click.stop="deleteTask(task)"
                        class="opacity-0 group-hover:opacity-100 p-1.5 text-zinc-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-md transition-all"
                        title="Delete task"
                    >
                        <Trash2 class="w-4 h-4" />
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
