<?php

namespace LaravelJsonApi\OpenApiSpec\Helpers;

use Carbon\Carbon;
use Error;
use GoldSpecDigital\ObjectOrientedOAS\Objects\Schema;

// SchemaFromExample attempts to generate a schema from an example given in a WithDescriptor field.
class SchemaFromExample
{
    public static function generate(Schema $schema = null, mixed $example = null, mixed $key = null, ?string $format = null): Schema
    {
        if ($schema === null) {
            if (is_int($example)) {
                $schema = Schema::integer($key)->example($example);
            } else if (is_float($example)) {
                $schema = Schema::number($key)->example($example);
            } else if (is_string($example)) {
                $schema = Schema::string($key)->example($example);
            } else if ($example instanceof Carbon) {
                // @todo: Since WithDescription is an attribute, this couldn't ever be instantiated. Instead try parsing string as time.
                $schema = Schema::string($key)->format(Schema::FORMAT_DATE_TIME)->example($example);
            } else if (is_array($example)) {
                if (self::arrayIsHash($example)) {
                    $schema = Schema::object($key);
                } else {
                    $schema = Schema::array($key)->example($example);
                }
            } else if ($example instanceof \stdClass && empty((array)$example)) {
                $schema = Schema::object($key)->example(new \stdClass());
            } else {
                throw new Error('unknown datatype in example: '.$key.' => '.gettype($example));
            }
        }
        if ($format) $schema = $schema->format($format);
        if (is_array($example)) {
            $props = [];
            foreach ($example as $k => $v) {
                $props[] = self::generate(null, $v,  $k);
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
