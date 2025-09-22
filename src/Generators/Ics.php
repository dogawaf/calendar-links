<?php

namespace Spatie\CalendarLinks\Generators;

use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\TimezoneGuesser\GuessFromMsTzId;
use Spatie\CalendarLinks\Generator;
use Spatie\CalendarLinks\Link;

/**
 * @see https://icalendar.org/RFC-Specifications/iCalendar-RFC-5545/
 * @see https://sabre.io/vobject/icalendar/
 * @psalm-type IcsOptions = array{UID?: string, URL?: string, REMINDER?: array{DESCRIPTION?: string, TIME?: \DateTimeInterface}}
 */
class Ics implements Generator
{
    public const FORMAT_HTML = 'html';
    public const FORMAT_FILE = 'file';

    /** @psalm-var IcsOptions */
    protected array $options = [];

    /** @var array{format?: self::FORMAT_*} */
    protected array $presentationOptions = [];

    /**
     * @param  IcsOptions  $options  Optional ICS properties and components
     * @param  array{format?: self::FORMAT_*}  $presentationOptions
     */
    public function __construct(array $options = [], array $presentationOptions = [])
    {
        $this->options = $options;
        $this->presentationOptions = $presentationOptions;
    }

    /** {@inheritDoc} */
    public function generate(Link $link): string
    {
        $vcalendar = new VCalendar();

        // Remove automatic PRODID as it can fail tests when the version changes,
        // and re-add it with a fixed value
        $vcalendar->remove('PRODID');
        $vcalendar->add('PRODID', 'Spatie calendar-links');

        $vevent = $vcalendar->createComponent('VEVENT', [
            'UID' => $this->options['UID'] ?? $this->generateEventUid($link),
            'SUMMARY' => $link->title,
            'DTSTAMP' => $link->from,
        ]);
        $vcalendar->add($vevent);

        $timeZones = [];
        if ($link->allDay) {
            $vevent->add('DTSTART', $link->from);
            $vevent->add('DURATION', 'P'.(max(1, $link->from->diff($link->to)->days)).'D');
            $timeZones[$link->from->getTimezone()->getName()] = $link->from->getTimezone();
        } else {
            $vevent->add('DTSTART', $link->from);
            $timeZones[$link->from->getTimezone()->getName()] = $link->from->getTimezone();
            $vevent->add('DTEND', $link->to);
            $timeZones[$link->to->getTimezone()->getName()] = $link->to->getTimezone();
        }

        if ($link->description) {
            $vevent->add('DESCRIPTION', strip_tags($link->description));
        }

        if ($link->address) {
            $vevent->add('LOCATION', $link->address);
        }

        if (isset($this->options['URL'])) {
            $vevent->add('URL', $this->options['URL'], ['VALUE' => 'URI']);
        }

        if (is_array($this->options['REMINDER'] ?? null)) {
            $vevent->add($this->generateAlertComponent($vcalendar, $link));
        }

        $this->addVTimezoneComponents($vcalendar, $timeZones, $link->from, $link->to);

        $format = $this->presentationOptions['format'] ?? self::FORMAT_HTML;

        return match ($format) {
            'file' => $this->buildFile($vcalendar),
            default => $this->buildLink($vcalendar),
        };
    }

    /**
     * @param  VCalendar  $vcalendar
     * @param  array<\DateTimeZone>  $timeZones
     * @param  \DateTimeInterface  $from
     * @param  \DateTimeInterface  $to
     * @return void
     */
    private function addVTimezoneComponents(VCalendar $vcalendar, array $timeZones, \DateTimeInterface $from, \DateTimeInterface $to): void
    {
        foreach ($timeZones as $timeZone) {
            if ($timeZone->getName() === 'UTC') {
                continue;
            }

            $vcalendar->add(
                $this->generateVTimeZoneComponent(
                    $vcalendar,
                    $timeZone,
                    $from->getTimestamp(),
                    $to->getTimestamp()
                )
            );
        }
    }

    /**
     * Returns a VTIMEZONE component for an Olson timezone identifier
     * with daylight transitions covering the given date range.
     *
     * Kindly inspired from https://gist.github.com/thomascube/47ff7d530244c669825736b10877a200
     * and https://stackoverflow.com/a/25971680
     *
     * @param  VCalendar  $vcalendar
     * @param  \DateTimeZone  $timeZone  Timezone
     * @param  int  $from  Unix timestamp with first date/time in this timezone
     * @param  int  $to  Unix timestap with last date/time in this timezone
     *
     * @return Component A Sabre\VObject\Component object representing a VTIMEZONE definition
     */
    private function generateVTimeZoneComponent(
        VCalendar $vcalendar,
        \DateTimeZone $timeZone,
        int $from = 0,
        int $to = 0
    ): Component {
        if ($from === 0) {
            $from = time();
        }
        if ($to === 0) {
            $to = $from;
        }

        // get all transitions for one year back/ahead
        $year = 86400 * 360;
        $transitions = $timeZone->getTransitions($from - $year, $to + $year);

        $vTimeZone = $vcalendar->createComponent('VTIMEZONE');
        $vTimeZone->add('TZID', $timeZone->getName());

        $std = null;
        $dst = null;
        $tzfrom = 0;
        $t_dst = 0;
        $t_std = 0;
        foreach ($transitions as $i => $trans) {
            if ($i === 0) {
                // remember the offset for the next TZOFFSETFROM value
                $tzfrom = $trans['offset'] / 3600;
            }

            if ($trans['isdst']) {
                // daylight saving time definition
                $t_dst = $trans['ts'];
                $dst = $vcalendar->createComponent('DAYLIGHT');
                $component = $dst;
            } else {
                // standard time definition
                $t_std = $trans['ts'];
                $std = $vcalendar->createComponent('STANDARD');
                $component = $std;
            }

            $dt = new \DateTime($trans['time']);
            $offset = $trans['offset'] / 3600;

            $component->add('DTSTART', $dt->format('Ymd\THis'));
            $component->add('TZOFFSETFROM', sprintf(
                '%s%02d%02d',
                $tzfrom >= 0 ? '+' : '',
                floor($tzfrom),
                ($tzfrom - floor($tzfrom)) * 60
            ));
            $component->add('TZOFFSETTO', sprintf(
                '%s%02d%02d',
                $offset >= 0 ? '+' : '',
                floor($offset),
                ($offset - floor($offset)) * 60
            ));

            // add abbreviated timezone name if available
            $component->add('TZNAME', $trans['abbr']);

            $tzfrom = $offset;
            $vTimeZone->add($component);

            // we covered the entire date range
            if ($std && $dst && min($t_std, $t_dst) < $from && max($t_std, $t_dst) > $to) {
                break;
            }
        }

        // add X-MICROSOFT-CDO-TZID if available
        $microsoftExchangeMap = array_flip(GuessFromMsTzId::$microsoftExchangeMap);
        if (array_key_exists($timeZone->getName(), $microsoftExchangeMap)) {
            $vTimeZone->add('X-MICROSOFT-CDO-TZID', $microsoftExchangeMap[$timeZone->getName()]);
        }

        return $vTimeZone;
    }

    private function buildLink(VCalendar $vcalendar): string
    {
        return 'data:text/calendar;charset=utf8;base64,'.base64_encode($vcalendar->serialize());
    }

    private function buildFile(VCalendar $vcalendar): string
    {
        return $vcalendar->serialize();
    }

    /** @see https://tools.ietf.org/html/rfc5545#section-3.8.4.7 */
    private function generateEventUid(Link $link): string
    {
        return md5(sprintf(
            '%s%s%s%s',
            $link->from->format(\DateTimeInterface::ATOM),
            $link->to->format(\DateTimeInterface::ATOM),
            $link->title,
            $link->address
        ));
    }

    private function generateAlertComponent(VCalendar $vcalendar, Link $link): Component
    {
        $description = $this->options['REMINDER']['DESCRIPTION'] ?? null;
        if (! is_string($description)) {
            $description = 'Reminder: '.$link->title;
        }

        $trigger = '-PT15M';
        $triggerParameters = [];
        if (($reminderTime = $this->options['REMINDER']['TIME'] ?? null) instanceof \DateTimeInterface) {
            $trigger = $reminderTime;
            $triggerParameters = ['VALUE' => 'DATE-TIME'];
        }

        $valarm = $vcalendar->createComponent('VALARM', [
            'ACTION' => 'DISPLAY',
            'DESCRIPTION' => $description,
        ]);
        $valarm->add('TRIGGER', $trigger, $triggerParameters);

        return $valarm;
    }
}
