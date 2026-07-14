# LDAP Re-auth on Approval

A GLPI plugin that enforces an **LDAP / Windows re-authentication** before a
ticket approval (`TicketValidation`) is accepted or rejected.

When an approver answers a validation (*Accepted* or *Refused*), the plugin
prompts for a Windows/LDAP username and password, verifies them with a direct
LDAP bind **before** the decision is written, and requires that the LDAP user
matches the signed-in GLPI approver. A **four-eyes principle** is always
enforced: the user who requested the validation can never answer it
themselves. Successful decisions are recorded in the ticket history.

This provides an explicit re-authentication step for approval workflows that
need it — for example regulated environments that require an approval to be
tied to a fresh credential check rather than the existing browser session.

- **Compatibility:** GLPI 11.0.x
- **License:** GPL-2.0-or-later
- **Author:** Robin Embacher — https://embxr.eu

## Installation

1. Copy the `ldapreauth` directory into your GLPI `plugins/` directory.
2. Go to **Setup > Plugins**, then install and activate *LDAP Re-auth on Approval*.
3. Configure it under **Setup > General > LDAP Re-auth on Approval** — the
   form guides you through three steps:

   **Step 1 — Connection**

   | Setting | Description |
   | --- | --- |
   | Protocol | `ldaps://` (recommended) or `ldap://` (combine with StartTLS) |
   | LDAP server | host name, e.g. `dc01.example.com` (use the FQDN so it matches the certificate); a full URI such as `ldaps://host:636` also works |
   | Use StartTLS | upgrades a plain `ldap://` connection to TLS before binding |
   | Verify LDAPS certificate | keep *Yes*; set *No* only for an internal CA the GLPI host does not trust |

   **Step 2 — Active Directory (direct bind)** — skip for other directories

   | Setting | Description |
   | --- | --- |
   | AD UPN suffix | enables `user@suffix` bind (e.g. `example.com`) |
   | AD NetBIOS domain | enables `DOMAIN\user` bind (e.g. `EXAMPLE`) |

   **Step 3 — Other directories (search & bind)** — skip for plain AD

   | Setting | Description |
   | --- | --- |
   | Base DN | e.g. `ou=people,dc=example,dc=com`; also enables a composed-DN direct bind |
   | Login attribute | e.g. `uid` (OpenLDAP) or `sAMAccountName` (AD) |
   | Search user DN / password | optional service account for the DN lookup; leave empty for an anonymous search |

## How it works

- A `post_item_form` hook adds the credential fields to the classic validation
  form; `public/js/ldapreauth.js` injects the same fields into the timeline
  answer form (below the comment field).
- A `pre_item_update` hook blocks the decision (accept or reject) unless the
  credentials bind successfully and belong to the signed-in GLPI approver.
- Credential verification tries, in order: the raw login, `user@UPN`,
  `NETBIOS\user`, a composed DN (`<login attribute>=<login>,<Base DN>`), and
  finally search & bind below the Base DN (service account or anonymous).
- The same hook enforces the four-eyes principle: the requester of the
  validation is rejected as decision-maker, whether identified by the GLPI
  session or by the entered Windows credentials.
- An `item_update` hook writes an audit line to the parent ticket's history.
- Settings are stored via GLPI's core `Config` (context `plugin:ldapreauth`)
  and rendered through a Twig template; the core Config controller handles
  saving, permissions and CSRF.

## Security notes

- The plugin performs a **direct bind** as the entered user; it does not store
  or cache the password.
- The approver-match and four-eyes checks are **always on** — they are not
  configurable, so an approval always involves two distinct people.
- Certificate verification for LDAPS is **on by default**. Disabling it removes
  protection against man-in-the-middle attacks — prefer importing your internal
  CA into the GLPI host's trust store, or use `TLS_REQCERT` in the system
  `ldap.conf`.

## Languages

The interface is translated into: Czech, Dutch, English, French, German,
Italian, Japanese, Polish, Portuguese (Brazil), Russian, Simplified Chinese,
Spanish and Turkish. Any GLPI language without a translation automatically
falls back to English. Corrections and new languages are welcome — edit or add
a `locales/<lang>.po` file and open a pull request.

## Requirements

- GLPI 11.0.x
- PHP 8.1+ with the `ldap` extension

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
