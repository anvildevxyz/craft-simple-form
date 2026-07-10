<?php

namespace anvildev\simpleform\fields;

use Craft;

class UrlFieldType extends FieldType
{
    public static function getType(): string
    {
        return 'url';
    }

    public static function getLabel(): string
    {
        return 'URL';
    }

    /**
     * @return string[]
     */
    public function validate(mixed $value): array
    {
        $errors = parent::validate($value);

        if ($this->hasValue($value)) {
            $normalized = self::normalizeUrl((string) $value);
            // Restrict to http(s) after normalization: a scheme-less entry gets a
            // default https:// prefix, while a non-web scheme (ftp:, javascript:,
            // data:) is rejected rather than stored.
            if (filter_var($normalized, FILTER_VALIDATE_URL) === false || !preg_match('~^https?://~i', $normalized)) {
                $errors[] = Craft::t('simple-form', 'Please enter a valid URL.');
            }
        }

        return $errors;
    }

    public function normalizeValue(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        return self::normalizeUrl($value);
    }

    public function renderInput(string $name, mixed $value = null): string
    {
        return sprintf(
            '<input type="url" %s class="text fullwidth">',
            $this->getInputAttributes($name, $value)
        );
    }

    /**
     * Trim the value and prepend a default `https://` scheme when the visitor
     * omitted one, so `example.com` is stored and validated as
     * `https://example.com`. An entry that already carries a scheme is left
     * untouched.
     */
    private static function normalizeUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $value)) {
            $value = 'https://' . $value;
        }

        return $value;
    }
}
