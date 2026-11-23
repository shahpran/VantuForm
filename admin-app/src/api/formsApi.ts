import apiFetch from '@wordpress/api-fetch';
import type { FormSchemaData } from '../types/schema';

export type FormStatus = 'publish' | 'draft' | 'trash';

export interface FormSummary {
  id: number;
  title: string;
  slug: string;
  status: FormStatus;
  schema_version: string;
  revision: number;
  entry_count: number;
  created_by: number;
  created_at: string;
  updated_at: string;
}

export interface FormEntity extends Omit<FormSummary, 'entry_count'> {
  schema: FormSchemaData;
  settings: {
    confirmation?: {
      type?: 'message' | 'redirect';
      message?: string;
      redirect_url?: string;
    };
    [key: string]: unknown;
  };
}

export interface ListFormsParams {
  status?: string;
  search?: string;
  page?: number;
  per_page?: number;
}

export interface ListFormsResponse {
  success: boolean;
  data: FormSummary[];
  meta: {
    total: number;
    page: number;
    per_page: number;
  };
}

export interface SingleFormResponse {
  success: boolean;
  data: FormEntity;
}

export interface DeleteFormResponse {
  success: boolean;
  data: {
    status: 'trashed' | 'already_trashed' | 'deleted';
    id: number;
  };
}

export interface CreateFormInput {
  title: string;
  slug?: string;
  status: FormStatus;
  schema?: FormSchemaData;
  settings?: Record<string, unknown>;
}

export interface UpdateFormInput extends Partial<CreateFormInput> {
  revision: number;
}

function requireApiFetch() {
  if (!apiFetch) {
    throw new Error('WordPress apiFetch is not available.');
  }
  return apiFetch;
}

function buildQueryString(params: ListFormsParams): string {
  const query = new URLSearchParams();
  if (params.status) query.set('status', params.status);
  if (params.search) query.set('search', params.search);
  if (params.page) query.set('page', String(params.page));
  if (params.per_page) query.set('per_page', String(params.per_page));
  const serialized = query.toString();
  return serialized ? `?${serialized}` : '';
}

export const formsApi = {
  async listForms(params: ListFormsParams = {}): Promise<ListFormsResponse> {
    const client = requireApiFetch();
    return client<ListFormsResponse>({
      path: `/vanturax-forms/v1/forms${buildQueryString(params)}`,
      method: 'GET',
    });
  },

  async getForm(id: number): Promise<SingleFormResponse> {
    const client = requireApiFetch();
    return client<SingleFormResponse>({
      path: `/vanturax-forms/v1/forms/${id}`,
      method: 'GET',
    });
  },

  async createForm(payload: CreateFormInput): Promise<SingleFormResponse> {
    const client = requireApiFetch();
    return client<SingleFormResponse>({
      path: '/vanturax-forms/v1/forms',
      method: 'POST',
      data: payload,
    });
  },

  async updateForm(id: number, payload: UpdateFormInput): Promise<SingleFormResponse> {
    const client = requireApiFetch();
    return client<SingleFormResponse>({
      path: `/vanturax-forms/v1/forms/${id}`,
      method: 'PUT',
      data: payload,
    });
  },

  async duplicateForm(id: number): Promise<SingleFormResponse> {
    const client = requireApiFetch();
    return client<SingleFormResponse>({
      path: `/vanturax-forms/v1/forms/${id}/duplicate`,
      method: 'POST',
    });
  },

  async restoreForm(id: number): Promise<SingleFormResponse> {
    const client = requireApiFetch();
    return client<SingleFormResponse>({
      path: `/vanturax-forms/v1/forms/${id}/restore`,
      method: 'POST',
    });
  },

  async deleteForm(id: number, force = false, confirm?: string): Promise<DeleteFormResponse> {
    const client = requireApiFetch();
    const query = new URLSearchParams();
    if (force) {
      query.set('force', 'true');
      if (confirm) {
        query.set('confirm', confirm);
      }
    }
    const serialized = query.toString();
    return client<DeleteFormResponse>({
      path: `/vanturax-forms/v1/forms/${id}${serialized ? `?${serialized}` : ''}`,
      method: 'DELETE',
      data: force && confirm ? { confirm } : undefined,
    });
  },
};
