import { useDraggable } from '@dnd-kit/core';
import { useMemo } from 'react';
import type { VxfFieldDefinitionMeta } from '../../types/globals';

interface PaletteItemProps {
  definition: VxfFieldDefinitionMeta;
  onQuickAdd: (definition: VxfFieldDefinitionMeta) => void;
}

function PaletteFieldItem({ definition, onQuickAdd }: PaletteItemProps) {
  const { attributes, listeners, setNodeRef, isDragging } = useDraggable({
    id: `palette:${definition.type}`,
    data: {
      source: 'palette',
      definition,
    },
  });

  return (
    <div
      ref={setNodeRef}
      className={`vxf-palette-item ${isDragging ? 'vxf-palette-item--dragging' : ''}`}
    >
      <div
        className="vxf-palette-item__info"
        {...listeners}
        {...attributes}
        aria-label={`Drag ${definition.label} field to canvas`}
      >
        <span className="vxf-palette-item__grip" aria-hidden="true">
          &#8942;&#8942;
        </span>
        <div>
          <div className="vxf-palette-item__label">{definition.label}</div>
          <div className="vxf-palette-item__type">{definition.type}</div>
        </div>
      </div>

      <button
        type="button"
        className="button button-small vxf-palette-item__add"
        aria-label={`Add ${definition.label} field`}
        onClick={() => onQuickAdd(definition)}
      >
        + Add
      </button>
    </div>
  );
}

interface FieldPaletteProps {
  definitions: VxfFieldDefinitionMeta[];
  onQuickAdd: (definition: VxfFieldDefinitionMeta) => void;
}

const CATEGORY_LABELS: Record<string, string> = {
  basic: 'Basic Fields',
  choice: 'Choice Fields',
  advanced: 'Advanced Fields',
};

export function FieldPalette({ definitions, onQuickAdd }: FieldPaletteProps) {
  const grouped = useMemo(() => {
    const map = new Map<string, VxfFieldDefinitionMeta[]>();
    for (const def of definitions) {
      const cat = def.category || 'basic';
      const list = map.get(cat) ?? [];
      list.push(def);
      map.set(cat, list);
    }
    return Array.from(map.entries());
  }, [definitions]);

  return (
    <aside className="vxf-builder-sidebar vxf-builder-sidebar--left" aria-label="Form fields palette">
      <div className="vxf-builder-sidebar__header">
        <h2 className="vxf-builder-sidebar__title">Fields</h2>
        <p className="vxf-builder-sidebar__hint">Drag a field onto the canvas or click + Add.</p>
      </div>

      <div className="vxf-builder-sidebar__content">
        {grouped.length === 0 ? (
          <p className="vxf-builder-sidebar__empty">No field definitions registered.</p>
        ) : (
          grouped.map(([category, items]) => (
            <div key={category} className="vxf-palette-group">
              <div className="vxf-palette-group__title">
                {CATEGORY_LABELS[category] || category.toUpperCase()}
              </div>
              <div className="vxf-palette-group__list">
                {items.map((def) => (
                  <PaletteFieldItem key={def.type} definition={def} onQuickAdd={onQuickAdd} />
                ))}
              </div>
            </div>
          ))
        )}
      </div>
    </aside>
  );
}
