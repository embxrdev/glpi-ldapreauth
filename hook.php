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
    $defaults = [
        'protocol'     => 'ldaps', // ldaps:// (recommended) or ldap://
        'server'       => '',
        'ad_domain'    => '',
        'ad_netbios'   => '',
        'base_dn'      => '',    // enables search & bind (OpenLDAP etc.)
        'login_attr'   => 'uid', // attribute matched against the entered login
        'bind_dn'      => '',    // optional service account for the search
        'bind_pass'    => '',
        'use_starttls' => '0',
        'tls_verify'   => '1',   // verify LDAPS certificate
    ];

    // Seed only missing keys: setConfigurationValues() overwrites existing
    // rows, which would wipe saved settings on every plugin update.
    $current = Config::getConfigurationValues(PLUGIN_LDAPREAUTH_CONTEXT);
    $missing = array_diff_key($defaults, $current);
    if ($missing !== []) {
        Config::setConfigurationValues(PLUGIN_LDAPREAUTH_CONTEXT, $missing);
    }

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
    echo '  <td>' . htmlescape(__('Windows username', 'ldapreauth')) . '</td>';
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

    // Act when the validation is being answered (accepted or rejected).
    $status = isset($input['status']) ? (int) $input['status'] : null;
    if (
        $status !== CommonITILValidation::ACCEPTED
        && $status !== CommonITILValidation::REFUSED
    ) {
        return;
    }

    $ldap_login = $input['_ldapreauth_login']    ?? '';
    $ldap_pass  = $input['_ldapreauth_password'] ?? '';

    // Block the decision, keep the previous status, drop the password.
    $deny = static function (CommonDBTM $item): void {
        $item->input['status'] = $item->fields['status'];
        unset($item->input['_ldapreauth_password']);
    };

    // Four-eyes principle: the requester of the validation can never answer
    // it, neither as the signed-in GLPI user nor via the entered credentials.
    $requester_id = (int) ($item->fields['users_id'] ?? 0);
    $four_eyes_block = static function () use ($deny, $item): void {
        Session::addMessageAfterRedirect(
            __('The approval requester cannot approve or reject their own request — a second person is required.', 'ldapreauth'),
            false,
            ERROR
        );
        $deny($item);
    };

    if ($requester_id > 0 && $requester_id === (int) Session::getLoginUserID()) {
        $four_eyes_block();
        return;
    }

    if ($ldap_login === '' || $ldap_pass === '') {
        Session::addMessageAfterRedirect(
            __('A Windows username and password are required to approve or reject.', 'ldapreauth'),
            false,
            ERROR
        );
        $deny($item);
        return;
    }

    // Four-eyes, part 2: the credentials themselves must not belong to the
    // requester. Redundant with the approver-match check below, but kept as
    // defence in depth for the always-two-people guarantee.
    if ($requester_id > 0) {
        $requester = new User();
        if ($requester->getFromDB($requester_id)) {
            $requester_login = plugin_ldapreauth_normalize($requester->fields['name'] ?? '');
            if ($requester_login !== '' && $requester_login === plugin_ldapreauth_normalize($ldap_login)) {
                $four_eyes_block();
                return;
            }
        }
    }

    // Mandatory restriction: the LDAP user must match the GLPI approver.
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
 * After a successful re-auth decision, write an audit line to the ticket
 * history (useful for regulated / traceable approval workflows).
 */
function plugin_ldapreauth_item_update(CommonDBTM $item)
{
    if (!($item instanceof TicketValidation)) {
        return;
    }
    $status = isset($item->input['status']) ? (int) $item->input['status'] : null;
    if (
        $status !== CommonITILValidation::ACCEPTED
        && $status !== CommonITILValidation::REFUSED
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

    $message = $status === CommonITILValidation::ACCEPTED
        ? __('Approval authorised: "%s" confirmed their identity with their Windows password.', 'ldapreauth')
        : __('Rejection authorised: "%s" confirmed their identity with their Windows password.', 'ldapreauth');

    Log::history(
        $ticket_id,
        'Ticket',
        [
            0,
            '',
            sprintf($message, $ldapuser),
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
    $base_dn    = trim($conf['base_dn']    ?? '');
    $login_attr = trim($conf['login_attr'] ?? '');
    $bind_dn    = trim($conf['bind_dn']    ?? '');
    $bind_pass  = (string) ($conf['bind_pass'] ?? '');
    $starttls   = ($conf['use_starttls'] ?? '0') !== '0';
    $tls_verify = ($conf['tls_verify'] ?? '1') !== '0';

    if ($login_attr === '') {
        $login_attr = 'uid';
    }

    // The server may be a bare host name; build the URI from the selected
    // protocol. A full ldap[s]:// URI is used verbatim and wins.
    if ($server_uri !== '' && !preg_match('#^ldaps?://#i', $server_uri)) {
        $protocol   = strtolower(trim($conf['protocol'] ?? 'ldaps')) === 'ldap' ? 'ldap' : 'ldaps';
        $server_uri = $protocol . '://' . $server_uri;
    }

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

    // Upgrade a plain ldap:// connection to TLS when requested.
    if ($starttls && stripos($server_uri, 'ldaps://') !== 0) {
        if (!@ldap_start_tls($conn)) {
            Session::addMessageAfterRedirect(
                sprintf(__('StartTLS failed: %s', 'ldapreauth'), ldap_error($conn)),
                false,
                ERROR
            );
            @ldap_unbind($conn);
            return false;
        }
    }

    // Direct-bind candidates: raw login, UPN and NetBIOS forms (Active
    // Directory), plus a composed DN under the base DN (flat LDAP trees).
    $candidates = [$login];
    if ($ad_domain !== '' && stripos($login, '@') === false) {
        $candidates[] = $login . '@' . $ad_domain;
    }
    if ($ad_netbios !== '' && strpos($login, '\\') === false) {
        $candidates[] = $ad_netbios . '\\' . $login;
    }
    if ($base_dn !== '' && strpos($login, '=') === false) {
        $candidates[] = $login_attr . '=' . ldap_escape($login, '', LDAP_ESCAPE_DN) . ',' . $base_dn;
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

    // Search & bind: find the user's DN under the base DN (service account
    // or anonymous search), then bind with it. Covers nested trees where the
    // DN cannot be derived from the login (OpenLDAP, FreeIPA, AD without UPN).
    if (!$ok && $base_dn !== '') {
        $search_bound = $bind_dn !== ''
            ? @ldap_bind($conn, $bind_dn, $bind_pass)
            : @ldap_bind($conn); // anonymous
        if ($search_bound) {
            $filter = '(' . $login_attr . '=' . ldap_escape($login, '', LDAP_ESCAPE_FILTER) . ')';
            $result = @ldap_search($conn, $base_dn, $filter, ['dn'], 0, 2);
            $entries = $result ? @ldap_get_entries($conn, $result) : false;
            if (is_array($entries) && (int) ($entries['count'] ?? 0) === 1) {
                $user_dn = $entries[0]['dn'] ?? '';
                if ($user_dn !== '' && @ldap_bind($conn, $user_dn, $password)) {
                    $ok = true;
                }
            }
        }
        if (!$ok) {
            $err = ldap_error($conn);
            if ($err !== '' && strcasecmp($err, 'Success') !== 0) {
                $last_error = $err;
            }
        }
    }

    if (!$ok) {
        // Translate the common failure causes; fall back to the raw
        // LDAP error text only for unusual ones.
        $errno = ldap_errno($conn);
        if ($errno === 49) { // LDAP_INVALID_CREDENTIALS
            $message = sprintf(
                __('LDAP authentication failed for "%s": wrong username or password.', 'ldapreauth'),
                $login
            );
        } elseif ($errno === -1) { // LDAP_SERVER_DOWN
            $message = __('Cannot reach the LDAP server. Check the protocol (ldaps:// vs ldap://), the server name and the firewall.', 'ldapreauth');
        } else {
            $message = sprintf(
                __('LDAP authentication failed for "%1$s". Error: %2$s', 'ldapreauth'),
                $login,
                $last_error
            );
        }
        Session::addMessageAfterRedirect($message, false, WARNING);
    }

    @ldap_unbind($conn);
    return $ok;
}
