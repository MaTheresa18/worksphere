<script setup lang="ts">
import { ref, computed, watch, nextTick } from "vue";
import { Modal, Button, Input, Textarea, SelectFilter } from "@/components/ui";
import { useForm } from "vee-validate";
import { toTypedSchema } from "@vee-validate/zod";
import * as z from "zod";
import axios from "axios";
import { toast } from "vue-sonner";
import { useAuthStore } from "@/stores/auth";
import {
    taskTemplateService,
    type TaskTemplate,
} from "@/services/task-template.service";

const authStore = useAuthStore();

interface Props {
    open: boolean;
    task?: any; // If editing
    teamId?: string;
    projectId?: string;
    projectMembers?: any[];
}

const props = withDefaults(defineProps<Props>(), {
    projectMembers: () => [],
});
const emit = defineEmits(["update:open", "task-saved", "close"]);

const isEditing = computed(() => !!props.task);
const isLoading = ref(false);
const isFetchingMembers = ref(false);

const isOpen = computed({
    get: () => props.open,
    set: (val) => {
        emit("update:open", val);
        if (!val) emit("close");
    },
});

const schema = toTypedSchema(
    z.object({
        title: z.string().min(1, "Title is required").max(255),
        description: z.string().optional(),
        status: z.string().min(1, "Status is required"),
        priority: z.number().min(1, "Priority is required"),
        due_date: z.string().min(1, "Due date is required"),
        assigned_to: z.string().optional(),
        qa_user_id: z.string().optional(),
        estimated_hours: z.number().min(0).optional(),
    }),
);
const { setValues } = useForm({
    validationSchema: schema,
    initialValues: {
        status: "open",
        priority: 2,
    },
});

const formValues = ref({
    title: "",
    description: "",
    status: "open",
    priority: 2,
    due_date: "",
    assigned_to: "",
    qa_user_id: "",
    estimated_hours: 0,
    checklist: [] as any[],
    save_as_template: false,
});

// Checklist state
const newChecklistItem = ref("");

const addChecklistItem = () => {
    if (!newChecklistItem.value.trim()) return;
    formValues.value.checklist.push({
        title: newChecklistItem.value.trim(),
        is_completed: false,
    });
    newChecklistItem.value = "";
};

const removeChecklistItem = (index: number) => {
    formValues.value.checklist.splice(index, 1);
};

const resetFormValues = () => {
    setValues({
        status: "open",
        priority: 2,
    });
    formValues.value = {
        title: "",
        description: "",
        status: "open",
        priority: 2,
        due_date: "",
        assigned_to: "",
        qa_user_id: "",
        estimated_hours: 0,
        checklist: [],
        save_as_template: false,
    };
    newChecklistItem.value = "";
    selectedTemplateId.value = "";
};

// Options
const statusOptions = [
    { value: "open", label: "To Do" },
    { value: "in_progress", label: "In Progress" },
    { value: "on_hold", label: "On Hold" },
    { value: "submitted", label: "Submitted" },
    { value: "in_qa", label: "In QA" },
    { value: "approved", label: "QA Approved" },
    { value: "rejected", label: "QA Rejected" },
    { value: "pm_review", label: "PM Review" },
    { value: "sent_to_client", label: "Sent to Client" },
    { value: "client_approved", label: "Client Approved" },
    { value: "client_rejected", label: "Client Rejected" },
    { value: "completed", label: "Done" },
];

const priorityOptions = [
    { value: 1, label: "Low" },
    { value: 2, label: "Medium" },
    { value: 3, label: "High" },
    { value: 4, label: "Urgent" },
];

const localMembers = ref<any[]>([]);

const canEditMetadata = computed(() => {
    if (props.task) return (props.task as any).can?.edit_metadata;
    if (!selectedTeamId.value) return true; // Default to true if creating and team not yet selected (entry gated by teamOptions)
    return authStore.hasTeamPermission(selectedTeamId.value, "tasks.edit_all");
});

const canManageChecklist = computed(() => {
    if (props.task) return (props.task as any).can?.manage_checklist;
    if (!selectedTeamId.value) return true;
    return authStore.hasTeamPermission(
        selectedTeamId.value,
        "tasks.manage_checklist",
    );
});

const canAssign = computed(() => {
    if (props.task) return (props.task as any).can?.assign;
    if (!selectedTeamId.value) return true;
    return authStore.hasTeamPermission(selectedTeamId.value, "tasks.assign");
});

const isReadOnly = computed(() => {
    if (props.task) return (props.task as any).can?.is_read_only;
    return false;
});

const operatorMemberOptions = computed(() => {
    const list =
        props.projectMembers && props.projectMembers.length > 0
            ? props.projectMembers
            : localMembers.value;

    return list.map((m: any) => ({
        value: m.public_id || m.id,
        label: m.name,
        avatar: m.avatar_url,
        subtitle:
            (m.team_role || m.role)
                ?.replace(/_/g, " ")
                .replace(/\b\w/g, (c: string) => c.toUpperCase()) || m.email,
    }));
});

const qaMemberOptions = computed(() => {
    const list =
        props.projectMembers && props.projectMembers.length > 0
            ? props.projectMembers
            : localMembers.value;

    return list
        .filter((m: any) => m.is_qa_eligible)
        .map((m: any) => ({
            value: m.public_id || m.id,
            label: m.name,
            avatar: m.avatar_url,
            subtitle:
                (m.team_role || m.role)
                    ?.replace(/_/g, " ")
                    .replace(/\b\w/g, (c: string) => c.toUpperCase()) ||
                m.email,
        }));
});

// Dynamic State for Selectors
const selectedTeamId = ref("");
const selectedProjectId = ref("");
const projectOptions = ref<any[]>([]);

const teamOptions = computed(() => {
    if (!authStore.user?.teams) return [];

    return authStore.user.teams
        .filter((team) => {
            // Super admin check
            if (authStore.isSuperAdmin) return true;

            const perms =
                authStore.user?.team_permissions?.[team.public_id] || [];
            return perms.includes("tasks.create");
        })
        .map((team) => ({
            value: team.public_id,
            label: team.name,
        }));
});

// Fetch projects when team changes
const fetchProjects = async () => {
    if (!selectedTeamId.value) return;
    try {
        const response = await axios.get(
            `/api/teams/${selectedTeamId.value}/projects`,
        );
        projectOptions.value = response.data.data.map((p: any) => ({
            value: p.public_id,
            label: p.name,
        }));
    } catch (error) {
        console.error("Failed to fetch projects", error);
    }
};

const fetchMembers = async () => {
    if (props.projectMembers && props.projectMembers.length > 0) return;
    if (!selectedTeamId.value || !selectedProjectId.value) return;

    try {
        isFetchingMembers.value = true;
        const response = await axios.get(
            `/api/teams/${selectedTeamId.value}/projects/${selectedProjectId.value}`,
        );
        localMembers.value = response.data.data?.members || [];
    } catch (error) {
        console.error("Failed to fetch project members", error);
    } finally {
        isFetchingMembers.value = false;
    }
};

// Watchers for Dependencies
watch(
    () => selectedTeamId.value,
    (newVal, oldVal) => {
        if (oldVal && newVal !== oldVal) {
            projectOptions.value = [];
            selectedProjectId.value = "";
            localMembers.value = [];
        }
        if (newVal) {
            fetchProjects();
        }
    },
);

watch(
    () => selectedProjectId.value,
    (newVal, oldVal) => {
        if (newVal && newVal !== oldVal) {
            localMembers.value = [];
            fetchMembers();
        }
    },
);

// Templates Logic
const templates = ref<TaskTemplate[]>([]);
const selectedTemplateId = ref("");

const templateOptions = computed(() => {
    return templates.value.map((t) => ({
        value: t.public_id,
        label: t.name,
    }));
});

const fetchTemplates = async () => {
    if (!selectedTeamId.value) return;
    try {
        const data = await taskTemplateService.getAll(selectedTeamId.value);
        templates.value = data;
    } catch (error) {
        console.error("Failed to fetch templates", error);
    }
};

watch(
    () => selectedTeamId.value,
    (newVal) => {
        if (newVal) {
            fetchTemplates();
        } else {
            templates.value = [];
        }
    },
);

watch(
    () => selectedTemplateId.value,
    (newVal) => {
        const template = templates.value.find((t) => t.public_id === newVal);
        if (template) {
            setValues({
                status: "open",
                priority:
                    template.default_priority === "low"
                        ? 1
                        : template.default_priority === "medium"
                          ? 2
                          : template.default_priority === "high"
                            ? 3
                            : template.default_priority === "urgent"
                              ? 4
                              : 2,
            });

            formValues.value.title = template.name.replace(" (Template)", "");
            formValues.value.description =
                template.description || formValues.value.description;
            formValues.value.priority =
                template.default_priority === "low"
                    ? 1
                    : template.default_priority === "medium"
                      ? 2
                      : template.default_priority === "high"
                        ? 3
                        : template.default_priority === "urgent"
                          ? 4
                          : 2;
            formValues.value.estimated_hours =
                template.default_estimated_hours || 0;

            if (template.checklist_template) {
                formValues.value.checklist = template.checklist_template.map(
                    (item: any) => ({
                        title: item.title || item.text || "",
                        is_completed: item.is_completed || false,
                    }),
                );
            }
            toast.success("Template loaded");
        }
    },
);

const initializeModal = async () => {
    if (props.teamId) {
        selectedTeamId.value = props.teamId;
    } else if (!selectedTeamId.value && authStore.user?.teams?.length === 1) {
        selectedTeamId.value = authStore.user.teams[0].public_id;
    }

    if (props.projectId) {
        selectedProjectId.value = props.projectId;
    }

    if (selectedTeamId.value && projectOptions.value.length === 0) {
        await fetchProjects();
    }
    if (selectedTeamId.value && templates.value.length === 0) {
        await fetchTemplates();
    }
    if (
        selectedTeamId.value &&
        selectedProjectId.value &&
        (!props.projectMembers || props.projectMembers.length === 0)
    ) {
        await fetchMembers();
    }
};

watch(
    () => props.open,
    async (isOpenVal) => {
        if (isOpenVal) {
            await nextTick();
            await initializeModal();
        }
    },
    { immediate: true },
);

watch(
    () => props.task,
    (newTask) => {
        if (newTask) {
            const statusValue =
                typeof newTask.status === "object"
                    ? newTask.status?.value
                    : newTask.status;
            const priorityValue =
                typeof newTask.priority === "object"
                    ? newTask.priority?.value
                    : newTask.priority;

            setValues({
                title: newTask.title,
                description: newTask.description,
                status: statusValue || "open",
                priority: priorityValue || 2,
                due_date: newTask.due_date
                    ? new Date(newTask.due_date).toISOString()
                    : "",
                assigned_to: newTask.assignee?.id || newTask.assigned_to || "",
                estimated_hours: Number(newTask.estimated_hours) || 0,
            });

            formValues.value = {
                title: newTask.title,
                description: newTask.description || "",
                status: statusValue || "open",
                priority: priorityValue || 2,
                due_date: newTask.due_date
                    ? new Date(newTask.due_date).toISOString().split("T")[0]
                    : "",
                assigned_to: newTask.assignee?.id || newTask.assigned_to || "",
                qa_user_id: newTask.qa_user?.id || newTask.qa_user_id || "",
                estimated_hours: Number(newTask.estimated_hours) || 0,
                checklist:
                    newTask.checklist?.map((item: any) => ({
                        title:
                            typeof item === "string"
                                ? item
                                : item.title || item.text,
                        is_completed:
                            typeof item === "string"
                                ? false
                                : item.is_completed ||
                                  item.status === "done" ||
                                  false,
                    })) || [],
                save_as_template: false,
            };
        } else {
            resetFormValues();
            formValues.value = {
                title: "",
                description: "",
                status: "open",
                priority: 2,
                due_date: "",
                assigned_to: "",
                estimated_hours: 0,
                checklist: [],
                save_as_template: false,
            };
        }
    },
    { immediate: true },
);

const onSubmit = async () => {
    if (!formValues.value.title) {
        toast.error("Title is required");
        return;
    }

    if (!formValues.value.due_date) {
        toast.error("Due date is required");
        return;
    }

    if (formValues.value.checklist.length === 0) {
        toast.error("At least one checklist item is required");
        return;
    }

    try {
        isLoading.value = true;
        const payload = {
            ...formValues.value,
            checklist: formValues.value.checklist.map((item) => {
                if (typeof item === "string") {
                    return { title: item, is_completed: false };
                }
                return item;
            }),
        };

        const teamId =
            selectedTeamId.value ||
            props.teamId ||
            props.task?.project?.team_id ||
            props.task?.team_id ||
            "";
        const projectId =
            selectedProjectId.value ||
            props.projectId ||
            props.task?.project?.id ||
            props.task?.project?.public_id ||
            props.task?.project_id ||
            "";

        if (!teamId || !projectId) {
            toast.error("Please select a team and project");
            isLoading.value = false;
            return;
        }

        if (isEditing.value && props.task) {
            const taskId = props.task.public_id || props.task.id;
            const response = await axios.put(
                `/api/teams/${teamId}/projects/${projectId}/tasks/${taskId}`,
                payload,
            );
            emit("task-saved", response.data.data);
            toast.success("Task updated successfully");
        } else {
            const response = await axios.post(
                `/api/teams/${teamId}/projects/${projectId}/tasks`,
                payload,
            );
            emit("task-saved", response.data.data);
            toast.success("Task created successfully");
        }
        isOpen.value = false;
    } catch (err: any) {
        console.error("Failed to save task", err);
        toast.error(err.response?.data?.message || "Failed to save task");
    } finally {
        isLoading.value = false;
    }
};
</script>

<template>
    <Modal
        v-model:open="isOpen"
        :title="isEditing ? 'Edit Task' : 'Create New Task'"
        size="2xl"
    >
        <template #default>
            <form
                id="task-form"
                @submit.prevent="onSubmit"
                class="flex flex-col gap-6 p-2"
            >
                <div class="space-y-4">
                    <!-- Project/Team Context (Horizontal) -->
                    <div
                        v-if="!props.projectId && !isEditing"
                        class="grid grid-cols-2 gap-4"
                    >
                        <SelectFilter
                            v-model="selectedTeamId"
                            :options="teamOptions"
                            placeholder="Select Team"
                            class="w-full"
                        />
                        <SelectFilter
                            v-model="selectedProjectId"
                            :options="projectOptions"
                            placeholder="Select Project"
                            :disabled="!selectedTeamId"
                            class="w-full"
                        />
                    </div>

                    <!-- Template Loader -->
                    <div
                        v-if="
                            !isEditing && templates.length > 0 && selectedTeamId
                        "
                    >
                        <SelectFilter
                            v-model="selectedTemplateId"
                            :options="templateOptions"
                            placeholder="Load from template..."
                            class="w-full"
                        />
                    </div>

                    <!-- Title -->
                    <div>
                        <Input
                            v-model="formValues.title"
                            placeholder="Task Title"
                            required
                            class="text-xl font-bold border-none px-0 shadow-none focus:ring-0 bg-transparent placeholder-[var(--text-muted)]"
                            :disabled="!canEditMetadata || isReadOnly"
                        />
                    </div>

                    <!-- Properties Grid -->
                    <div
                        class="grid grid-cols-2 md:grid-cols-4 gap-4 bg-[var(--surface-secondary)]/30 p-4 rounded-xl border border-[var(--border-subtle)]"
                    >
                        <!-- Status -->
                        <div class="space-y-1">
                            <label
                                class="text-[10px] uppercase tracking-wider font-bold text-[var(--text-muted)]"
                                >Status</label
                            >
                            <SelectFilter
                                v-model="formValues.status"
                                :options="statusOptions"
                                placeholder="Status"
                                class="w-full text-sm"
                                :disabled="isReadOnly"
                            />
                        </div>

                        <!-- Priority -->
                        <div class="space-y-1">
                            <label
                                class="text-[10px] uppercase tracking-wider font-bold text-[var(--text-muted)]"
                                >Priority</label
                            >
                            <SelectFilter
                                v-model="formValues.priority"
                                :options="priorityOptions"
                                placeholder="Priority"
                                class="w-full text-sm"
                                :disabled="!canEditMetadata || isReadOnly"
                            />
                        </div>

                        <!-- Due Date -->
                        <div class="space-y-1">
                            <label
                                class="text-[10px] uppercase tracking-wider font-bold text-[var(--text-muted)]"
                                >Due Date
                                <span class="text-red-500">*</span></label
                            >
                            <Input
                                type="date"
                                v-model="formValues.due_date"
                                class="w-full text-sm h-10"
                                :disabled="!canEditMetadata || isReadOnly"
                            />
                        </div>

                        <!-- Est Hours -->
                        <div class="space-y-1">
                            <label
                                class="text-[10px] uppercase tracking-wider font-bold text-[var(--text-muted)]"
                                >Est. Hours</label
                            >
                            <Input
                                type="number"
                                step="0.5"
                                v-model="formValues.estimated_hours"
                                class="w-full text-sm h-10"
                                placeholder="0"
                                :disabled="!canEditMetadata || isReadOnly"
                            />
                        </div>
                    </div>

                    <!-- People Grid -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="space-y-1">
                            <label
                                class="text-[10px] uppercase tracking-wider font-bold text-[var(--text-muted)]"
                                >Assignee</label
                            >
                            <SelectFilter
                                v-model="formValues.assigned_to"
                                :options="operatorMemberOptions"
                                :placeholder="
                                    operatorMemberOptions.length === 0
                                        ? 'No members'
                                        : 'Unassigned'
                                "
                                :disabled="
                                    operatorMemberOptions.length === 0 ||
                                    !canAssign ||
                                    isReadOnly
                                "
                                class="w-full"
                                searchable
                            />
                        </div>
                        <div class="space-y-1">
                            <label
                                class="text-[10px] uppercase tracking-wider font-bold text-[var(--text-muted)]"
                                >QA Owner</label
                            >
                            <SelectFilter
                                v-model="formValues.qa_user_id"
                                :options="qaMemberOptions"
                                :placeholder="
                                    qaMemberOptions.length === 0
                                        ? 'No members'
                                        : 'Unassigned'
                                "
                                :disabled="
                                    qaMemberOptions.length === 0 ||
                                    isFetchingMembers ||
                                    !canAssign ||
                                    isReadOnly
                                "
                                class="w-full"
                                searchable
                            />
                        </div>
                    </div>

                    <!-- Description -->
                    <div class="space-y-2">
                        <label
                            class="text-sm font-medium text-[var(--text-secondary)]"
                            >Description</label
                        >
                        <div
                            class="bg-[var(--surface-primary)] border border-[var(--border-default)] rounded-xl overflow-hidden focus-within:border-[var(--interactive-primary)] focus-within:ring-2 focus-within:ring-[var(--interactive-primary)]/20 transition-all"
                        >
                            <Textarea
                                v-model="formValues.description"
                                placeholder="Add more details..."
                                rows="5"
                                borderless
                                class="border-none shadow-none bg-transparent focus:ring-0 focus-visible:ring-0 min-h-[120px] resize-y"
                                :disabled="!canEditMetadata || isReadOnly"
                            />
                        </div>
                    </div>

                    <!-- Checklist -->
                    <div
                        class="space-y-3 pt-4 border-t border-[var(--border-subtle)]"
                    >
                        <div class="flex items-center justify-between">
                            <label
                                class="text-sm font-medium text-[var(--text-secondary)]"
                                >Checklist <span class="text-red-500">*</span>
                                <span
                                    class="text-xs text-[var(--text-muted)] font-normal"
                                    >(Min. 1 item)</span
                                ></label
                            >
                            <span class="text-xs text-[var(--text-muted)]"
                                >{{
                                    formValues.checklist.filter((i) =>
                                        typeof i === "string"
                                            ? false
                                            : i.is_completed,
                                    ).length
                                }}/{{ formValues.checklist.length }}</span
                            >
                        </div>

                        <div class="space-y-2">
                            <div class="flex gap-2">
                                <Input
                                    v-model="newChecklistItem"
                                    placeholder="Add item..."
                                    @keydown.enter.prevent="addChecklistItem"
                                    class="flex-1"
                                    :disabled="
                                        !canManageChecklist || isReadOnly
                                    "
                                />
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="secondary"
                                    @click="addChecklistItem"
                                    >Add</Button
                                >
                            </div>

                            <div
                                v-if="formValues.checklist.length > 0"
                                class="space-y-2 mt-2"
                            >
                                <div
                                    v-for="(
                                        item, index
                                    ) in formValues.checklist"
                                    :key="index"
                                    class="flex items-center gap-3 p-2 rounded-lg bg-[var(--surface-secondary)]/30 border border-[var(--border-subtle)] hover:border-[var(--border-default)] transition-all group"
                                >
                                    <div
                                        class="w-4 h-4 rounded-full border border-[var(--border-default)] flex items-center justify-center"
                                    >
                                        <div
                                            v-if="
                                                typeof item !== 'string' &&
                                                item.is_completed
                                            "
                                            class="w-2.5 h-2.5 bg-[var(--success-DEFAULT)] rounded-full"
                                        ></div>
                                    </div>
                                    <span
                                        class="text-sm flex-1 text-[var(--text-primary)]"
                                        >{{
                                            typeof item === "string"
                                                ? item
                                                : item.title
                                        }}</span
                                    >
                                    <button
                                        v-if="canManageChecklist && !isReadOnly"
                                        type="button"
                                        @click="removeChecklistItem(index)"
                                        class="text-[var(--text-muted)] hover:text-red-500 opacity-0 group-hover:opacity-100 transition-opacity"
                                    >
                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            width="14"
                                            height="14"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="2"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                        >
                                            <line
                                                x1="18"
                                                y1="6"
                                                x2="6"
                                                y2="18"
                                            ></line>
                                            <line
                                                x1="6"
                                                y1="6"
                                                x2="18"
                                                y2="18"
                                            ></line>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    class="flex items-center gap-2 pt-4 border-t border-[var(--border-subtle)] mt-auto"
                >
                    <!-- Save as template -->
                    <label
                        class="flex items-center gap-2 text-sm text-[var(--text-secondary)] cursor-pointer select-none"
                    >
                        <input
                            type="checkbox"
                            v-model="formValues.save_as_template"
                            class="rounded border-[var(--border-default)] text-[var(--primary-DEFAULT)] focus:ring-[var(--primary-DEFAULT)]"
                        />
                        Save as Template
                    </label>
                    <div class="flex-1"></div>
                    <Button
                        variant="ghost"
                        type="button"
                        @click="isOpen = false"
                        >Cancel</Button
                    >
                    <Button type="submit" :loading="isLoading">{{
                        isEditing ? "Save Changes" : "Create Task"
                    }}</Button>
                </div>
            </form>
        </template>
    </Modal>
</template>
