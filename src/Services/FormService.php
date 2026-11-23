<?php

declare(strict_types=1);

namespace VanturaxForms\Services;

use VanturaxForms\Database\FormRepository;
use VanturaxForms\Exceptions\FormConflictException;
use VanturaxForms\Schema\FormSchema;
use VanturaxForms\Schema\SchemaValidator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Business logic layer for Form Management.
 */
final class FormService
{
    private const ALLOWED_STATUSES             = ['publish', 'draft', 'trash'];
    public const PERMANENT_DELETE_CONFIRMATION = 'DELETE';

    private FormRepository $repository;
    private SchemaValidator $validator;

    public function __construct(FormRepository $repository, SchemaValidator $validator)
    {
        $this->repository = $repository;
        $this->validator  = $validator;
    }

    /**
     * @param array{status?: string, search?: string, page?: int, per_page?: int} $args
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function listForms(array $args = []): array
    {
        $result = $this->repository->paginate($args);
        $items  = array_map([$this, 'formatSummaryRow'], $result['items']);

        return [
            'items' => $items,
            'total' => $result['total'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getForm(int $id): ?array
    {
        $row = $this->repository->findById($id);
        if ($row === null) {
            return null;
        }

        return $this->formatFullRow($row);
    }

    /**
     * Create a new form with validated schema and settings.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createForm(array $payload, int $userId): array
    {
        $title = sanitize_text_field((string) ($payload['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException(__('Form title is required.', 'vanturax-forms'));
        }

        $status = $this->sanitizeStatus((string) ($payload['status'] ?? 'publish'));

        $rawSchema = array_key_exists('schema', $payload)
            ? $this->decodeJsonIfString($payload['schema'], 'schema')
            : FormSchema::defaultSchema();

        $incomingSettings = array_key_exists('settings', $payload)
            ? $this->decodeJsonIfString($payload['settings'], 'settings')
            : [];

        $mergedSettings = array_replace_recursive(FormSchema::defaultSettings(), $incomingSettings);

        $cleanSchema   = $this->validator->validateAndSanitizeSchema($rawSchema);
        $cleanSettings = $this->validator->validateAndSanitizeSettings($mergedSettings);

        $schemaJson   = wp_json_encode($cleanSchema);
        $settingsJson = wp_json_encode($cleanSettings);

        if (!is_string($schemaJson) || !is_string($settingsJson)) {
            throw new \RuntimeException(__('Failed to encode form configuration as JSON.', 'vanturax-forms'));
        }

        $desiredSlug = isset($payload['slug']) && trim((string) $payload['slug']) !== ''
            ? (string) $payload['slug']
            : $title;

        $newId = $this->repository->insertWithUniqueSlug([
            'title'          => $title,
            'desired_slug'   => $desiredSlug,
            'status'         => $status,
            'schema_version' => (string) $cleanSchema['schema_version'],
            'schema_json'    => $schemaJson,
            'settings_json'  => $settingsJson,
            'created_by'     => max(0, $userId),
        ]);

        do_action('vanturax_forms_form_created', $newId, $cleanSchema);

        $created = $this->getForm($newId);
        if ($created === null) {
            throw new \RuntimeException(__('Form was created but could not be retrieved.', 'vanturax-forms'));
        }

        return $created;
    }

    /**
     * Update an existing form using strict positive-integer revision verification
     * and recursive settings preservation.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null Returns null if form ID does not exist.
     * @throws \InvalidArgumentException When revision is missing, non-integer, or <= 0, or payload is invalid.
     * @throws FormConflictException When expected revision does not match current DB revision or slug collides.
     */
    public function updateForm(int $id, array $payload): ?array
    {
        if (!array_key_exists('revision', $payload)) {
            throw new \InvalidArgumentException(
                __('Expected form revision number is required to save changes.', 'vanturax-forms')
            );
        }

        $expectedRevision = $this->parsePositiveIntRevision($payload['revision']);

        $existing = $this->getForm($id);
        if ($existing === null) {
            return null;
        }

        if ((int) $existing['revision'] !== $expectedRevision) {
            throw new FormConflictException(
                __('This form was modified by another user since you opened it. Please reload the latest version before saving.', 'vanturax-forms')
            );
        }

        $title = array_key_exists('title', $payload)
            ? sanitize_text_field((string) $payload['title'])
            : (string) $existing['title'];

        if ($title === '') {
            throw new \InvalidArgumentException(__('Form title cannot be empty.', 'vanturax-forms'));
        }

        $status = array_key_exists('status', $payload)
            ? $this->sanitizeStatus((string) $payload['status'])
            : (string) $existing['status'];

        $rawSchema = array_key_exists('schema', $payload)
            ? $this->decodeJsonIfString($payload['schema'], 'schema')
            : $existing['schema'];

        $mergedSettings = $existing['settings'];
        if (array_key_exists('settings', $payload)) {
            $incomingSettings = $this->decodeJsonIfString($payload['settings'], 'settings');
            $mergedSettings   = array_replace_recursive($existing['settings'], $incomingSettings);
        }

        $cleanSchema   = $this->validator->validateAndSanitizeSchema($rawSchema);
        $cleanSettings = $this->validator->validateAndSanitizeSettings($mergedSettings);

        $schemaJson   = wp_json_encode($cleanSchema);
        $settingsJson = wp_json_encode($cleanSettings);

        if (!is_string($schemaJson) || !is_string($settingsJson)) {
            throw new \RuntimeException(__('Failed to encode updated form configuration as JSON.', 'vanturax-forms'));
        }

        $slug = (string) $existing['slug'];
        if (array_key_exists('slug', $payload) && trim((string) $payload['slug']) !== '') {
            $slug = $this->repository->generateUniqueSlug((string) $payload['slug'], $id);
        }

        $updated = $this->repository->updateWithOptimisticLock($id, $expectedRevision, [
            'title'          => $title,
            'slug'           => $slug,
            'status'         => $status,
            'schema_version' => (string) $cleanSchema['schema_version'],
            'schema_json'    => $schemaJson,
            'settings_json'  => $settingsJson,
        ]);

        if (!$updated) {
            return null;
        }

        do_action('vanturax_forms_form_updated', $id, $cleanSchema);

        return $this->getForm($id);
    }

    /**
     * Duplicate an existing form with a unique title and slug (created as 'draft').
     *
     * @return array<string, mixed>|null
     */
    public function duplicateForm(int $id, int $userId): ?array
    {
        $source = $this->getForm($id);
        if ($source === null) {
            return null;
        }

        $copyTitle = sprintf(
            /* translators: %s: Original form title */
            __('%s (Copy)', 'vanturax-forms'),
            (string) $source['title']
        );

        return $this->createForm(
            [
                'title'    => $copyTitle,
                'slug'     => (string) $source['slug'] . '-copy',
                'status'   => 'draft',
                'schema'   => $source['schema'],
                'settings' => $source['settings'],
            ],
            $userId
        );
    }

    /**
     * Safe deletion policy:
     * - Default ($force = false): Moves the form to 'trash' while preserving all
     *   submissions, submission_meta, integration_logs, and form_counters.
     * - Explicit permanent purge ($force = true): Requires exact confirmation phrase
     *   'DELETE' AND requires the form to already be in 'trash', then atomically
     *   removes the form and its associated records inside an InnoDB transaction.
     *
     * @return array{status: 'trashed'|'already_trashed'|'deleted'|'not_found'|'not_in_trash', id: int}
     * @throws \InvalidArgumentException When $force is true without exact 'DELETE' confirmation.
     */
    public function deleteForm(int $id, bool $force = false, string $confirmPhrase = ''): array
    {
        if (!$force) {
            $result = $this->repository->trash($id);
            if ($result === 'trashed') {
                do_action('vanturax_forms_form_trashed', $id);
            }

            return [
                'status' => $result,
                'id'     => $id,
            ];
        }

        if (!hash_equals(self::PERMANENT_DELETE_CONFIRMATION, $confirmPhrase)) {
            throw new \InvalidArgumentException(
                __('Permanent deletion requires exact confirmation phrase "DELETE".', 'vanturax-forms')
            );
        }

        $existing = $this->repository->findById($id);
        if ($existing === null) {
            return [
                'status' => 'not_found',
                'id'     => $id,
            ];
        }

        if (($existing['status'] ?? '') !== 'trash') {
            return [
                'status' => 'not_in_trash',
                'id'     => $id,
            ];
        }

        $purged = $this->repository->deletePermanentlyWithAssociatedData($id);
        if ($purged) {
            do_action('vanturax_forms_form_deleted_permanently', $id);
            return [
                'status' => 'deleted',
                'id'     => $id,
            ];
        }

        return [
            'status' => 'not_found',
            'id'     => $id,
        ];
    }

    /**
     * Restore a trashed form back to 'draft'.
     *
     * @return array{status: 'restored'|'not_trashed'|'not_found', form: array<string, mixed>|null}
     */
    public function restoreForm(int $id): array
    {
        $result = $this->repository->restore($id);
        if ($result === 'restored') {
            do_action('vanturax_forms_form_restored', $id);
        }

        return [
            'status' => $result,
            'form'   => $result === 'restored' ? $this->getForm($id) : null,
        ];
    }

    /**
     * Parse and validate a strictly positive integer revision (>= 1).
     */
    private function parsePositiveIntRevision(mixed $value): int
    {
        if (is_int($value)) {
            if ($value >= 1) {
                return $value;
            }
            throw new \InvalidArgumentException(
                __('Expected form revision must be a positive integer.', 'vanturax-forms')
            );
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT);
            if (is_int($parsed) && $parsed >= 1) {
                return $parsed;
            }
        }

        throw new \InvalidArgumentException(
            __('Expected form revision must be a positive integer.', 'vanturax-forms')
        );
    }

    private function sanitizeStatus(string $status): string
    {
        $clean = sanitize_key($status);
        if (!in_array($clean, self::ALLOWED_STATUSES, true)) {
            throw new \InvalidArgumentException(
                sprintf(__('Invalid form status: %s', 'vanturax-forms'), esc_html($status))
            );
        }

        return $clean;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonIfString(mixed $value, string $fieldName): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                throw new \InvalidArgumentException(
                    sprintf(__('Malformed JSON provided for %s.', 'vanturax-forms'), $fieldName)
                );
            }
            return $decoded;
        }

        throw new \InvalidArgumentException(
            sprintf(__('Invalid payload type for %s.', 'vanturax-forms'), $fieldName)
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatSummaryRow(array $row): array
    {
        return [
            'id'             => (int) $row['id'],
            'title'          => (string) $row['title'],
            'slug'           => (string) $row['slug'],
            'status'         => (string) $row['status'],
            'schema_version' => (string) $row['schema_version'],
            'revision'       => (int) $row['revision'],
            'entry_count'    => (int) ($row['entry_count'] ?? 0),
            'created_by'     => (int) $row['created_by'],
            'created_at'     => (string) $row['created_at'],
            'updated_at'     => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatFullRow(array $row): array
    {
        $decodedSchema   = json_decode((string) $row['schema_json'], true);
        $decodedSettings = json_decode((string) $row['settings_json'], true);

        return [
            'id'             => (int) $row['id'],
            'title'          => (string) $row['title'],
            'slug'           => (string) $row['slug'],
            'status'         => (string) $row['status'],
            'schema_version' => (string) $row['schema_version'],
            'revision'       => (int) $row['revision'],
            'schema'         => is_array($decodedSchema) ? $decodedSchema : FormSchema::defaultSchema(),
            'settings'       => is_array($decodedSettings) ? $decodedSettings : FormSchema::defaultSettings(),
            'created_by'     => (int) $row['created_by'],
            'created_at'     => (string) $row['created_at'],
            'updated_at'     => (string) $row['updated_at'],
        ];
    }
}
