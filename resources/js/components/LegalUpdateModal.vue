<script setup>
import { ref, onMounted, computed } from "vue";
import { Modal, Button } from "@/components/ui";
import { ShieldAlert } from "lucide-vue-next";
import axios from "axios";
import { toast } from "vue-sonner";

const isOpen = ref(false);
const pendingAgreements = ref([]);
const isSubmitting = ref(false);

const title = computed(() => {
    return pendingAgreements.value.length > 1
        ? "Updates to our Legal Agreements"
        : "Update to our Legal Agreement";
});

const description = computed(() => {
    return "We have updated our terms. Please review and accept the changes to continue using WorkSphere.";
});

onMounted(async () => {
    await checkStatus();
    // Poll checks or listen to events? For now, mount check is sufficient.
});

async function checkStatus() {
    try {
        const { data } = await axios.get("/api/legal/status");
        if (!data.is_compliant) {
            pendingAgreements.value = data.pending_agreements;
            isOpen.value = true;
        }
    } catch (error) {
        console.error("Failed to check legal status", error);
    }
}

async function handleAgree() {
    isSubmitting.value = true;
    try {
        // Process all pending agreements
        for (const agreement of pendingAgreements.value) {
            await axios.post("/api/legal/agreements", {
                document_type: agreement.type,
            });
        }
        
        toast.success("Agreements accepted successfully.");
        isOpen.value = false;
    } catch (error) {
        toast.error("Failed to accept agreements. Please try again.");
    } finally {
        isSubmitting.value = false;
    }
}
</script>

<template>
    <!-- Prevent closing by clicking outside or escape key -->
    <Modal 
        :open="isOpen" 
        :title="title" 
        :description="description"
        prevent-close
    >
        <div class="space-y-6">
            <div class="flex items-center gap-4 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/50 dark:bg-amber-900/20">
                <ShieldAlert class="h-5 w-5 text-amber-600 dark:text-amber-500" />
                <p class="text-sm text-amber-800 dark:text-amber-200">
                    You must accept the updated agreements to continue.
                </p>
            </div>

            <div class="space-y-3">
                <div 
                    v-for="agreement in pendingAgreements" 
                    :key="agreement.type"
                    class="flex items-center justify-between rounded-md border p-3 dark:border-gray-700"
                >
                    <div class="flex flex-col">
                        <span class="font-medium capitalize">{{ agreement.type === 'tos' ? 'Terms of Service' : 'Privacy Policy' }}</span>
                        <span class="text-xs text-gray-500">Version {{ agreement.version }} • Effective {{ agreement.published_at }}</span>
                    </div>
                    <a 
                        :href="agreement.url" 
                        target="_blank" 
                        class="text-sm font-medium text-blue-600 hover:underline dark:text-blue-400"
                    >
                        Review
                    </a>
                </div>
            </div>

            <div class="flex justify-end pt-2">
                <Button 
                    @click="handleAgree" 
                    :loading="isSubmitting"
                    class="w-full sm:w-auto"
                >
                    I Agree to All Updates
                </Button>
            </div>
        </div>
    </Modal>
</template>
