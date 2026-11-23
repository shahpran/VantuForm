import { useEffect, useState } from 'react';
import type { CreateFormInput, FormEntity, FormStatus } from '../api/formsApi';

interface CreateFormModalProps {
  isOpen: boolean;
  isSaving: boolean;
  editingForm: FormEntity | null;
  conflictMessage: string | null;
  onReloadLatest: () => Promise<void>;
  onClose: () => void;
  onSave: (payload: CreateFormInput) => Promise<boolean>;
}

export function CreateFormModal({
  isOpen,
  isSaving,
  editingForm,
  conflictMessage,
  onReloadLatest,
  onClose,
  onSave,
}: CreateFormModalProps) {
  const [title, setTitle] = useState('');
  const [slug, setSlug] = useState('');
  const [status, setStatus] = useState<FormStatus>('publish');
  const [confirmationMessage, setConfirmationMessage] = useState(
    'Thank you! Your submission has been received.'
  );
  const [localError, setLocalError] = useState<string | null>(null);

  const existingConfirmation = editingForm?.settings?.confirmation;
  const isRedirectConfirmation = existingConfirmation?.type === 'redirect';

  useEffect(() => {
    if (!isOpen) {
      return;
    }

    setLocalError(null);
    if (editingForm) {
      setTitle(editingForm.title);
      setSlug(editingForm.slug);
      setStatus(editingForm.status);
      setConfirmationMessage(
        editingForm.settings?.confirmation?.message ||
          'Thank you! Your submission has been received.'
      );
    } else {
      setTitle('');
      setSlug('');
      setStatus('publish');
      setConfirmationMessage('Thank you! Your submission has been received.');
    }
  }, [isOpen, editingForm]);

  if (!isOpen) {
    return null;
  }

  const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const trimmedTitle = title.trim();
    if (!trimmedTitle) {
      setLocalError('Please enter a form title.');
      return;
    }

    setLocalError(null);

    // Preserve existing redirect confirmation type & redirect_url untouched
    // when editing a form that uses redirect confirmation.
    const nextConfirmation = isRedirectConfirmation
      ? {
          ...existingConfirmation,
          type: 'redirect' as const,
          redirect_url: existingConfirmation?.redirect_url || '',
        }
      : {
          ...(existingConfirmation ?? {}),
          type: 'message' as const,
          message: confirmationMessage.trim() || 'Thank you! Your submission has been received.',
          redirect_url: existingConfirmation?.redirect_url ?? '',
        };

    const payload: CreateFormInput = {
      title: trimmedTitle,
      slug: slug.trim() || undefined,
      status,
      settings: {
        ...(editingForm?.settings ?? {}),
        confirmation: nextConfirmation,
      },
    };

    await onSave(payload);
  };

  return (
    <div className="vxf-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="vxf-modal-title">
      <div className="vxf-modal">
        <div className="vxf-modal__header">
          <h2 id="vxf-modal-title" className="vxf-modal__title">
            {editingForm ? `Edit Form: ${editingForm.title}` : 'Create New Form'}
          </h2>
          <button
            type="button"
            className="vxf-modal__close"
            onClick={onClose}
            disabled={isSaving}
            aria-label="Close modal"
          >
            &times;
          </button>
        </div>

        <form onSubmit={handleSubmit}>
          <div className="vxf-modal__body">
            {conflictMessage && (
              <div className="vxf-alert vxf-alert--error">
                <div>
                  <strong>Save Conflict:</strong> {conflictMessage}
                </div>
                <button
                  type="button"
                  className="button button-small"
                  disabled={isSaving}
                  onClick={() => void onReloadLatest()}
                >
                  Reload Latest
                </button>
              </div>
            )}

            {localError && <div className="vxf-alert vxf-alert--error">{localError}</div>}

            <div className="vxf-field-group">
              <label className="vxf-label" htmlFor="vxf-form-title">
                Form Title <span className="vxf-required">*</span>
              </label>
              <input
                id="vxf-form-title"
                type="text"
                className="vxf-input"
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                placeholder="e.g., Agency Strategy Consultation"
                disabled={isSaving}
                autoFocus
              />
            </div>

            <div className="vxf-field-row">
              <div className="vxf-field-group">
                <label className="vxf-label" htmlFor="vxf-form-slug">
                  Slug (Optional)
                </label>
                <input
                  id="vxf-form-slug"
                  type="text"
                  className="vxf-input"
                  value={slug}
                  onChange={(e) => setSlug(e.target.value)}
                  placeholder="auto-generated-from-title"
                  disabled={isSaving}
                />
              </div>

              <div className="vxf-field-group">
                <label className="vxf-label" htmlFor="vxf-form-status">
                  Status
                </label>
                <select
                  id="vxf-form-status"
                  className="vxf-select"
                  value={status}
                  onChange={(e) => setStatus(e.target.value as FormStatus)}
                  disabled={isSaving}
                >
                  <option value="publish">Published</option>
                  <option value="draft">Draft</option>
                  {editingForm?.status === 'trash' && <option value="trash">Trash</option>}
                </select>
              </div>
            </div>

            {isRedirectConfirmation ? (
              <div className="vxf-field-group">
                <div className="vxf-meta-bar">
                  <span>
                    <strong>Redirect Confirmation Active:</strong>{' '}
                    <code>{existingConfirmation?.redirect_url}</code> (preserved automatically)
                  </span>
                </div>
              </div>
            ) : (
              <div className="vxf-field-group">
                <label className="vxf-label" htmlFor="vxf-form-confirmation">
                  Default Thank-You Message
                </label>
                <textarea
                  id="vxf-form-confirmation"
                  className="vxf-textarea"
                  rows={3}
                  value={confirmationMessage}
                  onChange={(e) => setConfirmationMessage(e.target.value)}
                  disabled={isSaving}
                />
              </div>
            )}

            {editingForm && (
              <div className="vxf-meta-bar">
                <span>Schema Version: v{editingForm.schema_version}</span>
                <span>Revision: #{editingForm.revision}</span>
                <span>Visual Builder: Stage 3</span>
              </div>
            )}
          </div>

          <div className="vxf-modal__footer">
            <button
              type="button"
              className="button"
              onClick={onClose}
              disabled={isSaving}
            >
              Cancel
            </button>
            <button
              type="submit"
              className="button button-primary"
              disabled={isSaving}
            >
              {isSaving ? 'Saving…' : editingForm ? 'Save Changes' : 'Create Form'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
