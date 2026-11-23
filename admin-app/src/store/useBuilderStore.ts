import { arrayMove } from '@dnd-kit/sortable';
import { create } from 'zustand';
import { formsApi, type FormEntity } from '../api/formsApi';
import type { VxfFieldDefinitionMeta } from '../types/globals';
import type {
  FormFieldConditions,
  FormFieldSchema,
  FormSchemaData,
  FormStepSchema,
  FormMultiStepConfig,
  FormThemeConfig,
} from '../types/schema';

interface BuilderState {
  sessionId: number;
  activeForm: FormEntity | null;
  schema: FormSchemaData | null;
  selectedFieldId: string | null;
  editVersion: number;
  isLoading: boolean;
  isSaving: boolean;
  isDirty: boolean;
  saveNotice: string | null;
  saveError: string | null;
  conflictMessage: string | null;

  openBuilder: (formId: number) => Promise<boolean>;
  reloadBuilderForm: () => Promise<void>;
  closeBuilder: () => void;
  selectField: (fieldId: string | null) => void;

  insertFieldFromPalette: (
    definition: VxfFieldDefinitionMeta,
    targetStepId: string,
    insertIndex?: number
  ) => void;
  moveOrReorderField: (
    activeFieldId: string,
    sourceStepId: string,
    targetStepId: string,
    targetIndex: number
  ) => void;
  removeField: (stepId: string, fieldId: string) => void;

  saveSchema: () => Promise<boolean>;
  clearBuilderMessages: () => void;
}

function cloneConditions(
  conditions: FormFieldConditions | null | undefined
): FormFieldConditions | null {
  if (!conditions || typeof conditions !== 'object') {
    return null;
  }

  return {
    ...conditions,
    action: conditions.action === 'hide' ? 'hide' : 'show',
    match: conditions.match === 'any' ? 'any' : 'all',
    rules: Array.isArray(conditions.rules)
      ? conditions.rules.map((rule) => ({ ...rule }))
      : [],
  };
}

function normalizeField(field: FormFieldSchema, stepIdx: number, fieldIdx: number): FormFieldSchema {
  return {
    ...field,
    id: field.id ?? `fld_${field.key ?? `${stepIdx}_${fieldIdx}`}`,
    key: field.key ?? `field_${stepIdx}_${fieldIdx}`,
    type: field.type ?? 'text',
    label: field.label ?? 'Untitled Field',
    required: Boolean(field.required),
    width: field.width ?? '100',
    order: fieldIdx,
    ...(field.validation && typeof field.validation === 'object'
      ? { validation: { ...field.validation } }
      : {}),
    ...(field.conditions !== undefined
      ? { conditions: cloneConditions(field.conditions) }
      : {}),
    ...(Array.isArray(field.options)
      ? { options: field.options.map((opt) => ({ ...opt })) }
      : {}),
  };
}

function normalizeSchema(raw: FormSchemaData | undefined | null): FormSchemaData {
  const defaultMultiStep = {
    enabled: false,
    progress_style: 'bar' as const,
    show_step_titles: true,
    allow_back_navigation: true,
  };

  const defaultTheme = {
    primary_color: '#4F46E5',
    border_radius: '8px',
    input_padding: '12px 14px',
    label_position: 'top' as const,
  };

  const fallbackStep: FormStepSchema = {
    id: 'step_1',
    title: 'Step 1',
    next_button_text: 'Next',
    submit_button_text: 'Submit',
    fields: [],
  };

  if (!raw || typeof raw !== 'object') {
    return {
      schema_version: '1.0.0',
      multi_step: defaultMultiStep,
      steps: [fallbackStep],
      theme: defaultTheme,
    };
  }

  const rawMultiStep: Partial<FormMultiStepConfig> =
  raw.multi_step && typeof raw.multi_step === 'object'
    ? raw.multi_step
    : {};

const rawTheme: Partial<FormThemeConfig> =
  raw.theme && typeof raw.theme === 'object'
    ? raw.theme
    : {};
  const hasSteps = Array.isArray(raw.steps) && raw.steps.length > 0;

  return {
    ...raw,
    schema_version: raw.schema_version ?? '1.0.0',
    multi_step: {
      ...defaultMultiStep,
      ...rawMultiStep,
      enabled: rawMultiStep.enabled ?? defaultMultiStep.enabled,
      progress_style: rawMultiStep.progress_style ?? defaultMultiStep.progress_style,
      show_step_titles: rawMultiStep.show_step_titles ?? defaultMultiStep.show_step_titles,
      allow_back_navigation:
        rawMultiStep.allow_back_navigation ?? defaultMultiStep.allow_back_navigation,
    },
    steps: hasSteps
      ? raw.steps.map((step, idx) => ({
          ...step,
          id: step.id ?? `step_${idx + 1}`,
          title: step.title ?? `Step ${idx + 1}`,
          next_button_text: step.next_button_text ?? 'Next',
          submit_button_text: step.submit_button_text ?? 'Submit',
          fields: Array.isArray(step.fields)
            ? step.fields.map((f, fIdx) => normalizeField(f, idx, fIdx))
            : [],
        }))
      : [fallbackStep],
    theme: {
      ...defaultTheme,
      ...rawTheme,
      primary_color: rawTheme.primary_color ?? defaultTheme.primary_color,
      border_radius: rawTheme.border_radius ?? defaultTheme.border_radius,
      input_padding: rawTheme.input_padding ?? defaultTheme.input_padding,
      label_position: rawTheme.label_position ?? defaultTheme.label_position,
    },
  };
}

function generateUniqueFieldIdentifiers(
  schema: FormSchemaData,
  fieldType: string
): { id: string; key: string } {
  const existingIds = new Set<string>();
  const existingKeys = new Set<string>();

  for (const step of schema.steps) {
    for (const field of step.fields) {
      existingIds.add(field.id);
      existingKeys.add(field.key);
    }
  }

  const sanitizedType = fieldType.replace(/[^a-z0-9_]/gi, '_').toLowerCase() || 'field';

  let counter = 1;
  let candidateKey = `${sanitizedType}_${counter}`;
  while (existingKeys.has(candidateKey) || candidateKey.length > 64) {
    counter++;
    candidateKey = `${sanitizedType}_${counter}`.slice(0, 64);
  }

  let candidateId = '';
  do {
    const randomPart =
      typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
        ? crypto.randomUUID().replace(/-/g, '').slice(0, 10)
        : `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 7)}`;
    candidateId = `fld_${randomPart}`;
  } while (existingIds.has(candidateId));

  return { id: candidateId, key: candidateKey };
}

function reindexFields(fields: FormFieldSchema[]): FormFieldSchema[] {
  return fields.map((field, index) => ({
    ...field,
    order: index,
  }));
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

export const useBuilderStore = create<BuilderState>((set, get) => ({
  sessionId: 0,
  activeForm: null,
  schema: null,
  selectedFieldId: null,
  editVersion: 0,
  isLoading: false,
  isSaving: false,
  isDirty: false,
  saveNotice: null,
  saveError: null,
  conflictMessage: null,

  openBuilder: async (formId) => {
    const requestSessionId = get().sessionId + 1;

    set({
      sessionId: requestSessionId,
      activeForm: null,
      schema: null,
      selectedFieldId: null,
      isLoading: true,
      isSaving: false,
      isDirty: false,
      saveError: null,
      saveNotice: null,
      conflictMessage: null,
    });

    try {
      const response = await formsApi.getForm(formId);

      if (get().sessionId !== requestSessionId) {
        return false;
      }

      const normalized = normalizeSchema(response.data.schema);

      set((state) => {
        if (state.sessionId !== requestSessionId) {
          return state;
        }

        return {
          activeForm: response.data,
          schema: normalized,
          editVersion: state.editVersion + 1,
          isLoading: false,
          isSaving: false,
          isDirty: false,
        };
      });

      return get().sessionId === requestSessionId;
    } catch (err) {
      if (get().sessionId !== requestSessionId) {
        return false;
      }

      const { message } = extractErrorDetails(err, 'Failed to open form in builder.');
      set((state) => {
        if (state.sessionId !== requestSessionId) {
          return state;
        }

        return {
          isLoading: false,
          isSaving: false,
          saveError: message,
        };
      });
      return false;
    }
  },

  reloadBuilderForm: async () => {
    const { activeForm } = get();
    if (!activeForm) {
      return;
    }
    await get().openBuilder(activeForm.id);
  },

  closeBuilder: () => {
    set((state) => ({
      sessionId: state.sessionId + 1,
      activeForm: null,
      schema: null,
      selectedFieldId: null,
      isLoading: false,
      isSaving: false,
      isDirty: false,
      saveNotice: null,
      saveError: null,
      conflictMessage: null,
    }));
  },

  selectField: (fieldId) => {
    set({ selectedFieldId: fieldId });
  },

  insertFieldFromPalette: (definition, targetStepId, insertIndex) => {
    const { schema } = get();
    if (!schema) {
      return;
    }

    const { id, key } = generateUniqueFieldIdentifiers(schema, definition.type);
    const defaults = definition.defaultConfig || {};

    const newField: FormFieldSchema = {
      ...defaults,
      id,
      key,
      type: definition.type,
      label: typeof defaults.label === 'string' ? defaults.label : definition.label,
      description: typeof defaults.description === 'string' ? defaults.description : '',
      placeholder: typeof defaults.placeholder === 'string' ? defaults.placeholder : '',
      default: typeof defaults.default === 'string' ? defaults.default : '',
      required: Boolean(defaults.required),
      width: (defaults.width as FormFieldSchema['width']) || '100',
      validation:
        defaults.validation && typeof defaults.validation === 'object'
          ? { ...(defaults.validation as Record<string, unknown>) }
          : {},
      conditions: null,
      ...(typeof defaults.rows === 'number' ? { rows: defaults.rows } : {}),
      ...(Array.isArray(defaults.options)
        ? { options: defaults.options.map((opt) => ({ ...opt })) }
        : {}),
    };

    const nextSteps = schema.steps.map((step) => {
      if (step.id !== targetStepId) {
        return step;
      }

      const nextFields = [...step.fields];
      const safeIndex =
        typeof insertIndex === 'number' && insertIndex >= 0 && insertIndex <= nextFields.length
          ? insertIndex
          : nextFields.length;

      nextFields.splice(safeIndex, 0, newField);

      return {
        ...step,
        fields: reindexFields(nextFields),
      };
    });

    set((state) => ({
      schema: {
        ...schema,
        steps: nextSteps,
      },
      selectedFieldId: newField.id,
      editVersion: state.editVersion + 1,
      isDirty: true,
      saveNotice: null,
      saveError: null,
    }));
  },

  moveOrReorderField: (activeFieldId, sourceStepId, targetStepId, targetIndex) => {
    const { schema } = get();
    if (!schema) {
      return;
    }

    const sourceStep = schema.steps.find((s) => s.id === sourceStepId);
    const targetStep = schema.steps.find((s) => s.id === targetStepId);
    if (!sourceStep || !targetStep) {
      return;
    }

    const activeIndex = sourceStep.fields.findIndex((f) => f.id === activeFieldId);
    if (activeIndex === -1) {
      return;
    }

    // Same-step reordering
    if (sourceStepId === targetStepId) {
      const clampedTarget = Math.max(0, Math.min(sourceStep.fields.length - 1, targetIndex));
      if (activeIndex === clampedTarget) {
        return;
      }

      const reordered = reindexFields(arrayMove(sourceStep.fields, activeIndex, clampedTarget));
      set((state) => ({
        schema: {
          ...schema,
          steps: schema.steps.map((step) =>
            step.id === sourceStepId ? { ...step, fields: reordered } : step
          ),
        },
        editVersion: state.editVersion + 1,
        isDirty: true,
        saveNotice: null,
      }));
      return;
    }

    // Cross-step movement
    const movingField = sourceStep.fields[activeIndex];
    const nextSourceFields = reindexFields(
      sourceStep.fields.filter((f) => f.id !== activeFieldId)
    );
    const nextTargetFields = [...targetStep.fields];
    const safeTargetIndex = Math.max(0, Math.min(nextTargetFields.length, targetIndex));
    nextTargetFields.splice(safeTargetIndex, 0, movingField);

    set((state) => ({
      schema: {
        ...schema,
        steps: schema.steps.map((step) => {
          if (step.id === sourceStepId) {
            return { ...step, fields: nextSourceFields };
          }
          if (step.id === targetStepId) {
            return { ...step, fields: reindexFields(nextTargetFields) };
          }
          return step;
        }),
      },
      editVersion: state.editVersion + 1,
      isDirty: true,
      saveNotice: null,
    }));
  },

  removeField: (stepId, fieldId) => {
    const { schema, selectedFieldId } = get();
    if (!schema) {
      return;
    }

    const nextSteps = schema.steps.map((step) => {
      if (step.id !== stepId) {
        return step;
      }
      return {
        ...step,
        fields: reindexFields(step.fields.filter((f) => f.id !== fieldId)),
      };
    });

    set((state) => ({
      schema: {
        ...schema,
        steps: nextSteps,
      },
      selectedFieldId: selectedFieldId === fieldId ? null : selectedFieldId,
      editVersion: state.editVersion + 1,
      isDirty: true,
      saveNotice: null,
      saveError: null,
    }));
  },

  saveSchema: async () => {
    const { sessionId, activeForm, schema, isSaving, editVersion } = get();
    if (!activeForm || !schema || isSaving) {
      return false;
    }

    const requestSessionId = sessionId;
    const requestFormId = activeForm.id;
    const saveStartEditVersion = editVersion;
    const schemaSnapshot = schema;

    set({
      isSaving: true,
      saveNotice: null,
      saveError: null,
      conflictMessage: null,
    });

    try {
      const response = await formsApi.updateForm(requestFormId, {
        revision: activeForm.revision,
        schema: schemaSnapshot,
      });

      if (
        get().sessionId !== requestSessionId ||
        get().activeForm?.id !== requestFormId
      ) {
        return false;
      }

      const serverNormalized = normalizeSchema(response.data.schema);

      set((currentState) => {
        if (
          currentState.sessionId !== requestSessionId ||
          currentState.activeForm?.id !== requestFormId
        ) {
          return currentState;
        }

        const hasConcurrentLocalEdits = currentState.editVersion !== saveStartEditVersion;

        return {
          activeForm: response.data,
          schema: hasConcurrentLocalEdits ? currentState.schema : serverNormalized,
          isSaving: false,
          isDirty: hasConcurrentLocalEdits,
          saveNotice: hasConcurrentLocalEdits
            ? `Saved revision #${response.data.revision}, preserving newer local edits.`
            : `Saved successfully (Revision #${response.data.revision}).`,
        };
      });

      return get().sessionId === requestSessionId && get().activeForm?.id === requestFormId;
    } catch (err) {
      if (
        get().sessionId !== requestSessionId ||
        get().activeForm?.id !== requestFormId
      ) {
        return false;
      }

      const { code, message } = extractErrorDetails(err, 'Failed to save form structure.');

      set((currentState) => {
        if (
          currentState.sessionId !== requestSessionId ||
          currentState.activeForm?.id !== requestFormId
        ) {
          return currentState;
        }

        if (code === 'vxf_revision_conflict') {
          return {
            isSaving: false,
            isDirty: true,
            conflictMessage: message,
          };
        }

        return {
          isSaving: false,
          isDirty: true,
          saveError: message,
        };
      });

      return false;
    }
  },

  clearBuilderMessages: () => {
    set({
      saveNotice: null,
      saveError: null,
      conflictMessage: null,
    });
  },
}));
