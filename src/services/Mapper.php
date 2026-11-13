<?php

namespace pixelwerft\calendarsync\services;

use Craft;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fields\Date;
use craft\fields\Email;
use craft\fields\Lightswitch;
use craft\fields\Link;
use craft\fields\PlainText;
use craft\models\EntryType;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use pixelwerft\calendarsync\CalendarSync;
use pixelwerft\calendarsync\helpers\Text;
use pixelwerft\calendarsync\models\Calendar;
use pixelwerft\calendarsync\models\Event;
use pixelwerft\calendarsync\models\EventData;
use yii\base\Component;

/**
 * Translates between an event and an entry, by the mapping of a calendar: which attribute of the event belongs to
 * which field of the entry.
 */
class Mapper extends Component
{
    public const KIND_TEXT = 'text';
    public const KIND_RICH = 'rich';
    public const KIND_DATE = 'date';
    public const KIND_BOOL = 'bool';
    public const KIND_URL = 'url';

    /** The native title of an entry as a mapping target. */
    public const TARGET_TITLE = 'title';

    /**
     * The kinds of field an attribute can be stored in.
     */
    public const KINDS = [
        'title' => [self::KIND_TEXT, self::KIND_RICH],
        'description' => [self::KIND_TEXT, self::KIND_RICH],
        'location' => [self::KIND_TEXT, self::KIND_RICH],
        'url' => [self::KIND_URL, self::KIND_TEXT],
        'start' => [self::KIND_DATE],
        'end' => [self::KIND_DATE],
        'allDay' => [self::KIND_BOOL],
        'status' => [self::KIND_TEXT],
        'organizer' => [self::KIND_TEXT],
    ];

    /**
     * The attributes with their labels, in the order the mapping shows them.
     *
     * @return array<string, array{label: string, instructions: string, readOnly: bool, required: bool}>
     */
    public function attributes(): array
    {
        return [
            'title' => ['label' => CalendarSync::t('Title'), 'instructions' => '', 'readOnly' => false, 'required' => false],
            'start' => ['label' => CalendarSync::t('Start'), 'instructions' => CalendarSync::t('A date field with a time.'), 'readOnly' => false, 'required' => true],
            'end' => ['label' => CalendarSync::t('End'), 'instructions' => CalendarSync::t('A date field with a time.'), 'readOnly' => false, 'required' => false],
            'allDay' => ['label' => CalendarSync::t('All day'), 'instructions' => CalendarSync::t('A lightswitch field.'), 'readOnly' => false, 'required' => false],
            'location' => ['label' => CalendarSync::t('Location'), 'instructions' => '', 'readOnly' => false, 'required' => false],
            'description' => ['label' => CalendarSync::t('Description'), 'instructions' => '', 'readOnly' => false, 'required' => false],
            'url' => ['label' => CalendarSync::t('Link'), 'instructions' => '', 'readOnly' => false, 'required' => false],
            'status' => ['label' => CalendarSync::t('Status'), 'instructions' => CalendarSync::t('confirmed, tentative or cancelled. Only read from the calendar.'), 'readOnly' => true, 'required' => false],
            'organizer' => ['label' => CalendarSync::t('Organizer'), 'instructions' => CalendarSync::t('Only read from the calendar.'), 'readOnly' => true, 'required' => false],
        ];
    }

    /**
     * What a field can hold, or null if the mapping has no use for it.
     */
    public function kind(FieldInterface $field): ?string
    {
        return match (true) {
            $field instanceof Date => self::KIND_DATE,
            $field instanceof Lightswitch => self::KIND_BOOL,
            $field instanceof Link => self::KIND_URL,
            $field instanceof PlainText, $field instanceof Email => self::KIND_TEXT,
            // rich text fields come from plugins that may not be installed
            in_array(get_class($field), ['craft\ckeditor\Field', 'craft\redactor\Field'], true) => self::KIND_RICH,
            default => null,
        };
    }

    /**
     * The fields of an entry type the mapping can use.
     *
     * @return array<int, array{handle: string, name: string, kind: string}>
     */
    public function fieldOptions(EntryType $entryType): array
    {
        $options = [];
        if ($entryType->hasTitleField) {
            $options[] = ['handle' => self::TARGET_TITLE, 'name' => Craft::t('app', 'Title'), 'kind' => self::KIND_TEXT];
        }
        foreach ($entryType->getFieldLayout()->getCustomFields() as $field) {
            $kind = $this->kind($field);
            if ($kind !== null && $field->handle) {
                $options[] = ['handle' => $field->handle, 'name' => (string)Craft::t('site', $field->name), 'kind' => $kind];
            }
        }

        return $options;
    }

    /**
     * The attributes an entry of this calendar writes to its event: the dates, the title, and whatever else is
     * mapped.
     *
     * @return string[]
     */
    public function writtenAttributes(Calendar $calendar): array
    {
        $attributes = ['title', 'start', 'end', 'allDay'];
        foreach (['description', 'location', 'url'] as $attribute) {
            if (!empty($calendar->mapping[$attribute])) {
                $attributes[] = $attribute;
            }
        }

        return $attributes;
    }

    /**
     * Writes an event into the mapped fields of an entry. The entry is not saved.
     */
    public function applyToEntry(Calendar $calendar, Entry $entry, EventData $data): void
    {
        $layout = $entry->getFieldLayout();
        $titleMapped = false;

        foreach ($calendar->mapping as $attribute => $target) {
            if (!isset(self::KINDS[$attribute]) || !$target) {
                continue;
            }
            if ($target === self::TARGET_TITLE) {
                $entry->title = $this->title($this->text($data, $attribute), $calendar);
                $titleMapped = true;
                continue;
            }
            $field = $layout?->getFieldByHandle($target);
            if ($field === null) {
                // the field left the layout since the mapping was made
                continue;
            }
            $entry->setFieldValue($target, $this->toFieldValue($field, $attribute, $data, $calendar));
        }

        if (!$titleMapped && (string)$entry->title === '') {
            $entry->title = $this->title($data->title, $calendar);
        }
    }

    /**
     * Reads the event an entry stands for from its mapped fields. Null if the entry has no start.
     *
     * @param Event|null $existing The event the entry is linked to. It decides what the mapping leaves open: whether
     * the event is all-day when no field says so, and how long it lasts when no field holds the end.
     */
    public function entryToEvent(Calendar $calendar, Entry $entry, ?Event $existing = null): ?EventData
    {
        $mapping = $calendar->mapping;
        $start = $this->fieldValue($entry, $mapping['start'] ?? null);
        if (!$start instanceof DateTimeInterface) {
            return null;
        }
        $end = $this->fieldValue($entry, $mapping['end'] ?? null);
        $end = $end instanceof DateTimeInterface ? $end : null;
        $allDay = !empty($mapping['allDay'])
            ? (bool)$this->fieldValue($entry, $mapping['allDay'])
            : ($existing !== null && $existing->allDay);

        $utc = new DateTimeZone('UTC');
        $site = new DateTimeZone(Craft::$app->getTimeZone());
        $data = new EventData();
        $data->allDay = $allDay;
        $data->timezone = $site->getName();

        if ($allDay) {
            // the days as the editor sees them
            $first = DateTimeImmutable::createFromInterface($start)->setTimezone($site)->format('Y-m-d');
            if ($end !== null) {
                $last = DateTimeImmutable::createFromInterface($end)->setTimezone($site)->format('Y-m-d');
            } elseif (empty($mapping['end']) && $existing?->allDay) {
                $days = (int)$existing->startDate->diff($existing->endDate)->days;
                $last = (new DateTimeImmutable($first, $utc))->modify("+$days days")->format('Y-m-d');
            } else {
                $last = $first;
            }
            $data->start = new DateTimeImmutable($first . ' 00:00:00', $utc);
            $data->end = (new DateTimeImmutable(max($first, $last) . ' 00:00:00', $utc))->modify('+1 day');
        } else {
            $data->start = DateTimeImmutable::createFromInterface($start)->setTimezone($utc);
            if ($end !== null) {
                $data->end = DateTimeImmutable::createFromInterface($end)->setTimezone($utc);
            } else {
                $minutes = empty($mapping['end']) && $existing && !$existing->allDay
                    ? $existing->getDuration()
                    : CalendarSync::$plugin->getSettings()->defaultEventDuration;
                $data->end = $data->start->modify(sprintf('+%d minutes', max(0, $minutes)));
            }
            if ($data->end < $data->start) {
                $data->end = $data->start;
            }
        }

        $data->title = $this->withoutPrefix((string)$entry->title, $calendar);
        foreach (['title', 'description', 'location', 'url'] as $attribute) {
            $target = $mapping[$attribute] ?? null;
            if (!$target) {
                continue;
            }
            $value = $target === self::TARGET_TITLE ? (string)$entry->title : trim((string)$this->fieldValue($entry, $target));
            if ($attribute === 'title') {
                $data->title = $this->withoutPrefix(Text::fromHtml($value), $calendar);
            } else {
                $data->$attribute = $value !== '' ? ($attribute === 'description' ? $value : Text::fromHtml($value)) : null;
            }
        }

        return $data;
    }

    /**
     * The fingerprint of what an entry would write to its event. Null if the entry has no start.
     */
    public function entryHash(Calendar $calendar, Entry $entry, ?Event $existing = null): ?string
    {
        return $this->entryToEvent($calendar, $entry, $existing)?->hash($this->writtenAttributes($calendar));
    }

    private function toFieldValue(FieldInterface $field, string $attribute, EventData $data, Calendar $calendar): mixed
    {
        $kind = $this->kind($field);

        if ($kind === self::KIND_DATE) {
            if (!in_array($attribute, ['start', 'end'], true)) {
                return null;
            }
            if ($data->allDay) {
                // the day itself, at midnight of the site; the last day of the event for the end
                $day = $attribute === 'start' ? $data->start : $data->end->modify('-1 day');

                return new DateTime($day->format('Y-m-d') . ' 00:00:00', new DateTimeZone(Craft::$app->getTimeZone()));
            }

            return DateTime::createFromImmutable($attribute === 'start' ? $data->start : $data->end);
        }

        if ($kind === self::KIND_BOOL) {
            return $attribute === 'allDay' ? $data->allDay : null;
        }

        $text = $this->text($data, $attribute);
        if ($kind === self::KIND_URL) {
            return $text !== '' ? $text : null;
        }
        // How the description is formatted is the choice of the calendar – as long as nothing is written back:
        // a calendar that syncs both ways gets its text as it is, so nothing is lost on the way home.
        $format = $attribute === 'description' && !$calendar->exports();
        $lineBreaks = !$format || $calendar->descriptionLineBreaks;
        $documents = $format && $calendar->descriptionDocuments ? $data->attachments : [];
        if ($kind === self::KIND_RICH) {
            return Text::toHtml($text, $lineBreaks) . Text::documentList($documents);
        }

        // plain text; a single line field cannot hold line breaks
        $text = $lineBreaks ? Text::fromHtml($text) : Text::singleLine($text);
        if ($documents) {
            $text = trim($text . "\n\n" . Text::documentLines($documents));
        }
        if ($field instanceof PlainText && !$field->multiline) {
            $text = (string)preg_replace('~\s*\n\s*~', ' ', $text);
        }

        return $text;
    }

    private function text(EventData $data, string $attribute): string
    {
        return match ($attribute) {
            'title' => $data->title,
            'description' => (string)$data->description,
            'location' => (string)$data->location,
            'url' => (string)$data->url,
            'status' => $data->status,
            'organizer' => (string)$data->organizer,
            default => '',
        };
    }

    private function title(string $title, Calendar $calendar): string
    {
        $title = trim(Text::fromHtml($title));
        $title = $title !== '' ? $title : CalendarSync::t('(No title)');
        $prefix = trim((string)$calendar->entryPrefix);

        return mb_substr($prefix !== '' ? $prefix . ' ' . $title : $title, 0, 255);
    }

    /**
     * The title of an entry as it goes to the calendar: without the prefix the calendar puts in front of it.
     */
    private function withoutPrefix(string $title, Calendar $calendar): string
    {
        // also a prefix the calendar used before: entries may still carry it until they are written again
        foreach (array_merge([(string)$calendar->entryPrefix], $calendar->retiredPrefixes) as $prefix) {
            $prefix = trim($prefix);
            if ($prefix !== '' && str_starts_with($title, $prefix)) {
                return ltrim(mb_substr($title, mb_strlen($prefix)));
            }
        }

        return $title;
    }

    private function fieldValue(Entry $entry, ?string $handle): mixed
    {
        if (!$handle || $handle === self::TARGET_TITLE) {
            return $handle ? $entry->title : null;
        }
        if ($entry->getFieldLayout()?->getFieldByHandle($handle) === null) {
            return null;
        }

        return $entry->getFieldValue($handle);
    }
}
