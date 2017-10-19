<?php

namespace Casebox\CoreBundle\Service\Util;

use Casebox\CoreBundle\Service\Cache;
use Casebox\CoreBundle\Service\DataModel as DM;
use Casebox\CoreBundle\Service\Sorter;
use Symfony\Component\HttpFoundation\Session\Session;

function coalesce()
{
    $args = func_get_args();
    foreach ($args as $a) {
        if (!empty($a)) {
            return $a;
        }
    }

    return '';
}

/**
 * @param string $mysqlTime
 *
 * @return string
 */
function formatPastTime($mysqlTime)
{
    if (empty($mysqlTime)) {
        return '';
    }

    $translator = Cache::get('symfony.container')->get('translator');

    $time = strtotime($mysqlTime);

    $time__ = date('j n Y', $time);

    if ($time__ == date('j n Y', time())) {
        return $translator->trans('todayAt').' '.date('H:i', $time);
    } elseif ($time__ == date('j n Y', time() - 3600 * 24)) {
        return $translator->trans('yesterdayAt').' '.date('H:i', $time);
    } elseif ($time__ == date('j n Y', time() - 3600 * 24 * 2)) {
        return $translator->trans('beforeYesterdayAt').' '.date('H:i', $time);
    } else {
        return translateMonths(date('j M Y', $time).' '.$translator->trans('at').' '.date(' H:i', $time));
    }
}

/**
 * @param string $mysqlDate
 *
 * @return string
 */
function formatAgoDate($mysqlDate)
{
    if (empty($mysqlDate)) {
        return '';
    }
    /*
    same day: today
    privous day: yesterday
    same week: Tuesday
    same year: November 8
    else: 2011, august 5
     */

    $translator = Cache::get('symfony.container')->get('translator');

    $TODAY_START = strtotime('today');
    $YESTERDAY_START = strtotime('yesterday');
    $WEEK_START = strtotime('last Sunday');
    $YEAR_START = strtotime('1 January');

    $time = strtotime(substr($mysqlDate, 0, 10));

    if ($TODAY_START <= $time) {
        return $translator->trans('Today');
    }

    if ($YESTERDAY_START <= $time) {
        return $translator->trans('Yesterday');
    }

    if ($WEEK_START <= $time) {
        return translateDays(date('l', $time));
    }

    if ($YEAR_START <= $time) {
        return translateMonths(date('d F', $time));
    }

    return translateMonths(date('Y, F d', $time));
}

/**
 * @param string $mysqlTime
 *
 * @return string
 */
function formatAgoTime($mysqlTime)
{
    if (empty($mysqlTime)) {
        return '';
    }

    /*
    same day: few seconds ago/10 min ago /3 hours 30 min ago
    privous day: yesterday at 15:30
    same week: Tuesday at 12:20
    same year: November 8
    else: 2011, august 5
     */

    $translator = Cache::get('symfony.container')->get('translator');

    $AHOUR = 3600; // 60 seconds * 60 minutes
    $TODAY_START = strtotime('today');
    $YESTERDAY_START = strtotime('yesterday');
    $WEEK_START = strtotime('last Sunday');
    $YEAR_START = strtotime('1 January');

    $mysqlTime = UTCTimeToUserTimezone($mysqlTime);

    $time = strtotime($mysqlTime);
    $interval = strtotime(UTCTimeToUserTimezone('now')) - $time;//11003
    if ($interval < 0) {
        // it's a future time
        return $translator->trans('fewSecondsAgo');
    }

    if ($interval < $AHOUR) {
        $m = intval($interval / 60);
        if ($m == 0) {
            return $translator->trans('fewSecondsAgo');
        }
        if ($m < 2) {
            return $m.' '.$translator->trans('minute').' '.$translator->trans('ago');
        }

        return $m.' '.$translator->trans('minutes').' '.$translator->trans('ago');
    }

    if ($interval < ($time - $TODAY_START)) {
        $H = intval($interval / $AHOUR);
        if ($H < 2) {
            return $H.' '.$translator->trans('hour').' '.$translator->trans('ago');
        }

        return $H.' '.$translator->trans('hours').' '.$translator->trans('ago');
    }

    if ($YESTERDAY_START <= $time) {
        return $translator->trans('Yesterday').' '.$translator->trans('at').' '.date('H:i', $time);
    }
    if ($interval < ($time - $WEEK_START)) {
        return translateDays(date('l', $time)).' '.$translator->trans('at').' '.date('H:i', $time);
    }

    if ($interval < ($time - $YEAR_START)) {
        return translateMonths(date('d F', $time));
    }

    return translateMonths(date('Y, F d', $time));
}

/**
 * @param string $dateString
 *
 * @return string
 */
function translateDays($dateString)
{
    $translator = Cache::get('symfony.container')->get('translator');

    // replace long day names
    $days_en = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $days = explode(',', $translator->trans('dayNames'));
    $days = array_combine($days_en, $days);

    $dateString = strtr($dateString, $days);

    // replace short day names
    $days_en = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    $days = explode(',', $translator->trans('dayNamesShort'));
    $days = array_combine($days_en, $days);

    $dateString = strtr($dateString, $days);

    return $dateString;
}

/**
 * @param string $dateString
 *
 * @return mixed
 */
function translateMonths($dateString)
{
    return $dateString;
    //$translator = Cache::get('symfony.container')->get('translator');

    // /* replace long month names */
    // $months_en = array('January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December');
    // $months = explode(',', $translator->trans('monthNames'));
    // $months = array_combine($months_en, $months);

    // $dateString = strtr($dateString, $months);

    // /* replace short month names */
    // $months_en = array('Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec');
    // $months = explode(',', $translator->trans('monthNamesShort'));
    // $months = array_combine($months_en, $months);

    // $dateString = strtr($dateString, $months);

    // return $dateString;
}

/**
 * Method used to format Tasks due time
 *
 * @param  string $isoDateString ISO date string
 * @param  boolean $showTime
 *
 * @return string
 */
function formatTaskTime($isoDateString, $showTime = true)
{
    $rez = '';
    if (empty($isoDateString)) {
        return $rez;
    }

    $translator = Cache::get('symfony.container')->get('translator');

    $tz = new \DateTimeZone(\Casebox\CoreBundle\Service\User::getTimezone());

    $dt = new \DateTime($isoDateString);
    $ct = new \DateTime('now');

    $dt->setTimezone($tz);
    $ct->setTimezone($tz);

    $diff = $dt->diff($ct);

    // display date for intervals bigger than 6 days
    if (($dt < $ct) || ($diff->days > 6)) {
        $format = getOption('short_date_format');

        $rez = $dt->format($format);
    } else {
        $rez = translateMonths($dt->format('F j, '));

        // there could be situation when date difference interval
        // doesnt cover a full day although dates are in different days
        $dayDelta = ($dt->format('d') == $ct->format('d')) ? 0 : 1;
        $days = $diff->d + $dayDelta;

        if ($days > 1) {
            $rez .= translateDays($dt->format('l'));
        } else {
            $rez .= formatLeftDays($days);
        }
    }

    if ($showTime) {
        $rez .= ' '.$translator->trans('at').$dt->format(' H:i');
    }

    return $rez;
}

/**
 * Get difference in minutes between two dates
 *
 * @param string $d1
 * @param string $d2
 *
 * @return float
 */
function getDatesDiff($d1, $d2 = 'now')
{
    $d1 = strtotime($d1);
    $d2 = strtotime($d2);
    $rez = ($d2 - $d1) / (60);

    return $rez;
}

/**
 * Formats a dateTime period between two dates (without time). For ex.: Tue Apr 30, 2013 - 31
 *
 * @param string $fromDateTime mysql Formated date
 * @param string $toDateTime mysql Formated date
 *
 * @return string
 */
function formatDatePeriod($fromDateTime, $toDateTime)
{
    $d1 = new \DateTime($fromDateTime);
    $d2 = new \DateTime($toDateTime);

    $rez = $d1->format('D M j, Y');

    $d2format = '';
    if ($d1->format('Y') != $d2->format('Y')) {
        $d2format = 'D M j, Y';
    } elseif ($d1->format('M') != $d2->format('M')) {
        $d2format = 'D M j';
    } elseif ($d1->format('j') != $d2->format('j')) {
        $d2format = 'D j';
    } elseif ($d1->format('D') != $d2->format('D')) {
        $d2format = 'D';
    }

    if (!empty($toDateTime) && !empty($d2format)) {
        $rez .= ' - '.$d2->format($d2format);
    }

    return $rez;
}

/**
 * Formats a dateTime string according to user timezone from session for webdav
 *
 * @param string $fromDateTime mysql formated date
 *
 * @return string
 */
function UTCTimeToUserTimezone($dateTime)
{
    $tz = \Casebox\CoreBundle\Service\User::getTimezone();
    if (empty($dateTime) || empty($tz)) {
        return $dateTime;
    }

    $d = new \DateTime($dateTime);
    $d->setTimezone(new \DateTimeZone($tz));

    return $d->format('Y-m-d H:i:s');
}

/**
 * Formats a dateTime string according to user timezone from session for webdav
 *
 * @param string $fromDateTime mysql formated date
 *
 * @return string
 */
function userTimeToUTCTimezone($dateTime)
{
    $tz = \Casebox\CoreBundle\Service\User::getTimezone();
    if (empty($dateTime) || empty($tz)) {
        return $dateTime;
    }

    $d = new \DateTime(dateISOToMysql($dateTime), new \DateTimeZone($tz));
    $d->setTimezone(new  \DateTimeZone('UTC'));

    return $d->format('Y-m-d H:i:s');
}

/**
 * Formats a dateTime period between two dates. For ex.: Tue Apr 30, 2013 00:10 - 01:10
 *
 * @param string $fromDateTime Mysql formated date
 * @param string $toDateTime Mysql formated date
 * @param string $TZ Timezone
 *
 * @return string
 */
function formatDateTimePeriod($fromDateTime, $toDateTime, $tz = 'UTC')
{
    $d1 = new \DateTime($fromDateTime);
    if (empty($tz)) {
        $tz = 'UTC';
    }
    $d1->setTimezone(new \DateTimeZone($tz));

    $rez = $d1->format('D M j, Y');
    $hourText = $d1->format('H:i');

    $rez .= ' '.$hourText;

    if (empty($toDateTime)) {
        return $rez;
    }
    $d2 = new \DateTime($toDateTime);
    $d2->setTimezone(new \DateTimeZone($tz));

    $d2format = '';
    if ($d1->format('Y') != $d2->format('Y')) {
        $d2format = 'D M j, Y';
    } elseif ($d1->format('M') != $d2->format('M')) {
        $d2format = 'D M j';
    } elseif ($d1->format('j') != $d2->format('j')) {
        $d2format = 'D j';
    } elseif ($d1->format('D') != $d2->format('D')) {
        $d2format = 'D';
    }

    $d2format .= (empty($d2format) ? '' : ', ').'H:i';

    if (!empty($d2format)) {
        $rez .= ' - '.$d2->format($d2format);
    }

    return $rez;
}

/**
 * @param string $days_difference
 *
 * @return string
 */
function formatLeftDays($days_difference)
{
    $translator = Cache::get('symfony.container')->get('translator');

    if ($days_difference == 0) {
        return $translator->trans('today');
    }
    if ($days_difference < 0) {
        return '';
    } elseif ($days_difference == 1) {
        return $translator->trans('tomorow');
    } elseif ($days_difference < 21) {
        return $days_difference.' '.$translator->trans('ofDays');
    }

    return '';
}

/**
 * @param string $date
 * @param bool $format
 * @param bool $tz
 *
 * @return string
 */
function formatMysqlDate($date, $format = false, $tz = false)
{
    if (empty($date)) {
        return '';
    }
    if ($tz === false) {
        $tz = \Casebox\CoreBundle\Service\User::getTimezone();
    }

    if (empty($tz)) {
        $tz = 'UTC';
    }

    if ($format == false) {
        $format = getOption('short_date_format', 'Y-m-d');
    }

    $d1 = new \DateTime($date);

    $d1->setTimezone(new \DateTimeZone($tz));

    $rez = $d1->format($format);

    return $rez;
}

/**
 * @param string $date
 * @param bool $format
 *
 * @return bool|string
 */
function formatMysqlTime($date, $format = false)
{
    if (empty($date)) {
        return '';
    }
    if ($format == false) {
        $format = getOption('short_date_format', 'Y-m-d').' '.getOption('time_format', 'H:i');
    }

    return date(str_replace('%', '', $format), (!is_numeric($date))?strtotime($date):$date);
}

/**
 * @param string $date
 *
 * @return null|string
 */
function clientToMysqlDate($date)
{
    if (empty($date)) {
        return null;
    }
    $d = date_parse_from_format(getOption('short_date_format'), $date);

    return $d['year'].'-'.$d['month'].'-'.$d['day'];
}

/**
 * @param integer $v
 *
 * @return string
 */
function formatFileSize($v)
{
    if (!is_numeric($v)) {
        return '';
    }
    if ($v <= 0) {
        return '0 KB';
    } elseif ($v < 1024) {
        return '1 KB';
    } elseif ($v < 1024 * 1024) {
        return round($v / 1024).' KB';
    } else {
        $n = $v / (1024 * 1024);
    }

    return number_format($n, 2).' MB';
}

/**
 * Buffered copy a stream from source to destination
 *
 * @param string $source
 * @param string $destination
 *
 * @return boolean
 */
function bufferedSaveFile($source, $destination)
{
    $rez = false;
    $sh = fopen($source, 'rb');
    $dh = fopen($destination, 'wb');

    // Check if handlers opened successfully
    if ($sh !== false && $dh !== false) {
        $rez = true;
        while (!feof($sh) && $rez) {
            if (fwrite($dh, fread($sh, 1024 * 512)) === false) {
                $rez = false; // Error occurred
            }
        }

        fclose($sh);
        fclose($dh);
    }

    return $rez;
}

/**
 * @param bool $id
 *
 * @return bool
 */
function validId($id = false)
{
    return (!empty($id) && is_numeric($id) && ($id > 0));
}

/**
 * @param $text
 *
 * @return string
 */
function adjustTextForDisplay($text)
{
    return htmlentities($text, ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * @param $date_string
 *
 * @return bool|null|string
 */
function dateISOToMysql($date_string)
{
    if (empty($date_string) || (substr($date_string, 0, 10) == '0000-00-00')) {
        return null;
    }
    $d = strtotime($date_string);

    return date('Y-m-d H:i:s', $d);
}

/**
 * @param $date_string
 *
 * @return bool|null|string
 */
function dateMysqlToISO($date_string)
{
    if (empty($date_string)) {
        return null;
    }
    $d = strtotime($date_string);

    return date('Y-m-d\TH:i:s\Z', $d);
}

/**
 * @param string|array $v
 * @param string $delimiter
 * @param bool $noFloat
 *
 * @return array
 */
function toNumericArray($v, $delimiter = ',', $noFloat = false)
{
    if (empty($v)) {
        return [];
    }
    if (!is_array($v)) {
        $v = explode($delimiter, $v);
    }

    $rez = [];

    $v = array_filter($v, 'is_numeric');

    foreach ($v as $k => $w) {
        $w = trim($w);
        $iw = intval($w);
        if ($iw == $w) {
            $rez[] = $iw;
        } elseif (!$noFloat) {
            $rez[] = floatval($v[$k]);
        }
    }

    return $rez;
}

/**
 * @param string|array $v
 * @param string $delimiter
 *
 * @return array
 */
function toIntArray($v, $delimiter = ',')
{
    return toNumericArray($v, $delimiter, true);
}

/**
 * @param string|array $v
 * @param string $delimiter
 *
 * @return array
 */
function toTrimmedArray($v, $delimiter = ',')
{
    if (empty($v)) {
        return [];
    }
    if (!is_array($v)) {
        $v = explode($delimiter, $v);
    }

    foreach ($v as $k => $w) {
        $v[$k] = trim($w);
    }

    return $v;
}

/**
 * @param string|array $v
 *
 * @return array
 */
function toJSONArray($v)
{
    $rez = [];

    if (empty($v)) {
        return $rez;
    }
    if (is_array($v)) {
        return $v;
    }

    if (is_scalar($v)) {
        $rez = jsonDecode($v);
    }

    if (empty($rez)) {
        $rez = [];
    }

    if (is_object($rez)) {
        $rez = (Array)$rez;
    }

    return $rez;
}

/**
 * @param array $arr
 */
function unsetNullValues(&$arr)
{
    if (!is_array($arr)) {
        return;
    }

    foreach ($arr as $k => $v) {
        if (is_null($v)) {
            unset($arr[$k]);
        }
    }
}

/**
 * Json encode a variable
 *
 * @param  array $var
 *
 * @return string|null
 */
function jsonEncode($var)
{
    if (empty($var) && (!is_array($var))) {
        return null;
    }

    return json_encode($var, JSON_UNESCAPED_UNICODE);
}

/**
 * Decodes a json string
 *
 * @param  string $var
 *
 * @return array|null
 */
function jsonDecode($var)
{
    return json_decode($var, true);
}

/**
 * Custom function to detect title property in a properties array
 *
 * @param  array $arr
 *
 * @return string
 */
function detectTitle(&$arr)
{
    $l = Cache::get('symfony.request')->getLocale();

    $rez = @coalesce(
        $arr[$l],
        $arr['title_'.$l],
        $arr['title'],
        $arr['_title'],
        $arr['name'],
        (!empty($arr['fieldName'])) ? $arr['fieldName'] : ''
    );

    return $rez;
}

/**
 * Check if a given value is presend in a comma separated string or array of values
 *
 * @param string $value Checked value
 * @param array $stringOrValues
 * @param string $delimiter
 *
 * @return boolean
 */
function isInValues($value, $stringOrValues, $delimiter = ',')
{
    $v = toTrimmedArray($stringOrValues, $delimiter);

    return in_array($value, $v);
}

/**
 * @param array $a
 *
 * @return bool
 */
function isAssocArray($a)
{
    if (!is_array($a)) {
        return false;
    }

    return array_keys($a) !== range(0, count($a) - 1);
}

/**
 * Sort an array of records accor to given params
 *
 * @param array &$records Records to be sorted
 * @param string $sortProperty Property by witch to sort recrods
 * @param string $sortDirection Sort direction
 * @param string $sortType (asDate, asFloat, asInt, asText, asUCText, asString, asUCString)
 * @param bool $assoc To maintain key associations or not
 */
function sortRecordsArray(&$records, $sortProperty, $sortDirection = 'asc', $sortType = 'asString', $assoc = false)
{
    $sortDirection = strtolower($sortDirection);

    Sorter::$sortField = $sortProperty;

    $sorter = '\\Casebox\CoreBundle\Service\\Sorter::'.$sortType.ucfirst($sortDirection);

    if ($assoc) {
        uasort($records, $sorter);

    } else {
        usort($records, $sorter);
    }
}

/**
 * @param string $value
 *
 * @return bool
 */
function validISO8601Date($value)
{
    try {
        $timestamp = strtotime($value);
        $date = date(DATE_ISO8601, $timestamp);

        return ($date === $value);
    } catch (\Exception $e) {
        return false;
    }
}

/**
 * Ensures the given string has utf8 encoding and concerts it if needed
 *
 * @param  string $value
 *
 * @return string
 */
function toUTF8String($value)
{
    if (empty($value)) {
        return $value;
    }

    // Detect encoding
    $charset = mb_detect_encoding($value);

    if (empty($charset)) {
        $charset = 'UTF-8';
    }

    $newValue = @iconv($charset, 'UTF-8', $value);

    // Return original value if cannot convert it
    if (empty($newValue)) {
        $newValue = $value;
    }

    return $newValue;
}

/**
 * @return string
 */
function getOS()
{
    switch (true) {
        case stristr(PHP_OS, 'DAR'):
            return 'OSX';

        case stristr(PHP_OS, 'WIN'):
            return 'WIN';

        case stristr(PHP_OS, 'LINUX'):
            return 'LINUX';

        default:
            return 'UNKNOWN';
    }
}

/**
 * Get referenced user ids inside a given text
 *
 * @param string $text
 *
 * @return array
 */
function getReferencedUsers($text)
{
    $rez = [];

    if (!empty($text) && preg_match_all('/@([^@\s,!\?]+)/', $text, $matches, PREG_SET_ORDER)) {
        $names = [];
        foreach ($matches as $match) {
            if (!isset($names[$match[1]])) {
                $names[$match[1]] = DM\Users::getIdByName($match[1]);

                if (is_numeric($names[$match[1]])) {
                    $rez[] = intval($names[$match[1]]);
                }
            }
        }
    }

    return $rez;
}

/**
 * Check if one or more keys exists in array
 *
 * @param array $keys
 * @param array $array
 *
 * @return boolean
 */
function checkKeyExists($keys, $array)
{
    $result = false;
    foreach ($keys as $key) {
        if (array_key_exists($key, $array)) {
            $result = true;
        }
    }

    return $result;
}

/**
 * Raise an user error if logical result is true
 *
 * @param boolean $result
 * @param string $translationIndex
 */
function raiseErrorIf($result, $translationIndex = 'Error')
{
    if ($result) {
        $translator = Cache::get('symfony.container')->get('translator');

        trigger_error($translator->trans($translationIndex), E_USER_ERROR);
    }
}

/**
 * Get an option value from config config options could be defined in:
 *      User config - is stored in session
 *      Core config - is stored in session
 *      Default casebox config - Default casebox config is merged with core config file and
 *                               with database configuration values from config table
 *
 * The merged result is managed by Config class
 *
 * @param  string $optionName Name of the option to get
 *
 * @return array|string|null
 */
function getOption($optionName, $defaultValue = null)
{
    /** @var Session $session */
    $session = Cache::get('session');

    $user = $session->get('user');

    if (!empty($user['cfg'][$optionName])) {
        return $user['cfg'][$optionName];
    }

    return Cache::get('symfony.container')->get('casebox_core.service.config')->get($optionName, $defaultValue);
}
