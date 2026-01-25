import { BaseService } from './base.service';
import type { ApiResponse } from '@/types';

export interface EmailSignature {
    id: string;
    user_id?: number;
    name: string;
    content: string;
    is_default: boolean;
    created_at?: string;
    updated_at?: string;
}

class EmailSignatureService extends BaseService {
    async list(): Promise<EmailSignature[]> {
        const response = await this.api.get<ApiResponse<EmailSignature[]>>('/api/emails/signatures');
        return this.extractData(response);
    }

    async create(data: Partial<EmailSignature>): Promise<EmailSignature> {
        const response = await this.api.post<ApiResponse<EmailSignature>>('/api/emails/signatures', data);
        return this.extractData(response);
    }

    async update(id: string, data: Partial<EmailSignature>): Promise<EmailSignature> {
        const response = await this.api.put<ApiResponse<EmailSignature>>(`/api/emails/signatures/${id}`, data);
        return this.extractData(response);
    }

    async delete(id: string): Promise<void> {
        await this.api.delete(`/api/emails/signatures/${id}`);
    }

    async uploadImage(id: string, file: File): Promise<{ url: string; id: number; mime_type: string }> {
        const formData = new FormData();
        formData.append('file', file);
        const response = await this.api.post<{ url: string; id: number; mime_type: string }>(`/api/emails/signatures/${id}/media`, formData, {
            headers: {
                'Content-Type': 'multipart/form-data'
            }
        });
        return response.data;
    }
}

export const emailSignatureService = new EmailSignatureService();
