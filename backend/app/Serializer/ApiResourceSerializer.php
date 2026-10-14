<?php

namespace App\Serializer;

/**
 * API value (de)serialization — faithful port of the legacy Casts.php.
 *
 * Handles JSON columns, boolean (TINYINT) <-> bool, DATETIME(3) ->
 * ISO-8601 UTC with 'Z' suffix, decimal -> float, and input coercion
 * (array -> JSON, "true"/"false"/"null" strings, ISO -> MariaDB datetime).
 */
class ApiResourceSerializer
{
    public const JSON_COLUMNS = [
        'attendees', 'absent_with_apology', 'absent_without_apology', 'visible_to_members',
        'attachments', 'supporting_documents', 'details', 'error_details', 'report_data',
        'variables', 'payout_rules', 'raw_user_meta_data', 'metadata',
    ];

    public const BOOL_COLUMNS = [
        'is_active', 'is_read', 'is_paid', 'matched', 'resolved', 'active',
        'allow_partial', 'show_on_login', 'auto_approve', 'used', 'is_online', 'is_banned',
    ];

    /**
     * Serialize a single row for API output.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function out(array $row): array
    {
        $result = [];
        foreach ($row as $column => $value) {
            $result[$column] = self::outValue($column, $value);
        }

        return $result;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public static function outCollection(array $rows): array
    {
        return array_map([self::class, 'out'], $rows);
    }

    public static function outValue(string $column, $value)
    {
        if ($value === null) {
            return null;
        }

        if (in_array($column, self::JSON_COLUMNS, true)) {
            if (is_string($value)) {
                $decoded = json_decode($value, true);

                return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
            }

            return $value;
        }

        if (in_array($column, self::BOOL_COLUMNS, true)) {
            return (bool) $value;
        }

        if (self::isDatetime($value)) {
            return self::formatTimestamp($value);
        }

        if (is_string($value) && preg_match('/^-?\d+\.\d+$/', $value)) {
            return (float) $value;
        }

        return $value;
    }

    /**
     * Prepare input data for database write, dropping columns that are not
     * in the allowed list (strict-schema behaviour).
     *
     * @param array<string,mixed> $data
     * @param string[] $allowed
     * @return array<string,mixed>
     */
    public static function in(array $data, array $allowed): array
    {
        $result = [];
        foreach ($data as $column => $value) {
            if (! in_array($column, $allowed, true)) {
                continue;
            }
            $result[$column] = self::inValue($value);
        }

        return $result;
    }

    public static function inValue($value)
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES);
        }

        if ($value === 'true') {
            return 1;
        }
        if ($value === 'false') {
            return 0;
        }
        if ($value === 'null') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $value;
    }

    public static function isDatetime($value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        return (bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,3})?$/', $value);
    }

    /**
     * Convert a MariaDB DATETIME(3) to ISO-8601 UTC with a 'Z' suffix.
     */
    public static function formatTimestamp(string $value): string
    {
        $dt = \DateTime::createFromFormat('Y-m-d H:i:s.u', $value)
            ?? \DateTime::createFromFormat('Y-m-d H:i:s', $value);

        if ($dt === false) {
            return $value;
        }

        $dt->setTimezone(new \DateTimeZone('UTC'));

        return $dt->format('Y-m-d\TH:i:s.v').'Z';
    }
}
