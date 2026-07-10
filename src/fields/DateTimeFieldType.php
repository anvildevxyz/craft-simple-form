<?php

namespace anvildev\simpleform\fields;

use anvildev\simpleform\helpers\TimeValue;
use Craft;

class DateTimeFieldType extends FieldType
{
    public static function getType(): string
    {
        return 'datetime';
    }

    public static function getLabel(): string
    {
        return 'Date & Time';
    }

    /**
     * @return string[]
     */
    public function validate(mixed $value): array
    {
        $errors = parent::validate($value);

        if ($this->hasValue($value) && !$this->isValid($value)) {
            $errors[] = Craft::t('simple-form', 'Please enter a valid date and time.');
        }

        return $errors;
    }

    public function normalizeValue(mixed $value): mixed
    {
        if (!is_string($value) || trim($value) === '') {
            return $value;
        }

        $parts = $this->splitParts($value);
        if ($parts === null) {
            // Leave an invalid entry untouched so validate() surfaces it rather
            // than silently blanking the field.
            return $value;
        }

        [$date, $time] = $parts;
        $normalizedTime = TimeValue::normalize($time);
        if ($normalizedTime === null) {
            return $value;
        }

        // Store the canonical `YYYY-MM-DDTHH:MM` shape: the date half verbatim
        // (as the Date field keeps it) and the time half seconds-stripped.
        return $date . 'T' . $normalizedTime;
    }

    public function renderInput(string $name, mixed $value = null): string
    {
        return sprintf(
            '<input type="datetime-local" %s class="text fullwidth">',
            $this->getInputAttributes($name, $value)
        );
    }

    /**
     * Whether the value is a well-formed combined date+time. The date half is
     * validated with the same `strtotime()` mechanism the Date field uses; the
     * time half is delegated to {@see TimeValue} so the two field types stay in
     * lockstep.
     */
    private function isValid(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $parts = $this->splitParts($value);
        if ($parts === null) {
            return false;
        }

        [$date, $time] = $parts;
        return strtotime($date) !== false && TimeValue::isValid($time);
    }

    /**
     * Split a combined value into its `[date, time]` halves. An
     * `<input type="datetime-local">` posts `YYYY-MM-DDTHH:MM`; a space
     * separator is tolerated for programmatic callers. Returns `null` when the
     * value doesn't carry both halves.
     *
     * @return array{0: string, 1: string}|null
     */
    private function splitParts(string $value): ?array
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $parts = preg_split('~[T ]~', $value, 2);
        if ($parts === false || count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        return [$parts[0], $parts[1]];
    }
}
