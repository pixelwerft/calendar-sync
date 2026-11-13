/* Calendar Sync – the form of a calendar. Plain JavaScript on purpose: a plugin is installed with Composer, so
   there is no build step. It fills the selects that depend on each other: the calendars of a connection, the entry
   types of a section and the fields of an entry type that can hold each attribute of an event. */
(function ($) {
  'use strict';

  window.CalendarSync = window.CalendarSync || {};

  // What a field is probably for, by its handle or name – only used to suggest a mapping, never to decide one.
  const HINTS = {
    start: /start|begin|from|von|date|datum/i,
    end: /end|until|bis|finish/i,
    allDay: /all.?day|ganzt|whole/i,
    location: /location|place|venue|ort|where|address|adresse/i,
    description: /desc|beschr|text|body|content|inhalt|detail/i,
    url: /url|link|web/i,
    status: /status/i,
    organizer: /organi[sz]|host|veranstalter/i,
  };

  class CalendarForm {
    constructor(settings) {
      this.settings = settings;
      this.sections = settings.sections || [];
      this.$form = $('#cs-calendar-form');
      this.$connection = $('#connectionId');
      this.$remote = $('#remoteId');
      this.$section = $('#sectionId');
      this.$type = $('#entryTypeId');
      this.$direction = $('#direction');
      this.$mapping = this.$form.find('.cs-mapping select');

      if (settings.isNew && Craft.HandleGenerator) {
        new Craft.HandleGenerator('#name', '#handle');
      }

      this.fillSections(this.$section.data('value'));
      this.fillTypes(this.$type.data('value'));
      this.fillMapping(false);
      this.toggle();

      this.$connection.on('change', () => {
        this.loadRemote(true);
        this.toggle();
      });
      $('#cs-remote-reload').on('click', () => this.loadRemote(false));
      this.$remote.on('change', () => this.remoteChosen(true));
      this.$section.on('change', () => {
        this.fillTypes(null);
        this.fillMapping(true);
        this.toggle();
      });
      this.$type.on('change', () => this.fillMapping(true));
      this.$direction.on('change', () => this.toggle());
      $('#cs-blueprint').on('click', () => this.installBlueprint());

      if (this.$connection.val()) {
        this.loadRemote(false);
      }
    }

    // ----- the calendars of the connection

    loadRemote(reset) {
      const connectionId = this.$connection.val();
      const $error = $('#cs-remote-error').addClass('hidden');
      const current = reset ? '' : this.$remote.val() || this.$remote.data('value') || '';

      if (!connectionId) {
        this.$remote.html($('<option/>', {value: '', text: this.settings.messages.chooseConnection}));
        return;
      }

      const $spinner = $('#cs-remote-spinner').removeClass('hidden');
      Craft.sendActionRequest('GET', 'calendar-sync/calendars/remote-calendars', {params: {connectionId}})
        .then(({data}) => {
          const calendars = data.calendars || [];
          this.$remote.empty();
          this.$remote.append($('<option/>', {
            value: '',
            text: calendars.length ? this.settings.messages.choose : this.settings.messages.noCalendars,
          }));
          let found = false;
          calendars.forEach((calendar) => {
            const notes = [];
            if (calendar.primary) notes.push(this.settings.messages.primary);
            if (calendar.readOnly) notes.push(this.settings.messages.readOnly);
            const $option = $('<option/>', {
              value: calendar.id,
              text: calendar.name + (notes.length ? ' (' + notes.join(', ') + ')' : ''),
            }).data('calendar', calendar);
            if (calendar.id === current) {
              $option.prop('selected', true);
              found = true;
            }
            this.$remote.append($option);
          });
          if (current && !found) {
            // the calendar that is saved is no longer listed: keep it selectable so nothing is lost by saving
            this.$remote.append($('<option/>', {value: current, text: $('#remoteName').val() || current, selected: true}));
          }
          this.remoteChosen(false);
        })
        .catch((error) => {
          const message = (error.response && error.response.data && error.response.data.message) || this.settings.messages.loadFailed;
          $error.text(message).removeClass('hidden');
        })
        .finally(() => $spinner.addClass('hidden'));
    }

    remoteChosen(byUser) {
      const calendar = this.$remote.find('option:selected').data('calendar');
      if (!calendar) {
        return;
      }
      $('#remoteName').val(calendar.name);
      $('#timezone').val(calendar.timezone || '');
      // the colour of the calendar at the provider is a suggestion: taken when a calendar is picked, never over
      // a colour that was chosen here
      const $color = $('#color');
      if (calendar.color && (byUser || !$color.val())) {
        $color.val(String(calendar.color).replace('#', '')).trigger('input').trigger('change');
      }
      const $name = $('#name');
      if (byUser && this.settings.isNew && !$name.val()) {
        $name.val(calendar.name).trigger('input');
      }
    }

    // ----- section, entry type, fields

    section() {
      const id = parseInt(this.$section.val(), 10);
      return this.sections.find((section) => section.id === id) || null;
    }

    entryType() {
      const section = this.section();
      const id = parseInt(this.$type.val(), 10);
      return (section && section.types.find((type) => type.id === id)) || null;
    }

    fillSections(selected) {
      this.$section.empty().append($('<option/>', {value: '', text: this.settings.messages.none}));
      this.sections.forEach((section) => {
        this.$section.append($('<option/>', {value: section.id, text: section.name}));
      });
      this.$section.val(selected ? String(selected) : '');
    }

    fillTypes(selected) {
      const section = this.section();
      this.$type.empty();
      if (!section) {
        return;
      }
      section.types.forEach((type) => {
        this.$type.append($('<option/>', {value: type.id, text: type.name}));
      });
      if (selected && section.types.some((type) => type.id === parseInt(selected, 10))) {
        this.$type.val(String(selected));
      }
    }

    /**
     * Offers for each attribute the fields that can hold it. `suggest` fills empty rows with a guess – after the
     * section or type was changed by hand, not when a saved mapping is shown.
     */
    fillMapping(suggest, values) {
      const type = this.entryType();
      const fields = type ? type.fields : [];
      const taken = {};

      this.$mapping.each((index, select) => {
        const $select = $(select);
        const attribute = $select.closest('tr').data('attribute');
        const kinds = this.settings.kinds[attribute] || [];
        const wanted = values ? values[attribute] || '' : suggest ? '' : $select.val() || $select.data('value') || '';
        const options = fields.filter((field) => {
          // the title of the entry is the title of the event and nothing else
          return field.handle === 'title' ? attribute === 'title' : kinds.indexOf(field.kind) !== -1;
        });

        $select.empty().append($('<option/>', {value: '', text: this.settings.messages.notMapped}));
        options.forEach((field) => {
          $select.append($('<option/>', {value: field.handle, text: field.name + (field.handle === 'title' ? '' : ' (' + field.handle + ')')}));
        });

        let value = options.some((field) => field.handle === wanted) ? wanted : '';
        if (!value && suggest) {
          const guess = options.find((field) => {
            if (taken[field.handle]) return false;
            if (attribute === 'title') return field.handle === 'title';
            return HINTS[attribute] && (HINTS[attribute].test(field.handle) || HINTS[attribute].test(field.name));
          });
          value = guess ? guess.handle : '';
        }
        if (value) {
          taken[value] = true;
        }
        $select.val(value);
      });
    }

    toggle() {
      const connection = (this.settings.connections || {})['c' + this.$connection.val()];
      const readOnly = !!connection && !connection.canWrite;
      this.$direction.find('option[value="export"], option[value="both"]').prop('disabled', readOnly);
      if (readOnly && this.$direction.val() !== 'import') {
        this.$direction.val('import');
      }
      $('#cs-direction-note').toggleClass('hidden', !readOnly || (!!connection && connection.busy));
      $('#cs-busy-note').toggleClass('hidden', !connection || !connection.busy);
      const direction = this.$direction.val();
      $('#cs-entries').toggleClass('hidden', !this.section());
      $('#cs-conflict').toggleClass('hidden', direction !== 'both');
      $('#cs-missing').toggleClass('hidden', direction === 'export');
      // an event that only goes to the calendar has nothing to read from it
      this.$form.find('.cs-mapping tr[data-read-only="1"]').toggleClass('hidden', direction === 'export');
    }

    // ----- the example model

    installBlueprint() {
      const $button = $('#cs-blueprint').addClass('disabled');
      const $spinner = $('#cs-blueprint-spinner').removeClass('hidden');
      Craft.sendActionRequest('POST', 'calendar-sync/calendars/install-blueprint')
        .then(({data}) => {
          this.sections = data.sections;
          this.fillSections(data.sectionId);
          this.fillTypes(data.entryTypeId);
          this.fillMapping(false, data.mapping);
          this.toggle();
          Craft.cp.displayNotice(data.message);
        })
        .catch((error) => {
          Craft.cp.displayError((error.response && error.response.data && error.response.data.message) || 'Error');
        })
        .finally(() => {
          $button.removeClass('disabled');
          $spinner.addClass('hidden');
        });
    }
  }

  window.CalendarSync.CalendarForm = CalendarForm;
})(jQuery);
