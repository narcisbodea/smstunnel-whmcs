<?php
/**
 * Texts: merge fields, SMS part counts, the suggested texts.
 *
 * @license MIT
 */

use SmsTunnel\Whmcs\Admin\Page;
use SmsTunnel\Whmcs\Text\Events;
use SmsTunnel\Whmcs\Text\SmsCounter;
use SmsTunnel\Whmcs\Text\Template;

group('texts');

test('merge fields are filled and tidied', function () {
    $values = array('client_first_name' => 'Ana', 'invoice_num' => '1042', 'company_name' => 'Ana &amp; Co', 'service_domain' => '');
    eq(Template::render('Hi {client_first_name}, invoice #{invoice_num}. {company_name}', $values), 'Hi Ana, invoice #1042. Ana & Co');
    eq(Template::render('Hi {$client_first_name}!', $values), 'Hi Ana!', 'the {$field} form of WHMCS emails works too');
    eq(Template::render('Tom &amp; Co: {client_first_name}', $values), 'Tom & Co: Ana', 'a text saved HTML-encoded reads as typed');
    eq(Template::render('Your {service_domain} is active.', $values), 'Your is active.', 'an empty field leaves no double space');
    eq(Template::render('Hi {missing_field}, {client_first_name}', $values), 'Hi {missing_field}, Ana', 'an unknown field stays as typed');
    eq(Template::render("Line one\r\n\r\n\r\n\r\nLine   two\t\tend", $values), "Line one\n\nLine two end");
    eq(Template::render('Hi {client_first_name} , thanks', array('client_first_name' => '')), 'Hi, thanks', 'an empty name before a comma');
    eq(Template::render('{x}', array('x' => "Tab\there\nnew\x07line")), 'Tab here new line', 'control characters become spaces');
    eq(Template::render('{x}', array('x' => "25\xC2\xA0USD")), '25 USD', 'a no-break space becomes a plain space');
    eq(Template::render('{x}', array('x' => '<b>Bold</b> &lt;3 a < b')), 'Bold <3 a < b', 'tags stripped, entities decoded, other < kept');
    eq(Template::render('{x}', array('x' => '<script>alert(1)</script>Hi<br/>there')), 'Hi there', 'scripts dropped whole');
    eq(Template::length(Template::render(str_repeat('x', 2000), array())), 1600, 'cut at 1600 characters');
    $subject = Template::render('{ticket_subject}', array('ticket_subject' => str_repeat('Long subject ', 10)));
    ok(Template::length($subject) <= 60, 'a field is cut at 60 characters');
    eq($subject, 'Long subject Long subject Long subject Long subject Long...', 'on a whole word, with three dots');
    eq(Template::render('About: {s}.', array('s' => str_repeat('word ', 20))), 'About: word word word word word word word word word word word...', 'no fourth dot');
    eq(Template::value(str_repeat('x', 80)), str_repeat('x', 57) . '...', 'one long word is cut where it must');
    eq(Template::render('{invoice_url}', array('invoice_url' => 'https://billing.example.com/viewinvoice.php?id=' . str_repeat('1', 70))), 'https://billing.example.com/viewinvoice.php?id=' . str_repeat('1', 70), 'links are never cut');
    eq(Template::fieldsIn('{a} {$B} {a} {c_d}'), array('a', 'b', 'c_d'));
    eq(Template::unknownFields('{a} {b} {c}', array('a', 'c')), array('b'));
});

test('SMS parts: GSM 160/153, Unicode 70/67, two-place characters (shared vectors with counter.js)', function () {
    $vectors = json_decode((string) file_get_contents(__DIR__ . '/sms-vectors.json'), true);
    ok(is_array($vectors) && count($vectors) > 10, 'vectors loaded');
    foreach ($vectors as $vector) {
        $text = '';
        foreach ($vector['build'] as $piece) {
            $text .= str_repeat($piece[0], $piece[1]);
        }
        $count = SmsCounter::count($text);
        eq(array($count['encoding'], $count['units'], $count['parts']), array($vector['encoding'], $vector['units'], $vector['parts']), $vector['name']);
    }
    $count = SmsCounter::count('Plătit 👍');
    eq($count['nonGsm'], array('ă', '👍'), 'the characters that make a text Unicode');
    eq(SmsCounter::count(str_repeat('a', 200))['perPart'], 153);
    eq(SmsCounter::count('a')['perPart'], 160);
    eq(Page::countLine(''), 'Empty: nothing is sent.');
    eq(Page::countLine('Hi Ana'), '1 SMS · 6 characters · GSM 7-bit (160 per SMS)');
    eq(Page::countLine(str_repeat('ă', 71)), '2 SMS · 71 characters · Unicode (67 per part) · Unicode because of: ă');
});

test('every suggested text is one GSM 7-bit SMS with example values, and uses only its own fields', function () {
    foreach (Events::keys() as $event) {
        $text = Events::defaultText($event);
        eq(Template::unknownFields($text, Events::fields($event)), array(), $event . ' uses only its fields');
        $rendered = Template::render($text, Events::samples($event));
        $count = SmsCounter::count($rendered);
        eq($count['encoding'], 'GSM-7', $event . ' stays in the GSM alphabet');
        eq($count['parts'], 1, $event . ' is one SMS (' . $count['units'] . ' characters)');
        lacks($rendered, '{', $event . ' has no field left');
        foreach (array('gateway', 'bulk', 'campaign', 'marketing', 'mass') as $word) {
            lacks(strtolower($text), $word, $event . ' wording');
        }
    }
    eq(count(Events::forAudience(Events::CLIENT)), 10, 'client texts');
    eq(count(Events::forAudience(Events::STAFF)), 3, 'staff alerts');
    foreach (Events::keys() as $event) {
        foreach (Events::fields($event) as $field) {
            ok(isset(Events::SAMPLES[$field]) && isset(Events::FIELD_HELP[$field]), $event . ': ' . $field . ' has an example and a description');
        }
    }
});
