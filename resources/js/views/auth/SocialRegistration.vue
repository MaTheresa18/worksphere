<template>
    <div
        class="min-h-screen flex items-center justify-center bg-gray-100 dark:bg-gray-950"
    >
        <!-- Background pattern or image could go here -->

        <Modal
            :open="true"
            @update:open="(val) => !val && cancel()"
            title="Complete Registration"
            :description="`You are authenticating with ${provider}.`"
        >
            <div class="space-y-6">
                <div
                    class="rounded-md bg-blue-50 dark:bg-blue-900/20 p-4 border border-blue-100 dark:border-blue-900/50"
                >
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <InfoIcon
                                class="h-5 w-5 text-blue-400"
                                aria-hidden="true"
                            />
                        </div>
                        <div class="ml-3">
                            <h3
                                class="text-sm font-medium text-blue-800 dark:text-blue-200"
                            >
                                Action Required
                            </h3>
                            <div
                                class="mt-2 text-sm text-blue-700 dark:text-blue-300"
                            >
                                <p>
                                    To create your account, you must review and
                                    agree to our
                                    <a
                                        href="/terms"
                                        target="_blank"
                                        class="font-medium underline hover:text-blue-600"
                                        >Terms of Service</a
                                    >
                                    and
                                    <a
                                        href="/privacy"
                                        target="_blank"
                                        class="font-medium underline hover:text-blue-600"
                                        >Privacy Policy</a
                                    >.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    class="flex items-start bg-gray-50 dark:bg-gray-800/50 p-3 rounded-lg border border-gray-100 dark:border-gray-800"
                >
                    <div class="flex items-center h-5">
                        <Checkbox id="terms" v-model="agreed" />
                    </div>
                    <div class="ml-3 text-sm">
                        <label
                            for="terms"
                            class="font-medium text-gray-700 dark:text-gray-300 cursor-pointer select-none"
                        >
                            I agree to the Terms of Service and Privacy Policy
                        </label>
                    </div>
                </div>

                <div class="flex flex-col gap-3 pt-2">
                    <Button
                        type="button"
                        variant="primary"
                        class="w-full justify-center shadow-lg shadow-blue-500/20"
                        size="lg"
                        :disabled="!agreed || isLoading"
                        :loading="isLoading"
                        @click="completeRegistration"
                    >
                        Agree & Create Account
                    </Button>

                    <Button
                        type="button"
                        variant="ghost"
                        class="w-full justify-center text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                        @click="cancel"
                    >
                        Cancel
                    </Button>
                </div>
            </div>
        </Modal>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from "vue";
import { useRoute, useRouter } from "vue-router";
import { Info as InfoIcon } from "lucide-vue-next";
import { Modal, Button, Checkbox } from "@/components/ui"; // Correct import now
import axios from "axios";
import { toast } from "vue-sonner";

const route = useRoute();
const router = useRouter();

const token = computed(() => route.query.token);
const provider = computed(() => route.query.provider || "Social Provider");
const agreed = ref(false);
const isLoading = ref(false);

const completeRegistration = async () => {
    if (!agreed.value) return;
    if (!token.value) {
        toast.error("Invalid registration session.");
        return;
    }

    isLoading.value = true;
    try {
        await axios.post("/api/auth/social/complete", {
            token: token.value,
            agreed: true,
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
        });

        toast.success("Account created successfully!");

        // Force reload to ensure auth state and session are picked up cleanly
        window.location.href = "/dashboard";
    } catch (error) {
        toast.error(
            error.response?.data?.message ||
                "Registration failed. Please try again.",
        );
    } finally {
        isLoading.value = false;
    }
};

const cancel = () => {
    router.push({ name: "login" });
};

onMounted(() => {
    if (!token.value) {
        // If arrived here without a token, bounce them
        router.replace({ name: "login" });
    }
});
</script>
