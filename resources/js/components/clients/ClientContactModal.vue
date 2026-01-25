<script setup>
import { ref, watch, onMounted } from 'vue';
import { Button, Checkbox } from '@/components/ui';
import api from '@/lib/api';
import { useAuthStore } from '@/stores/auth';

const props = defineProps({
    open: Boolean,
    contact: {
        type: Object,
        default: null
    },
    clientPublicId: {
        type: String,
        required: true
    }
});

const emit = defineEmits(['close', 'saved']);
const authStore = useAuthStore();

const isSubmitting = ref(false);
const errors = ref({});

const formData = ref({
    name: '',
    email: '',
    phone: '',
    role: '',
    is_primary: false,
});

watch(() => props.open, (isOpen) => {
    if (isOpen) {
        if (props.contact) {
            // Edit Mode
            formData.value = {
                name: props.contact.name,
                email: props.contact.email || '',
                phone: props.contact.phone || '',
                role: props.contact.role || '',
                is_primary: !!props.contact.is_primary,
            };
        } else {
            // Create Mode
            formData.value = {
                name: '',
                email: '',
                phone: '',
                role: '',
                is_primary: false,
            };
        }
        errors.value = {};
    }
});

const save = async () => {
    isSubmitting.value = true;
    errors.value = {};
    
    // Convert is_primary to boolean 0/1 if needed, but API validation handles boolean
    const data = { ...formData.value };

    try {
        if (props.contact) {
            // Shallow resource update: /teams/{team}/contacts/{contact}
            await api.put(`/api/teams/${authStore.currentTeamId}/contacts/${props.contact.id}`, data);
        } else {
            // Nested create: /teams/{team}/clients/{client}/contacts
            await api.post(`/api/teams/${authStore.currentTeamId}/clients/${props.clientPublicId}/contacts`, data);
        }
        emit('saved');
        emit('close');
    } catch (error) {
        if (error.response?.data?.errors) {
            errors.value = error.response.data.errors;
        } else {
            console.error(error);
        }
    } finally {
        isSubmitting.value = false;
    }
};
</script>

<template>
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" @click.self="$emit('close')">
        <div class="bg-[var(--surface-primary)] rounded-xl border border-[var(--border-muted)] shadow-xl w-full max-w-md overflow-hidden">
            <div class="p-6 border-b border-[var(--border-muted)]">
                <h3 class="text-lg font-semibold text-[var(--text-primary)]">
                    {{ contact ? 'Edit Contact' : 'Add New Contact' }}
                </h3>
            </div>
            
            <div class="p-6 space-y-4">
                <div class="space-y-1">
                    <label class="text-sm font-medium text-[var(--text-secondary)]">Name</label>
                    <input v-model="formData.name" type="text" class="input">
                    <p v-if="errors.name" class="text-xs text-[var(--color-error)]">{{ errors.name[0] }}</p>
                </div>

                <div class="space-y-1">
                    <label class="text-sm font-medium text-[var(--text-secondary)]">Email</label>
                    <input v-model="formData.email" type="email" class="input">
                    <p v-if="errors.email" class="text-xs text-[var(--color-error)]">{{ errors.email[0] }}</p>
                </div>

                <div class="space-y-1">
                    <label class="text-sm font-medium text-[var(--text-secondary)]">Phone</label>
                    <input v-model="formData.phone" type="text" class="input">
                    <p v-if="errors.phone" class="text-xs text-[var(--color-error)]">{{ errors.phone[0] }}</p>
                </div>

                <div class="space-y-1">
                    <label class="text-sm font-medium text-[var(--text-secondary)]">Role / Job Title</label>
                    <input v-model="formData.role" type="text" class="input" placeholder="e.g. Manager">
                    <p v-if="errors.role" class="text-xs text-[var(--color-error)]">{{ errors.role[0] }}</p>
                </div>

                <!-- 
                <div class="flex items-center space-x-2 pt-2">
                    <Checkbox id="is_primary" v-model:checked="formData.is_primary" />
                    <label for="is_primary" class="text-sm font-medium text-[var(--text-primary)] cursor-pointer">
                        Primary Contact
                    </label>
                </div>
                -->
            </div>
            <div class="px-6 py-4 bg-[var(--surface-secondary)] flex justify-end gap-3">
                <button @click="$emit('close')" class="btn btn-ghost">Cancel</button>
                <Button :loading="isSubmitting" @click="save">
                    {{ contact ? 'Save Changes' : 'Add Contact' }}
                </Button>
            </div>
        </div>
    </div>
</template>
