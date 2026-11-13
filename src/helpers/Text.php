<?php

namespace pixelwerft\calendarsync\helpers;

/**
 * Text between rich text fields and calendars that only know plain text.
 */
abstract class Text
{
    /**
     * Whether a text holds HTML tags. «I <3 calendars» does not.
     */
    public static function isHtml(string $text): bool
    {
        return (bool)preg_match('~</?[a-z][a-z0-9]*(\s[^<>]*)?/?>~i', $text);
    }

    /**
     * HTML as readable text: paragraphs and line breaks become new lines, the tags go.
     */
    public static function fromHtml(string $html): string
    {
        if (!self::isHtml($html)) {
            return trim($html);
        }
        $text = (string)preg_replace('~<br\s*/?>~i', "\n", $html);
        $text = (string)preg_replace('~</(p|div|h[1-6]|li|tr|blockquote)>~i', "\n", $text);
        $text = (string)preg_replace('~<li[^>]*>~i', '- ', $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{a0}", ' ', $text);
        $text = (string)preg_replace('~[ \t]+\n~', "\n", $text);
        $text = (string)preg_replace('~\n{3,}~', "\n\n", $text);

        return trim($text);
    }

    /**
     * Text as HTML for a rich text field: paragraphs for blank lines, line breaks inside. HTML stays as it is.
     *
     * @param bool $lineBreaks False = one paragraph of running text, whatever the source was
     */
    public static function toHtml(string $text, bool $lineBreaks = true): string
    {
        if (!$lineBreaks) {
            $line = self::singleLine($text);

            return $line !== '' ? '<p>' . htmlspecialchars($line, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>' : '';
        }
        if ($text === '' || self::isHtml($text)) {
            return $text;
        }
        $paragraphs = preg_split('~\n{2,}~', str_replace(["\r\n", "\r"], "\n", trim($text))) ?: [];

        return implode('', array_map(
            static fn(string $paragraph) => '<p>' . str_replace("\n", '<br>', htmlspecialchars($paragraph, ENT_QUOTES | ENT_HTML5, 'UTF-8')) . '</p>',
            $paragraphs,
        ));
    }

    /**
     * Text or HTML as one line of running text.
     */
    public static function singleLine(string $text): string
    {
        return trim((string)preg_replace('~\s+~u', ' ', self::fromHtml($text)));
    }

    /**
     * Turns the web addresses in HTML into links. What already is a link, and everything inside a tag, is left
     * alone. The HTML has to be clean already.
     */
    public static function linkify(string $html): string
    {
        $parts = preg_split('~(<[^>]+>)~', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $anchors = 0;
        $result = '';
        foreach ($parts as $index => $part) {
            if ($index % 2 === 1) {
                if (preg_match('~^<a[\s>]~i', $part)) {
                    $anchors++;
                } elseif (preg_match('~^</a\s*>~i', $part)) {
                    $anchors = max(0, $anchors - 1);
                }
                $result .= $part;
                continue;
            }
            $result .= $anchors > 0 ? $part : (string)preg_replace(
                '~\bhttps?://[^\s<>"\']+[^\s<>"\'.,;:!?)\]]~i',
                '<a href="$0" rel="noopener">$0</a>',
                $part,
            );
        }

        return $result;
    }

    /**
     * Documents attached to an event as a list of links.
     *
     * @param array<int, array{title: string, url: string}> $documents
     */
    public static function documentList(array $documents): string
    {
        $items = '';
        foreach ($documents as $document) {
            if (!preg_match('~^https?://~i', $document['url'])) {
                continue;
            }
            $items .= sprintf(
                '<li><a href="%s" rel="noopener">%s</a></li>',
                htmlspecialchars($document['url'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                htmlspecialchars($document['title'] !== '' ? $document['title'] : $document['url'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            );
        }

        return $items !== '' ? '<ul class="event-documents">' . $items . '</ul>' : '';
    }

    /**
     * Documents attached to an event as lines of text: «Title: address».
     *
     * @param array<int, array{title: string, url: string}> $documents
     */
    public static function documentLines(array $documents): string
    {
        $lines = [];
        foreach ($documents as $document) {
            $lines[] = $document['title'] !== '' && $document['title'] !== $document['url']
                ? $document['title'] . ': ' . $document['url']
                : $document['url'];
        }

        return implode("\n", $lines);
    }

    /**
     * A text cut to the length of a column, by characters.
     */
    public static function limit(?string $text, int $length): ?string
    {
        return $text === null ? null : mb_substr($text, 0, $length);
    }
}
