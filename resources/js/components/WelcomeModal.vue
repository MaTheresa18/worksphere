<script setup>
import { ref, onMounted } from "vue";
import { Modal } from "@/components/ui"; // Ensure this export exists or adjust path
import { Button } from "@/components/ui";
import { Checkbox } from "@/components/ui";
import { useAuthStore } from "@/stores/auth";
import { PartyPopper } from "lucide-vue-next";
import axios from "axios";

const authStore = useAuthStore();
const isOpen = ref(false);
const dontShowAgain = ref(false);

onMounted(() => {
    // Show if preference is missing or false
    // Also check if we are authenticated
    if (authStore.isAuthenticated) {
        const welcomeSeen = authStore.user?.preferences?.welcome_seen;
        if (!welcomeSeen) {
            isOpen.value = true;
        }
    }
});

async function close() {
    if (dontShowAgain.value) {
        try {
            // Update local store immediately for responsiveness
            if (authStore.user && authStore.user.preferences) {
                authStore.user.preferences.welcome_seen = true;
            } else if (authStore.user) {
                authStore.user.preferences = { welcome_seen: true };
            }

            // Persist to backend
            await axios.put("/api/user/preferences", {
                welcome_seen: true,
            });
        } catch (error) {
            console.error("Failed to save preference", error);
        }
    }
    isOpen.value = false;
}
</script>

<template>
    <Modal :open="isOpen" @update:open="close" title="Welcome to WorkSphere!">
        <div class="space-y-6">
            <div class="flex flex-col items-center justify-center py-4 text-center">
                <div class="mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900/30">
                    <PartyPopper class="h-8 w-8 text-blue-600 dark:text-blue-400" />
                </div>
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white">
                    We're glad you're here
                </h3>
                <p class="mt-2 text-gray-600 dark:text-gray-400 max-w-sm">
                    Your account has been successfully created. Explore your dashboard to get started with your projects.
                </p>
            </div>

            <div class="flex items-center justify-center">
                <Checkbox id="dontShow" v-model="dontShowAgain">
                    Don't show this again
                </Checkbox>
            </div>

            <div class="flex justify-end w-full">
                <Button @click="close" class="w-full"> Get Started </Button>
            </div>
        </div>
    </Modal>
</template>
