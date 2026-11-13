# Release Notes for Calendar Sync

All notable changes to this project are documented in this file. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.0 - 2026-11-13

First public release.

### Added
- Connections to Google Calendar, Microsoft 365 / Outlook and CalDAV servers; tokens and passwords are stored encrypted.
- Public calendars without a sign-in: a publicly shared Google calendar or any calendar address in iCal format.
- Access per connection: read and write, read only, or busy times only.
- Settings with a card per provider, step-by-step guides to the credentials, and the example model.
- Calendars synced from the calendar to Craft, from Craft to the calendar, or both ways.
- Field mapping between events and the entries of a section, with an example section that is created with one click.
- Sync settings per calendar (interval, period, conflicts, what happens to entries of removed events) and for all
  calendars (automatic sync, sending entries on save, defaults).
- A colour and a prefix for entries per calendar.
- Options for the description: line breaks, attached documents (Google Drive), web addresses as links.
- `craft.calendarSync.events()` for templates, GraphQL queries, a public iCalendar feed and a JSON feed per calendar.
- Dashboard widgets: a counter, and events by day, week and month.
- Console commands, queue jobs and user permissions.
- Interface in English, German, French, Italian and Spanish.
