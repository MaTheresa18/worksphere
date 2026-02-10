<script setup>
import { ref, onMounted, computed } from "vue";
import { Button, Input, Modal, Switch } from "@/components/ui";
import {
    Mail,
    Plus,
    Trash2,
    Edit2,
    CheckCircle,
    XCircle,
    RefreshCw,
    ExternalLink,
    AlertCircle,
    Activity,
} from "lucide-vue-next";
import { toast } from "vue-sonner";
import axios from "axios";
import EmailAccountWizard from "./EmailAccountWizard.vue";

const props = defineProps({
    teamId: {
        type: Number,
        default: null,
    },
    mode: {
        type: String,
        default: "personal", // 'personal' or 'system'
    },
});

const accounts = ref([]);
const providers = ref([]);
const isLoading = ref(true);
const showModal = ref(false);
const editingAccount = ref(null);
const isSaving = ref(false);
const isTesting = ref({});
const isCheckingHealth = ref({});
const healthResults = ref(null);
const showHealthModal = ref(false);
const healthCheckAccount = ref(null);

const isSystem = computed(() => props.mode === "system");

const form = ref({
    name: "",
    email: "",
    provider: "custom",
    auth_type: "password",
    imap_host: "",
    imap_port: 993,
    imap_encryption: "ssl",
    smtp_host: "",
    smtp_port: 587,
    smtp_encryption: "tls",
    username: "",
    password: "",
    system_usage: "",
    disabled_folders: [],
});

const folderTypes = [
    { value: "inbox", label: "Inbox", description: "Primary incoming emails" },
    { value: "sent", label: "Sent", description: "Sent emails" },
    { value: "drafts", label: "Drafts", description: "Draft emails" },
    { value: "trash", label: "Trash", description: "Deleted emails" },
    { value: "spam", label: "Spam", description: "Spam/junk emails" },
    {
        value: "archive",
        label: "Archive",
        description: "Gmail All Mail / Archived",
    },
];

const remoteFolders = ref([]);
const isLoadingRemoteFolders = ref(false);

const fetchRemoteFolders = async (accountId) => {
    isLoadingRemoteFolders.value = true;
    remoteFolders.value = [];
    try {
        const response = await axios.get(
            `/api/email-accounts/${accountId}/remote-folders`,
        );
        remoteFolders.value = response.data.data;
    } catch (error) {
        console.error("Failed to fetch remote folders:", error);
        toast.error("Failed to fetch folders from server");
    } finally {
        isLoadingRemoteFolders.value = false;
    }
};

const toggleFolderSync = (folderValue) => {
    const idx = form.value.disabled_folders.indexOf(folderValue);
    if (idx === -1) {
        form.value.disabled_folders.push(folderValue);
    } else {
        form.value.disabled_folders.splice(idx, 1);
    }
};

const errors = ref({});

const encryptionOptions = [
    { value: "ssl", label: "SSL" },
    { value: "tls", label: "TLS" },
    { value: "none", label: "None" },
];

const selectedProvider = computed(() => {
    return providers.value.find((p) => p.id === form.value.provider) || {};
});

const isCustomProvider = computed(() => form.value.provider === "custom");

const fetchAccounts = async () => {
    try {
        const response = await axios.get("/api/email-accounts", {
            params: { mode: props.mode },
        });
        accounts.value = response.data.data;
    } catch (error) {
        console.error("Failed to fetch email accounts:", error);
    }
};

const fetchProviders = async () => {
    try {
        const response = await axios.get("/api/email-accounts/providers");
        providers.value = response.data.data;
    } catch (error) {
        console.error("Failed to fetch providers:", error);
    }
};

const openModal = (account = null) => {
    editingAccount.value = account;
    if (account) {
        fetchRemoteFolders(account.id);
        form.value = {
            name: account.name,
            email: account.email,
            provider: account.provider,
            auth_type: account.auth_type,
            imap_host: account.imap_host || "",
            imap_port: account.imap_port,
            imap_encryption: account.imap_encryption,
            smtp_host: account.smtp_host || "",
            smtp_port: account.smtp_port,
            smtp_encryption: account.smtp_encryption,
            username: account.username || "",
            password: "",
            system_usage: account.system_usage || "",
            disabled_folders: account.disabled_folders || [],
        };
    } else {
        form.value = {
            name: "",
            email: "",
            provider: "custom",
            auth_type: "password",
            imap_host: "",
            imap_port: 993,
            imap_encryption: "ssl",
            smtp_host: "",
            smtp_port: 587,
            smtp_encryption: "tls",
            username: "",
            password: "",
            system_usage: "",
            disabled_folders: [],
        };
    }
    errors.value = {};
    showModal.value = true;
};

const onProviderChange = () => {
    const provider = selectedProvider.value;
    if (provider.imap_host) {
        form.value.imap_host = provider.imap_host;
        form.value.smtp_host = provider.smtp_host;
    }
};

const saveAccount = async () => {
    errors.value = {};
    isSaving.value = true;

    try {
        const payload = { ...form.value };
        if (props.teamId) {
            payload.team_id = props.teamId;
        }
        if (isSystem.value) {
            payload.is_system = true;
        }

        if (editingAccount.value) {
            await axios.put(
                `/api/email-accounts/${editingAccount.value.id}`,
                payload,
            );
            toast.success("Email account updated");
        } else {
            await axios.post("/api/email-accounts", payload);
            toast.success("Email account created");
        }

        showModal.value = false;
        await fetchAccounts();
    } catch (error) {
        if (error.response?.status === 422) {
            errors.value = error.response.data.errors || {};
        } else {
            toast.error("Failed to save email account");
        }
    } finally {
        isSaving.value = false;
    }
};

const deleteAccount = async (id) => {
    if (!confirm("Are you sure you want to delete this email account?")) return;

    try {
        await axios.delete(`/api/email-accounts/${id}`);
        toast.success("Email account deleted");
        await fetchAccounts();
    } catch (error) {
        toast.error("Failed to delete email account");
    }
};

const testConnection = async (account) => {
    isTesting.value[account.id] = true;
    try {
        const response = await axios.post(
            `/api/email-accounts/${account.id}/test`,
        );
        if (response.data.success) {
            toast.success("Connection successful!");
            account.is_verified = true;
            account.last_error = null;
        } else {
            toast.error(response.data.message || "Connection failed");
            account.is_verified = false;
            account.last_error = response.data.message;
        }
    } catch (error) {
        toast.error("Connection test failed");
    } finally {
        isTesting.value[account.id] = false;
    }
};

const isTestingConfig = ref(false); // Add this ref for config testing state

const testConfiguration = async () => {
    isTestingConfig.value = true;
    try {
        const response = await axios.post(
            "/api/email-accounts/test-configuration",
            form.value,
        );
        if (response.data.success) {
            toast.success("Configuration test successful!");
        } else {
            toast.error(response.data.message || "Configuration test failed");
        }
    } catch (error) {
        if (error.response?.status === 422) {
            errors.value = error.response.data.errors || {};
            toast.error("Please check your input fields");
        } else {
            toast.error("Connection test failed");
        }
    } finally {
        isTestingConfig.value = false;
    }
};

const checkHealth = async (account) => {
    isCheckingHealth.value[account.id] = true;
    healthResults.value = null;
    try {
        const response = await axios.post(
            `/api/email-accounts/${account.id}/health-check`,
        );
        healthResults.value = response.data.data;
        healthCheckAccount.value = account;
        showHealthModal.value = true;
    } catch (error) {
        toast.error("Failed to perform health check");
    } finally {
        isCheckingHealth.value[account.id] = false;
    }
};

const connectOAuth = async (provider) => {
    try {
        const params = props.teamId ? `?team_id=${props.teamId}` : "";
        const response = await axios.get(
            `/api/email-accounts/oauth/${provider}/redirect${params}`,
        );
        if (response.data.redirect_url) {
            window.location.href = response.data.redirect_url;
        }
    } catch (error) {
        toast.error("Failed to start OAuth flow");
    }
};

// Check for OAuth callback result
onMounted(async () => {
    try {
        await Promise.all([fetchAccounts(), fetchProviders()]);
    } finally {
        isLoading.value = false;
    }
});

const onWizardSaved = () => {
    fetchAccounts();
    showModal.value = false;
};
</script>

<template>
    <div class="space-y-4">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div
                    class="w-8 h-8 rounded-lg bg-indigo-500/10 flex items-center justify-center"
                >
                    <Mail class="w-4 h-4 text-indigo-600" />
                </div>
                <div>
                    <h3 class="font-medium text-(--text-primary)">
                        {{
                            isSystem
                                ? "System Email Accounts"
                                : "Email Accounts"
                        }}
                    </h3>
                    <p class="text-xs text-(--text-muted)">
                        {{
                            isSystem
                                ? "Connect accounts for system notifications. These accounts are for system internal use."
                                : "Connect email accounts for sending"
                        }}
                    </p>
                </div>
            </div>
            <Button variant="primary" size="sm" @click="openModal()">
                <Plus class="w-4 h-4" />
                Add Account
            </Button>
        </div>

        <!-- Loading -->
        <div v-if="isLoading" class="flex items-center justify-center py-8">
            <RefreshCw class="w-6 h-6 animate-spin text-(--text-muted)" />
        </div>

        <!-- Empty State -->
        <div
            v-else-if="accounts.length === 0"
            class="text-center py-8 border border-dashed border-(--border-default) rounded-lg"
        >
            <Mail
                class="w-10 h-10 text-(--text-muted) mx-auto mb-2 opacity-50"
            />
            <p class="text-[var(--text-muted)]">No email accounts connected</p>
            <p class="text-sm text-(--text-muted) mb-4">
                Add an account to send emails from the application
            </p>
            <div class="flex items-center justify-center gap-2">
                <Button variant="outline" size="sm" @click="openModal()">
                    <Plus class="w-4 h-4 mr-2" />
                    Connect Account
                </Button>
            </div>
        </div>

        <!-- Accounts List -->
        <div v-else class="space-y-2">
            <div
                v-for="account in accounts"
                :key="account.id"
                class="flex items-center justify-between p-4 bg-(--surface-secondary) rounded-lg border border-(--border-default)"
            >
                <div class="flex items-center gap-3">
                    <div
                        :class="[
                            'w-10 h-10 rounded-full flex items-center justify-center text-white text-sm font-medium',
                            account.provider === 'gmail'
                                ? 'bg-red-500'
                                : account.provider === 'outlook'
                                  ? 'bg-blue-500'
                                  : account.provider === 'outlook'
                                    ? 'bg-blue-500'
                                    : account.is_system
                                      ? 'bg-gray-700'
                                      : 'bg-gray-500',
                        ]"
                    >
                        {{ account.email.charAt(0).toUpperCase() }}
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <p class="font-medium text-(--text-primary)">
                                {{ account.name }}
                            </p>
                            <span
                                v-if="account.is_default"
                                class="px-1.5 py-0.5 text-xs bg-blue-500/10 text-blue-600 rounded"
                                >Default</span
                            >
                            <span
                                v-if="account.system_usage"
                                class="px-1.5 py-0.5 text-xs bg-purple-500/10 text-purple-600 rounded capitalize"
                                >{{ account.system_usage }}</span
                            >
                            <span
                                v-if="account.is_shared"
                                class="px-1.5 py-0.5 text-[10px] font-bold bg-purple-500/10 text-purple-600 rounded uppercase tracking-wider border border-purple-500/20"
                                >Shared</span
                            >
                            <!-- Badges -->
                            <span
                                v-if="account.auth_type === 'oauth'"
                                class="px-1.5 py-0.5 text-[10px] font-bold bg-indigo-500/10 text-indigo-600 rounded uppercase tracking-wider border border-indigo-500/20"
                            >
                                OAuth
                            </span>
                            <span
                                v-else
                                class="px-1.5 py-0.5 text-[10px] font-bold bg-gray-500/10 text-gray-600 rounded uppercase tracking-wider border border-gray-500/20"
                            >
                                IMAP
                            </span>
                        </div>
                        <p class="text-sm text-(--text-secondary)">
                            {{ account.email }}
                        </p>
                        <div class="flex items-center gap-3 mt-1.5">
                            <span
                                :class="[
                                    'inline-flex items-center gap-1 text-[11px] font-medium px-1.5 py-0.5 rounded-full border',
                                    account.is_verified
                                        ? 'text-emerald-600 bg-emerald-500/5 border-emerald-500/20'
                                        : 'text-amber-600 bg-amber-500/5 border-amber-500/20',
                                ]"
                            >
                                <CheckCircle
                                    v-if="account.is_verified"
                                    class="w-2.5 h-2.5"
                                />
                                <AlertCircle v-else class="w-2.5 h-2.5" />
                                {{
                                    account.is_verified
                                        ? "Verified"
                                        : "Not verified"
                                }}
                            </span>
                            <span
                                class="text-[11px] text-(--text-muted) font-medium capitalize flex items-center gap-1"
                            >
                                <span
                                    class="w-1 h-1 rounded-full bg-[var(--border-default)]"
                                ></span>
                                {{ account.provider }}
                            </span>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-1">
                    <Button
                        v-if="account.needs_reauth"
                        @click="connectOAuth(account.provider)"
                        size="xs"
                        class="bg-red-50 text-red-600 hover:bg-red-100 border-red-200 mr-2"
                        variant="outline"
                    >
                        <AlertCircle class="w-3 h-3 mr-1" />
                        Reconnect
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="h-8 w-8"
                        @click="testConnection(account)"
                        :disabled="isTesting[account.id]"
                    >
                        <RefreshCw
                            :class="[
                                'w-4 h-4',
                                isTesting[account.id] && 'animate-spin',
                            ]"
                        />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="h-8 w-8"
                        @click="checkHealth(account)"
                        :disabled="isCheckingHealth[account.id]"
                        title="Run Health Check"
                    >
                        <Activity
                            :class="[
                                'w-4 h-4',
                                isCheckingHealth[account.id] &&
                                    'animate-bounce',
                            ]"
                        />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="h-8 w-8"
                        @click="openModal(account)"
                    >
                        <Edit2 class="w-4 h-4" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="h-8 w-8 text-[var(--color-error)]"
                        @click="deleteAccount(account.id)"
                    >
                        <Trash2 class="w-4 h-4" />
                    </Button>
                </div>
            </div>
        </div>

        <!-- Add/Edit Modal -->
        <EmailAccountWizard
            v-if="!isSystem"
            :open="showModal"
            @update:open="showModal = $event"
            :account="editingAccount"
            @saved="onWizardSaved"
        />

        <Modal
            v-else
            :open="showModal"
            @update:open="showModal = $event"
            :title="
                editingAccount ? 'Edit Email Account' : 'Connect Email Account'
            "
            size="lg"
        >
            <div class="space-y-6">
                <!-- Provider Selection (only when adding new) -->
                <div v-if="!editingAccount" class="grid grid-cols-3 gap-3">
                    <button
                        v-for="provider in providers"
                        :key="provider.id"
                        @click="
                            form.provider = provider.id;
                            onProviderChange();
                        "
                        :class="[
                            'p-4 rounded-xl border text-center transition-all duration-200',
                            form.provider === provider.id
                                ? 'border-indigo-600 bg-indigo-50 ring-1 ring-indigo-600 dark:bg-indigo-500/10'
                                : 'border-(--border-default) hover:border-(--border-hover) hover:bg-(--surface-hover)',
                        ]"
                    >
                        <div class="flex flex-col items-center gap-2">
                            <!-- Icons -->
                            <div
                                v-if="provider.id === 'gmail'"
                                class="w-8 h-8 rounded-full bg-red-100 flex items-center justify-center text-red-600"
                            >
                                <Mail class="w-5 h-5" />
                            </div>
                            <div
                                v-else-if="provider.id === 'outlook'"
                                class="w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center text-blue-600"
                            >
                                <Mail class="w-5 h-5" />
                            </div>
                            <div
                                v-else
                                class="w-8 h-8 rounded-full bg-gray-100 flex items-center justify-center text-gray-600"
                            >
                                <Mail class="w-5 h-5" />
                            </div>

                            <span
                                class="text-sm font-medium text-(--text-primary)"
                            >
                                {{ provider.name }}
                            </span>
                        </div>
                    </button>
                </div>

                <!-- OAuth Connect View -->
                <div
                    v-if="selectedProvider.supports_oauth"
                    class="py-8 flex flex-col items-center text-center space-y-4"
                >
                    <div
                        class="p-3 rounded-full bg-indigo-100 text-indigo-600 dark:bg-indigo-500/20"
                    >
                        <ExternalLink class="w-6 h-6" />
                    </div>
                    <div>
                        <h3
                            class="text-lg font-medium text-(--text-primary)"
                        >
                            Connect with {{ selectedProvider.name }}
                        </h3>
                        <p
                            class="text-sm text-(--text-secondary) mt-1 max-w-xs mx-auto"
                        >
                            You will be redirected to
                            {{ selectedProvider.name }} to authorize access to
                            your email account.
                        </p>
                    </div>
                    <Button
                        size="lg"
                        variant="primary"
                        @click="connectOAuth(form.provider)"
                        class="w-full max-w-sm mt-2"
                    >
                        Continue to {{ selectedProvider.name }}
                    </Button>
                </div>

                <!-- Manual Form -->
                <div v-else class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label
                                class="text-sm font-medium text-(--text-primary)"
                                >Account Name *</label
                            >
                            <Input
                                v-model="form.name"
                                placeholder="My Work Email"
                            />
                        </div>
                        <div class="space-y-1.5">
                            <label
                                class="text-sm font-medium text-(--text-primary)"
                                >Email Address *</label
                            >
                            <Input
                                v-model="form.email"
                                type="email"
                                placeholder="name@company.com"
                            />
                        </div>

                        <!-- System Usage (Only for System Mode) -->
                        <div v-if="isSystem" class="col-span-2">
                            <label
                                class="text-sm font-medium text-(--text-primary)"
                                >System Role</label
                            >
                            <select
                                v-model="form.system_usage"
                                class="w-full px-3 py-2 mt-1.5 text-sm bg-(--surface-primary) border border-(--border-default) rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
                            >
                                <option value="">None / General</option>
                                <option value="support">
                                    Support (Incoming Tickets)
                                </option>
                                <option value="notification">
                                    Notification (Outgoing Alerts)
                                </option>
                                <option value="noreply">
                                    Noreply (Automated Emails)
                                </option>
                            </select>
                            <p class="text-xs text-(--text-muted) mt-1">
                                Assign a specific role to this account. Only one
                                active account per role is allowed.
                            </p>
                        </div>

                        <!-- IMAP Settings -->
                        <div
                            class="col-span-2 border-t border-(--border-default) pt-4 mt-2"
                        >
                            <div class="flex items-center gap-2 mb-3">
                                <div
                                    class="w-1 h-4 bg-indigo-500 rounded-full"
                                ></div>
                                <p
                                    class="text-sm font-medium text-(--text-primary)"
                                >
                                    IMAP Settings (Incoming)
                                </p>
                            </div>
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-sm text-(--text-secondary)"
                                >IMAP Host</label
                            >
                            <Input
                                v-model="form.imap_host"
                                placeholder="imap.example.com"
                            />
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div class="space-y-1.5">
                                <label
                                    class="text-sm text-(--text-secondary)"
                                    >Port</label
                                >
                                <Input
                                    v-model.number="form.imap_port"
                                    type="number"
                                />
                            </div>
                            <div class="space-y-1.5">
                                <label
                                    class="text-sm text-(--text-secondary)"
                                    >Encryption</label
                                >
                                <select
                                    v-model="form.imap_encryption"
                                    class="w-full px-3 py-2 text-sm bg-(--surface-primary) border border-(--border-default) rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
                                >
                                    <option
                                        v-for="e in encryptionOptions"
                                        :key="e.value"
                                        :value="e.value"
                                    >
                                        {{ e.label }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <!-- SMTP Settings -->
                        <div
                            class="col-span-2 border-t border-(--border-default) pt-4 mt-2"
                        >
                            <div class="flex items-center gap-2 mb-3">
                                <div
                                    class="w-1 h-4 bg-purple-500 rounded-full"
                                ></div>
                                <p
                                    class="text-sm font-medium text-(--text-primary)"
                                >
                                    SMTP Settings (Outgoing)
                                </p>
                            </div>
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-sm text-(--text-secondary)"
                                >SMTP Host</label
                            >
                            <Input
                                v-model="form.smtp_host"
                                placeholder="smtp.example.com"
                            />
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div class="space-y-1.5">
                                <label
                                    class="text-sm text-(--text-secondary)"
                                    >Port</label
                                >
                                <Input
                                    v-model.number="form.smtp_port"
                                    type="number"
                                />
                            </div>
                            <div class="space-y-1.5">
                                <label
                                    class="text-sm text-(--text-secondary)"
                                    >Encryption</label
                                >
                                <select
                                    v-model="form.smtp_encryption"
                                    class="w-full px-3 py-2 text-sm bg-(--surface-primary) border border-(--border-default) rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
                                >
                                    <option
                                        v-for="e in encryptionOptions"
                                        :key="e.value"
                                        :value="e.value"
                                    >
                                        {{ e.label }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <!-- Credentials -->
                        <div
                            class="col-span-2 border-t border-(--border-default) pt-4 mt-2"
                        >
                            <div class="flex items-center gap-2 mb-3">
                                <div
                                    class="w-1 h-4 bg-green-500 rounded-full"
                                ></div>
                                <p
                                    class="text-sm font-medium text-(--text-primary)"
                                >
                                    Authentication
                                </p>
                            </div>
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-sm text-(--text-secondary)"
                                >Username</label
                            >
                            <Input
                                v-model="form.username"
                                :placeholder="form.email || 'Username'"
                            />
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-sm text-(--text-secondary)"
                                >Password</label
                            >
                            <Input
                                v-model="form.password"
                                type="password"
                                placeholder="••••••••"
                            />
                        </div>
                    </div>
                </div>

                <!-- Folder Sync Settings (visible when editing any account type) -->
                <div
                    v-if="editingAccount"
                    class="border-t border-(--border-default) pt-4 mt-2"
                >
                    <div class="flex items-center gap-2 mb-3">
                        <div class="w-1 h-4 bg-amber-500 rounded-full"></div>
                        <p
                            class="text-sm font-medium text-(--text-primary)"
                        >
                            Folder Sync Settings
                        </p>
                    </div>
                    <p class="text-xs text-(--text-muted) mb-3">
                        Toggle which folders to sync. Disabled folders won't be
                        downloaded.
                    </p>
                    <div class="grid grid-cols-2 gap-2">
                        <!-- Loading State -->
                        <div
                            v-if="isLoadingRemoteFolders && editingAccount"
                            class="col-span-2 flex items-center justify-center py-4"
                        >
                            <RefreshCw
                                class="w-5 h-5 animate-spin text-(--text-muted)"
                            />
                            <span class="ml-2 text-sm text-(--text-muted)"
                                >Fetching folders...</span
                            >
                        </div>

                        <!-- Fallback to hardcoded types if not editing or fetch failed -->
                        <template v-else-if="!remoteFolders.length">
                            <div
                                v-for="folder in folderTypes"
                                :key="folder.value"
                                class="flex items-center justify-between p-2 rounded-lg border border-(--border-default) bg-(--surface-primary)"
                            >
                                <div class="flex flex-col">
                                    <span
                                        class="text-sm font-medium text-(--text-primary)"
                                    >
                                        {{ folder.label }}
                                    </span>
                                    <span
                                        class="text-xs text-(--text-muted)"
                                    >
                                        {{ folder.description }}
                                    </span>
                                </div>
                                <Switch
                                    :checked="
                                        !form.disabled_folders.includes(
                                            folder.value,
                                        )
                                    "
                                    @update:checked="
                                        toggleFolderSync(folder.value)
                                    "
                                />
                            </div>
                        </template>

                        <!-- Display Remote Folders -->
                        <template v-else>
                            <div
                                v-for="folder in remoteFolders"
                                :key="folder.id"
                                class="flex items-center justify-between p-2 rounded-lg border border-(--border-default) bg-(--surface-primary)"
                            >
                                <div class="flex flex-col overflow-hidden">
                                    <span
                                        class="text-sm font-medium text-(--text-primary) truncate"
                                        :title="folder.name"
                                    >
                                        {{ folder.name }}
                                    </span>
                                    <span
                                        class="text-xs text-(--text-muted) truncate"
                                        :title="folder.id"
                                    >
                                        {{
                                            folder.type === "system"
                                                ? "System Folder"
                                                : "Label"
                                        }}
                                    </span>
                                </div>
                                <Switch
                                    :checked="
                                        !form.disabled_folders.includes(
                                            folder.id,
                                        )
                                    "
                                    @update:checked="
                                        toggleFolderSync(folder.id)
                                    "
                                />
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <template #footer>
                <div class="flex w-full justify-between">
                    <Button variant="ghost" @click="showModal = false"
                        >Cancel</Button
                    >
                    <div class="flex gap-2">
                        <Button
                            v-if="!selectedProvider.supports_oauth"
                            variant="outline"
                            @click="testConfiguration"
                            :loading="isTestingConfig"
                            :disabled="isSaving"
                        >
                            <RefreshCw
                                class="w-4 h-4 mr-2"
                                :class="{ 'animate-spin': isTestingConfig }"
                            />
                            Test Connection
                        </Button>
                        <Button
                            v-if="!selectedProvider.supports_oauth"
                            variant="primary"
                            @click="saveAccount"
                            :loading="isSaving"
                        >
                            {{
                                editingAccount
                                    ? "Update Account"
                                    : "Save Account"
                            }}
                        </Button>
                        <!-- Update button for OAuth accounts (only when editing) -->
                        <Button
                            v-if="
                                selectedProvider.supports_oauth &&
                                editingAccount
                            "
                            variant="primary"
                            @click="saveAccount"
                            :loading="isSaving"
                        >
                            Update Settings
                        </Button>
                    </div>
                </div>
            </template>
        </Modal>
        <!-- Health Check Modal -->
        <Modal
            :open="showHealthModal"
            @update:open="showHealthModal = $event"
            title="Email Health Status"
        >
            <div v-if="healthResults" class="space-y-4">
                <p class="text-sm text-(--text-secondary)">
                    Health check results for
                    <strong>{{ healthCheckAccount?.email }}</strong
                    >.
                </p>

                <div class="space-y-3">
                    <!-- MX Record -->
                    <div
                        class="p-3 rounded-lg border border-(--border-default)"
                    >
                        <div class="flex items-center justify-between mb-2">
                            <span class="font-medium text-(--text-primary)"
                                >MX Records</span
                            >
                            <span
                                :class="
                                    healthResults.mx.status
                                        ? 'text-emerald-600'
                                        : 'text-red-600'
                                "
                            >
                                <CheckCircle
                                    v-if="healthResults.mx.status"
                                    class="w-4 h-4"
                                />
                                <XCircle v-else class="w-4 h-4" />
                            </span>
                        </div>
                        <p class="text-sm text-(--text-secondary)">
                            {{ healthResults.mx.message }}
                        </p>
                        <div
                            v-if="healthResults.mx.records"
                            class="mt-2 text-xs text-(--text-muted) bg-(--surface-primary) p-2 rounded"
                        >
                            <div
                                v-for="(rec, idx) in healthResults.mx.records"
                                :key="idx"
                            >
                                {{ rec.host }} ({{ rec.ttl }})
                            </div>
                        </div>
                    </div>

                    <!-- SPF Record -->
                    <div
                        class="p-3 rounded-lg border border-(--border-default)"
                    >
                        <div class="flex items-center justify-between mb-2">
                            <span class="font-medium text-(--text-primary)"
                                >SPF Record</span
                            >
                            <span
                                :class="
                                    healthResults.spf.status
                                        ? 'text-emerald-600'
                                        : 'text-red-600'
                                "
                            >
                                <CheckCircle
                                    v-if="healthResults.spf.status"
                                    class="w-4 h-4"
                                />
                                <XCircle v-else class="w-4 h-4" />
                            </span>
                        </div>
                        <p class="text-sm text-(--text-secondary)">
                            {{ healthResults.spf.message }}
                        </p>
                        <div
                            v-if="healthResults.spf.record"
                            class="mt-2 text-xs font-mono text-(--text-muted) bg-(--surface-primary) p-2 rounded break-all"
                        >
                            {{ healthResults.spf.record }}
                        </div>
                    </div>

                    <!-- DMARC Record -->
                    <div
                        class="p-3 rounded-lg border border-(--border-default)"
                    >
                        <div class="flex items-center justify-between mb-2">
                            <span class="font-medium text-(--text-primary)"
                                >DMARC Record</span
                            >
                            <span
                                :class="
                                    healthResults.dmarc.status
                                        ? 'text-emerald-600'
                                        : 'text-red-600'
                                "
                            >
                                <CheckCircle
                                    v-if="healthResults.dmarc.status"
                                    class="w-4 h-4"
                                />
                                <XCircle v-else class="w-4 h-4" />
                            </span>
                        </div>
                        <p class="text-sm text-(--text-secondary)">
                            {{ healthResults.dmarc.message }}
                        </p>
                        <div
                            v-if="healthResults.dmarc.record"
                            class="mt-2 text-xs font-mono text-(--text-muted) bg-(--surface-primary) p-2 rounded break-all"
                        >
                            {{ healthResults.dmarc.record }}
                        </div>
                    </div>
                </div>

                <div class="flex justify-end mt-4">
                    <Button variant="outline" @click="showHealthModal = false"
                        >Close</Button
                    >
                </div>
            </div>
        </Modal>
    </div>
</template>
