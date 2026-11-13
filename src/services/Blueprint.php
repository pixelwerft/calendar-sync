<?php

namespace pixelwerft\calendarsync\services;

use Craft;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\Date;
use craft\fields\Lightswitch;
use craft\fields\Link;
use craft\fields\PlainText;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use pixelwerft\calendarsync\CalendarSync;
use yii\base\Component;
use yii\base\Exception;

/**
 * The example model: a section «Calendar events» with an entry type and the six fields an event needs, and the
 * mapping that goes with it. For sites that have no place for events yet – and as a pattern for those that do.
 */
class Blueprint extends Component
{
    public const SECTION_HANDLE = 'calendarEvents';
    public const ENTRY_TYPE_HANDLE = 'calendarEvent';

    /**
     * Fields and sections are project config: they can only be created where admin changes are allowed.
     */
    public function canInstall(): bool
    {
        return Craft::$app->getConfig()->getGeneral()->allowAdminChanges;
    }

    public function getSection(): ?Section
    {
        return Craft::$app->getEntries()->getSectionByHandle(self::SECTION_HANDLE);
    }

    /**
     * The mapping of the example model: attribute of the event => handle of the field.
     *
     * @return array<string, string>
     */
    public function mapping(): array
    {
        return [
            'title' => Mapper::TARGET_TITLE,
            'start' => 'eventStart',
            'end' => 'eventEnd',
            'allDay' => 'eventAllDay',
            'location' => 'eventLocation',
            'description' => 'eventDescription',
            'url' => 'eventLink',
        ];
    }

    /**
     * What the example model consists of, for whoever wants to know before creating it.
     *
     * @return array<int, array{kind: string, name: string, handle: string, type: string, attribute: ?string}>
     */
    public function describe(): array
    {
        $attributes = array_flip($this->mapping());
        $parts = [
            ['kind' => 'section', 'name' => CalendarSync::t('Calendar events'), 'handle' => self::SECTION_HANDLE, 'type' => Craft::t('app', 'Channel'), 'attribute' => null],
            ['kind' => 'entryType', 'name' => CalendarSync::t('Calendar event'), 'handle' => self::ENTRY_TYPE_HANDLE, 'type' => '', 'attribute' => null],
        ];
        foreach ($this->fieldDefinitions() as [$handle, $name, $type]) {
            $parts[] = ['kind' => 'field', 'name' => $name, 'handle' => $handle, 'type' => $type::displayName(), 'attribute' => $attributes[$handle] ?? null];
        }

        return $parts;
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: class-string<FieldInterface>, 3: array<string, mixed>}>
     */
    private function fieldDefinitions(): array
    {
        return [
            ['eventStart', CalendarSync::t('Start'), Date::class, ['showDate' => true, 'showTime' => true]],
            ['eventEnd', CalendarSync::t('End'), Date::class, ['showDate' => true, 'showTime' => true]],
            ['eventAllDay', CalendarSync::t('All day'), Lightswitch::class, ['default' => false]],
            ['eventLocation', CalendarSync::t('Location'), PlainText::class, []],
            ['eventDescription', CalendarSync::t('Description'), PlainText::class, ['multiline' => true, 'initialRows' => 6]],
            ['eventLink', CalendarSync::t('Link'), Link::class, []],
        ];
    }

    /**
     * Creates the section, the entry type and the fields – whatever of them is not there yet.
     *
     * @return array{section: Section, entryType: EntryType, mapping: array<string, string>}
     * @throws Exception
     */
    public function install(): array
    {
        if (!$this->canInstall()) {
            throw new Exception(CalendarSync::t('Sections and fields can only be created where admin changes are allowed.'));
        }

        $entries = Craft::$app->getEntries();
        $section = $this->getSection();
        if ($section !== null) {
            $entryType = $section->getEntryTypes()[0] ?? null;
            if ($entryType === null) {
                throw new Exception(CalendarSync::t('The section «{name}» has no entry type.', ['name' => $section->name]));
            }

            return ['section' => $section, 'entryType' => $entryType, 'mapping' => $this->mapping()];
        }

        $fields = array_map(
            fn(array $definition) => $this->field($definition[0], $definition[1], $definition[2], $definition[3]),
            $this->fieldDefinitions(),
        );

        $entryType = $entries->getEntryTypeByHandle(self::ENTRY_TYPE_HANDLE);
        if ($entryType === null) {
            $entryType = new EntryType([
                'name' => CalendarSync::t('Calendar event'),
                'handle' => self::ENTRY_TYPE_HANDLE,
                'hasTitleField' => true,
                'icon' => 'calendar',
            ]);
            $layout = new FieldLayout(['type' => Entry::class]);
            $tab = new FieldLayoutTab(['layout' => $layout, 'name' => Craft::t('app', 'Content')]);
            $tab->setElements(array_merge(
                [new EntryTitleField()],
                array_map(static fn(FieldInterface $field) => new CustomField($field), $fields),
            ));
            $layout->setTabs([$tab]);
            $entryType->setFieldLayout($layout);
            if (!$entries->saveEntryType($entryType)) {
                throw new Exception(implode(' ', $entryType->getFirstErrors()));
            }
        }

        $siteSettings = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteSettings[$site->id] = new Section_SiteSettings([
                'siteId' => $site->id,
                'enabledByDefault' => true,
                'hasUrls' => false,
            ]);
        }
        $section = new Section([
            'name' => CalendarSync::t('Calendar events'),
            'handle' => self::SECTION_HANDLE,
            'type' => Section::TYPE_CHANNEL,
            'enableVersioning' => true,
        ]);
        $section->setEntryTypes([$entryType]);
        $section->setSiteSettings($siteSettings);
        if (!$entries->saveSection($section)) {
            throw new Exception(implode(' ', $section->getFirstErrors()));
        }

        return ['section' => $section, 'entryType' => $entryType, 'mapping' => $this->mapping()];
    }

    /**
     * The field with this handle, created if the site has none. A field of another type under the same handle
     * is not touched – the example model then cannot be created.
     *
     * @param class-string<FieldInterface> $type
     * @param array<string, mixed> $settings
     * @throws Exception
     */
    private function field(string $handle, string $name, string $type, array $settings): FieldInterface
    {
        $fields = Craft::$app->getFields();
        $field = $fields->getFieldByHandle($handle);
        if ($field !== null) {
            if (!$field instanceof $type) {
                throw new Exception(CalendarSync::t('There already is a field «{handle}» of another type.', ['handle' => $handle]));
            }

            return $field;
        }

        $field = $fields->createField(['type' => $type, 'name' => $name, 'handle' => $handle] + $settings);
        if (!$fields->saveField($field)) {
            throw new Exception(implode(' ', $field->getFirstErrors()));
        }

        return $field;
    }
}
