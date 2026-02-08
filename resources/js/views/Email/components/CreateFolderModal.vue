<template>
    <Modal :open="isOpen" @close="handleClose" title="Create Folder" size="sm">
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-(--text-primary) mb-1.5">
                    Folder Name
                </label>
                <input
                    v-model="folderName"
                    type="text"
                    placeholder="Enter folder name..."
                    class="w-full px-3 py-2 text-sm bg-(--surface-elevated) border border-(--border-default) rounded-lg text-(--text-primary) placeholder-(--text-muted) focus:outline-none focus:ring-2 focus:ring-(--interactive-primary)/50 focus:border-(--interactive-primary)"
                    @keydown.enter="handleCreate"
                    autofocus
                />
            </div>
        </div>

        <template #footer>
            <div class="flex justify-end gap-2">
                <button
                    @click="handleClose"
                    class="px-4 py-2 text-sm font-medium text-(--text-secondary) hover:text-(--text-primary) hover:bg-(--surface-tertiary) rounded-lg transition-colors"
                >
                    Cancel
                </button>
                <button
                    @click="handleCreate"
                    :disabled="!folderName.trim()"
                    class="px-4 py-2 text-sm font-medium bg-(--interactive-primary) text-white rounded-lg hover:bg-(--interactive-primary-hover) disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                >
                    Create Folder
                </button>
            </div>
        </template>
    </Modal>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue';
import Modal from '@/components/ui/Modal.vue';
import { useEmailStore } from '@/stores/emailStore';

const props = defineProps<{
    isOpen: boolean;
}>();

const emit = defineEmits<{
    close: [];
    created: [folderId: string];
}>();

const store = useEmailStore();
const folderName = ref('');

watch(() => props.isOpen, (open) => {
    if (open) {
        folderName.value = '';
    }
});

function handleClose() {
    folderName.value = '';
    emit('close');
}

async function handleCreate() {
    if (!folderName.value.trim()) return;
    
    const folder = await store.addFolder(folderName.value);
    if (folder) {
        emit('created', folder.id);
        handleClose();
    }
}
</script>
