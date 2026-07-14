# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/).

## [1.0.0] - 2026-07-14

### Added
- Initial public release for GLPI 11.0.x.
- LDAP/Windows re-authentication prompt when answering a ticket approval
  (`TicketValidation`) — enforced for both accepting and rejecting.
- Credential verification for Active Directory (direct bind, UPN/NetBIOS) and
  generic LDAP directories (composed-DN bind and search & bind below a Base DN
  with optional service account, configurable login attribute).
- Protocol selection (ldaps:// or ldap://) and StartTLS support.
- Guided step-by-step configuration form with example placeholders.
- Mandatory enforcement that the LDAP user matches the signed-in GLPI approver.
- Four-eyes principle: the requester of a validation can never answer it
  themselves, neither via the GLPI session nor via the entered credentials.
- Audit history entry on the parent ticket after a successful decision.
- Configuration tab under Setup > General (Twig template, core Config controller).
- Configurable, off-by-default relaxation of LDAPS certificate verification.
- Translations: English (source), Czech, Dutch, French, German, Italian,
  Japanese, Polish, Portuguese (Brazil), Russian, Simplified Chinese, Spanish,
  Turkish. Any other GLPI language falls back to English.
