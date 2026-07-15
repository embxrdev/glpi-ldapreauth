# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/).

## [1.2.0] - 2026-07-15

### Added
- Initial public release for GLPI 11.0.x.
- LDAP/Windows re-authentication prompt when answering a ticket or change
  approval (`TicketValidation` / `ChangeValidation`) — enforced for both
  accepting and rejecting.
- Blocked attempts (four-eyes violations, approver mismatch, failed identity
  check) are also written to the parent object's history.
- "Test connection" button in the configuration form (AJAX, config rights
  required, uses only the saved settings).
- The search user password is stored encrypted via GLPI's secured configs
  (GLPIKey).
- Credential verification for Active Directory (direct bind, UPN/NetBIOS) and
  generic LDAP directories (composed-DN bind and search & bind below a Base DN
  with optional service account, configurable login attribute).
- Protocol selection (ldaps:// or ldap://) and StartTLS support.
- Guided step-by-step configuration form with example placeholders.
- Mandatory enforcement that the LDAP user matches the signed-in GLPI approver.
- Four-eyes principle: the requester of a validation can never answer it
  themselves, neither via the GLPI session nor via the entered credentials.
- Audit history entry on the parent ticket after a successful decision,
  written in plain language ("Approval authorised: … confirmed their identity
  with their Windows password"). Deliberately always in English: history
  entries are stored verbatim, so a fixed language keeps the audit trail
  consistent for mixed-language teams.
- Configuration tab under Setup > General (Twig template, core Config controller).
- Configurable, off-by-default relaxation of LDAPS certificate verification.
- Translations: English (source), Czech, Dutch, French, German, Italian,
  Japanese, Polish, Portuguese (Brazil), Russian, Simplified Chinese, Spanish,
  Turkish. Any other GLPI language falls back to English.
