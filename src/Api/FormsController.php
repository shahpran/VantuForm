<?php

declare(strict_types=1);

namespace VanturaxForms\Api;

use VanturaxForms\Core\Capabilities;
use VanturaxForms\Core\Security;
use VanturaxForms\Exceptions\FormConflictException;
use VanturaxForms\Services\FormService;

if (!defined('ABSPATH')) {
    exit;
}

final class FormsController
{
    public const NAMESPACE = 'vanturax-forms/v1';
    public const REST_BASE = 'forms';

    private FormService $formService;

    public function __construct(FormService $formService)
    {
        $this->formService = $formService;
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            self::NAMESPACE,
            '/' . self::REST_BASE,
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'listItems'],
                    'permission_callback' => [$this, 'checkAdminPermission'],
                    'args'                => [
                        'status' => [
                            'type'              => 'string',
                            'required'          => false,
                            'sanitize_callback' => 'sanitize_key',
                        ],
                        'search' => [
                            'type'              => 'string',
                            'required'          => false,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'page' => [
                            'type'              => 'integer',
                            'default'           => 1,
                            'minimum'           => 1,
                            'sanitize_callback' => 'absint',
                        ],
                        'per_page' => [
                            'type'              => 'integer',
                            'default'           => 20,
                            'minimum'           => 1,
                            'maximum'           => 100,
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'createItem'],
                    'permission_callback' => [$this, 'checkAdminPermission'],
                ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/' . self::REST_BASE . '/(?P<id>\d+)',
            [
                'args' => [
                    'id' => [
                        'type'              => 'integer',
                        'required'          => true,
                        'minimum'           => 1,
                        'sanitize_callback' => 'absint',
                    ],
                ],
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'getItem'],
                    'permission_callback' => [$this, 'checkAdminPermission'],
                ],
                [
                    'methods'             => 'PUT, PATCH',
                    'callback'            => [$this, 'updateItem'],
                    'permission_callback' => [$this, 'checkAdminPermission'],
                ],
                [
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'callback'            => [$this, 'deleteItem'],
                    'permission_callback' => [$this, 'checkAdminPermission'],
                    'args'                => [
                        'force' => [
                            'type'    => 'boolean',
                            'default' => false,
                        ],
                        'confirm' => [
                            'type'     => 'string',
                            'required' => false,
                            'default'  => '',
                        ],
                    ],
                ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/' . self::REST_BASE . '/(?P<id>\d+)/duplicate',
            [
                'args' => [
                    'id' => [
                        'type'              => 'integer',
                        'required'          => true,
                        'minimum'           => 1,
                        'sanitize_callback' => 'absint',
                    ],
                ],
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'duplicateItem'],
                    'permission_callback' => [$this, 'checkAdminPermission'],
                ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/' . self::REST_BASE . '/(?P<id>\d+)/restore',
            [
                'args' => [
                    'id' => [
                        'type'              => 'integer',
                        'required'          => true,
                        'minimum'           => 1,
                        'sanitize_callback' => 'absint',
                    ],
                ],
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'restoreItem'],
                    'permission_callback' => [$this, 'checkAdminPermission'],
                ],
            ]
        );
    }

    /**
     * Enforce both vxf_manage_forms capability and X-WP-Nonce verification.
     *
     * @return bool|\WP_Error
     */
    public function checkAdminPermission(\WP_REST_Request $request): bool|\WP_Error
    {
        if (!is_user_logged_in() || !current_user_can(Capabilities::MANAGE_FORMS)) {
            return new \WP_Error(
                'vxf_rest_forbidden',
                __('You do not have permission to manage forms.', 'vanturax-forms'),
                ['status' => 403]
            );
        }

        if (!Security::verifyRestRequest($request, Capabilities::MANAGE_FORMS)) {
            return new \WP_Error(
                'vxf_rest_invalid_nonce',
                __('Security nonce verification failed.', 'vanturax-forms'),
                ['status' => 403]
            );
        }

        return true;
    }

    public function listItems(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $result = $this->formService->listForms([
                'status'   => (string) ($request->get_param('status') ?? ''),
                'search'   => (string) ($request->get_param('search') ?? ''),
                'page'     => (int) ($request->get_param('page') ?? 1),
                'per_page' => (int) ($request->get_param('per_page') ?? 20),
            ]);

            return new \WP_REST_Response(
                [
                    'success' => true,
                    'data'    => $result['items'],
                    'meta'    => [
                        'total'    => $result['total'],
                        'page'     => (int) ($request->get_param('page') ?? 1),
                        'per_page' => (int) ($request->get_param('per_page') ?? 20),
                    ],
                ],
                200
            );
        } catch (\Throwable $e) {
            return $this->handleInternalError('listItems', $e);
        }
    }

    public function getItem(\WP_REST_Request $request): \WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        if ($id <= 0) {
            return $this->errorResponse('vxf_invalid_id', __('Invalid form ID.', 'vanturax-forms'), 400);
        }

        try {
            $form = $this->formService->getForm($id);
            if ($form === null) {
                return $this->errorResponse('vxf_not_found', __('Form not found.', 'vanturax-forms'), 404);
            }

            return new \WP_REST_Response(
                [
                    'success' => true,
                    'data'    => $form,
                ],
                200
            );
        } catch (\Throwable $e) {
            return $this->handleInternalError('getItem', $e);
        }
    }

    public function createItem(\WP_REST_Request $request): \WP_REST_Response
    {
        $body = $this->parseRequestBody($request);
        if ($body === null) {
            return $this->errorResponse('vxf_malformed_json', __('Invalid or malformed JSON request body.', 'vanturax-forms'), 400);
        }

        try {
            $created = $this->formService->createForm($body, get_current_user_id());

            return new \WP_REST_Response(
                [
                    'success' => true,
                    'data'    => $created,
                ],
                201
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse('vxf_validation_error', $e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->handleInternalError('createItem', $e);
        }
    }

    public function updateItem(\WP_REST_Request $request): \WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        if ($id <= 0) {
            return $this->errorResponse('vxf_invalid_id', __('Invalid form ID.', 'vanturax-forms'), 400);
        }

        $body = $this->parseRequestBody($request);
        if ($body === null) {
            return $this->errorResponse('vxf_malformed_json', __('Invalid or malformed JSON request body.', 'vanturax-forms'), 400);
        }

        try {
            $updated = $this->formService->updateForm($id, $body);
            if ($updated === null) {
                return $this->errorResponse('vxf_not_found', __('Form not found.', 'vanturax-forms'), 404);
            }

            return new \WP_REST_Response(
                [
                    'success' => true,
                    'data'    => $updated,
                ],
                200
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse('vxf_validation_error', $e->getMessage(), 400);
        } catch (FormConflictException $e) {
            return $this->errorResponse('vxf_revision_conflict', $e->getMessage(), 409);
        } catch (\Throwable $e) {
            return $this->handleInternalError('updateItem', $e);
        }
    }

    public function duplicateItem(\WP_REST_Request $request): \WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        if ($id <= 0) {
            return $this->errorResponse('vxf_invalid_id', __('Invalid form ID.', 'vanturax-forms'), 400);
        }

        try {
            $duplicated = $this->formService->duplicateForm($id, get_current_user_id());
            if ($duplicated === null) {
                return $this->errorResponse('vxf_not_found', __('Source form not found.', 'vanturax-forms'), 404);
            }

            return new \WP_REST_Response(
                [
                    'success' => true,
                    'data'    => $duplicated,
                ],
                201
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse('vxf_validation_error', $e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->handleInternalError('duplicateItem', $e);
        }
    }

    public function deleteItem(\WP_REST_Request $request): \WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        if ($id <= 0) {
            return $this->errorResponse('vxf_invalid_id', __('Invalid form ID.', 'vanturax-forms'), 400);
        }

        $force        = rest_sanitize_boolean($request->get_param('force'));
        $confirmParam = $request->get_param('confirm');
        $confirm      = is_string($confirmParam) ? $confirmParam : '';

        if ($force && $confirm === '') {
            $rawBody = $request->get_body();
            if ($rawBody !== '') {
                $decoded = json_decode($rawBody, true);
                if (is_array($decoded) && isset($decoded['confirm']) && is_string($decoded['confirm'])) {
                    $confirm = $decoded['confirm'];
                }
            }
        }

        try {
            $result = $this->formService->deleteForm($id, $force, $confirm);

            return match ($result['status']) {
                'not_found'    => $this->errorResponse('vxf_not_found', __('Form not found.', 'vanturax-forms'), 404),
                'not_in_trash' => $this->errorResponse(
                    'vxf_trash_required',
                    __('Form must be moved to Trash before it can be permanently deleted.', 'vanturax-forms'),
                    400
                ),
                default        => new \WP_REST_Response(
                    [
                        'success' => true,
                        'data'    => $result,
                    ],
                    200
                ),
            };
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse('vxf_confirmation_required', $e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->handleInternalError('deleteItem', $e);
        }
    }

    public function restoreItem(\WP_REST_Request $request): \WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        if ($id <= 0) {
            return $this->errorResponse('vxf_invalid_id', __('Invalid form ID.', 'vanturax-forms'), 400);
        }

        try {
            $result = $this->formService->restoreForm($id);

            return match ($result['status']) {
                'not_found'   => $this->errorResponse('vxf_not_found', __('Form not found.', 'vanturax-forms'), 404),
                'not_trashed' => $this->errorResponse(
                    'vxf_not_trashed',
                    __('Only trashed forms can be restored.', 'vanturax-forms'),
                    400
                ),
                default       => new \WP_REST_Response(
                    [
                        'success' => true,
                        'data'    => $result['form'],
                    ],
                    200
                ),
            };
        } catch (\Throwable $e) {
            return $this->handleInternalError('restoreItem', $e);
        }
    }

    /**
     * Safely parse JSON or form-encoded REST request body.
     *
     * @return array<string, mixed>|null
     */
    private function parseRequestBody(\WP_REST_Request $request): ?array
    {
        $rawBody = $request->get_body();
        if ($rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                return null;
            }
            return $decoded;
        }

        $params = $request->get_params();
        unset($params['id'], $params['force'], $params['confirm']);

        return is_array($params) ? $params : [];
    }

    /**
     * Log internal exception details server-side without exposing SQL, file paths,
     * or sensitive payloads in the HTTP 500 response.
     */
    private function handleInternalError(string $context, \Throwable $e): \WP_REST_Response
    {
        $sanitizedMessage = preg_replace('/[\r\n]+/', ' ', $e->getMessage()) ?? 'Unknown error';
        $sanitizedMessage = mb_substr($sanitizedMessage, 0, 500);

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log(
            sprintf(
                '[VanturaxForms][REST:%s] %s: %s in %s:%d',
                $context,
                get_class($e),
                $sanitizedMessage,
                basename($e->getFile()),
                $e->getLine()
            )
        );

        return $this->errorResponse(
            'vxf_internal_error',
            __('An unexpected internal error occurred while processing your request.', 'vanturax-forms'),
            500
        );
    }

    private function errorResponse(string $code, string $message, int $status): \WP_REST_Response
    {
        return new \WP_REST_Response(
            [
                'success' => false,
                'code'    => $code,
                'message' => $message,
            ],
            $status
        );
    }
}
