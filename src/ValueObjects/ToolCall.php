<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;
use JsonException;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Support\Json;
use Prism\Prism\Support\JsonMap;
use stdClass;

/**
 * @implements Arrayable<string, mixed>
 */
class ToolCall implements Arrayable
{
    /**
     * @param  string|array<string, mixed>  $arguments
     * @param  null|array<string, mixed>  $reasoningSummary
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string|array $arguments,
        public readonly ?string $resultId = null,
        public readonly ?string $reasoningId = null,
        public readonly ?array $reasoningSummary = null,
    ) {}

    /**
     * The arguments as PHP sees them: associative arrays all the way down.
     *
     * This is what every caller INSIDE Prism gets — tool handlers, approval
     * callbacks, `handle(...$args)`. It is deliberately unchanged by the
     * container-type work: a handler typed `array $filter` must keep receiving
     * `[]` for a `{}` the model sent, or fixing the wire would break the very
     * tools the wire exists to call.
     *
     * @return array<string, mixed>
     */
    public function arguments(): array
    {
        if (is_string($this->arguments)) {
            $decoded = $this->decodeArguments();

            return is_array($decoded) ? $decoded : [];
        }

        /** @var array<string, mixed> $arguments */
        $arguments = $this->arguments;

        return $arguments;
    }

    /**
     * Whether the model supplied any arguments at all.
     *
     * Mirrors Tool::hasParameters(). A provider that OMITS the arguments key
     * for a no-argument call asks this, so that no send site has to reach for
     * the raw array and decide the container type for itself.
     */
    public function hasArguments(): bool
    {
        return $this->arguments() !== [];
    }

    /**
     * The arguments as a JSON OBJECT, in the container types the MODEL sent.
     *
     * Every provider message map goes through here, and it differs from
     * `arguments()` in two ways that both matter on the wire:
     *
     *  - an empty argument set is `{}`, never `[]`, which is what the
     *    `?: (object) []` guard copied into each message map used to do — and
     *    what the maps that never got the guard did not;
     *  - a `{}` NESTED anywhere inside survives the round trip, because the
     *    decode preserves it rather than a rule downstream guessing which keys
     *    were maps. `{"filter":{}}` came back out as `{"filter":[]}` before
     *    this, and went to the provider that way on the next turn.
     *
     * The second only works when the provider handed us the arguments as a
     * raw string. A provider that decodes them itself before building the
     * value object has already thrown the distinction away — which is why
     * ToolCallMaps pass the string through.
     */
    public function argumentsAsObject(): stdClass
    {
        $decoded = is_string($this->arguments)
            ? $this->decodeArguments(preservingContainerTypes: true)
            : $this->arguments();

        if ($decoded instanceof stdClass) {
            return $decoded;
        }

        if (! is_array($decoded)) {
            return new stdClass;
        }

        /** @var array<string, mixed> $decoded */
        return JsonMap::of($decoded);
    }

    /**
     * The same object, encoded — for the providers that carry the arguments as
     * a JSON string rather than inline.
     */
    public function argumentsAsJson(): string
    {
        return json_encode($this->argumentsAsObject(), JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // A map when the provider gave us one, and still the original
            // string when it gave us that — but never a JSON list, which is
            // what an empty argument set would otherwise serialise as.
            'arguments' => is_array($this->arguments) ? JsonMap::of($this->arguments) : $this->arguments,
            'result_id' => $this->resultId,
            'reasoning_id' => $this->reasoningId,
            'reasoning_summary' => $this->reasoningSummary,
        ];
    }

    /**
     * @throws PrismException
     */
    protected function decodeArguments(bool $preservingContainerTypes = false): mixed
    {
        if (! is_string($this->arguments) || $this->arguments === '' || $this->arguments === '0') {
            return [];
        }

        try {
            return Json::decode($this->arguments, $preservingContainerTypes);
        } catch (JsonException) {
            // Some providers (e.g. DeepSeek when streaming) emit raw control
            // characters inside string values, which RFC 8259 requires to be
            // escaped. Escape them in place — rather than stripping them, which
            // would corrupt intentional newlines/tabs — and decode again.
            try {
                return Json::decode(
                    self::escapeControlCharactersInStrings($this->arguments),
                    $preservingContainerTypes
                );
            } catch (JsonException $e) {
                throw PrismException::malformedToolCallArguments($this->name, $e);
            }
        }
    }

    /**
     * Escape raw control characters (0x00–0x1F) that appear inside JSON string
     * literals with their JSON escape sequences, and drop the ones that appear
     * outside strings where they can never be valid (raw \t, \n and \r between
     * tokens are legal whitespace and are kept).
     */
    protected static function escapeControlCharactersInStrings(string $json): string
    {
        $result = '';
        $inString = false;
        $escaped = false;
        $length = strlen($json);

        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];
            $ord = ord($char);

            if ($ord <= 0x1F) {
                if ($inString) {
                    $result .= match ($char) {
                        "\x08" => '\b',
                        "\x09" => '\t',
                        "\x0A" => '\n',
                        "\x0C" => '\f',
                        "\x0D" => '\r',
                        default => sprintf('\u%04x', $ord),
                    };
                } elseif (in_array($char, ["\t", "\n", "\r"], true)) {
                    $result .= $char;
                }

                $escaped = false;

                continue;
            }

            $result .= $char;

            if ($escaped) {
                $escaped = false;
            } elseif ($inString && $char === '\\') {
                $escaped = true;
            } elseif ($char === '"') {
                $inString = ! $inString;
            }
        }

        return $result;
    }
}
