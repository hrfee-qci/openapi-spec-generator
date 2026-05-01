<?php

namespace LaravelJsonApi\OpenApiSpec\Helpers;

use Carbon\Carbon;
use Error;
use GoldSpecDigital\ObjectOrientedOAS\Objects\Schema;

// SchemaFromExample attempts to generate a schema from an example given in a WithDescriptor field.
// If an enum is passed, it is assumed to be an enum of possible values of the whole schema (e.g. if schema is like {"a": int}, enum is assumed to be like [{"a": 1}, {"a": 2}. The enum will be unpackaged and sub-schemas will be given their own enums.
class SchemaFromExample
{
    /**
     * Attempts to format an example if a format is set, and is a standard one (e.g. date/date-time).
     * If it can't it returns the value.
     * @param mixed $example
     * @param ?string $format
     * @return string|int|float|mixed
     */
    public static function formatExample(mixed $example, ?string $format = null): mixed
    {
        if (!$format || $example === null)
            return $example;
        if ($format === 'date' || $format === 'date-time') {
            $date = null;
            try {
                $date = Carbon::parse($example);
            } catch (\Exception $_) {
                $date = Carbon::createFromTimestamp(0);
            }
            return $date->format($format === 'date' ? 'Y-m-d' : 'c');
        }
        return $example;
    }

    // Holistically check if a string is a date/datetime or not.
    public static function parseDateTime(string $example): ?Carbon
    {
        if (
            strlen($example) > 36
            || !(
                str_contains($example, '-')
                || !str_contains($example, ' ')
                || !str_contains($example, '/')
                || str_contains($example, '.')
                && substr_count($example, '.') > 1
            )
        )
            return null;

        $date = null;
        try {
            $date = Carbon::parse($example);
        } catch (\Exception $_) {
            return null;
        }
        return $date;
    }

    public static function generate(
        Schema $schema = null,
        mixed $example = null,
        mixed $key = null,
        ?string $format = null,
        ?array $enum = null,
    ): Schema {
        if ($schema === null) {
            if (is_int($example)) {
                $schema = Schema::integer($key)->example($example);
            } else if (is_float($example)) {
                $schema = Schema::number($key)->example($example);
            } else if (is_string($example)) {
                $date = self::parseDateTime($example);
                if ($date !== null) {
                    if ($date->hour == 0 && $date->minute == 0 && $date->second == 0 && $date->millisecond == 0) {
                        $schema = Schema::string($key)->format(Schema::FORMAT_DATE)->example($date->format('Y-m-d'));
                    } else {
                        $schema = Schema::string($key)->format(Schema::FORMAT_DATE_TIME)->example($date->format('c'));
                    }
                } else {
                    $schema = Schema::string($key)->example($example);
                }
            } else if ($example instanceof Carbon) {
                // This is unlikely to happen but just in case
                $schema = Schema::string($key)->format(Schema::FORMAT_DATE_TIME)->example($example);
            } else if (is_array($example)) {
                if (self::arrayIsHash($example)) {
                    $schema = Schema::object($key);
                } else {
                    $schema = Schema::array($key)->example($example);
                }
            } else if ($example instanceof \stdClass && empty((array) $example)) {
                $schema = Schema::object($key)->example(new \stdClass());
            } else if ($example instanceof Schema) {
                // Assume they've sorted it all out themselves
                $schema = $example;
                return $schema;
            } else {
                throw new Error('unknown datatype in example: ' . $key . ' => ' . gettype($example));
            }
        }
        if ($format)
            $schema = $schema->format($format);
        if (is_array($example)) {
            $props = [];
            foreach ($example as $k => $v) {
                $subEnum = is_array($enum) && !empty($enum)
                    ? array_map(fn(array $enumItem) => $enumItem[$k], $enum)
                    : null;

                $prop = self::generate(
                    null,
                    $v,
                    $k,
                    null,
                    $subEnum && is_array($enum[array_key_first($enum)]) ? $subEnum : null,
                );
                if ($subEnum) {
                    $prop = $prop->enum(...$subEnum);
                }
                $props[] = $prop;
            }
            if (self::arrayIsHash($example)) {
                $schema = $schema->properties(...$props);
            } else {
                foreach ($props as $prop) {
                    $schema = $schema->items($prop);
                    break;
                }
            }
        }
        return $schema;
    }

    /** Returns whether or not an array is a hash map (i.e. has non-integer keys).
     * @param array $arr
     * @return bool
     */
    private static function arrayIsHash(array $arr): bool
    {
        foreach (array_keys($arr) as $k) {
            if (!is_int($k)) {
                return true;
            }
        }
        return false;
    }
}
