<?php

declare(strict_types=1);

namespace VanturaxForms\Schema;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Defines the canonical versioned Form Schema (v1.0.0), supported version registry,
 * explicit migration pipeline, and default settings.
 */
final class FormSchema
{
    public const CURRENT_VERSION = '1.0.0';

    /**
     * Explicit list of schema versions supported by this plugin release.
     *
     * @var string[]
     */
    public const SUPPORTED_VERSIONS = [
        self::CURRENT_VERSION,
    ];

    /**
     * Check whether a given schema version string is explicitly supported.
     */
    public static function isSupportedVersion(string $version): bool
    {
        return in_array($version, self::SUPPORTED_VERSIONS, true);
    }

    /**
     * Explicit schema migration pipeline for supported schema versions.
     * Requires an explicit, non-empty string schema_version and rejects missing,
     * empty, malformed, unsupported, or future versions.
     *
     * @param array<string, mixed> $rawSchema
     * @return array<string, mixed>
     * @throws \InvalidArgumentException When schema_version is missing, empty, or unsupported.
     */
    public static function migrateToCurrentVersion(array $rawSchema): array
    {
        if (!array_key_exists('schema_version', $rawSchema) || !is_string($rawSchema['schema_version'])) {
            throw new \InvalidArgumentException(
                __('Form schema must explicitly include a string "schema_version".', 'vanturax-forms')
            );
        }

        $version = trim($rawSchema['schema_version']);
        if ($version === '') {
            throw new \InvalidArgumentException(
                __('Form schema "schema_version" cannot be empty.', 'vanturax-forms')
            );
        }

        if (!self::isSupportedVersion($version)) {
            throw new \InvalidArgumentException(
                sprintf(
                    /* translators: 1: Provided schema version, 2: Comma-separated supported versions */
                    __('Unsupported form schema version "%1$s". Supported versions: %2$s.', 'vanturax-forms'),
                    esc_html($version),
                    implode(', ', self::SUPPORTED_VERSIONS)
                )
            );
        }

        // Future incremental migrations (e.g., 1.0.0 -> 1.1.0) will execute here explicitly.
        $rawSchema['schema_version'] = self::CURRENT_VERSION;

        return $rawSchema;
    }

    /**
     * Build a clean default schema for a newly created form.
     *
     * @return array<string, mixed>
     */
    public static function defaultSchema(): array
    {
        return [
            'schema_version' => self::CURRENT_VERSION,
            'multi_step'     => [
                'enabled'               => false,
                'progress_style'        => 'bar',
                'show_step_titles'      => true,
                'allow_back_navigation' => true,
            ],
            'steps' => [
                [
                    'id'                 => 'step_1',
                    'title'              => 'Step 1',
                    'next_button_text'   => 'Next',
                    'submit_button_text' => 'Submit',
                    'fields'             => [],
                ],
            ],
            'theme' => [
                'primary_color'  => '#4F46E5',
                'border_radius'  => '8px',
                'input_padding'  => '12px 14px',
                'label_position' => 'top',
            ],
        ];
    }

    /**
     * Build default form-level settings (confirmations, notifications, spam controls).
     *
     * @return array<string, mixed>
     */
    public static function defaultSettings(): array
    {
        return [
            'confirmation' => [
                'type'         => 'message',
                'message'      => __('Thank you! Your submission has been received.', 'vanturax-forms'),
                'redirect_url' => '',
            ],
            'notifications' => [
                'admin_enabled' => true,
                'admin_to'      => '{admin_email}',
                'admin_subject' => __('New Form Submission: {form_title}', 'vanturax-forms'),
            ],
            'spam_protection' => [
                'honeypot'       => true,
                'min_fill_ms'    => 1500,
                'rate_limit_max' => 5,
            ],
        ];
    }
}
