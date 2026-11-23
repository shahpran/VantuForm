<?php

declare(strict_types=1);

namespace VanturaxForms\Fields;

if (!defined('ABSPATH')) {
    exit;
}

abstract class AbstractFieldDefinition implements FieldDefinitionInterface
{
    public function getCategory(): string
    {
        return 'basic';
    }

    public function isPro(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDefaultConfig(): array
    {
        return [
            'type'        => $this->getType(),
            'label'       => $this->getLabel(),
            'description' => '',
            'placeholder' => '',
            'default'     => '',
            'required'    => false,
            'width'       => '100',
            'validation'  => [],
            'conditions'  => null,
        ];
    }

    /**
     * Sanitize common field schema properties shared across all field types.
     *
     * @param array<string, mixed> $rawField
     * @return array<string, mixed>
     */
    public function sanitizeSchemaConfig(array $rawField): array
    {
        $allowedWidths = ['25', '33', '50', '66', '75', '100'];
        $width         = isset($rawField['width']) ? (string) $rawField['width'] : '100';
        if (!in_array($width, $allowedWidths, true)) {
            $width = '100';
        }

        return [
            'id'          => sanitize_key((string) ($rawField['id'] ?? '')),
            'key'         => sanitize_key((string) ($rawField['key'] ?? '')),
            'type'        => $this->getType(),
            'label'       => sanitize_text_field((string) ($rawField['label'] ?? $this->getLabel())),
            'description' => sanitize_text_field((string) ($rawField['description'] ?? '')),
            'placeholder' => sanitize_text_field((string) ($rawField['placeholder'] ?? '')),
            'default'     => sanitize_text_field((string) ($rawField['default'] ?? '')),
            'required'    => !empty($rawField['required']),
            'width'       => $width,
            'validation'  => $this->sanitizeValidationRules(
                is_array($rawField['validation'] ?? null) ? $rawField['validation'] : []
            ),
            'conditions'  => $this->sanitizeConditions(
                is_array($rawField['conditions'] ?? null) ? $rawField['conditions'] : null
            ),
        ];
    }

    /**
     * @param array<string, mixed> $rules
     * @return array<string, mixed>
     */
    protected function sanitizeValidationRules(array $rules): array
    {
        $clean = [];

        if (isset($rules['min_length']) && is_numeric($rules['min_length'])) {
            $clean['min_length'] = max(0, (int) $rules['min_length']);
        }

        if (isset($rules['max_length']) && is_numeric($rules['max_length'])) {
            $clean['max_length'] = max(1, (int) $rules['max_length']);
        }

        return $clean;
    }

    /**
     * Sanitize conditional logic rule structure for forward compatibility.
     *
     * @param array<string, mixed>|null $conditions
     * @return array<string, mixed>|null
     */
    protected function sanitizeConditions(?array $conditions): ?array
    {
        if ($conditions === null || empty($conditions['rules']) || !is_array($conditions['rules'])) {
            return null;
        }

        $action = ($conditions['action'] ?? 'show') === 'hide' ? 'hide' : 'show';
        $match  = ($conditions['match'] ?? 'all') === 'any' ? 'any' : 'all';

        $allowedOperators = [
            'equals',
            'not_equals',
            'contains',
            'not_contains',
            'greater_than',
            'less_than',
            'is_empty',
            'is_not_empty',
        ];

        $sanitizedRules = [];
        foreach ($conditions['rules'] as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $fieldKey = sanitize_key((string) ($rule['field'] ?? ''));
            $operator = (string) ($rule['operator'] ?? 'equals');

            if ($fieldKey === '' || !in_array($operator, $allowedOperators, true)) {
                continue;
            }

            $sanitizedRules[] = [
                'field'    => $fieldKey,
                'operator' => $operator,
                'value'    => sanitize_text_field((string) ($rule['value'] ?? '')),
            ];
        }

        if (empty($sanitizedRules)) {
            return null;
        }

        return [
            'action' => $action,
            'match'  => $match,
            'rules'  => $sanitizedRules,
        ];
    }
}
