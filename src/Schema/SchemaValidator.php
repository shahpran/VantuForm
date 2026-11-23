<?php

declare(strict_types=1);

namespace VanturaxForms\Schema;

use VanturaxForms\Fields\FieldRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validates and sanitizes incoming form schemas and settings server-side.
 * Never trusts client-provided structure, schema versions, or field types.
 */
final class SchemaValidator
{
    private FieldRegistry $fieldRegistry;

    public function __construct(FieldRegistry $fieldRegistry)
    {
        $this->fieldRegistry = $fieldRegistry;
    }

    /**
     * Validate and sanitize a form schema array.
     *
     * @param mixed $rawSchema
     * @return array<string, mixed>
     * @throws \InvalidArgumentException When schema structure, version, or field keys are invalid.
     */
    public function validateAndSanitizeSchema(mixed $rawSchema): array
    {
        if (!is_array($rawSchema)) {
            throw new \InvalidArgumentException(__('Form schema must be a valid JSON object.', 'vanturax-forms'));
        }

        // Enforce explicit version allowlist and run explicit migration path if applicable.
        $migratedSchema = FormSchema::migrateToCurrentVersion($rawSchema);
        $schemaVersion  = (string) $migratedSchema['schema_version'];

        if (!isset($migratedSchema['steps']) || !is_array($migratedSchema['steps']) || count($migratedSchema['steps']) === 0) {
            throw new \InvalidArgumentException(__('Form schema must contain at least one step.', 'vanturax-forms'));
        }

        $seenFieldKeys = [];
        $seenStepIds   = [];
        $cleanSteps    = [];

        foreach ($migratedSchema['steps'] as $index => $rawStep) {
            if (!is_array($rawStep)) {
                throw new \InvalidArgumentException(
                    sprintf(__('Step at index %d must be an object.', 'vanturax-forms'), $index)
                );
            }

            $stepId = sanitize_key((string) ($rawStep['id'] ?? 'step_' . ($index + 1)));
            if ($stepId === '' || isset($seenStepIds[$stepId])) {
                throw new \InvalidArgumentException(__('Each form step must have a unique non-empty ID.', 'vanturax-forms'));
            }
            $seenStepIds[$stepId] = true;

            $rawFields = $rawStep['fields'] ?? [];
            if (!is_array($rawFields)) {
                throw new \InvalidArgumentException(__('Step fields must be an array.', 'vanturax-forms'));
            }

            $cleanFields = [];
            foreach ($rawFields as $fieldIndex => $rawField) {
                if (!is_array($rawField)) {
                    throw new \InvalidArgumentException(__('Each field definition must be an object.', 'vanturax-forms'));
                }

                $fieldType  = sanitize_key((string) ($rawField['type'] ?? ''));
                $definition = $this->fieldRegistry->get($fieldType);
                if ($definition === null) {
                    throw new \InvalidArgumentException(
                        sprintf(__('Unsupported field type: %s', 'vanturax-forms'), esc_html($fieldType))
                    );
                }

                $fieldKey = sanitize_key((string) ($rawField['key'] ?? ''));
                if ($fieldKey === '' || strlen($fieldKey) > 64) {
                    throw new \InvalidArgumentException(
                        __('Each field must have a non-empty key of at most 64 characters.', 'vanturax-forms')
                    );
                }

                if (isset($seenFieldKeys[$fieldKey])) {
                    throw new \InvalidArgumentException(
                        sprintf(__('Duplicate field key detected: %s', 'vanturax-forms'), esc_html($fieldKey))
                    );
                }
                $seenFieldKeys[$fieldKey] = true;

                $sanitizedField          = $definition->sanitizeSchemaConfig($rawField);
                $sanitizedField['key']   = $fieldKey;
                $sanitizedField['order'] = $fieldIndex;

                if ($sanitizedField['id'] === '') {
                    $sanitizedField['id'] = 'fld_' . $fieldKey;
                }

                $cleanFields[] = $sanitizedField;
            }

            $cleanSteps[] = [
                'id'                 => $stepId,
                'title'              => sanitize_text_field((string) ($rawStep['title'] ?? 'Step ' . ($index + 1))),
                'next_button_text'   => sanitize_text_field((string) ($rawStep['next_button_text'] ?? 'Next')),
                'submit_button_text' => sanitize_text_field((string) ($rawStep['submit_button_text'] ?? 'Submit')),
                'fields'             => $cleanFields,
            ];
        }

        $multiStepRaw   = is_array($migratedSchema['multi_step'] ?? null) ? $migratedSchema['multi_step'] : [];
        $progressStyles = ['bar', 'steps', 'none'];
        $progressStyle  = (string) ($multiStepRaw['progress_style'] ?? 'bar');
        if (!in_array($progressStyle, $progressStyles, true)) {
            $progressStyle = 'bar';
        }

        $themeRaw       = is_array($migratedSchema['theme'] ?? null) ? $migratedSchema['theme'] : [];
        $primaryColor   = sanitize_hex_color((string) ($themeRaw['primary_color'] ?? '#4F46E5')) ?: '#4F46E5';
        $labelPositions = ['top', 'left', 'floating'];
        $labelPosition  = (string) ($themeRaw['label_position'] ?? 'top');
        if (!in_array($labelPosition, $labelPositions, true)) {
            $labelPosition = 'top';
        }

        return [
            'schema_version' => $schemaVersion,
            'multi_step'     => [
                'enabled'               => !empty($multiStepRaw['enabled']),
                'progress_style'        => $progressStyle,
                'show_step_titles'      => array_key_exists('show_step_titles', $multiStepRaw) ? (bool) $multiStepRaw['show_step_titles'] : true,
                'allow_back_navigation' => array_key_exists('allow_back_navigation', $multiStepRaw) ? (bool) $multiStepRaw['allow_back_navigation'] : true,
            ],
            'steps' => $cleanSteps,
            'theme' => [
                'primary_color'  => $primaryColor,
                'border_radius'  => sanitize_text_field((string) ($themeRaw['border_radius'] ?? '8px')),
                'input_padding'  => sanitize_text_field((string) ($themeRaw['input_padding'] ?? '12px 14px')),
                'label_position' => $labelPosition,
            ],
        ];
    }

    /**
     * Validate and sanitize form-level settings.
     *
     * @param mixed $rawSettings
     * @return array<string, mixed>
     * @throws \InvalidArgumentException When settings payload is malformed.
     */
    public function validateAndSanitizeSettings(mixed $rawSettings): array
    {
        if (!is_array($rawSettings)) {
            throw new \InvalidArgumentException(__('Form settings must be a valid JSON object.', 'vanturax-forms'));
        }

        $defaults        = FormSchema::defaultSettings();
        $confirmationRaw = is_array($rawSettings['confirmation'] ?? null) ? $rawSettings['confirmation'] : [];
        $confirmType     = ($confirmationRaw['type'] ?? 'message') === 'redirect' ? 'redirect' : 'message';
        $redirectUrl     = esc_url_raw((string) ($confirmationRaw['redirect_url'] ?? ''));

        if ($confirmType === 'redirect' && $redirectUrl === '') {
            throw new \InvalidArgumentException(__('A valid redirect URL is required when confirmation type is set to redirect.', 'vanturax-forms'));
        }

        $notifyRaw = is_array($rawSettings['notifications'] ?? null) ? $rawSettings['notifications'] : [];
        $spamRaw   = is_array($rawSettings['spam_protection'] ?? null) ? $rawSettings['spam_protection'] : [];

        return [
            'confirmation' => [
                'type'         => $confirmType,
                'message'      => wp_kses_post((string) ($confirmationRaw['message'] ?? $defaults['confirmation']['message'])),
                'redirect_url' => $redirectUrl,
            ],
            'notifications' => [
                'admin_enabled' => array_key_exists('admin_enabled', $notifyRaw) ? (bool) $notifyRaw['admin_enabled'] : true,
                'admin_to'      => sanitize_text_field((string) ($notifyRaw['admin_to'] ?? '{admin_email}')),
                'admin_subject' => sanitize_text_field((string) ($notifyRaw['admin_subject'] ?? $defaults['notifications']['admin_subject'])),
            ],
            'spam_protection' => [
                'honeypot'       => array_key_exists('honeypot', $spamRaw) ? (bool) $spamRaw['honeypot'] : true,
                'min_fill_ms'    => max(0, min(30000, (int) ($spamRaw['min_fill_ms'] ?? 1500))),
                'rate_limit_max' => max(1, min(100, (int) ($spamRaw['rate_limit_max'] ?? 5))),
            ],
        ];
    }
}
