# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/).

## [1.0.0] - 2026-07-14

### Added
- Initial public release for GLPI 11.0.x.
- LDAP/Windows re-authentication prompt on ticket approval (`TicketValidation`).
- Direct-bind credential verification with optional UPN and NetBIOS forms.
- Optional enforcement that the LDAP user matches the signed-in GLPI approver.
- Audit history entry on the parent ticket after a successful approval.
- Configuration tab under Setup > General (Twig template, core Config controller).
- Configurable, off-by-default relaxation of LDAPS certificate verification.
- Translations: English (source), Czech, Dutch, French, German, Italian,
  Japanese, Polish, Portuguese (Brazil), Russian, Simplified Chinese, Spanish,
  Turkish. Any other GLPI language falls back to English.
