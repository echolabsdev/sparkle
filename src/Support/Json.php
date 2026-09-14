<?php

declare(strict_types=1);

namespace Prism\Prism\Support;

use stdClass;

/**
 * Decoding JSON without throwing away the one thing a PHP array cannot hold.
 *
 * `json_decode($raw, true)` is where the information is lost, not the encode
 * that follows it: `{}` and `[]` both arrive as `[]`, and re-encoding an empty
 * map therefore emits a list. Repairing that afterwards means GUESSING which
 * fields were maps, which works for the fields you thought of and fails for
 * arbitrary JSON nested arbitrarily deep — a schema property declared `{}`
 * three levels down is reached by no key-aware rule.
 *
 * So the distinction is CARRIED from the input rather than inferred at the
 * output. `preservingContainerTypes: true` decodes to objects and then folds
 * every POPULATED object back into an associative array — leaving only the
 * empty ones as `stdClass`, because that is the sole case an array cannot
 * express. Nothing is promoted on a hunch: `"required": []` arrived as a list
 * and stays a list.
 *
 * @see JsonMap for the other half — a map-typed field that Prism itself
 *      DEFAULTS to empty was never decoded from anything, so there is no input
 *      to carry and the field's own declaration is the only evidence there is.
 */
class Json
{
    /**
     * @throws \JsonException
     */
    public static function decode(string $json, bool $preservingContainerTypes = false): mixed
    {
        $decoded = json_decode($json, ! $preservingContainerTypes, 512, JSON_THROW_ON_ERROR);

        return $preservingContainerTypes ? self::foldPopulatedObjects($decoded) : $decoded;
    }

    /**
     * Every populated object becomes an array — which is what the rest of the
     * codebase has always been handed — and every EMPTY one stays an object.
     */
    protected static function foldPopulatedObjects(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);

            return $properties === []
                ? $value
                : array_map(self::foldPopulatedObjects(...), $properties);
        }

        if (is_array($value)) {
            return array_map(self::foldPopulatedObjects(...), $value);
        }

        return $value;
    }
}
