import { useEffect, useState } from 'react';
import type { DeleteTarget } from '../store/useFormsStore';

interface DeleteConfirmModalProps {
  target: DeleteTarget | null;
  isDeleting: boolean;
  onCancel: () => void;
  onConfirm: (confirmPhrase?: string) => Promise<void>;
}

export function DeleteConfirmModal({
  target,
  isDeleting,
  onCancel,
  onConfirm,
}: DeleteConfirmModalProps) {
  const [confirmPhrase, setConfirmPhrase] = useState('');

  useEffect(() => {
    setConfirmPhrase('');
  }, [target]);

  if (!target) {
    return null;
  }

  const { form, mode } = target;
  const isPermanent = mode === 'permanent';
  const isConfirmed = !isPermanent || confirmPhrase.trim() === 'DELETE';

  return (
    <div className="vxf-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="vxf-delete-title">
      <div className="vxf-modal vxf-modal--sm">
        <div className="vxf-modal__header">
          <h2 id="vxf-delete-title" className="vxf-modal__title">
            {isPermanent ? 'Permanently Delete Form' : 'Move Form to Trash'}
          </h2>
          <button
            type="button"
            className="vxf-modal__close"
            onClick={onCancel}
            disabled={isDeleting}
            aria-label="Close modal"
          >
            &times;
          </button>
        </div>

        <div className="vxf-modal__body">
          {isPermanent ? (
            <>
              <p className="vxf-confirm-text">
                You are about to <strong>permanently delete</strong> <strong>{form.title}</strong>{' '}
                (ID #{form.id}), including its form configuration, <strong>{form.entry_count}</strong>{' '}
                submission(s), metadata, counters, and integration logs. This action cannot be undone.
              </p>

              <div className="vxf-field-group" style={{ marginTop: '12px' }}>
                <label className="vxf-label" htmlFor="vxf-confirm-delete-input">
                  Type <code>DELETE</code> to confirm permanent removal:
                </label>
                <input
                  id="vxf-confirm-delete-input"
                  type="text"
                  className="vxf-input"
                  value={confirmPhrase}
                  onChange={(e) => setConfirmPhrase(e.target.value)}
                  placeholder="DELETE"
                  disabled={isDeleting}
                  autoFocus
                />
              </div>
            </>
          ) : (
            <p className="vxf-confirm-text">
              Move <strong>{form.title}</strong> (ID #{form.id}) to Trash? All{' '}
              <strong>{form.entry_count}</strong> associated submission(s) and logs will remain
              completely preserved, and you can restore the form at any time.
            </p>
          )}
        </div>

        <div className="vxf-modal__footer">
          <button
            type="button"
            className="button"
            onClick={onCancel}
            disabled={isDeleting}
          >
            Cancel
          </button>
          <button
            type="button"
            className={isPermanent ? 'button vxf-btn-danger' : 'button button-primary'}
            onClick={() => void onConfirm(isPermanent ? confirmPhrase.trim() : undefined)}
            disabled={isDeleting || !isConfirmed}
          >
            {isDeleting
              ? isPermanent
                ? 'Deleting Permanently…'
                : 'Moving to Trash…'
              : isPermanent
                ? 'Delete Permanently'
                : 'Move to Trash'}
          </button>
        </div>
      </div>
    </div>
  );
}
