<?php

namespace pixelwerft\calendarsync\tests\unit;

use PHPUnit\Framework\TestCase;
use pixelwerft\calendarsync\helpers\Text;

class TextTest extends TestCase
{
    public function testHtmlBecomesReadableText(): void
    {
        $html = '<p>First &amp; foremost</p><p>Second<br>line</p><ul><li>One</li><li>Two</li></ul>';

        $this->assertSame("First & foremost\nSecond\nline\n- One\n- Two", Text::fromHtml($html));
    }

    public function testPlainTextStaysAsItIs(): void
    {
        $this->assertSame("Line one\nLine two", Text::fromHtml("Line one\nLine two"));
    }

    public function testTextBecomesParagraphs(): void
    {
        $this->assertSame('<p>One<br>two</p><p>Three &lt;3</p>', Text::toHtml("One\ntwo\n\nThree <3"));
    }

    public function testALessThanSignIsNoHtml(): void
    {
        $this->assertFalse(Text::isHtml('I <3 calendars, 2 < 3'));
        $this->assertTrue(Text::isHtml('Line<br>break'));
        $this->assertSame('I <3 calendars', Text::fromHtml('I <3 calendars'));
        $this->assertSame('AT&amp;T', Text::fromHtml('AT&amp;T'));
    }

    public function testHtmlIsNotWrappedAgain(): void
    {
        $this->assertSame('<p>Already</p>', Text::toHtml('<p>Already</p>'));
    }

    public function testLimitCountsCharacters(): void
    {
        $this->assertSame('Zür', Text::limit('Zürich', 3));
        $this->assertNull(Text::limit(null, 3));
    }

    public function testWithoutLineBreaksTextBecomesOneParagraph(): void
    {
        $this->assertSame('<p>First line second line Third &amp; last</p>', Text::toHtml("First line\nsecond line\n\nThird & last", false));
        $this->assertSame('<p>One Two</p>', Text::toHtml('<p>One</p><p>Two</p>', false));
        $this->assertSame('', Text::toHtml('', false));
        $this->assertSame('One - Two three', Text::singleLine("<p>One</p><ul><li>Two</li></ul>\n three"));
    }

    public function testAddressesBecomeLinks(): void
    {
        $this->assertSame(
            '<p>See <a href="https://example.org/a?b=1&amp;c=2" rel="noopener">https://example.org/a?b=1&amp;c=2</a>.</p>',
            Text::linkify('<p>See https://example.org/a?b=1&amp;c=2.</p>'),
        );
    }

    public function testWhatAlreadyIsALinkStaysAsItIs(): void
    {
        $html = '<p><a href="https://example.org">https://example.org</a> and <img src="https://example.org/a.png" alt=""></p>';
        $this->assertSame($html, Text::linkify($html));
        $this->assertSame('<p>No address here, only example.org</p>', Text::linkify('<p>No address here, only example.org</p>'));
    }

    public function testDocumentsBecomeAListOfLinks(): void
    {
        $documents = [
            ['title' => 'Agenda <draft>', 'url' => 'https://drive.google.com/file/d/abc/view?x=1&y=2'],
            ['title' => 'Not a web address', 'url' => 'javascript:alert(1)'],
        ];
        $this->assertSame(
            '<ul class="event-documents"><li><a href="https://drive.google.com/file/d/abc/view?x=1&amp;y=2" rel="noopener">Agenda &lt;draft&gt;</a></li></ul>',
            Text::documentList($documents),
        );
        $this->assertSame('', Text::documentList([]));
        $this->assertSame("Agenda: https://example.org/a\nhttps://example.org/b", Text::documentLines([
            ['title' => 'Agenda', 'url' => 'https://example.org/a'],
            ['title' => 'https://example.org/b', 'url' => 'https://example.org/b'],
        ]));
    }
}
