import { BaseService } from './base.service';
import type { ApiResponse } from '@/types';

export interface EmailTemplate {
    id: string;
    user_id?: number;
    name: string;
    subject: string;
    body: string;
    is_default: boolean; // Assuming templates might have this too, or at least consistent with signature
    created_at?: string;
    updated_at?: string;
}

class EmailTemplateService extends BaseService {
    async list(): Promise<EmailTemplate[]> {
        const response = await this.api.get<ApiResponse<EmailTemplate[]>>('/api/emails/templates');
        return this.extractData(response);
    }

    async create(data: Partial<EmailTemplate>): Promise<EmailTemplate> {
        const response = await this.api.post<ApiResponse<EmailTemplate>>('/api/emails/templates', data);
        return this.extractData(response);
    }

    async update(id: string, data: Partial<EmailTemplate>): Promise<EmailTemplate> {
        const response = await this.api.put<ApiResponse<EmailTemplate>>(`/api/emails/templates/${id}`, data);
        return this.extractData(response);
    }

    async delete(id: string): Promise<void> {
        await this.api.delete(`/api/emails/templates/${id}`);
    }

    async uploadImage(id: string, file: File): Promise<{ url: string; id: number; mime_type: string }> {
        const formData = new FormData();
        formData.append('file', file);
        const response = await this.api.post<{ url: string; id: number; mime_type: string }>(`/api/emails/templates/${id}/media`, formData, {
            headers: {
                'Content-Type': 'multipart/form-data'
            }
        });
        return response.data;
    }
}

export const emailTemplateService = new EmailTemplateService();
