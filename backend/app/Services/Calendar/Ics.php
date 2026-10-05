<?php

namespace App\Services\Calendar;

/**
 * A calendar's items as an iCalendar file (RFC 5545) to import into Google
 * or Outlook - docs/specs/calendar-spec.md, C3. All-day items are dates;
 * timed ones are floating local times, as the calendar shows them.
 */
final class Ics
{
    /** @param  array<int, array>  $items  occurrences (Calendar::occurrences) */
    public static function build(array $items, string $name): string
    {
        $stamp = gmdate('Ymd\THis\Z');
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Makueni West Diocese//Calendar//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:'.self::text($name)];
        foreach ($items as $o) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.preg_replace('/[^A-Za-z0-9\-]/', '-', ($o['source'] ?? 'calendar').'-'.$o['key']).'@makueniwest';
            $lines[] = "DTSTAMP:{$stamp}";
            if ($o['all_day']) {
                $lines[] = 'DTSTART;VALUE=DATE:'.self::date($o['start']);
                // The calendar's all-day end is already the day after, as iCalendar wants.
                $lines[] = 'DTEND;VALUE=DATE:'.self::date($o['end']);
            } else {
                $lines[] = 'DTSTART:'.self::dateTime($o['start']);
                $lines[] = 'DTEND:'.self::dateTime($o['end'] ?: $o['start']);
            }
            $lines[] = 'SUMMARY:'.self::text($o['title']);
            $about = array_filter([$o['description'] ?? null, $o['owner']['name'] ?? null]);
            if ($about) {
                $lines[] = 'DESCRIPTION:'.self::text(implode(' · ', $about));
            }
            if (! empty($o['location'])) {
                $lines[] = 'LOCATION:'.self::text($o['location']);
            }
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([self::class, 'fold'], $lines))."\r\n";
    }

    private static function date(string $value): string
    {
        return str_replace('-', '', substr($value, 0, 10));
    }

    private static function dateTime(string $value): string
    {
        $time = strlen($value) > 10 ? str_replace(':', '', substr($value, 11, 5)) : '0000';

        return self::date($value).'T'.$time.'00';
    }

    /** Escape text values: backslash, semicolon, comma and new lines. */
    private static function text(string $value): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\\,', '\\n', '\\n'], $value);
    }

    /** Lines longer than 75 octets continue on the next line after a space, never splitting a UTF-8 character. */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = [];
        $current = '';
        foreach (mb_str_split($line) as $char) {
            if (strlen($current) + strlen($char) > ($out ? 74 : 75)) {
                $out[] = $current;
                $current = '';
            }
            $current .= $char;
        }
        $out[] = $current;

        return implode("\r\n ", $out);
    }
}
