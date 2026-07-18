<?php

namespace App\Services;

use InvalidArgumentException;
use Truschery\Kanon\Json;

final class JcsCanonicalizer
{
    private const MAX_SAFE_INTEGER = 9007199254740991;

    public function canonicalize(mixed $value): string
    {
        $this->validate($value);

        return Json::canonicalize($value);
    }

    private function validate(mixed $value): void
    {
        if ($value === null || is_string($value) || is_bool($value)) {
            return;
        }
        if (is_int($value)) {
            if (abs($value) > self::MAX_SAFE_INTEGER) {
                throw new InvalidArgumentException('JCS integer exceeds the interoperable IEEE-754 safe range; encode it as a string');
            }

            return;
        }
        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('JCS does not permit NaN or infinite numbers');
            }

            return;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->validate($item);
            }

            return;
        }
        if (is_object($value)) {
            foreach (get_object_vars($value) as $item) {
                $this->validate($item);
            }

            return;
        }

        throw new InvalidArgumentException('Unsupported JCS value type: '.get_debug_type($value));
    }
}
