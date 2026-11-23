import { create } from 'zustand';
import {
  formsApi,
  type CreateFormInput,
  type FormEntity,
  type FormStatus,
  type FormSummary,
} from '../api/formsApi';

export interface DeleteTarget {
  form: FormSummary;
  mode: 'trash' | 'permanent';
}

interface FormsState {
  forms: FormSummary[];
  total: number;
  page: number;
  perPage: number;
  statusFilter: '' | FormStatus;
  searchQuery: string;
  isLoading: boolean;
  isSaving: boolean;
  errorMessage: string | null;
  noticeMessage: string | null;
  conflictMessage: string | null;

  isFormModalOpen: boolean;
  editingForm: FormEntity | null;
  deleteTarget: DeleteTarget | null;

  fetchForms: () => Promise<void>;
  setStatusFilter: (status: '' | FormStatus) => void;
  setSearchQuery: (query: string) => void;
  setPage: (page: number) => void;

  openCreateModal: () => void;
  openEditModal: (id: number) => Promise<void>;
  reloadEditingForm: () => Promise<void>;
  closeFormModal: () => void;
  saveForm: (payload: CreateFormInput) => Promise<boolean>;

  duplicateForm: (id: number) => Promise<void>;
  restoreForm: (id: number) => Promise<void>;
  requestTrashForm: (form: FormSummary) => void;
  requestPermanentDeleteForm: (form: FormSummary) => void;
  cancelDeleteForm: () => void;
  confirmDeleteForm: (confirmPhrase?: string) => Promise<void>;
  clearMessages: () => void;
}

function extractErrorDetails(err: unknown, fallback: string): { code: string; message: string } {
  if (err && typeof err === 'object') {
    const code = 'code' in err && typeof err.code === 'string' ? err.code : '';
    const message =
      'message' in err && typeof err.message === 'string' ? err.message : fallback;
    return { code, message };
  }
  return { code: '', message: fallback };
}

export const useFormsStore = create<FormsState>((set, get) => ({
  forms: [],
  total: 0,
  page: 1,
  perPage: 20,
  statusFilter: '',
  searchQuery: '',
  isLoading: false,
  isSaving: false,
  errorMessage: null,
  noticeMessage: null,
  conflictMessage: null,

  isFormModalOpen: false,
  editingForm: null,
  deleteTarget: null,

  fetchForms: async () => {
    const { statusFilter, searchQuery, page, perPage } = get();
    set({ isLoading: true, errorMessage: null });

    try {
      const response = await formsApi.listForms({
        status: statusFilter || undefined,
        search: searchQuery || undefined,
        page,
        per_page: perPage,
      });

      set({
        forms: response.data || [],
        total: response.meta?.total ?? 0,
        isLoading: false,
      });
    } catch (err) {
      const { message } = extractErrorDetails(err, 'Failed to load forms.');
      set({
        isLoading: false,
        errorMessage: message,
      });
    }
  },

  setStatusFilter: (status) => {
    set({ statusFilter: status, page: 1 });
    void get().fetchForms();
  },

  setSearchQuery: (query) => {
    set({ searchQuery: query, page: 1 });
    void get().fetchForms();
  },

  setPage: (page) => {
    set({ page });
    void get().fetchForms();
  },

  openCreateModal: () => {
    set({
      isFormModalOpen: true,
      editingForm: null,
      errorMessage: null,
      conflictMessage: null,
    });
  },

  openEditModal: async (id) => {
    set({ isSaving: true, errorMessage: null, conflictMessage: null });
    try {
      const response = await formsApi.getForm(id);
      set({
        editingForm: response.data,
        isFormModalOpen: true,
        isSaving: false,
      });
    } catch (err) {
      const { message } = extractErrorDetails(err, 'Failed to load form details.');
      set({
        isSaving: false,
        errorMessage: message,
      });
    }
  },

  reloadEditingForm: async () => {
    const { editingForm } = get();
    if (!editingForm) {
      return;
    }

    set({ isSaving: true, conflictMessage: null, errorMessage: null });
    try {
      const response = await formsApi.getForm(editingForm.id);
      set({
        editingForm: response.data,
        isSaving: false,
      });
    } catch (err) {
      const { message } = extractErrorDetails(err, 'Failed to reload latest form version.');
      set({
        isSaving: false,
        errorMessage: message,
      });
    }
  },

  closeFormModal: () => {
    set({
      isFormModalOpen: false,
      editingForm: null,
      conflictMessage: null,
    });
  },

  saveForm: async (payload) => {
    const { editingForm } = get();
    set({ isSaving: true, errorMessage: null, noticeMessage: null, conflictMessage: null });

    try {
      if (editingForm) {
        await formsApi.updateForm(editingForm.id, {
          ...payload,
          revision: editingForm.revision,
        });
        set({
          isSaving: false,
          isFormModalOpen: false,
          editingForm: null,
          noticeMessage: `Form "${payload.title}" updated successfully.`,
        });
      } else {
        await formsApi.createForm(payload);
        set({
          isSaving: false,
          isFormModalOpen: false,
          editingForm: null,
          noticeMessage: `Form "${payload.title}" created successfully.`,
        });
      }

      await get().fetchForms();
      return true;
    } catch (err) {
      const { code, message } = extractErrorDetails(err, 'Failed to save form.');
      if (code === 'vxf_revision_conflict') {
        set({
          isSaving: false,
          conflictMessage: message,
        });
      } else {
        set({
          isSaving: false,
          errorMessage: message,
        });
      }
      return false;
    }
  },

  duplicateForm: async (id) => {
    set({ isSaving: true, errorMessage: null, noticeMessage: null });
    try {
      const response = await formsApi.duplicateForm(id);
      set({
        isSaving: false,
        noticeMessage: `Duplicated as "${response.data.title}".`,
      });
      await get().fetchForms();
    } catch (err) {
      const { message } = extractErrorDetails(err, 'Failed to duplicate form.');
      set({
        isSaving: false,
        errorMessage: message,
      });
    }
  },

  restoreForm: async (id) => {
    set({ isSaving: true, errorMessage: null, noticeMessage: null });
    try {
      const response = await formsApi.restoreForm(id);
      set({
        isSaving: false,
        noticeMessage: `Restored "${response.data.title}" to Draft.`,
      });
      await get().fetchForms();
    } catch (err) {
      const { message } = extractErrorDetails(err, 'Failed to restore form.');
      set({
        isSaving: false,
        errorMessage: message,
      });
    }
  },

  requestTrashForm: (form) => {
    set({ deleteTarget: { form, mode: 'trash' }, errorMessage: null });
  },

  requestPermanentDeleteForm: (form) => {
    set({ deleteTarget: { form, mode: 'permanent' }, errorMessage: null });
  },

  cancelDeleteForm: () => {
    set({ deleteTarget: null });
  },

  confirmDeleteForm: async (confirmPhrase = '') => {
    const { deleteTarget } = get();
    if (!deleteTarget) {
      return;
    }

    const isPermanent = deleteTarget.mode === 'permanent';
    set({ isSaving: true, errorMessage: null, noticeMessage: null });

    try {
      await formsApi.deleteForm(
        deleteTarget.form.id,
        isPermanent,
        isPermanent ? confirmPhrase.trim() : undefined
      );
      set({
        isSaving: false,
        deleteTarget: null,
        noticeMessage: isPermanent
          ? `Form "${deleteTarget.form.title}" and its associated records were permanently deleted.`
          : `Form "${deleteTarget.form.title}" moved to Trash. All submissions remain intact.`,
      });
      await get().fetchForms();
    } catch (err) {
      const { message } = extractErrorDetails(err, 'Failed to delete form.');
      set({
        isSaving: false,
        deleteTarget: null,
        errorMessage: message,
      });
    }
  },

  clearMessages: () => {
    set({ errorMessage: null, noticeMessage: null, conflictMessage: null });
  },
}));
