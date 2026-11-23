<?php

declare(strict_types=1);

namespace VanturaxForms\Fields;

use VanturaxForms\Fields\Definitions\EmailFieldDefinition;
use VanturaxForms\Fields\Definitions\SelectFieldDefinition;
use VanturaxForms\Fields\Definitions\TextareaFieldDefinition;
use VanturaxForms\Fields\Definitions\TextFieldDefinition;

if (!defined('ABSPATH')) {
    exit;
}

final class FieldRegistry
{
    /** @var array<string, FieldDefinitionInterface> */
    private array $definitions = [];
    private bool $initialized = false;

    /**
     * Register built-in core fields and allow extensions via WordPress action hook.
     */
    public function init(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->initialized = true;

        $this->register(new TextFieldDefinition());
        $this->register(new EmailFieldDefinition());
        $this->register(new TextareaFieldDefinition());
        $this->register(new SelectFieldDefinition());

        do_action('vanturax_forms_register_fields', $this);
    }

    /**
     * Register a field definition in the registry.
     */
    public function register(FieldDefinitionInterface $definition): void
    {
        $type = sanitize_key($definition->getType());
        if ($type === '') {
            throw new \InvalidArgumentException('Field definition type cannot be empty.');
        }

        $this->definitions[$type] = $definition;
    }

    public function has(string $type): bool
    {
        return isset($this->definitions[$type]);
    }

    public function get(string $type): ?FieldDefinitionInterface
    {
        return $this->definitions[$type] ?? null;
    }

    /**
     * @return array<string, FieldDefinitionInterface>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * Export serialized field metadata for the Admin React application.
     *
     * @return array<int, array<string, mixed>>
     */
    public function toClientArray(): array
    {
        $items = [];

        foreach ($this->definitions as $definition) {
            $items[] = [
                'type'          => $definition->getType(),
                'label'         => $definition->getLabel(),
                'category'      => $definition->getCategory(),
                'isPro'         => $definition->isPro(),
                'defaultConfig' => $definition->getDefaultConfig(),
            ];
        }

        return $items;
    }
}
