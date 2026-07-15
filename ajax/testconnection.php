<?php

/**
 * LDAP Re-auth on Approval — GLPI plugin
 * Copyright (C) 2026 Robin Embacher (embxr)
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 2 of the License, or (at your option)
 * any later version.
 *
 * Tests the SAVED connection settings (no request parameters are used, no
 * state is changed). Restricted to users with config update rights.
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
Session::checkRight('config', UPDATE);

$plugin = new Plugin();
if (!$plugin->isActivated('ldapreauth')) {
    http_response_code(404);
    exit;
}

include_once(Plugin::getPhpDir('ldapreauth') . '/hook.php');

header('Content-Type: application/json; charset=UTF-8');
echo json_encode(plugin_ldapreauth_test_connection());
