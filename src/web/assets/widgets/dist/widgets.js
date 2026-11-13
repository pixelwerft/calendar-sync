/* Calendar Sync – the dashboard widget that shows events by day, week or month. Plain JavaScript on purpose: a
   plugin is installed with Composer, so there is no build step. The server hands over days and ready-made times
   in the time zone of the site; this script only puts them where they belong. Days are handled as UTC dates so
   that no daylight saving time gets in the way. */
(function () {
  'use strict';

  window.CalendarSync = window.CalendarSync || {};

  const DAY = 86400000;
  const parse = (key) => new Date(Date.UTC(+key.slice(0, 4), +key.slice(5, 7) - 1, +key.slice(8, 10)));
  const key = (date) => date.toISOString().slice(0, 10);
  const addDays = (date, days) => new Date(date.getTime() + days * DAY);

  function el(tag, attributes, children) {
    const node = document.createElement(tag);
    Object.entries(attributes || {}).forEach(([name, value]) => {
      if (value === null || value === undefined || value === false) return;
      if (name === 'text') node.textContent = value;
      else if (name === 'on') Object.entries(value).forEach(([event, handler]) => node.addEventListener(event, handler));
      else node.setAttribute(name, value === true ? '' : value);
    });
    (children || []).forEach((child) => child && node.appendChild(child));
    return node;
  }

  class Agenda {
    constructor(selector, settings) {
      this.root = document.querySelector(selector);
      if (!this.root) return;
      this.settings = settings;
      this.messages = settings.messages;
      this.view = settings.view;
      this.today = parse(settings.today);
      this.cursor = this.today;
      this.request = 0;

      const format = (options) => new Intl.DateTimeFormat(settings.locale, Object.assign({timeZone: 'UTC'}, options));
      this.formats = {
        day: format({weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'}),
        dayShort: format({weekday: 'short', day: 'numeric', month: 'short'}),
        range: format({day: 'numeric', month: 'short', year: 'numeric'}),
        month: format({month: 'long', year: 'numeric'}),
        weekday: format({weekday: 'short'}),
      };

      this.title = el('strong', {class: 'csw-title', 'aria-live': 'polite'});
      this.body = el('div', {class: 'csw-body'});
      this.views = el('div', {class: 'btngroup small csw-views', role: 'group', 'aria-label': this.messages.view},
        ['day', 'week', 'month'].map((view) => el('button', {
          type: 'button', class: 'btn small', 'data-view': view, text: this.messages[view],
          on: {click: () => this.show(view, this.cursor)},
        })));
      this.root.appendChild(el('div', {class: 'csw-bar'}, [
        el('div', {class: 'csw-nav'}, [
          el('button', {type: 'button', class: 'btn small', 'data-icon': 'leftangle', 'aria-label': this.messages.previous, on: {click: () => this.step(-1)}}),
          el('button', {type: 'button', class: 'btn small', text: this.messages.today, on: {click: () => this.show(this.view, this.today)}}),
          el('button', {type: 'button', class: 'btn small', 'data-icon': 'rightangle', 'aria-label': this.messages.next, on: {click: () => this.step(1)}}),
        ]),
        this.title,
        this.views,
      ]));
      this.root.appendChild(this.body);
      this.show(this.view, this.cursor);
    }

    // ----- where we are

    weekStart(date) {
      const offset = (date.getUTCDay() - this.settings.weekStart + 7) % 7;
      return addDays(date, -offset);
    }

    /** The days the view shows: [first, one after the last]. */
    period() {
      if (this.view === 'day') return [this.cursor, addDays(this.cursor, 1)];
      if (this.view === 'week') {
        const start = this.weekStart(this.cursor);
        return [start, addDays(start, 7)];
      }
      const first = new Date(Date.UTC(this.cursor.getUTCFullYear(), this.cursor.getUTCMonth(), 1));
      const last = new Date(Date.UTC(this.cursor.getUTCFullYear(), this.cursor.getUTCMonth() + 1, 0));
      const start = this.weekStart(first);
      return [start, addDays(this.weekStart(last), 7)];
    }

    step(direction) {
      let cursor;
      if (this.view === 'day') cursor = addDays(this.cursor, direction);
      else if (this.view === 'week') cursor = addDays(this.cursor, 7 * direction);
      else cursor = new Date(Date.UTC(this.cursor.getUTCFullYear(), this.cursor.getUTCMonth() + direction, 1));
      this.show(this.view, cursor);
    }

    show(view, cursor) {
      this.view = view;
      this.cursor = cursor;
      this.views.querySelectorAll('button').forEach((button) => {
        const active = button.getAttribute('data-view') === view;
        button.classList.toggle('active', active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
      });

      const [from, to] = this.period();
      if (view === 'day') this.title.textContent = this.formats.day.format(cursor);
      else if (view === 'month') this.title.textContent = this.formats.month.format(cursor);
      else this.title.textContent = this.formats.range.formatRange
        ? this.formats.range.formatRange(from, addDays(to, -1))
        : this.formats.range.format(from) + ' – ' + this.formats.range.format(addDays(to, -1));

      const request = ++this.request;
      this.body.classList.add('csw-loading');
      Craft.sendActionRequest('GET', 'calendar-sync/widgets/events', {
        params: {calendar: this.settings.calendar || '', from: key(from), to: key(to)},
      }).then(({data}) => {
        if (request !== this.request) return;
        this.render(from, to, data.events || []);
      }).catch(() => {
        if (request !== this.request) return;
        this.body.replaceChildren(el('p', {class: 'error', text: this.messages.failed}));
        this.reflow();
      }).finally(() => {
        if (request === this.request) this.body.classList.remove('csw-loading');
      });
    }

    // ----- what is shown

    /** The events of each day of the period, all-day ones first, then by time. */
    byDay(from, to, events) {
      const days = {};
      for (let date = from; date < to; date = addDays(date, 1)) days[key(date)] = [];
      events.forEach((event) => {
        const last = parse(event.end);
        for (let date = parse(event.start); date <= last; date = addDays(date, 1)) {
          const list = days[key(date)];
          if (list) list.push(event);
        }
      });
      Object.values(days).forEach((list) => list.sort((a, b) => (a.sort < b.sort ? -1 : a.sort > b.sort ? 1 : a.title.localeCompare(b.title))));
      return days;
    }

    render(from, to, events) {
      const days = this.byDay(from, to, events);
      if (this.view === 'day') {
        this.body.replaceChildren(this.list(days[key(this.cursor)] || [], true));
      } else if (this.view === 'week') {
        this.body.replaceChildren(el('div', {class: 'csw-week'}, Object.keys(days).map((day) => {
          const date = parse(day);
          return el('section', {class: 'csw-day' + (day === key(this.today) ? ' csw-today' : '')}, [
            el('h3', {}, [el('button', {
              type: 'button', class: 'csw-link', text: this.formats.dayShort.format(date),
              on: {click: () => this.show('day', date)},
            })]),
            this.list(days[day], false),
          ]);
        })));
      } else {
        this.body.replaceChildren(this.month(from, days));
      }
      this.reflow();
    }

    /** The dashboard lays its widgets out by their height: tell it that this one has changed. */
    reflow() {
      const grid = window.dashboard && window.dashboard.grid;
      if (grid && typeof grid.refreshCols === 'function') {
        grid.refreshCols(true, false);
      }
    }

    list(events, detailed) {
      if (!events.length) {
        return el('p', {class: 'light csw-none', text: detailed ? this.messages.none : '–'});
      }
      return el('ul', {class: 'csw-list'}, events.map((event) => el('li', {class: 'csw-event' + (event.tentative ? ' csw-tentative' : '')}, [
        el('span', {class: 'csw-dot', style: event.color ? 'background:' + event.color : null, title: event.calendar}),
        el('span', {class: 'csw-time', text: event.allDay ? this.messages.allDay : event.time}),
        el('span', {class: 'csw-what'}, [
          event.url ? el('a', {href: event.url, text: event.title}) : el('span', {text: event.title}),
          detailed && event.location ? el('span', {class: 'light csw-where', text: event.location}) : null,
        ]),
      ])));
    }

    month(from, days) {
      const month = this.cursor.getUTCMonth();
      const head = [];
      for (let i = 0; i < 7; i++) head.push(el('div', {class: 'csw-weekday', text: this.formats.weekday.format(addDays(from, i))}));
      const cells = Object.keys(days).map((day) => {
        const date = parse(day);
        const events = days[day];
        const classes = ['csw-cell'];
        if (date.getUTCMonth() !== month) classes.push('csw-other');
        if (day === key(this.today)) classes.push('csw-today');
        const shown = events.slice(0, 3);
        return el('button', {
          type: 'button', class: classes.join(' '),
          'aria-label': this.formats.day.format(date) + (events.length ? ' – ' + events.length : ''),
          on: {click: () => this.show('day', date)},
        }, [
          el('span', {class: 'csw-number', text: String(date.getUTCDate())}),
          el('span', {class: 'csw-chips'}, shown.map((event) => el('span', {
            class: 'csw-chip', style: event.color ? '--csw-color:' + event.color : null, title: event.title,
          }, [el('span', {class: 'csw-chip-text', text: event.title})])).concat(
            events.length > shown.length ? [el('span', {class: 'csw-more', text: '+' + (events.length - shown.length)})] : [],
          )),
        ]);
      });
      return el('div', {class: 'csw-month'}, head.concat(cells));
    }
  }

  window.CalendarSync.Agenda = Agenda;
})();
