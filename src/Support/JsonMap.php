<?php

declare(strict_types=1);

namespace Prism\Prism\Support;

use stdClass;

/**
 * A string-keyed MAP, in the shape JSON can tell apart from a list.
 *
 * PHP has one array type, so `json_encode([])` is `[]` while
 * `json_encode(['a' => 1])` is `{"a":1}`. A map-typed field therefore changes
 * its JSON TYPE with its contents — array when empty, object when populated —
 * and that is not a cosmetic difference:
 *
 *   - Providers reject it. An empty tool-call argument set or an empty
 *     `properties` map sent as `[]` where the API expects an object is a 400.
 *   - Every other language Prism is ported to distinguishes the two, so a
 *     PHP-authored conformance golden cannot even state which one is meant.
 *
 * Before this existed, the workaround was a `?: (object) []` guard repeated at
 * every send site, which meant the rule held only where somebody remembered
 * it — a provider added without the guard sent `[]` and nothing caught it.
 * Three providers were already in that state. The rule now lives with the
 * field instead of with the caller.
 *
 * Use it for a MAP. Do NOT use it for a list: a list is `[]` in every
 * language, empty included, and wrapping one would corrupt the payload.
 */
class JsonMap
{
    /**
     * @param  array<string, mixed>  $map
     */
    public static function of(array $map): stdClass
    {
        return (object) $map;
    }

    /**
     * A map whose VALUES are themselves maps — a JSON Schema `properties`
     * block, say, where each value is a schema and the empty schema `{}`
     * means "any value".
     *
     * One level, deliberately. Folding all the way down would promote the
     * entirely ordinary `"required": []` to `{}` and trade one silent
     * divergence for a commoner one; below this level the container type has
     * to be CARRIED from the input instead — see Json::decode().
     *
     * @param  array<string, array<string, mixed>>  $maps
     */
    public static function ofMaps(array $maps): stdClass
    {
        return self::of(array_map(self::of(...), $maps));
    }

    /**
     * The same map, JSON-encoded — for the providers that carry a nested
     * object as a string rather than inline.
     *
     * @param  array<string, mixed>  $map
     */
    public static function encode(array $map): string
    {
        return json_encode(self::of($map), JSON_THROW_ON_ERROR);
    }
}
