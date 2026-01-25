import { ref } from 'vue';
import { emailSignatureService, type EmailSignature } from '@/services/email-signature.service';
import { toast } from 'vue-sonner';

const signatures = ref<EmailSignature[]>([]);
const selectedSignatureId = ref<string | null>(null);
const loading = ref(false);

export function useEmailSignatures() {
    
    async function fetchSignatures() {
        loading.value = true;
        try {
            signatures.value = await emailSignatureService.list();
        } catch (error) {
            console.error(error);
            toast.error('Failed to load signatures');
        } finally {
            loading.value = false;
        }
    }

    async function addSignature(data: Partial<EmailSignature>) {
        try {
            const newSig = await emailSignatureService.create(data);
            signatures.value.push(newSig);
            toast.success('Signature created');
            return newSig;
        } catch (error) {
            console.error(error);
            toast.error('Failed to create signature');
            throw error;
        }
    }

    async function updateSignature(id: string, updates: Partial<EmailSignature>) {
        try {
            const updatedSig = await emailSignatureService.update(id, updates);
            const index = signatures.value.findIndex(s => s.id === id);
            if (index !== -1) {
                signatures.value[index] = updatedSig;
            }
            toast.success('Signature saved');
            return updatedSig;
        } catch (error) {
            console.error(error);
            toast.error('Failed to save signature');
            throw error;
        }
    }

    async function deleteSignature(id: string) {
        try {
            await emailSignatureService.delete(id);
            signatures.value = signatures.value.filter(s => s.id !== id);
            toast.success('Signature deleted');
        } catch (error) {
            console.error(error);
            toast.error('Failed to delete signature');
        }
    }

    async function uploadImage(id: string, file: File) {
        try {
            return await emailSignatureService.uploadImage(id, file);
        } catch (error) {
            console.error(error);
            toast.error('Failed to upload image');
            throw error;
        }
    }

    return {
        signatures,
        selectedSignatureId,
        loading,
        fetchSignatures,
        addSignature,
        updateSignature,
        deleteSignature,
        uploadImage
    };
}

