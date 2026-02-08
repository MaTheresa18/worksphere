<script setup lang="ts">
import { ref, onMounted, onUnmounted, watch } from "vue";
import { Modal, Button, Input } from "@/components/ui"; 
import { 
    Mail, 
    ArrowRight, 
    CheckCircle, 
    AlertTriangle,
    Loader2,
    Server,
    Shield,
    Info,
    ExternalLink,
    Check
} from "lucide-vue-next";
import { toast } from "vue-sonner";
import api from "@/lib/api";

const props = defineProps<{
    open: boolean;
    mode?: "personal" | "system";
    account?: any; // For editing
}>();

const emit = defineEmits(["update:open", "saved"]);

// State
const step = ref(1);
const isLoading = ref(false);
const accountId = ref<string | null>(null);
const remoteFolders = ref<any[]>([]);
const selectedFolders = ref<string[]>([]); 

const form = ref({
    provider: "",
    email: "",
    name: "",
    // Custom IMAP
    imap_host: "",
    imap_port: 993,
    imap_encryption: "ssl",
    smtp_host: "",
    smtp_port: 587,
    smtp_encryption: "tls",
    username: "",
    password: "",
});

const providers = [
    { id: "gmail", name: "Gmail", icon: Mail, color: "text-red-600", bg: "bg-red-100 dark:bg-red-900/20" },
    { id: "outlook", name: "Outlook", icon: Mail, color: "text-blue-600", bg: "bg-blue-100 dark:bg-blue-900/20" },
    { id: "custom", name: "Custom IMAP", icon: Server, color: "text-gray-600", bg: "bg-gray-100 dark:bg-gray-800" },
];

const steps = [
    { number: 1, title: 'Privacy' },
    { number: 2, title: 'Provider' },
    { number: 3, title: 'Connect' },
    { number: 4, title: 'Folders' },
    { number: 5, title: 'Finish' }
];

const totalSteps = 5;

const reset = () => {
    step.value = 1;
    accountId.value = null;
    remoteFolders.value = [];
    selectedFolders.value = [];
    form.value = {
        provider: "",
        email: "",
        name: "",
        imap_host: "",
        imap_port: 993,
        imap_encryption: "ssl",
        smtp_host: "",
        smtp_port: 587,
        smtp_encryption: "tls",
        username: "",
        password: "",
    };
};

const close = () => {
    emit("update:open", false);
    setTimeout(reset, 300);
};

// Initialize
watch(() => props.open, (isOpen) => {
    if (isOpen) {
        if (props.account) {
            accountId.value = props.account.id;
            form.value = { ...props.account };
            if (!form.value.provider) form.value.provider = 'custom';
            fetchRemoteFolders();
        } else {
            reset();
        }
    }
});

// Actions
const selectProvider = (id: string) => {
    form.value.provider = id;
    step.value = 3;
};

const connectOAuth = async () => {
    try {
        isLoading.value = true;
        const { data } = await api.get(`/api/email-accounts/oauth/${form.value.provider}/redirect?popup=1`);
        
        const width = 600;
        const height = 700;
        const left = (window.screen.width - width) / 2;
        const top = (window.screen.height - height) / 2;
        
        const popup = window.open(
            data.redirect_url,
            "oauth_window",
            `width=${width},height=${height},top=${top},left=${left}`
        );

        if (popup) {
            window.addEventListener("message", handleOAuthMessage);

            // Poll for popup closure as fallback
            const timer = setInterval(() => {
                if (popup.closed) {
                    clearInterval(timer);
                    if (!accountId.value && isLoading.value) {
                        checkRecentAccount();
                    }
                }
            }, 1000);
        } else {
            toast.error("Popup blocked. Please allow popups for this site.");
            isLoading.value = false;
        }

    } catch (e) {
        toast.error("Failed to start connection");
        isLoading.value = false;
    }
};

const checkRecentAccount = async () => {
    try {
        // Fallback: Check if account was created recently
        const { data } = await api.get('/api/email-accounts');
        const accounts = data.data || [];
        
        // Find account created in last 2 minutes with matching provider
        const recent = accounts.find((a: any) => {
            const created = new Date(a.created_at).getTime();
            const now = new Date().getTime();
            return a.provider === form.value.provider && (now - created) < 120000;
        });

        if (recent) {
            window.removeEventListener("message", handleOAuthMessage);
            accountId.value = recent.id;
            toast.success("Account connected successfully!");
            fetchRemoteFolders();
        } else {
            toast.error("Connection cancelled or failed.");
            isLoading.value = false;
        }
    } catch (e) {
        // If check fails
        isLoading.value = false;
    }
};

const handleOAuthMessage = (event: MessageEvent) => {
    if (event.data?.type === "oauth_success") {
        window.removeEventListener("message", handleOAuthMessage);
        accountId.value = event.data.account_id;
        toast.success("Account connected successfully!");
        fetchRemoteFolders();
    } else if (event.data?.type === "oauth_error") {
        window.removeEventListener("message", handleOAuthMessage);
        toast.error(event.data.error || "Connection failed");
        isLoading.value = false;
    }
};

const connectCustom = async () => {
    try {
        isLoading.value = true;
        const { data } = await api.post("/api/email-accounts", {
            ...form.value,
            auth_type: "password",
            name: form.value.name || form.value.email,
        });
        
        accountId.value = data.data.id;
        toast.success("Account connected successfully!");
        fetchRemoteFolders();
    } catch (e: any) {
        toast.error(e.response?.data?.message || "Connection failed");
        isLoading.value = false;
    }
};

const fetchRemoteFolders = async () => {
    step.value = 4;
    isLoading.value = true;
    try {
        const { data } = await api.get(`/api/email-accounts/${accountId.value}/remote-folders`);
        remoteFolders.value = data.data; 
        // Default select all if new, or maintain selection if edit logic needed (but here we just fetch fresh)
        // If editing, we probably want to respect `disabled_folders` from account props, 
        // but `fetchRemoteFolders` just gets the list. 
        // We should calculate `selectedFolders` based on `remoteFolders` AND `props.account.disabled_folders` if exists.
        
        if (props.account && props.account.disabled_folders) {
             const disabled = props.account.disabled_folders || [];
             selectedFolders.value = remoteFolders.value
                .map(f => f.name)
                .filter(name => !disabled.includes(name));
        } else {
             selectedFolders.value = remoteFolders.value.map(f => f.name);
        }

    } catch (e) {
        console.error(e);
        toast.error("Failed to fetch folders");
    } finally {
        isLoading.value = false;
    }
};

const toggleFolder = (name: string) => {
    if (selectedFolders.value.includes(name)) {
        selectedFolders.value = selectedFolders.value.filter(f => f !== name);
    } else {
        selectedFolders.value.push(name);
    }
};

const saveFolders = async () => {
    if (!accountId.value) return;
    
    const disabled = remoteFolders.value
        .map(f => f.name)
        .filter(name => !selectedFolders.value.includes(name));
        
    try {
        isLoading.value = true;
        await api.put(`/api/email-accounts/${accountId.value}`, {
            disabled_folders: disabled
        });
        step.value = 5;
    } catch (e) {
        toast.error("Failed to save folder preferences");
    } finally {
        isLoading.value = false;
    }
};

onUnmounted(() => {
    window.removeEventListener("message", handleOAuthMessage);
});
</script>

<template>
    <Modal 
        :open="open" 
        @update:open="emit('update:open', $event)"
        title="Connect Email Account"
        size="lg"
        :show-close="false"
    >
        <template #title>
             <div class="flex justify-between items-center w-full">
                <span>{{ props.account ? 'Edit Email Account' : 'Connect Email Account' }}</span>
            </div>
        </template>

        <div class="space-y-8 min-h-[450px] flex flex-col">
            <!-- Stepper -->
        <div class="flex items-start justify-between px-4 w-full max-w-2xl mx-auto relative mb-8">
            <div 
                v-for="(s, index) in steps" 
                :key="index"
                class="flex flex-col items-center relative z-10 w-20"
            >        <div 
                        class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-semibold transition-all duration-300 border-2"
                        :class="[
                            step > s.number ? 'bg-[var(--brand-primary)] border-[var(--brand-primary)] text-white' : 
                            step === s.number ? 'bg-[var(--surface-primary)] border-[var(--brand-primary)] text-[var(--brand-primary)]' : 
                            'bg-[var(--surface-primary)] border-[var(--border-default)] text-[var(--text-muted)]'
                        ]"
                    >
                        <Check v-if="step > s.number" class="w-4 h-4" />
                        <span v-else>{{ s.number }}</span>
                    </div>
                    <span 
                        class="text-[10px] font-medium mt-2 absolute -bottom-5 w-20 text-center transition-colors duration-300"
                        :class="step >= s.number ? 'text-[var(--text-primary)]' : 'text-[var(--text-muted)]'"
                    >
                        {{ s.title }}
                    </span>
                </div>
                
                <!-- Connecting Lines -->
                <div class="absolute top-4 left-8 right-8 h-0.5" aria-hidden="true">
                     <div class="w-full h-full bg-[var(--border-default)] relative z-0">
                         <div 
                            class="h-full bg-[var(--brand-primary)] transition-all duration-500 ease-in-out"
                            :style="{ width: ((step - 1) / (steps.length - 1)) * 100 + '%' }"
                        ></div>
                     </div>
                </div>
            </div>

            <!-- Content Area -->
            <div class="flex-1 relative pt-8 overflow-hidden flex flex-col min-h-[400px]">
                <Transition name="fade" mode="out-in">
                    
                    <!-- Step 1: Privacy Notice -->
                    <div v-if="step === 1" class="space-y-6 w-full max-w-2xl mx-auto animate-in fade-in slide-in-from-bottom-4 duration-500 px-4">
                        <div class="text-center space-y-2">
                            <h2 class="text-2xl font-bold tracking-tight">Private & Secure Email Sync</h2>
                            <p class="text-[var(--text-secondary)] text-sm max-w-md mx-auto">
                                We prioritize your privacy with strict data retention policies.
                            </p>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="p-5 rounded-xl border border-[var(--border-default)] bg-[var(--surface-secondary)]/50 space-y-3">
                                <div class="w-10 h-10 rounded-lg bg-[var(--surface-primary)] flex items-center justify-center shadow-sm">
                                    <Server class="w-5 h-5 text-[var(--brand-primary)]" />
                                </div>
                                <h3 class="font-semibold">Non-Permanent Storage</h3>
                                <p class="text-sm text-[var(--text-secondary)] leading-relaxed">
                                    Emails are automatically removed after <strong class="text-[var(--text-primary)]">90 days</strong>. 
                                    Trash & Spam are cleared after <strong class="text-[var(--text-primary)]">30 days</strong>.
                                </p>
                            </div>

                            <div class="p-5 rounded-xl border border-[var(--border-default)] bg-[var(--surface-secondary)]/50 space-y-3">
                                <div class="w-10 h-10 rounded-lg bg-[var(--surface-primary)] flex items-center justify-center shadow-sm">
                                    <Shield class="w-5 h-5 text-green-600" />
                                </div>
                                <h3 class="font-semibold">Read-Only Sync</h3>
                                <p class="text-sm text-[var(--text-secondary)] leading-relaxed">
                                    Deleting emails in WorkSphere <strong class="text-[var(--text-primary)]">does not</strong> delete them from your provider.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: Provider Selection -->
                    <div v-else-if="step === 2" class="space-y-6 w-full max-w-md mx-auto animate-in fade-in slide-in-from-bottom-4 duration-500 px-4">
                        <div class="text-center space-y-2">
                            <h2 class="text-2xl font-bold tracking-tight">Select Provider</h2>
                            <p class="text-[var(--text-secondary)] text-sm">
                                Choose your email service provider to continue.
                            </p>
                        </div>

                        <div class="grid grid-cols-1 gap-4">
                            <button
                                v-for="p in providers"
                                :key="p.id"
                                @click="selectProvider(p.id)"
                                class="flex items-center gap-4 p-4 rounded-xl border border-[var(--border-default)] hover:border-[var(--brand-primary)] hover:shadow-md hover:bg-[var(--surface-primary)] transition-all group text-left relative overflow-hidden"
                            >
                                <div 
                                    class="w-12 h-12 rounded-full flex items-center justify-center shrink-0 transition-colors"
                                    :class="p.bg"
                                >
                                    <component :is="p.icon" class="w-6 h-6" :class="p.color" />
                                </div>
                                <div>
                                    <h4 class="font-semibold text-[var(--text-primary)] text-lg">{{ p.name }}</h4>
                                    <p class="text-xs text-[var(--text-secondary)]">Connect your {{ p.name }} account</p>
                                </div>
                                <ArrowRight class="w-5 h-5 text-[var(--brand-primary)] ml-auto opacity-0 -translate-x-2 group-hover:opacity-100 group-hover:translate-x-0 transition-all duration-300" />
                            </button>
                        </div>
                    </div>

                    <!-- Step 3: Connection -->
                    <div v-else-if="step === 3" class="space-y-6 w-full max-w-md mx-auto animate-in fade-in slide-in-from-bottom-4 duration-500 px-4">
                        <div class="text-center space-y-4">
                            <div 
                                class="w-20 h-20 rounded-2xl flex items-center justify-center mx-auto shadow-sm"
                                :class="providers.find(p => p.id === form.provider)?.bg || 'bg-gray-100'"
                            >
                                <component 
                                    :is="providers.find(p => p.id === form.provider)?.icon" 
                                    class="w-10 h-10"
                                    :class="providers.find(p => p.id === form.provider)?.color"
                                />
                            </div>
                            <div>
                                <h2 class="text-2xl font-bold tracking-tight">Connect {{ providers.find(p => p.id === form.provider)?.name }}</h2>
                                <p class="text-[var(--text-secondary)] text-sm mt-1">
                                    {{ form.provider === 'custom' ? 'Enter your server details.' : 'Authenticate via popup window.' }}
                                </p>
                            </div>
                        </div>

                        <!-- OAuth Button -->
                        <div v-if="form.provider !== 'custom'" class="w-full pt-4">
                            <Button 
                                size="lg" 
                                class="w-full h-12 text-base relative overflow-hidden" 
                                :disabled="isLoading"
                                @click="connectOAuth"
                            >
                                <Loader2 v-if="isLoading" class="w-5 h-5 animate-spin mr-2" />
                                <ExternalLink v-else class="w-5 h-5 mr-2" />
                                {{ isLoading ? 'Waiting for popup...' : `Authorize ${providers.find(p => p.id === form.provider)?.name}` }}
                            </Button>
                            <div class="flex items-start gap-2 mt-4 p-3 bg-[var(--surface-secondary)] rounded-lg text-xs text-[var(--text-muted)]">
                                <Info class="w-4 h-4 shrink-0 mt-0.5" />
                                <p>Ensure popups are allowed for this site. The authentication happens securely on your provider's page.</p>
                            </div>
                        </div>

                        <!-- Manual Form -->
                        <div v-else class="space-y-5">
                            <div class="grid grid-cols-2 gap-5">
                                <div class="col-span-2 space-y-1.5">
                                    <label class="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Account Info</label>
                                    <Input v-model="form.email" placeholder="Email Address" class="h-10" />
                                    <Input v-model="form.name" placeholder="Display Name (Optional)" class="h-10 mt-2" />
                                </div>
                                
                                <div class="col-span-2 border-t border-dashed my-1"></div>
                                <div class="space-y-1.5">
                                    <label class="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Incoming (IMAP)</label>
                                    <Input v-model="form.imap_host" placeholder="imap.server.com" class="h-10" />
                                </div>
                                <div class="space-y-1.5">
                                    <label class="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Port</label>
                                    <Input v-model.number="form.imap_port" class="h-10" />
                                </div>
                                
                                <div class="space-y-1.5">
                                    <label class="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Outgoing (SMTP)</label>
                                    <Input v-model="form.smtp_host" placeholder="smtp.server.com" class="h-10" />
                                </div>
                                <div class="space-y-1.5">
                                    <label class="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Port</label>
                                    <Input v-model.number="form.smtp_port" class="h-10" />
                                </div>
                                
                                <div class="col-span-2 border-t border-dashed my-1"></div>
                                <div class="col-span-2 space-y-1.5">
                                    <label class="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Credentials</label>
                                    <Input v-model="form.username" placeholder="Username" class="h-10" />
                                    <Input v-model="form.password" type="password" placeholder="Password" class="h-10 mt-2" />
                                </div>
                            </div>
                            
                            <Button class="w-full h-11 mt-2" :disabled="isLoading" @click="connectCustom">
                                <Loader2 v-if="isLoading" class="w-5 h-5 animate-spin mr-2" />
                                Connect Account
                            </Button>
                        </div>
                    </div>

                    <!-- Step 4: Folder Selection -->
                    <div v-else-if="step === 4" class="w-full h-full flex flex-col animate-in fade-in slide-in-from-bottom-4 duration-500 px-4">
                        <div class="mb-6 text-center">
                            <h2 class="text-2xl font-bold tracking-tight">Sync Folders</h2>
                            <p class="text-[var(--text-secondary)] text-sm mt-1">
                                Select the folders you want to access in WorkSphere.
                            </p>
                        </div>

                        <div v-if="isLoading" class="flex-1 flex flex-col items-center justify-center min-h-[200px]">
                            <Loader2 class="w-10 h-10 animate-spin text-[var(--brand-primary)] mb-4" />
                            <p class="text-sm font-medium">Fetching folder structure...</p>
                        </div>

                        <div v-else class="flex-1 flex flex-col">
                             <div class="flex justify-end px-1 mb-2">
                                <button 
                                    @click="selectedFolders = remoteFolders.map(f => f.name)"
                                    class="text-xs font-medium text-[var(--brand-primary)] hover:underline mr-3"
                                >
                                    Select All
                                </button>
                                <button 
                                    @click="selectedFolders = []"
                                    class="text-xs font-medium text-[var(--text-muted)] hover:underline"
                                >
                                    Deselect All
                                </button>
                            </div>
                            
                            <div class="flex-1 overflow-y-auto border border-[var(--border-default)] rounded-xl p-2 max-h-[350px] bg-[var(--surface-primary)]">
                                <div 
                                    v-for="folder in remoteFolders" 
                                    :key="folder.name"
                                    @click="toggleFolder(folder.name)"
                                    class="flex items-center gap-3 p-3 rounded-lg hover:bg-[var(--surface-secondary)] cursor-pointer select-none transition-all group"
                                >
                                    <div 
                                        class="w-5 h-5 rounded border flex items-center justify-center transition-all duration-200"
                                        :class="selectedFolders.includes(folder.name) ? 'bg-[var(--brand-primary)] border-[var(--brand-primary)] text-white shadow-sm scale-110' : 'border-[var(--border-default)] bg-white group-hover:border-[var(--brand-primary)]/50'"
                                    >
                                        <Check v-if="selectedFolders.includes(folder.name)" class="w-3.5 h-3.5" />
                                    </div>
                                    <span class="text-sm font-medium" :class="selectedFolders.includes(folder.name) ? 'text-[var(--text-primary)]' : 'text-[var(--text-secondary)]'">
                                        {{ folder.name }}
                                    </span>
                                </div>
                                <div v-if="remoteFolders.length === 0" class="p-8 text-center text-[var(--text-muted)]">
                                    <p>No folders found.</p>
                                    <p class="text-xs mt-1">We will sync your Inbox by default.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 5: Finish -->
                    <div v-else-if="step === 5" class="w-full max-w-md mx-auto pt-10 px-4 text-center">
                        <div class="mx-auto w-24 h-24 bg-green-100 dark:bg-green-900/30 rounded-full flex items-center justify-center text-green-600 dark:text-green-400 mb-6 shadow-sm ring-8 ring-green-50 dark:ring-green-900/10 shrink-0">
                            <CheckCircle class="w-12 h-12" />
                        </div>
                        <h2 class="text-3xl font-bold mb-3 text-[var(--text-primary)]">All Set!</h2>
                        <p class="text-[var(--text-secondary)] max-w-sm mx-auto mb-8 text-base">
                            Your email account has been successfully connected. Syncing will run in the background.
                        </p>
                        <div class="flex justify-center w-full">
                            <Button size="lg" class="px-8 min-w-[200px]" @click="emit('saved'); close()">
                                Return to Settings
                            </Button>
                        </div>
                    </div>
                </Transition>
            </div>
        </div>

        <template #footer>
            <div class="flex w-full items-center justify-between pt-4 border-t border-[var(--border-default)] mt-4" v-if="step < 5">
                <Button variant="ghost" @click="step > 1 ? step-- : close()" :disabled="isLoading">
                    {{ step === 1 ? 'Cancel' : 'Back' }}
                </Button>

                <div v-if="step === 1">
                    <Button @click="step++">
                        I Understand & Continue
                    </Button>
                </div>
                <div v-else-if="step === 4">
                     <Button 
                        :disabled="isLoading"
                        @click="saveFolders"
                        class="min-w-[120px]"
                    >
                         <Loader2 v-if="isLoading" class="w-4 h-4 animate-spin mr-2" />
                        Finish Setup
                    </Button>
                </div>
                 <!-- Other steps handle forward nav internally -->
                 <div v-else></div>
            </div>
        </template>
    </Modal>
</template>
