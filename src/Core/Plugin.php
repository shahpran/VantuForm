<?php

declare(strict_types=1);

namespace VanturaxForms\Core;

use VanturaxForms\Admin\AdminMenu;
use VanturaxForms\Api\FormsController;
use VanturaxForms\Database\FormRepository;
use VanturaxForms\Database\Migrator;
use VanturaxForms\Fields\FieldRegistry;
use VanturaxForms\Schema\SchemaValidator;
use VanturaxForms\Services\FormService;

if (!defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    private static ?self $instance = null;
    private bool $booted = false;

    private Migrator $migrator;
    private FieldRegistry $fieldRegistry;
    private AdminMenu $adminMenu;
    private Assets $assets;

    private function __construct()
    {
        $this->migrator      = new Migrator();
        $this->fieldRegistry = new FieldRegistry();
        $this->adminMenu     = new AdminMenu($this->migrator);
        $this->assets        = new Assets($this->migrator, $this->fieldRegistry);
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Initialize plugin services safely once per request.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        load_plugin_textdomain(
            'vanturax-forms',
            false,
            dirname(VXF_PLUGIN_BASENAME) . '/languages'
        );

        // Initialize extensible field registry so field types are available
        // for REST schema validation and Admin bootstrap serialization.
        $this->fieldRegistry->init();

        // Run incremental schema migration check in admin or cron contexts.
        if (is_admin() || wp_doing_cron()) {
            $this->migrator->maybeMigrate();
        }

        add_action('rest_api_init', [$this, 'registerRestRoutes']);

        if (is_admin()) {
            $this->adminMenu->registerHooks();
            $this->assets->registerHooks();
        }
    }

    /**
     * Register WordPress REST API controllers.
     */
    public function registerRestRoutes(): void
    {
        $repository = new FormRepository();
        $validator  = new SchemaValidator($this->fieldRegistry);
        $service    = new FormService($repository, $validator);
        $controller = new FormsController($service);

        $controller->registerRoutes();
    }

    /**
     * Plugin activation callback.
     */
    public static function activate(): void
    {
        Capabilities::register();

        $migrator = new Migrator();
        $migrator->install();
    }

    /**
     * Plugin deactivation callback.
     */
    public static function deactivate(): void
    {
        // Preserves all user forms, entries, capabilities, database tables,
        // and any active migration lock owned by an in-flight worker.
    }

    public function migrator(): Migrator
    {
        return $this->migrator;
    }

    public function fieldRegistry(): FieldRegistry
    {
        return $this->fieldRegistry;
    }
}
