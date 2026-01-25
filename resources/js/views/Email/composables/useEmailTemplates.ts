import { ref } from 'vue';
import { emailTemplateService, type EmailTemplate } from '@/services/email-template.service';
import { toast } from 'vue-sonner';

const templates = ref<EmailTemplate[]>([]);
const selectedTemplateId = ref<string | null>(null);
const loading = ref(false);

export function useEmailTemplates() {
    
    async function fetchTemplates() {
        loading.value = true;
        try {
            templates.value = await emailTemplateService.list();
        } catch (error) {
            console.error(error);
            toast.error('Failed to load templates');
        } finally {
            loading.value = false;
        }
    }

    async function addTemplate(data: Partial<EmailTemplate>) {
        try {
            const newTpl = await emailTemplateService.create(data);
            templates.value.push(newTpl);
            toast.success('Template created');
            return newTpl;
        } catch (error) {
            console.error(error);
            toast.error('Failed to create template');
            throw error;
        }
    }

    async function updateTemplate(id: string, updates: Partial<EmailTemplate>) {
        try {
            const updatedTpl = await emailTemplateService.update(id, updates);
            const index = templates.value.findIndex(t => t.id === id);
            if (index !== -1) {
                templates.value[index] = updatedTpl;
            }
            toast.success('Template saved');
            return updatedTpl;
        } catch (error) {
            console.error(error);
            toast.error('Failed to save template');
            throw error;
        }
    }

    async function deleteTemplate(id: string) {
        try {
            await emailTemplateService.delete(id);
            templates.value = templates.value.filter(t => t.id !== id);
            toast.success('Template deleted');
        } catch (error) {
            console.error(error);
            toast.error('Failed to delete template');
        }
    }

    async function uploadImage(id: string, file: File) {
        try {
            return await emailTemplateService.uploadImage(id, file);
        } catch (error) {
            console.error(error);
            toast.error('Failed to upload image');
            throw error;
        }
    }

    return {
        templates,
        selectedTemplateId,
        loading,
        fetchTemplates,
        addTemplate,
        updateTemplate,
        deleteTemplate,
        uploadImage
    };
}

