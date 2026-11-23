<?php

declare(strict_types=1);

namespace VanturaxForms\Core;

if (!defined('ABSPATH')) {
    exit;
}

final class Security
{
    public const ADMIN_NONCE_ACTION = 'vxf_admin_action';
    public const REST_NONCE_ACTION  = 'wp_rest';

    /**
     * Create a nonce for WordPress Admin AJAX / form actions.
     */
    public static function createAdminNonce(): string
    {
        return wp_create_nonce(self::ADMIN_NONCE_ACTION);
    }

    /**
     * Verify an Admin nonce and ensure user holds the required capability.
     */
    public static function verifyAdminRequest(string $nonce, string $capability = Capabilities::MANAGE_FORMS): bool
    {
        if (!current_user_can($capability)) {
            return false;
        }

        return wp_verify_nonce($nonce, self::ADMIN_NONCE_ACTION) !== false;
    }

    /**
     * Verify a REST API request nonce and capability.
     */
    public static function verifyRestRequest(\WP_REST_Request $request, string $capability = Capabilities::MANAGE_FORMS): bool
    {
        if (!current_user_can($capability)) {
            return false;
        }

        $nonce = $request->get_header('X-WP-Nonce');
        if (!is_string($nonce) || $nonce === '') {
            return false;
        }

        return wp_verify_nonce($nonce, self::REST_NONCE_ACTION) !== false;
    }
}
