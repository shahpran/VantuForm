<?php

declare(strict_types=1);

namespace VanturaxForms\Fields\Definitions;

use VanturaxForms\Fields\AbstractFieldDefinition;

if (!defined('ABSPATH')) {
    exit;
}

final class TextFieldDefinition extends AbstractFieldDefinition
{
    public function getType(): string
    {
        return 'text';
    }

    public function getLabel(): string
    {
        return __('Single Line Text', 'vanturax-forms');
    }
}
