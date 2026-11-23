import {
  DndContext,
  DragOverlay,
  KeyboardSensor,
  PointerSensor,
  closestCorners,
  useSensor,
  useSensors,
  type ClientRect,
  type DragEndEvent,
  type DragOverEvent,
  type DragStartEvent,
} from '@dnd-kit/core';
import { sortableKeyboardCoordinates } from '@dnd-kit/sortable';
import { useState } from 'react';
import { useBuilderStore } from '../../store/useBuilderStore';
import type { VxfFieldDefinitionMeta } from '../../types/globals';
import type { FormFieldSchema } from '../../types/schema';
import { BuilderCanvas } from './BuilderCanvas';
import { FieldPalette } from './FieldPalette';
import { InspectorPlaceholder } from './InspectorPlaceholder';

interface FormBuilderViewProps {
  fieldDefinitions: VxfFieldDefinitionMeta[];
  onBackToForms: () => void;
}

type ActiveDragPayload =
  | { source: 'palette'; definition: VxfFieldDefinitionMeta }
  | { source: 'canvas'; stepId: string; field: FormFieldSchema };

export function FormBuilderView({ fieldDefinitions, onBackToForms }: FormBuilderViewProps) {
  const {
    activeForm,
    schema,
    selectedFieldId,
    isLoading,
    isSaving,
    isDirty,
    saveNotice,
    saveError,
    conflictMessage,
    selectField,
    insertFieldFromPalette,
    moveOrReorderField,
    removeField,
    saveSchema,
    reloadBuilderForm,
    clearBuilderMessages,
  } = useBuilderStore();

  const [activeDrag, setActiveDrag] = useState<ActiveDragPayload | null>(null);
  const [activeDropStepId, setActiveDropStepId] = useState<string | null>(null);

  const sensors = useSensors(
    useSensor(PointerSensor, {
      activationConstraint: {
        distance: 6,
      },
    }),
    useSensor(KeyboardSensor, {
      coordinateGetter: sortableKeyboardCoordinates,
    })
  );

  if (isLoading || !activeForm || !schema) {
    return (
      <div className="vxf-dashboard">
        <div className="vxf-empty-state">
          <p>Loading Form Builder…</p>
        </div>
      </div>
    );
  }

  const defaultStepId = schema.steps[0]?.id ?? 'step_1';

  const findStepContainingField = (fieldId: string): { stepId: string; index: number } | null => {
    for (const step of schema.steps) {
      const idx = step.fields.findIndex((f) => f.id === fieldId);
      if (idx !== -1) {
        return { stepId: step.id, index: idx };
      }
    }
    return null;
  };

  const isDroppedBelowMidpoint = (
    activeTranslatedRect: ClientRect | null,
    overRect: ClientRect
  ): boolean => {
    if (!activeTranslatedRect) {
      return false;
    }
    const activeCenterY = activeTranslatedRect.top + activeTranslatedRect.height / 2;
    const overMidpointY = overRect.top + overRect.height / 2;
    return activeCenterY > overMidpointY;
  };

  const resolveDropTarget = (
    overId: string,
    overData: Record<string, unknown> | undefined,
    activeSource: 'palette' | 'canvas' | undefined,
    activeId: string,
    activeSourceStepId: string | undefined,
    activeTranslatedRect: ClientRect | null,
    overRect: ClientRect
  ): { stepId: string; index?: number } | null => {
    if (overData?.source === 'step' && typeof overData.stepId === 'string') {
      return { stepId: overData.stepId };
    }
    if (overId.startsWith('step:')) {
      return { stepId: overId.slice('step:'.length) };
    }

    const fieldLocation = findStepContainingField(overId);
    const targetStepId =
      typeof overData?.stepId === 'string' ? overData.stepId : fieldLocation?.stepId;

    if (!targetStepId || !fieldLocation) {
      return null;
    }

    const baseIndex = fieldLocation.index;
    const belowMidpoint = isDroppedBelowMidpoint(activeTranslatedRect, overRect);

    if (activeSource === 'palette') {
      return {
        stepId: targetStepId,
        index: belowMidpoint ? baseIndex + 1 : baseIndex,
      };
    }

    if (activeSource === 'canvas' && activeSourceStepId === targetStepId) {
      if (activeId === overId) {
        return { stepId: targetStepId, index: baseIndex };
      }

      const sourceLocation = findStepContainingField(activeId);
      const activeIndex = sourceLocation ? sourceLocation.index : -1;

      if (activeIndex === -1) {
        return { stepId: targetStepId, index: baseIndex };
      }

      if (activeIndex < baseIndex) {
        return {
          stepId: targetStepId,
          index: belowMidpoint ? baseIndex : Math.max(0, baseIndex - 1),
        };
      }

      return {
        stepId: targetStepId,
        index: belowMidpoint ? baseIndex + 1 : baseIndex,
      };
    }

    return {
      stepId: targetStepId,
      index: belowMidpoint ? baseIndex + 1 : baseIndex,
    };
  };

  const handleDragStart = (event: DragStartEvent) => {
    const data = event.active.data.current as Record<string, unknown> | undefined;
    if (data?.source === 'palette' && data.definition) {
      setActiveDrag({
        source: 'palette',
        definition: data.definition as VxfFieldDefinitionMeta,
      });
    } else if (data?.source === 'canvas' && data.field && typeof data.stepId === 'string') {
      setActiveDrag({
        source: 'canvas',
        stepId: data.stepId,
        field: data.field as FormFieldSchema,
      });
    }
  };

  const handleDragOver = (event: DragOverEvent) => {
    if (!event.over) {
      setActiveDropStepId(null);
      return;
    }

    const activeData = event.active.data.current as Record<string, unknown> | undefined;
    const target = resolveDropTarget(
      String(event.over.id),
      event.over.data.current as Record<string, unknown> | undefined,
      activeData?.source === 'palette' || activeData?.source === 'canvas'
        ? activeData.source
        : undefined,
      String(event.active.id),
      typeof activeData?.stepId === 'string' ? activeData.stepId : undefined,
      event.active.rect.current.translated,
      event.over.rect
    );
    setActiveDropStepId(target ? target.stepId : null);
  };

  const handleDragEnd = (event: DragEndEvent) => {
    const { active, over } = event;
    setActiveDrag(null);
    setActiveDropStepId(null);

    if (!over) {
      return;
    }

    const activeData = active.data.current as Record<string, unknown> | undefined;
    const overData = over.data.current as Record<string, unknown> | undefined;
    const activeSource =
      activeData?.source === 'palette' || activeData?.source === 'canvas'
        ? activeData.source
        : undefined;

    const target = resolveDropTarget(
      String(over.id),
      overData,
      activeSource,
      String(active.id),
      typeof activeData?.stepId === 'string' ? activeData.stepId : undefined,
      active.rect.current.translated,
      over.rect
    );

    if (!target) {
      return;
    }

    if (activeSource === 'palette' && activeData?.definition) {
      insertFieldFromPalette(
        activeData.definition as VxfFieldDefinitionMeta,
        target.stepId,
        target.index
      );
      return;
    }

    if (activeSource === 'canvas' && typeof activeData?.stepId === 'string') {
      const activeId = String(active.id);
      const sourceStepId = activeData.stepId;
      const targetStep = schema.steps.find((s) => s.id === target.stepId);
      const targetIndex =
        typeof target.index === 'number'
          ? target.index
          : targetStep
            ? targetStep.fields.length
            : 0;

      moveOrReorderField(activeId, sourceStepId, target.stepId, targetIndex);
    }
  };

  const handleBack = () => {
    if (isDirty) {
      const proceed = window.confirm(
        'You have unsaved changes in the Form Builder. Discard unsaved changes and return to Forms?'
      );
      if (!proceed) {
        return;
      }
    }
    onBackToForms();
  };

  return (
    <div className="vxf-builder-shell">
      <header className="vxf-builder-topbar">
        <div className="vxf-builder-topbar__left">
          <button type="button" className="button" onClick={handleBack} disabled={isSaving}>
            &larr; All Forms
          </button>
          <div>
            <div className="vxf-builder-topbar__title-row">
              <h1 className="vxf-builder-topbar__title">{activeForm.title}</h1>
              <span className="vxf-dashboard__badge">r{activeForm.revision}</span>
              {isDirty && <span className="vxf-dirty-badge">Unsaved Changes</span>}
            </div>
          </div>
        </div>

        <div className="vxf-builder-topbar__right">
          <button
            type="button"
            className="button button-primary"
            disabled={isSaving || !isDirty}
            onClick={() => void saveSchema()}
          >
            {isSaving ? 'Saving…' : 'Save Form'}
          </button>
        </div>
      </header>

      {saveNotice && (
        <div className="vxf-alert vxf-alert--success vxf-builder-banner">
          <span>{saveNotice}</span>
          <button type="button" className="button-link" onClick={clearBuilderMessages}>
            Dismiss
          </button>
        </div>
      )}

      {saveError && (
        <div className="vxf-alert vxf-alert--error vxf-builder-banner">
          <span>{saveError}</span>
          <button type="button" className="button-link" onClick={clearBuilderMessages}>
            Dismiss
          </button>
        </div>
      )}

      {conflictMessage && (
        <div className="vxf-alert vxf-alert--error vxf-builder-banner">
          <div>
            <strong>Revision Conflict:</strong> {conflictMessage}
          </div>
          <button
            type="button"
            className="button button-small"
            disabled={isSaving}
            onClick={() => void reloadBuilderForm()}
          >
            Reload Latest Version
          </button>
        </div>
      )}

      <DndContext
        sensors={sensors}
        collisionDetection={closestCorners}
        onDragStart={handleDragStart}
        onDragOver={handleDragOver}
        onDragEnd={handleDragEnd}
        onDragCancel={() => {
          setActiveDrag(null);
          setActiveDropStepId(null);
        }}
      >
        <div className="vxf-builder-workspace">
          <FieldPalette
            definitions={fieldDefinitions}
            onQuickAdd={(def) => insertFieldFromPalette(def, defaultStepId)}
          />

          <BuilderCanvas
            formTitle={activeForm.title}
            formSlug={activeForm.slug}
            schema={schema}
            selectedFieldId={selectedFieldId}
            activeDropStepId={activeDropStepId}
            onSelectField={selectField}
            onRemoveField={removeField}
          />

          <InspectorPlaceholder schema={schema} selectedFieldId={selectedFieldId} />
        </div>

        <DragOverlay>
          {activeDrag ? (
            <div className="vxf-drag-overlay">
              <span className="vxf-drag-overlay__grip">&#8942;&#8942;</span>
              <span className="vxf-drag-overlay__label">
                {activeDrag.source === 'palette'
                  ? activeDrag.definition.label
                  : activeDrag.field.label}
              </span>
              <span className="vxf-drag-overlay__type">
                {activeDrag.source === 'palette'
                  ? activeDrag.definition.type
                  : activeDrag.field.type}
              </span>
            </div>
          ) : null}
        </DragOverlay>
      </DndContext>
    </div>
  );
}
