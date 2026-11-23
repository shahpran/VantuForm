<?php

declare(strict_types=1);

namespace VanturaxForms\Fields\Definitions;

use VanturaxForms\Fields\AbstractFieldDefinition;

if (!defined('ABSPATH')) {
    exit;
}

final class TextareaFieldDefinition extends AbstractFieldDefinition
{
    public function getType(): string
    {
        return 'textarea';
    }

    public function getLabel(): string
    {
        return __('Paragraph Text', 'vanturax-forms');
    }

    public function getDefaultConfig(): array
    {
        $config         = parent::getDefaultConfig();
        $config['rows'] = 4;

        return $config;
    }

    public function sanitizeSchemaConfig(array $rawField): array
    {
        $clean         = parent::sanitizeSchemaConfig($rawField);
        $rows          = isset($rawField['rows']) && is_numeric($rawField['rows']) ? (int) $rawField['rows'] : 4;
        $clean['rows'] = max(2, min(20, $rows));

        return $clean;
    }
}
