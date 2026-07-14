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

namespace GlpiPlugin\Ldapreauth;

use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Session;

if (!defined('GLPI_ROOT')) {
    die('Direct access not allowed');
}

/**
 * Plugin settings, surfaced as a tab under Setup > General.
 * Persistence and CSRF are handled by GLPI's core Config controller.
 */
class Config extends \Config
{
    public static function getTypeName($nb = 0)
    {
        return __('LDAP Re-auth on Approval', 'ldapreauth');
    }

    public static function getConfig(): array
    {
        return \Config::getConfigurationValues(PLUGIN_LDAPREAUTH_CONTEXT);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item->getType() === \Config::class) {
            return self::createTabEntry(self::getTypeName());
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item->getType() === \Config::class) {
            return self::showForConfig();
        }
        return true;
    }

    public static function showForConfig(): bool
    {
        if (!self::canView()) {
            return false;
        }

        TemplateRenderer::getInstance()->display('@ldapreauth/config.html.twig', [
            'current_config' => self::getConfig(),
            'can_edit'       => Session::haveRight(self::$rightname, UPDATE),
        ]);

        return true;
    }
}
