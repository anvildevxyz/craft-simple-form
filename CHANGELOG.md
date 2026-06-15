# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Conditional logic: show/hide fields and make them conditionally required based
  on other fields' values, with live client-side evaluation and authoritative
  server-side enforcement (hidden fields are not validated or stored). Exposed
  via the field builder, GraphQL (`SimpleFormField.conditional`), and the MCP
  `add_field`/`update_field` tools. See [docs/conditional-logic.md](docs/conditional-logic.md).
- Initial plugin scaffold with Form and Submission element types
- CP navigation menu for Forms and Submissions
- Database migrations for forms, fields, and submissions tables
