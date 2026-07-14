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

if (!defined('GLPI_ROOT')) {
    die('Direct access not allowed');
}

/**
 * Install: seed secure default settings.
 */
function plugin_ldapreauth_install()
{
    Config::setConfigurationValues(PLUGIN_LDAPREAUTH_CONTEXT, [
        'server'             => '',
        'ad_domain'          => '',
        'ad_netbios'         => '',
        'enforce_glpi_match' => '1', // require LDAP user == GLPI approver
        'tls_verify'         => '1', // verify LDAPS certificate
    ]);

    return true;
}

/**
 * Uninstall: remove all stored settings.
 */
function plugin_ldapreauth_uninstall()
{
    $config = new Config();
    $config->deleteByCriteria(['context' => PLUGIN_LDAPREAUTH_CONTEXT]);

    return true;
}

/**
 * Add Windows username/password fields on the classic TicketValidation form.
 * The timeline popup is handled by public/js/ldapreauth.js; field names match.
 */
function plugin_ldapreauth_post_item_form(array $params)
{
    if (!isset($params['item']) || !($params['item'] instanceof TicketValidation)) {
        return;
    }

    echo '<tr class="tab_bg_1">';
    echo '  <td>' . htmlescape(__('Windows username for approval', 'ldapreauth')) . '</td>';
    echo '  <td><input type="text" name="_ldapreauth_login" autocomplete="off"></td>';
    echo '</tr>';

    echo '<tr class="tab_bg_1">';
    echo '  <td>' . htmlescape(__('Windows password', 'ldapreauth')) . '</td>';
    echo '  <td><input type="password" name="_ldapreauth_password" autocomplete="off"></td>';
    echo '</tr>';
}

/**
 * Normalize a login for comparison: lower-case and strip DOMAIN\ / @domain.
 */
function plugin_ldapreauth_normalize(string $user): string
{
    $user = trim(strtolower($user));

    if (strpos($user, '\\') !== false) {
        $parts = explode('\\', $user, 2);
        $user  = $parts[1];
    }
    if (strpos($user, '@') !== false) {
        $parts = explode('@', $user, 2);
        $user  = $parts[0];
    }

    return $user;
}

/**
 * Enforce Windows/LDAP re-authentication before a TicketValidation is written.
 */
function plugin_ldapreauth_pre_item_update(CommonDBTM $item)
{
    if (!($item instanceof TicketValidation)) {
        return;
    }

    $input = $item->input;

    // Act only when the validation is being set to ACCEPTED.
    if (
        !isset($input['status'])
        || (int) $input['status'] !== CommonITILValidation::ACCEPTED
    ) {
        return;
    }

    $conf       = Config::getConfigurationValues(PLUGIN_LDAPREAUTH_CONTEXT);
    $enforce    = ($conf['enforce_glpi_match'] ?? '1') !== '0';
    $ldap_login = $input['_ldapreauth_login']    ?? '';
    $ldap_pass  = $input['_ldapreauth_password'] ?? '';

    // Block the approval, keep the previous status, drop the password.
    $deny = static function (CommonDBTM $item): void {
        $item->input['status'] = $item->fields['status'];
        unset($item->input['_ldapreauth_password']);
    };

    if ($ldap_login === '' || $ldap_pass === '') {
        Session::addMessageAfterRedirect(
            __('A Windows username and password are required to approve.', 'ldapreauth'),
            false,
            ERROR
        );
        $deny($item);
        return;
    }

    // Optional restriction: the LDAP user must match the GLPI approver.
    if ($enforce) {
        $glpi_login = '';
        $glpi_id    = Session::getLoginUserID();
        if ($glpi_id) {
            $u = new User();
            if ($u->getFromDB($glpi_id)) {
                $glpi_login = $u->fields['name'] ?? '';
            }
        }

        $norm_ldap = plugin_ldapreauth_normalize($ldap_login);
        $norm_glpi = plugin_ldapreauth_normalize($glpi_login);

        if ($norm_ldap === '' || $norm_glpi === '' || $norm_ldap !== $norm_glpi) {
            Session::addMessageAfterRedirect(
                sprintf(
                    __('Windows user "%1$s" does not match the signed-in GLPI user "%2$s".', 'ldapreauth'),
                    $ldap_login,
                    $glpi_login
                ),
                false,
                ERROR
            );
            $deny($item);
            return;
        }
    }

    // LDAP bind check.
    if (!plugin_ldapreauth_check_ldap_credentials($ldap_login, $ldap_pass)) {
        // Error message is added inside the check function.
        $deny($item);
        return;
    }

    // Success: never let the plaintext password travel further.
    // Keep _ldapreauth_login so item_update() can write the audit entry.
    unset($item->input['_ldapreauth_password']);
}

/**
 * After a successful re-auth approval, write an audit line to the ticket
 * history (useful for regulated / traceable approval workflows).
 */
function plugin_ldapreauth_item_update(CommonDBTM $item)
{
    if (!($item instanceof TicketValidation)) {
        return;
    }
    if (
        !isset($item->input['status'])
        || (int) $item->input['status'] !== CommonITILValidation::ACCEPTED
    ) {
        return;
    }

    $ldapuser = $item->input['_ldapreauth_login'] ?? '';
    if ($ldapuser === '') {
        return;
    }

    $ticket_id = (int) ($item->fields['tickets_id'] ?? 0);
    if ($ticket_id <= 0) {
        return;
    }

    Log::history(
        $ticket_id,
        'Ticket',
        [
            0,
            '',
            sprintf(
                __('LDAP re-authentication OK — approved as "%s"', 'ldapreauth'),
                $ldapuser
            ),
        ],
        '',
        Log::HISTORY_LOG_SIMPLE_MESSAGE
    );
}

/**
 * Verify credentials with a direct LDAP bind as the user.
 * All environment specifics come from the plugin configuration.
 */
function plugin_ldapreauth_check_ldap_credentials(string $login, string $password): bool
{
    $login = trim($login);
    if ($login === '' || $password === '') {
        return false;
    }

    $conf       = Config::getConfigurationValues(PLUGIN_LDAPREAUTH_CONTEXT);
    $server_uri = trim($conf['server']     ?? '');
    $ad_domain  = trim($conf['ad_domain']  ?? '');
    $ad_netbios = trim($conf['ad_netbios'] ?? '');
    $tls_verify = ($conf['tls_verify'] ?? '1') !== '0';

    if ($server_uri === '') {
        Session::addMessageAfterRedirect(
            __('LDAP server is not configured (Setup > General > LDAP Re-auth).', 'ldapreauth'),
            false,
            ERROR
        );
        return false;
    }

    // Only relax certificate verification when the admin explicitly opts in.
    // This global option must be set BEFORE ldap_connect() to take effect.
    if (!$tls_verify && defined('LDAP_OPT_X_TLS_REQUIRE_CERT') && defined('LDAP_OPT_X_TLS_NEVER')) {
        @ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_NEVER);
    }

    $conn = @ldap_connect($server_uri);
    if (!$conn) {
        Session::addMessageAfterRedirect(
            sprintf(__('Invalid LDAP server URI: %s', 'ldapreauth'), $server_uri),
            false,
            ERROR
        );
        return false;
    }

    ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
    @ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 5);
    if (!$tls_verify && defined('LDAP_OPT_X_TLS_REQUIRE_CERT') && defined('LDAP_OPT_X_TLS_NEVER')) {
        @ldap_set_option($conn, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_NEVER);
    }

    // Try the raw login plus optional UPN and NetBIOS forms.
    $candidates = [$login];
    if ($ad_domain !== '' && stripos($login, '@') === false) {
        $candidates[] = $login . '@' . $ad_domain;
    }
    if ($ad_netbios !== '' && strpos($login, '\\') === false) {
        $candidates[] = $ad_netbios . '\\' . $login;
    }
    $candidates = array_unique($candidates);

    $ok         = false;
    $last_error = '';
    foreach ($candidates as $bind_rdn) {
        if (@ldap_bind($conn, $bind_rdn, $password)) {
            $ok = true;
            break;
        }
        $last_error = ldap_error($conn);
    }

    if (!$ok) {
        Session::addMessageAfterRedirect(
            sprintf(
                __('LDAP authentication failed for "%1$s". Error: %2$s', 'ldapreauth'),
                $login,
                $last_error
            ),
            false,
            WARNING
        );
    }

    @ldap_unbind($conn);
    return $ok;
}
