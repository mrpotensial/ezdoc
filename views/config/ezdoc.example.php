<?php
/**
 * ezdoc.example.php — sample consumer configuration for the Ezdoc library.
 *
 * COPY THIS FILE to your application's config directory and edit the values:
 *
 *   cp vendor/ezdoc/config/ezdoc.example.php /app/config/ezdoc.php
 *
 * Then load it during bootstrap:
 *
 *   use Ezdoc\Config;
 *   Config::fromFile('/app/config/ezdoc.php');
 *
 * All keys are optional — defaults are baked into the starter views.
 * See docs/UI-CUSTOMIZATION.md → "Level 1: Config only" for the full list.
 *
 * @return array<string,mixed>
 */

declare(strict_types=1);

return [
    // ------------------------------------------------------------------
    // Branding — surfaced by the layout view + injected as CSS variables
    // ------------------------------------------------------------------
    'brand.app_name'         => 'MyDocApp',
    'brand.primary_color'    => '#0e7490',
    'brand.secondary_color'  => '#f59e0b',
    'brand.logo_url'         => '/img/logo.png',

    // ------------------------------------------------------------------
    // Page copy — override strings without publishing views
    // ------------------------------------------------------------------
    'pages.list.title'         => 'All Documents',
    'pages.list.empty_message' => 'No documents yet — click "New" to create your first.',
    'pages.form.title'         => 'Create Document',
    'pages.form.submit_label'  => 'Save Document',

    // ------------------------------------------------------------------
    // Asset append-lists — loaded AFTER core ezdoc.css/ezdoc.js so any
    // rule/handler in these files wins the cascade.
    // ------------------------------------------------------------------
    'custom_css' => [
        '/css/branding.css',
    ],
    'custom_js' => [
        '/js/ext.js',
    ],

    // Optional URL overrides used by starter views (Cancel link, etc.)
    'urls.list' => '/ezdoc/documents',
];
