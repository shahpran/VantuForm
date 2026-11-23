import { useDroppable } from '@dnd-kit/core';
import { SortableContext, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import type { FormFieldSchema, FormSchemaData, FormStepSchema } from '../../types/schema';

interface SortableFieldCardProps {
  field: FormFieldSchema;
  stepId: string;
  isSelected: boolean;
  onSelect: (fieldId: string) => void;
  onRemove: (stepId: string, fieldId: string) => void;
}

function SortableFieldCard({
  field,
  stepId,
  isSelected,
  onSelect,
  onRemove,
}: SortableFieldCardProps) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: field.id,
    data: {
      source: 'canvas',
      stepId,
      field,
    },
  });

  const style: React.CSSProperties = {
    transform: CSS.Transform.toString(transform),
    transition,
  };

  return (
    <div
      ref={setNodeRef}
      style={style}
      className={[
        'vxf-canvas-field',
        isSelected ? 'vxf-canvas-field--selected' : '',
        isDragging ? 'vxf-canvas-field--dragging' : '',
      ]
        .filter(Boolean)
        .join(' ')}
      onClick={() => onSelect(field.id)}
    >
      <div className="vxf-canvas-field__left">
        <button
          type="button"
          className="vxf-canvas-field__handle"
          aria-label={`Reorder ${field.label}`}
          {...attributes}
          {...listeners}
          onClick={(e) => e.stopPropagation()}
        >
          &#8942;&#8942;
        </button>

        <div className="vxf-canvas-field__meta">
          <div className="vxf-canvas-field__label-row">
            <span className="vxf-canvas-field__label">{field.label}</span>
            {field.required && <span className="vxf-canvas-field__req">*</span>}
            <span className="vxf-canvas-field__badge">{field.type}</span>
          </div>
          <div className="vxf-canvas-field__sub">
            Key: <code>{field.key}</code> &bull; Width: {field.width}%
          </div>
        </div>
      </div>

      <div className="vxf-canvas-field__actions">
        <button
          type="button"
          className="button button-small vxf-canvas-field__remove"
          aria-label={`Remove ${field.label}`}
          onClick={(e) => {
            e.stopPropagation();
            onRemove(stepId, field.id);
          }}
        >
          Remove
        </button>
      </div>
    </div>
  );
}

interface StepDropZoneProps {
  step: FormStepSchema;
  stepIndex: number;
  totalSteps: number;
  selectedFieldId: string | null;
  activeDropStepId: string | null;
  onSelectField: (fieldId: string) => void;
  onRemoveField: (stepId: string, fieldId: string) => void;
}

function StepDropZone({
  step,
  stepIndex,
  totalSteps,
  selectedFieldId,
  activeDropStepId,
  onSelectField,
  onRemoveField,
}: StepDropZoneProps) {
  const { setNodeRef, isOver } = useDroppable({
    id: `step:${step.id}`,
    data: {
      source: 'step',
      stepId: step.id,
    },
  });

  const isHighlighted = isOver || activeDropStepId === step.id;
  const fieldIds = step.fields.map((f) => f.id);

  return (
    <section
      ref={setNodeRef}
      className={`vxf-step-card ${isHighlighted ? 'vxf-step-card--drop-active' : ''}`}
      aria-label={step.title}
    >
      <div className="vxf-step-card__header">
        <div>
          <span className="vxf-step-card__title">{step.title}</span>
          {totalSteps > 1 && (
            <span className="vxf-step-card__count">
              Step {stepIndex + 1} of {totalSteps}
            </span>
          )}
        </div>
        <span className="vxf-step-card__field-count">
          {step.fields.length} {step.fields.length === 1 ? 'field' : 'fields'}
        </span>
      </div>

      <div className="vxf-step-card__body">
        <SortableContext items={fieldIds} strategy={verticalListSortingStrategy}>
          {step.fields.length === 0 ? (
            <div className="vxf-canvas-empty">
              <p className="vxf-canvas-empty__title">Drop fields here</p>
              <p className="vxf-canvas-empty__desc">
                Drag any field component from the left sidebar into this area to start building your
                form.
              </p>
            </div>
          ) : (
            <div className="vxf-canvas-fields-list">
              {step.fields.map((field) => (
                <SortableFieldCard
                  key={field.id}
                  field={field}
                  stepId={step.id}
                  isSelected={selectedFieldId === field.id}
                  onSelect={onSelectField}
                  onRemove={onRemoveField}
                />
              ))}
            </div>
          )}
        </SortableContext>
      </div>
    </section>
  );
}

interface BuilderCanvasProps {
  formTitle: string;
  formSlug: string;
  schema: FormSchemaData;
  selectedFieldId: string | null;
  activeDropStepId: string | null;
  onSelectField: (fieldId: string | null) => void;
  onRemoveField: (stepId: string, fieldId: string) => void;
}

export function BuilderCanvas({
  formTitle,
  formSlug,
  schema,
  selectedFieldId,
  activeDropStepId,
  onSelectField,
  onRemoveField,
}: BuilderCanvasProps) {
  return (
    <main className="vxf-builder-canvas" onClick={() => onSelectField(null)}>
      <div className="vxf-builder-canvas__inner" onClick={(e) => e.stopPropagation()}>
        <div className="vxf-canvas-form-header">
          <h2 className="vxf-canvas-form-header__title">{formTitle}</h2>
          <span className="vxf-canvas-form-header__slug">Slug: {formSlug}</span>
        </div>

        {schema.steps.map((step, index) => (
          <StepDropZone
            key={step.id}
            step={step}
            stepIndex={index}
            totalSteps={schema.steps.length}
            selectedFieldId={selectedFieldId}
            activeDropStepId={activeDropStepId}
            onSelectField={(id) => onSelectField(id)}
            onRemoveField={onRemoveField}
          />
        ))}
      </div>
    </main>
  );
}
