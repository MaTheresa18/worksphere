<script setup lang="ts">
import { ref, onMounted, onUnmounted, watch } from "vue";
import { 
    StepperRoot, 
    StepperItem, 
    StepperIndicator, 
    StepperTrigger,
    StepperSeparator,
    StepperTitle, 
    StepperDescription 
} from "reka-ui";
import { Checkbox, Modal, Button, Input } from "@/components/ui"; 
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
const isTestingConfig = ref(false);
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

const encryptionOptions = [
    { value: "ssl", label: "SSL" },
    { value: "tls", label: "TLS" },
    { value: "none", label: "None" },
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

const testConfiguration = async () => {
    try {
        isTestingConfig.value = true;
        const { data } = await api.post("/api/email-accounts/test-configuration", {
            ...form.value,
            auth_type: "password",
        });
        
        if (data.success) {
            toast.success("Connection test successful!");
        } else {
            toast.error(data.message || "Connection test failed");
        }
    } catch (e: any) {
        toast.error(e.response?.data?.message || "Connection test failed");
    } finally {
        isTestingConfig.value = false;
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
        size="xl"
        :show-close="false"
    >
        <template #title>
             <div class="flex justify-between items-center w-full">
                <span>{{ props.account ? 'Edit Email Account' : 'Connect Email Account' }}</span>
            </div>
        </template>

        <div class="space-y-8 min-h-[450px] flex flex-col">
            <!-- Stepper -->
            <StepperRoot
                :model-value="step"
                class="flex items-start justify-between px-10 w-full max-w-3xl mx-auto relative mb-10"
            >
                <StepperItem
                    v-for="s in steps"
                    :key="s.number"
                    :step="s.number"
                    class="flex flex-col items-center relative z-10 flex-1 group"
                >
                    <StepperTrigger class="focus:outline-none focus:ring-2 focus:ring-(--brand-primary)/10 rounded-full cursor-default mb-2 transition-transform duration-300">
                        <StepperIndicator
                            class="w-7 h-7 rounded-full flex items-center justify-center text-[10px] font-bold transition-all duration-500 border shadow-xs"
                            :class="[
                                step > s.number ? 'bg-linear-to-br from-emerald-500 to-emerald-600 border-emerald-400 text-white shadow-emerald-500/10' : 
                                step === s.number ? 'bg-(--surface-primary) border-(--brand-primary) text-(--brand-primary) ring-2 ring-(--brand-primary)/5 shadow-sm scale-110' : 
                                'bg-(--surface-secondary) border-(--border-subtle) text-(--text-muted) opacity-50'
                            ]"
                        >
                            <Check v-if="step > s.number" class="w-3.5 h-3.5 stroke-[4px]" />
                            <span v-else>{{ s.number }}</span>
                        </StepperIndicator>
                    </StepperTrigger>

                    <div class="flex flex-col items-center justify-center w-full min-h-6 px-1">
                        <StepperTitle
                            class="text-[10px] font-bold transition-all duration-300 text-center leading-tight whitespace-nowrap"
                            :class="[
                                step > s.number ? 'text-emerald-600 dark:text-emerald-400' :
                                step === s.number ? 'text-(--brand-primary)' : 
                                'text-(--text-muted) opacity-40'
                            ]"
                        >
                            {{ s.title }}
                        </StepperTitle>
                    </div>

                    <!-- Enhanced Separator -->
                    <div
                        v-if="s.number < steps.length"
                        class="absolute top-3.5 left-[calc(50%+0.875rem)] right-[calc(-50%+0.875rem)] h-px bg-(--border-default)/30 rounded-full -z-10"
                    >
                         <div 
                            class="absolute left-0 h-full bg-linear-to-r from-emerald-500 to-emerald-600 rounded-full transition-all duration-700 ease-in-out"
                            :style="{ width: step > s.number ? '100%' : '0%' }"
                        ></div>
                    </div>
                </StepperItem>
            </StepperRoot>

            <!-- Content Area -->
            <div class="flex-1 relative mt-4 overflow-hidden flex flex-col min-h-[420px]">
                <Transition name="fade" mode="out-in">
                    
                    <!-- Step 1: Privacy Notice -->
                        <div v-if="step === 1" class="space-y-6 w-full max-w-2xl mx-auto animate-in fade-in slide-in-from-bottom-4 duration-500 px-4">
                            <div class="text-center space-y-2">
                                <h2 class="text-2xl font-bold tracking-tight bg-linear-to-r from-(--brand-primary) to-(--brand-primary-hover) bg-clip-text text-transparent">Private & Secure Email Sync</h2>
                                <p class="text-(--text-secondary) text-sm max-w-md mx-auto">
                                    We prioritize your privacy with strict data retention policies.
                                </p>
                            </div>
    
                            <div class="grid gap-4 md:grid-cols-2">
                                <div class="p-6 rounded-2xl border border-(--border-default) bg-(--surface-secondary)/40 backdrop-blur-sm space-y-4 hover:border-(--brand-primary)/30 transition-colors">
                                    <div class="w-12 h-12 rounded-xl bg-(--brand-primary)/10 flex items-center justify-center shadow-sm">
                                        <Server class="w-6 h-6 text-(--brand-primary)" />
                                    </div>
                                    <h3 class="font-bold text-lg">Non-Permanent Storage</h3>
                                    <p class="text-sm text-(--text-secondary) leading-relaxed">
                                        Emails are automatically removed after <strong class="text-(--text-primary)">90 days</strong>. 
                                        Trash & Spam are cleared after <strong class="text-(--text-primary)">30 days</strong>.
                                    </p>
                                </div>
    
                                <div class="p-6 rounded-2xl border border-(--border-default) bg-(--surface-secondary)/40 backdrop-blur-sm space-y-4 hover:border-emerald-500/30 transition-colors">
                                    <div class="w-12 h-12 rounded-xl bg-emerald-500/10 flex items-center justify-center shadow-sm">
                                        <Shield class="w-6 h-6 text-emerald-600" />
                                    </div>
                                    <h3 class="font-bold text-lg">Read-Only Sync</h3>
                                    <p class="text-sm text-(--text-secondary) leading-relaxed">
                                        Deleting emails in WorkSphere <strong class="text-(--text-primary)">does not</strong> delete them from your provider.
                                    </p>
                                </div>
                            </div>
                        </div>
    
                        <!-- Step 2: Provider Selection -->
                        <div v-else-if="step === 2" class="space-y-6 w-full max-w-md mx-auto animate-in fade-in slide-in-from-bottom-4 duration-500 px-4">
                            <div class="text-center space-y-2">
                                <h2 class="text-2xl font-bold tracking-tight">Select Provider</h2>
                                <p class="text-(--text-secondary) text-sm">
                                    Choose your email service provider to continue.
                                </p>
                            </div>
    
                            <div class="grid grid-cols-1 gap-4">
                                <button
                                    v-for="p in providers"
                                    :key="p.id"
                                    @click="selectProvider(p.id)"
                                    class="flex items-center gap-4 p-5 rounded-2xl border border-(--border-default) bg-(--surface-secondary)/50 backdrop-blur-sm hover:border-(--brand-primary) hover:shadow-xl hover:shadow-(--brand-primary)/5 hover:bg-(--surface-tertiary) transition-all group text-left relative overflow-hidden active:scale-[0.98]"
                                >
                                    <div 
                                        class="w-14 h-14 rounded-2xl flex items-center justify-center shrink-0 transition-all duration-300 group-hover:scale-110 shadow-sm"
                                        :class="p.bg"
                                    >
                                        <component :is="p.icon" class="w-7 h-7" :class="p.color" />
                                    </div>
                                    <div class="flex-1">
                                        <h4 class="font-bold text-(--text-primary) text-lg">{{ p.name }}</h4>
                                        <p class="text-xs text-(--text-secondary)">Connect your {{ p.name }} account</p>
                                    </div>
                                    <ArrowRight class="w-5 h-5 text-(--brand-primary) ml-auto opacity-0 -translate-x-2 group-hover:opacity-100 group-hover:translate-x-0 transition-all duration-300" />
                                </button>
                            </div>
                        </div>
    
                        <!-- Step 3: Connection -->
                        <div v-else-if="step === 3" class="space-y-6 w-full max-w-md mx-auto animate-in fade-in slide-in-from-bottom-4 duration-500 px-4">
                            <div class="text-center space-y-4">
                                <div 
                                    class="w-24 h-24 rounded-[32px] flex items-center justify-center mx-auto shadow-lg ring-8 ring-(--surface-secondary)/50"
                                    :class="providers.find(p => p.id === form.provider)?.bg || 'bg-gray-100'"
                                >
                                    <component 
                                        :is="providers.find(p => p.id === form.provider)?.icon" 
                                        class="w-12 h-12"
                                        :class="providers.find(p => p.id === form.provider)?.color"
                                    />
                                </div>
                                <div>
                                    <h2 class="text-2xl font-bold tracking-tight">Connect {{ providers.find(p => p.id === form.provider)?.name }}</h2>
                                    <p class="text-(--text-secondary) text-sm mt-1">
                                        {{ form.provider === 'custom' ? 'Enter your server details.' : 'Authenticate via popup window.' }}
                                    </p>
                                </div>
                            </div>
    
                            <!-- OAuth Button -->
                            <div v-if="form.provider !== 'custom'" class="w-full pt-4">
                                <Button 
                                    size="lg" 
                                    class="w-full h-14 text-base relative overflow-hidden font-bold rounded-2xl shadow-lg hover:shadow-(--brand-primary)/25" 
                                    :disabled="isLoading"
                                    @click="connectOAuth"
                                >
                                    <Loader2 v-if="isLoading" class="w-5 h-5 animate-spin mr-2" />
                                    <ExternalLink v-else class="w-5 h-5 mr-3" />
                                    {{ isLoading ? 'Waiting for popup...' : `Authorize ${providers.find(p => p.id === form.provider)?.name}` }}
                                </Button>
                                <div class="flex items-start gap-3 mt-6 p-4 bg-blue-500/5 border border-blue-500/10 rounded-2xl text-[13px] text-(--text-secondary) leading-relaxed">
                                    <Info class="w-5 h-5 shrink-0 mt-0.5 text-blue-500" />
                                    <p>Ensure popups are allowed for this site. The authentication happens securely on your provider's page.</p>
                                </div>
                            </div>

                        <!-- Manual Form -->
                        <div v-else class="space-y-4">
                            <div class="space-y-4">
                                <div class="space-y-2">
                                    <label class="text-[10px] font-bold uppercase tracking-wider text-(--text-muted) px-1">Account Info</label>
                                    <div class="grid grid-cols-1 gap-2.5">
                                        <Input v-model="form.email" placeholder="Email Address" class="h-11 rounded-xl" />
                                        <Input v-model="form.name" placeholder="Display Name (e.g. Work Email)" class="h-11 rounded-xl" />
                                    </div>
                                </div>
                                
                                <div class="border-t border-dashed border-(--border-default) opacity-50 py-1"></div>
                                
                                <div class="space-y-2">
                                    <label class="text-[10px] font-bold uppercase tracking-wider text-(--text-muted) px-1">Incoming (IMAP)</label>
                                    <div class="grid grid-cols-12 gap-2.5">
                                        <div class="col-span-6">
                                            <Input v-model="form.imap_host" placeholder="imap.server.com" class="h-11 rounded-xl" />
                                        </div>
                                        <div class="col-span-3">
                                            <Input v-model.number="form.imap_port" placeholder="Port" class="h-11 rounded-xl" />
                                        </div>
                                        <div class="col-span-3">
                                            <select 
                                                v-model="form.imap_encryption" 
                                                class="w-full h-11 px-3 bg-(--surface-primary) border border-(--border-default) rounded-xl text-sm focus:outline-none focus:ring-4 focus:ring-(--brand-primary)/10 focus:border-(--brand-primary) transition-all"
                                            >
                                                <option v-for="opt in encryptionOptions" :key="opt.value" :value="opt.value">
                                                    {{ opt.label }}
                                                </option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="space-y-2">
                                    <label class="text-[10px] font-bold uppercase tracking-wider text-(--text-muted) px-1">Outgoing (SMTP)</label>
                                    <div class="grid grid-cols-12 gap-2.5">
                                        <div class="col-span-6">
                                            <Input v-model="form.smtp_host" placeholder="smtp.server.com" class="h-11 rounded-xl" />
                                        </div>
                                        <div class="col-span-3">
                                            <Input v-model.number="form.smtp_port" placeholder="Port" class="h-11 rounded-xl" />
                                        </div>
                                        <div class="col-span-3">
                                            <select 
                                                v-model="form.smtp_encryption" 
                                                class="w-full h-11 px-3 bg-(--surface-primary) border border-(--border-default) rounded-xl text-sm focus:outline-none focus:ring-4 focus:ring-(--brand-primary)/10 focus:border-(--brand-primary) transition-all"
                                            >
                                                <option v-for="opt in encryptionOptions" :key="opt.value" :value="opt.value">
                                                    {{ opt.label }}
                                                </option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="border-t border-dashed border-(--border-default) opacity-50 py-1"></div>
                                
                                <div class="space-y-2">
                                    <label class="text-[10px] font-bold uppercase tracking-wider text-(--text-muted) px-1">Credentials</label>
                                    <div class="grid grid-cols-1 gap-2.5">
                                        <Input v-model="form.username" placeholder="Username (usually same as email)" class="h-11 rounded-xl" />
                                        <Input v-model="form.password" type="password" placeholder="App Password / Login Password" class="h-11 rounded-xl" />
                                    </div>
                                </div>
                            </div>
                            
                            <div class="flex gap-4 pt-4">
                                <Button 
                                    variant="secondary" 
                                    class="flex-1 h-12 border-(--border-default) font-bold rounded-xl" 
                                    :disabled="isLoading || isTestingConfig" 
                                    @click="testConfiguration"
                                >
                                    <Loader2 v-if="isTestingConfig" class="w-4 h-4 animate-spin mr-2" />
                                    <CheckCircle v-else class="w-4 h-4 mr-2 text-emerald-500" />
                                    Test
                                </Button>
                                <Button 
                                    class="flex-2 h-12 shadow-lg font-bold rounded-xl" 
                                    :disabled="isLoading || isTestingConfig" 
                                    @click="connectCustom"
                                >
                                    <Loader2 v-if="isLoading" class="w-4 h-4 animate-spin mr-2" />
                                    <ArrowRight v-else class="w-4 h-4 mr-2" />
                                    Connect Account
                                </Button>
                            </div>
                        </div>
                    </div>
    
                    <!-- Step 4: Folder Selection -->
                    <div v-else-if="step === 4" class="w-full h-full flex flex-col animate-in fade-in slide-in-from-bottom-4 duration-500 px-4">
                        <div class="mb-6 text-center">
                            <h2 class="text-2xl font-bold tracking-tight text-(--text-primary)">Sync Folders</h2>
                            <p class="text-(--text-secondary) text-sm mt-1">
                                Select the folders you want to access in WorkSphere.
                            </p>
                        </div>
    
                        <div v-if="isLoading" class="flex-1 flex flex-col items-center justify-center min-h-[200px]">
                            <Loader2 class="w-12 h-12 animate-spin text-(--brand-primary) mb-4 opacity-50" />
                            <p class="text-base font-semibold text-(--text-secondary)">Discovering folders...</p>
                        </div>
    
                        <div v-else class="flex-1 flex flex-col">
                             <div class="flex justify-end px-2 mb-3">
                                <button 
                                    @click="selectedFolders = remoteFolders.map(f => f.name)"
                                    class="text-xs font-bold text-(--brand-primary) hover:text-(--brand-primary-hover) px-2 py-1 transition-colors"
                                >
                                    Select All
                                </button>
                                <div class="w-px h-3 bg-(--border-default) self-center mx-1"></div>
                                <button 
                                    @click="selectedFolders = []"
                                    class="text-xs font-bold text-(--text-muted) hover:text-(--text-secondary) px-2 py-1 transition-colors"
                                >
                                    Deselect All
                                </button>
                            </div>
                            
                            <div class="flex-1 overflow-y-auto border border-(--border-default) rounded-[24px] p-3 max-h-[380px] bg-(--surface-secondary)/30 backdrop-blur-xl space-y-2 scrollbar-thin">
                                <div 
                                    v-for="folder in remoteFolders" 
                                    :key="folder.name"
                                    @click="toggleFolder(folder.name)"
                                    class="flex items-center gap-4 p-4 rounded-2xl cursor-pointer select-none transition-all duration-300 group border-2"
                                    :class="selectedFolders.includes(folder.name) 
                                        ? 'bg-blue-500/10 border-blue-500 shadow-[0_8px_20px_-8px_rgba(59,130,246,0.3)] scale-[1.01]' 
                                        : 'bg-(--surface-primary)/50 border-(--border-subtle) hover:bg-(--surface-primary) hover:border-blue-500/30'"
                                >
                                    <div class="relative flex items-center justify-center w-7 h-7 rounded-xl border-2 transition-all duration-300 shrink-0"
                                         :class="selectedFolders.includes(folder.name) ? 'bg-blue-500 border-blue-500 shadow-sm' : 'border-(--border-strong) bg-(--surface-elevated) group-hover:border-blue-500/50'">
                                        <Check v-if="selectedFolders.includes(folder.name)" class="w-5 h-5 text-white stroke-[3.5px]" />
                                    </div>
                                    <div class="flex flex-col min-w-0">
                                        <span class="text-base font-black tracking-tight transition-colors" :class="selectedFolders.includes(folder.name) ? 'text-blue-600 dark:text-blue-400' : 'text-(--text-secondary) group-hover:text-(--text-primary)'">
                                            {{ folder.name }}
                                        </span>
                                        <span class="text-[10px] font-black uppercase tracking-widest transition-colors" :class="selectedFolders.includes(folder.name) ? 'text-blue-600/70 dark:text-blue-400/60' : 'text-(--text-muted) opacity-50'">
                                            {{ selectedFolders.includes(folder.name) ? 'Synchronized' : 'Hidden' }}
                                        </span>
                                    </div>
                                </div>
                                <div v-if="remoteFolders.length === 0" class="p-10 text-center text-(--text-muted) italic">
                                    <p>No folders discovered.</p>
                                    <p class="text-xs mt-2 font-medium opacity-70">We will sync your Inbox by default.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 5: Finish -->
                    <div v-else-if="step === 5" class="w-full max-w-md mx-auto pt-10 px-4 text-center">
                        <div class="mx-auto w-24 h-24 bg-green-100 dark:bg-green-900/30 rounded-full flex items-center justify-center text-green-600 dark:text-green-400 mb-6 shadow-sm ring-8 ring-green-50 dark:ring-green-900/10 shrink-0">
                            <CheckCircle class="w-12 h-12" />
                        </div>
                        <h2 class="text-3xl font-bold mb-3 text-(--text-primary)">All Set!</h2>
                        <p class="text-(--text-secondary) max-w-sm mx-auto mb-8 text-base">
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
            <div class="flex w-full items-center justify-between pt-4 border-t border-(--border-default) mt-4" v-if="step < 5">
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
<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
