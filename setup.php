<?php

/**
 * LDAP Re-auth on Approval — GLPI plugin
 * Copyright (C) 2026 Robin Embacher (embxr)
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 2 of the License, or (at your option)
 * any later version.
 */

use GlpiPlugin\Ldapreauth\Config as LdapreauthConfig;

if (!defined('GLPI_ROOT')) {
    die('Direct access not allowed');
}

define('PLUGIN_LDAPREAUTH_VERSION', '1.0.0');

// Minimal / maximal GLPI versions (inclusive / exclusive).
define('PLUGIN_LDAPREAUTH_MIN_GLPI', '11.0.0');
define('PLUGIN_LDAPREAUTH_MAX_GLPI', '11.0.99');

// Context under which settings are stored in glpi_configs.
define('PLUGIN_LDAPREAUTH_CONTEXT', 'plugin:ldapreauth');

/**
 * Initialize hooks. Called by GLPI on every page.
 */
function plugin_init_ldapreauth()
{
    global $PLUGIN_HOOKS;

    // Declares the plugin as CSRF-compliant (all forms carry a token).
    $PLUGIN_HOOKS['csrf_compliant']['ldapreauth'] = true;

    // Configuration tab under Setup > General.
    Plugin::registerClass(LdapreauthConfig::class, [
        'addtabon' => Config::class,
    ]);

    // Extra Windows credential fields on the classic validation form.
    $PLUGIN_HOOKS['post_item_form']['ldapreauth'] = [
        'TicketValidation' => 'plugin_ldapreauth_post_item_form',
    ];

    // Enforce LDAP re-authentication before the validation row is written.
    $PLUGIN_HOOKS['pre_item_update']['ldapreauth'] = [
        'TicketValidation' => 'plugin_ldapreauth_pre_item_update',
    ];

    // Write an audit history line after a successful approval.
    $PLUGIN_HOOKS['item_update']['ldapreauth'] = [
        'TicketValidation' => 'plugin_ldapreauth_item_update',
    ];

    // JS that injects the credential fields into the timeline answer form.
    // File physically lives in public/js/ (GLPI 11); the /public segment is
    // omitted from the registered path.
    $PLUGIN_HOOKS['add_javascript']['ldapreauth'][] = 'js/ldapreauth.js';
}

/**
 * Plugin metadata shown in Setup > Plugins.
 */
function plugin_version_ldapreauth()
{
    return [
        'name'         => 'LDAP Re-auth on Approval',
        'version'      => PLUGIN_LDAPREAUTH_VERSION,
        'author'       => '<a href="https://embxr.eu">Robin Embacher (embxr)</a>',
        'license'      => 'GPLv2+',
        'homepage'     => 'https://embxr.eu',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_LDAPREAUTH_MIN_GLPI,
                'max' => PLUGIN_LDAPREAUTH_MAX_GLPI,
            ],
        ],
    ];
}

/**
 * Prerequisites: PHP LDAP extension is required.
 */
function plugin_ldapreauth_check_prerequisites()
{
    if (!function_exists('ldap_connect')) {
        echo __('This plugin requires the PHP LDAP extension.', 'ldapreauth');
        return false;
    }
    return true;
}

/**
 * Config check. Always installable; behaviour is governed by settings.
 */
function plugin_ldapreauth_check_config($verbose = false)
{
    return true;
}
