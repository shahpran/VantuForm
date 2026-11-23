<?php

declare(strict_types=1);

namespace VanturaxForms\Fields;

if (!defined('ABSPATH')) {
    exit;
}

interface FieldDefinitionInterface
{
    /**
     * Unique machine slug for this field type (e.g., 'text', 'email').
     */
    public function getType(): string;

    /**
     * Human-readable label shown in the Admin UI.
     */
    public function getLabel(): string;

    /**
     * Logical grouping category (e.g., 'basic', 'choice', 'advanced').
     */
    public function getCategory(): string;

    /**
     * Whether this field requires a Pro license entitlement.
     */
    public function isPro(): bool;

    /**
     * Default schema properties when a new instance of this field is created.
     *
     * @return array<string, mixed>
     */
    public function getDefaultConfig(): array;

    /**
     * Sanitize and normalize raw field configuration stored in schema_json.
     *
     * @param array<string, mixed> $rawField
     * @return array<string, mixed>
     */
    public function sanitizeSchemaConfig(array $rawField): array;
}
