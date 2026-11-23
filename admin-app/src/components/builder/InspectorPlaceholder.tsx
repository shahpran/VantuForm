import type { FormFieldSchema, FormSchemaData } from '../../types/schema';

interface InspectorPlaceholderProps {
  schema: FormSchemaData;
  selectedFieldId: string | null;
}

function findSelectedField(
  schema: FormSchemaData,
  fieldId: string | null
): { field: FormFieldSchema; stepTitle: string } | null {
  if (!fieldId) {
    return null;
  }

  for (const step of schema.steps) {
    const match = step.fields.find((f) => f.id === fieldId);
    if (match) {
      return { field: match, stepTitle: step.title };
    }
  }

  return null;
}

export function InspectorPlaceholder({ schema, selectedFieldId }: InspectorPlaceholderProps) {
  const selected = findSelectedField(schema, selectedFieldId);

  return (
    <aside className="vxf-builder-sidebar vxf-builder-sidebar--right" aria-label="Field inspector">
      <div className="vxf-builder-sidebar__header">
        <h2 className="vxf-builder-sidebar__title">Inspector</h2>
        <p className="vxf-builder-sidebar__hint">Field &amp; structure inspection</p>
      </div>

      <div className="vxf-builder-sidebar__content">
        {selected ? (
          <div className="vxf-inspector-panel">
            <div className="vxf-inspector-section">
              <div className="vxf-inspector-section__title">Selected Field</div>
              <dl className="vxf-inspector-dl">
                <dt>Label</dt>
                <dd>{selected.field.label}</dd>
                <dt>Type</dt>
                <dd>
                  <code>{selected.field.type}</code>
                </dd>
                <dt>Field Key</dt>
                <dd>
                  <code>{selected.field.key}</code>
                </dd>
                <dt>Instance ID</dt>
                <dd>
                  <code>{selected.field.id}</code>
                </dd>
                <dt>Container</dt>
                <dd>{selected.stepTitle}</dd>
              </dl>
            </div>

            <div className="vxf-inspector-placeholder-box">
              <strong>Field Settings (Stage 4)</strong>
              <p>
                Label editing, placeholder, required toggle, validation rules, and conditional
                logic controls will attach to this inspector panel in Stage 4.
              </p>
            </div>
          </div>
        ) : (
          <div className="vxf-inspector-placeholder-box">
            <strong>No Field Selected</strong>
            <p>
              Click any field on the canvas to inspect its schema identifier, or drag fields from
              the left sidebar to arrange your form layout.
            </p>
          </div>
        )}
      </div>
    </aside>
  );
}
