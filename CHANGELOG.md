# Release Notes for Calendar Sync

All notable changes to this project are documented in this file. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.1.0 - 2026-11-19

### Added
- A log of the runs of every calendar: when, what started them, the numbers, every single change and what went wrong.
- A trial run that shows what the next run of a calendar would do, without writing anything
  (`calendar-sync/sync --dry-run` on the console).
- Filters per calendar: leave out private events, only take events with certain words or categories, or leave those out.
- A notice in the control panel while a calendar fails or a connection needs attention, and optional e-mails.
- A warning before the client secret of the Microsoft app runs out, from a date entered in the settings.
- `event.addToCalendarLinks` and `event.addToCalendarUrl()` for Google Calendar, Outlook.com, Microsoft 365 and a
  calendar file of the single event.
- `event.jsonLd`, `event.jsonLdScript` and `craft.calendarSync.jsonLd()` for schema.org structured data.
- GraphQL: `addToCalendarUrl` and `jsonLd` on events.

## 1.0.1 - 2026-11-13

### Fixed
- The license file has the form of the Craft License that the Plugin Store recognises.

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
