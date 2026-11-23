import { useEffect, useState } from 'react';
import type { FormStatus } from './api/formsApi';
import { FormBuilderView } from './components/builder/FormBuilderView';
import { CreateFormModal } from './components/CreateFormModal';
import { DeleteConfirmModal } from './components/DeleteConfirmModal';
import { useBuilderStore } from './store/useBuilderStore';
import { useFormsStore } from './store/useFormsStore';
import type { VxfAdminBootstrapData } from './types/globals';

interface AppProps {
  bootstrap: VxfAdminBootstrapData;
}

function formatDate(utcDateString: string): string {
  if (!utcDateString) {
    return '—';
  }
  const normalized = utcDateString.replace(' ', 'T') + 'Z';
  const parsed = new Date(normalized);
  if (Number.isNaN(parsed.getTime())) {
    return utcDateString;
  }
  return parsed.toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });
}

export function App({ bootstrap }: AppProps) {
  const {
    forms,
    total,
    statusFilter,
    searchQuery,
    isLoading,
    isSaving,
    errorMessage,
    noticeMessage,
    conflictMessage,
    isFormModalOpen,
    editingForm,
    deleteTarget,
    fetchForms,
    setStatusFilter,
    setSearchQuery,
    openCreateModal,
    openEditModal,
    reloadEditingForm,
    closeFormModal,
    saveForm,
    duplicateForm,
    restoreForm,
    requestTrashForm,
    requestPermanentDeleteForm,
    cancelDeleteForm,
    confirmDeleteForm,
    clearMessages,
  } = useFormsStore();

  const {
    activeForm: builderForm,
    isLoading: isBuilderLoading,
    openBuilder,
    closeBuilder,
  } = useBuilderStore();

  const [localSearch, setLocalSearch] = useState(searchQuery);

  useEffect(() => {
    void fetchForms();
  }, [fetchForms]);

  const handleOpenBuilder = async (formId: number) => {
    clearMessages();
    await openBuilder(formId);
  };

  const handleBackFromBuilder = () => {
    closeBuilder();
    void fetchForms();
  };

  if (builderForm || isBuilderLoading) {
    return (
      <FormBuilderView
        fieldDefinitions={bootstrap.fieldDefinitions || []}
        onBackToForms={handleBackFromBuilder}
      />
    );
  }

  const handleSearchSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    setSearchQuery(localSearch.trim());
  };

  const publishedCount = forms.filter((f) => f.status === 'publish').length;

  return (
    <div className="vxf-dashboard">
      <header className="vxf-dashboard__header">
        <div>
          <div className="vxf-dashboard__header-left">
            <h1 className="vxf-dashboard__title">{bootstrap.pluginName}</h1>
            <span className="vxf-dashboard__badge">v{bootstrap.version}</span>
          </div>
          <p className="vxf-dashboard__subtitle">
            Form Management Foundation &bull; Schema v{bootstrap.dbVersion}
          </p>
        </div>

        {bootstrap.user.canManage && (
          <button
            type="button"
            className="button button-primary button-hero"
            onClick={openCreateModal}
          >
            + Create New Form
          </button>
        )}
      </header>

      <div className="vxf-dashboard__body">
        {noticeMessage && (
          <div className="vxf-alert vxf-alert--success">
            <span>{noticeMessage}</span>
            <button type="button" className="button-link" onClick={clearMessages}>
              Dismiss
            </button>
          </div>
        )}

        {errorMessage && (
          <div className="vxf-alert vxf-alert--error">
            <span>{errorMessage}</span>
            <button type="button" className="button-link" onClick={clearMessages}>
              Dismiss
            </button>
          </div>
        )}

        <div className="vxf-overview-grid">
          <div className="vxf-status-card">
            <div className="vxf-status-card__label">Total Forms</div>
            <div className="vxf-status-card__value">{total}</div>
          </div>

          <div className="vxf-status-card">
            <div className="vxf-status-card__label">Published (Current View)</div>
            <div className="vxf-status-card__value">{publishedCount}</div>
          </div>

          <div className="vxf-status-card">
            <div className="vxf-status-card__label">Registered Field Types</div>
            <div className="vxf-status-card__value">
              {bootstrap.fieldDefinitions?.length ?? 0} Core Types
            </div>
          </div>

          <div className="vxf-status-card">
            <div className="vxf-status-card__label">Database Health</div>
            <div className="vxf-status-card__value">
              {bootstrap.dbStatus.is_healthy ? (
                <span className="vxf-pill vxf-pill--ok">Healthy (InnoDB)</span>
              ) : (
                <span className="vxf-pill vxf-pill--error">Check Required</span>
              )}
            </div>
          </div>
        </div>

        <div className="vxf-toolbar">
          <div className="vxf-toolbar__filters">
            <label htmlFor="vxf-status-filter" className="screen-reader-text">
              Filter by status
            </label>
            <select
              id="vxf-status-filter"
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value as '' | FormStatus)}
            >
              <option value="">All Active Forms</option>
              <option value="publish">Published</option>
              <option value="draft">Draft</option>
              <option value="trash">Trash</option>
            </select>

            <form onSubmit={handleSearchSubmit} style={{ display: 'flex', gap: '6px' }}>
              <input
                type="search"
                className="vxf-toolbar__search"
                placeholder="Search forms by title or slug…"
                value={localSearch}
                onChange={(e) => setLocalSearch(e.target.value)}
              />
              <button type="submit" className="button">
                Search
              </button>
            </form>
          </div>
        </div>

        {isLoading ? (
          <div className="vxf-empty-state">
            <p>Loading forms…</p>
          </div>
        ) : forms.length === 0 ? (
          <div className="vxf-empty-state">
            <h3>No forms found</h3>
            <p>
              {statusFilter === 'trash'
                ? 'Trash is currently empty.'
                : 'Create your first form to initialize its versioned schema configuration.'}
            </p>
            {bootstrap.user.canManage && statusFilter !== 'trash' && (
              <button type="button" className="button button-primary" onClick={openCreateModal}>
                + Create New Form
              </button>
            )}
          </div>
        ) : (
          <div className="vxf-table-wrap">
            <table className="vxf-table">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Form Title &amp; Slug</th>
                  <th>Status</th>
                  <th>Schema</th>
                  <th>Entries</th>
                  <th>Created</th>
                  <th>Updated</th>
                  <th style={{ textAlign: 'right' }}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {forms.map((form) => (
                  <tr key={form.id}>
                    <td>#{form.id}</td>
                    <td>
                      <span className="vxf-form-title">{form.title}</span>
                      <span className="vxf-form-slug">{form.slug}</span>
                    </td>
                    <td>
                      <span className={`vxf-pill vxf-pill--${form.status}`}>
                        {form.status === 'publish'
                          ? 'Published'
                          : form.status === 'draft'
                            ? 'Draft'
                            : 'Trash'}
                      </span>
                    </td>
                    <td>
                      v{form.schema_version} (r{form.revision})
                    </td>
                    <td>{form.entry_count}</td>
                    <td>{formatDate(form.created_at)}</td>
                    <td>{formatDate(form.updated_at)}</td>
                    <td>
                      {bootstrap.user.canManage && (
                        <div className="vxf-actions">
                          {form.status === 'trash' ? (
                            <>
                              <button
                                type="button"
                                className="button button-small"
                                disabled={isSaving}
                                onClick={() => void restoreForm(form.id)}
                              >
                                Restore
                              </button>
                              <button
                                type="button"
                                className="button button-small vxf-btn-danger"
                                disabled={isSaving}
                                onClick={() => requestPermanentDeleteForm(form)}
                              >
                                Delete Permanently
                              </button>
                            </>
                          ) : (
                            <>
                              <button
                                type="button"
                                className="button button-primary button-small"
                                disabled={isSaving}
                                onClick={() => void handleOpenBuilder(form.id)}
                              >
                                Build
                              </button>
                              <button
                                type="button"
                                className="button button-small"
                                disabled={isSaving}
                                onClick={() => void openEditModal(form.id)}
                              >
                                Settings
                              </button>
                              <button
                                type="button"
                                className="button button-small"
                                disabled={isSaving}
                                onClick={() => void duplicateForm(form.id)}
                              >
                                Duplicate
                              </button>
                              <button
                                type="button"
                                className="button button-small"
                                disabled={isSaving}
                                onClick={() => requestTrashForm(form)}
                              >
                                Trash
                              </button>
                            </>
                          )}
                        </div>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <CreateFormModal
        isOpen={isFormModalOpen}
        isSaving={isSaving}
        editingForm={editingForm}
        conflictMessage={conflictMessage}
        onReloadLatest={reloadEditingForm}
        onClose={closeFormModal}
        onSave={saveForm}
      />

      <DeleteConfirmModal
        target={deleteTarget}
        isDeleting={isSaving}
        onCancel={cancelDeleteForm}
        onConfirm={confirmDeleteForm}
      />
    </div>
  );
}
