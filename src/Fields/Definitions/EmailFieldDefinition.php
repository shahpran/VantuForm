<?php

declare(strict_types=1);

namespace VanturaxForms\Fields\Definitions;

use VanturaxForms\Fields\AbstractFieldDefinition;

if (!defined('ABSPATH')) {
    exit;
}

final class EmailFieldDefinition extends AbstractFieldDefinition
{
    public function getType(): string
    {
        return 'email';
    }

    public function getLabel(): string
    {
        return __('Email Address', 'vanturax-forms');
    }
}
