<?php

declare(strict_types=1);

namespace VanturaxForms\Fields\Definitions;

use VanturaxForms\Fields\AbstractFieldDefinition;

if (!defined('ABSPATH')) {
    exit;
}

final class SelectFieldDefinition extends AbstractFieldDefinition
{
    public function getType(): string
    {
        return 'select';
    }

    public function getLabel(): string
    {
        return __('Dropdown Select', 'vanturax-forms');
    }

    public function getCategory(): string
    {
        return 'choice';
    }

    public function getDefaultConfig(): array
    {
        $config            = parent::getDefaultConfig();
        $config['options'] = [
            ['label' => 'Option 1', 'value' => 'option_1'],
            ['label' => 'Option 2', 'value' => 'option_2'],
        ];

        return $config;
    }

    public function sanitizeSchemaConfig(array $rawField): array
    {
        $clean   = parent::sanitizeSchemaConfig($rawField);
        $options = [];

        if (is_array($rawField['options'] ?? null)) {
            foreach ($rawField['options'] as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $label = sanitize_text_field((string) ($option['label'] ?? ''));
                $value = sanitize_text_field((string) ($option['value'] ?? $label));

                if ($label === '' && $value === '') {
                    continue;
                }

                $options[] = [
                    'label' => $label !== '' ? $label : $value,
                    'value' => $value !== '' ? $value : sanitize_key($label),
                ];
            }
        }

        $clean['options'] = $options;

        return $clean;
    }
}
