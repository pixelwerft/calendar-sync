# Plugin Store listing

What goes into the form in the Craft Console (*Plugin Store → Plugins → Add a plugin*). The Console reads most of it
from `composer.json`; descriptions and URLs are not synced again afterwards, so change them in the Console too.

| Field | Value |
| --- | --- |
| Repository | `https://github.com/pixelwerft/calendar-sync` |
| Name | Calendar Sync |
| Package name | `pixelwerft/calendar-sync` |
| Handle | `calendar-sync` |
| License | Craft |
| Price | $39 |
| Renewal price | $19 per year |
| Documentation URL | `https://github.com/pixelwerft/calendar-sync/blob/main/README.md` |
| Changelog | `CHANGELOG.md` |
| Icon | `src/icon.svg` |
| Screenshots | the six images in `docs/screenshots/`, in the order of their numbers (see below) |
| Keywords | calendar, sync, google calendar, microsoft 365, outlook, caldav, ical, ics, events |
| Categories | pick what fits best in the form, e.g. integrations, content, utilities |

## Screenshots

2880 × 1800 pixels (the dashboard is taller), taken from the English control panel with demo data.

| File | Shows |
| --- | --- |
| `docs/screenshots/01-calendars.png` | The synced calendars with direction, interval and the result of the last run |
| `docs/screenshots/02-field-mapping.png` | A calendar: section, entry type and the mapping of the event to the fields of the entry |
| `docs/screenshots/03-providers.png` | Settings → Providers: Google with its two ways, the connections and the step-by-step guide |
| `docs/screenshots/04-events.png` | The events of all calendars with filters and search |
| `docs/screenshots/05-dashboard-widgets.png` | The dashboard widgets: numbers, and events by month, day and week |
| `docs/screenshots/06-connection.png` | A CalDAV connection with what Craft may do |

## Short description

Connect Google Calendar, Microsoft 365 / Outlook, CalDAV and public iCal calendars – sync events both ways, map them
to your entries, present them with Twig, GraphQL, feeds and dashboard widgets.

## Long description

Calendar Sync connects the calendars your team already uses to Craft: Google Calendar, Microsoft 365 / Outlook and any
CalDAV calendar (Nextcloud, iCloud, Fastmail, mailbox.org …). Events come to Craft, entries go to the calendar, or both.

Keep a calendar in one place and let the website show it – or publish events on the website and find them in the
calendar.

### What you get

- **Three providers** – Google Calendar, Microsoft 365 / Outlook.com / Exchange Online and CalDAV. Several accounts,
  several calendars per account. Each with a step-by-step guide in the settings.
- **Public calendars without a sign-in** – a publicly shared Google calendar or any calendar address in iCal format.
- **Read, write, or busy times only** – set per connection what Craft may do.
- **Three directions** – calendar to Craft, Craft to calendar, or both ways – set per calendar.
- **Your data, your fields** – map start, end, «all day», location, description and link to the fields of your own
  entries. What is not mapped is left alone: guests and reminders of an event survive a change made in Craft.
- **An example model with one click** – a section «Calendar events» with the fields an event needs, created and mapped
  for you. Or no section at all: the events are kept by the plugin and are just as easy to show.
- **Sync settings** – how often, how many days back and ahead, what happens when an event leaves the calendar, and who
  wins when both sides changed. Entries are sent to the calendar the moment they are saved.
- **Presenting** – `craft.calendarSync.events()` for Twig, GraphQL queries, an iCalendar feed to subscribe to and a
  JSON feed for FullCalendar. A colour and a prefix per calendar; line breaks, attached documents and links in the
  description the way you need them.
- **Dashboard widgets** – a counter and the events of a day, a week or a month.
- **A log and a trial run** – see what every run did, change by change, and what the next one would do before it does.
- **Filters and notifications** – leave out private events or pick events by word and category; a notice and an
  e-mail when a calendar fails.
- **Add to calendar and structured data** – links for Google, Outlook and a calendar file, and schema.org data.
- Console commands for a cron job, queue jobs, permissions, encrypted credentials. Interface in English, German,
  French, Italian and Spanish.

### Requirements

Craft CMS 5.3 or later, PHP 8.2 or later, MySQL, MariaDB or PostgreSQL. Google and Microsoft need an OAuth app of
your own (free).

## Publishing, step by step

### 1. The repository

1. Push `main` to `https://github.com/pixelwerft/calendar-sync`. The repository has to be public: the store installs
   from it.
2. For the release: set the date in `CHANGELOG.md` (`## 1.0.0 - YYYY-MM-DD`), commit, tag `v1.0.0` and push the tag.
   The Console reads the version from the tag and the release notes from the changelog.

### 2. Craft Console (console.craftcms.com)

Needed once, before a paid plugin can be listed:

- An account, best with an **organization** «pixelwerft» that owns the plugin.
- The **GitHub** account connected to the Console, with admin rights on the repository (the Console adds a webhook
  so new tags arrive by themselves).
- A **Stripe** account connected for payouts, and the billing and tax details of the organization filled in.

Then *Plugins → Add a plugin*, choose the repository, and fill in the form with the values of the table above:

- Name, handle and package name are read from `composer.json`.
- Icon: `src/icon.svg`.
- Short and long description: the two sections below.
- Documentation URL and changelog: as in the table.
- Categories and keywords: as in the table.
- Screenshots: the images of `docs/screenshots/`, in the order of their numbers. If the form takes fewer than six,
  leave out `06-connection.png` first, then `04-events.png`.
- License: Craft. Price **$39**, renewal price **$19** per year.

Save, then **submit for approval**. Pixel & Tonic review every new plugin by hand; that takes a few working days,
and they may come back with questions.

### 3. After approval

- The plugin appears in the Plugin Store and in the control panel of every Craft site; `composer require
  pixelwerft/calendar-sync` works through the store.
- Optional: register the package on Packagist too.
- Every later release is a changelog entry and a tag – nothing to do in the Console.

### Checked before the first release

- The handle `calendar-sync` is free in the Plugin Store (looked up on 2026-11-13).
- Not tested against the live services: signing in at Google and at Microsoft (OAuth). Do that with an OAuth app of
  each before the release – the guides are in *Calendar Sync → Settings → Providers*.
