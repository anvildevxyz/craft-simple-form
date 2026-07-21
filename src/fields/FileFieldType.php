<?php

namespace anvildev\simpleform\fields;

use Craft;
use craft\helpers\FileHelper;
use craft\web\UploadedFile;

/**
 * A file/asset upload field. Uploaded files are saved as Craft Assets (in the
 * configured volume) by the submission path; the stored value is the list of
 * asset ids. Config: `volume` (handle), `allowedExtensions` (csv/array),
 * `maxSize` (MB), `multiple` (bool).
 */
class FileFieldType extends FieldType
{
    /**
     * Hard ceiling on files accepted by a multi-file field, bounding an
     * upload-flood from an anonymous submitter even when the field configures no
     * explicit limit. A single-file field is capped at 1.
     */
    private const MAX_FILES = 20;

    /**
     * Default per-file size ceiling (bytes) applied when the field configures no
     * `maxSize`, so an anonymous upload is always bounded (PHP's own
     * `upload_max_filesize` is a server-wide fallback, not a per-field one).
     */
    private const DEFAULT_MAX_BYTES = 25 * 1024 * 1024;

    /**
     * Extensions rejected regardless of the per-field allowlist: browser-rendered
     * markup/script (`svg`, `html`, …) that would be stored XSS if the volume is
     * public and same-origin, plus server-executable types. This is an extension
     * denylist that backs up the content sniff in {@see isExecutableContent()},
     * which finfo can evade by misclassifying e.g. an SVG as `text/xml`.
     *
     * @var list<string>
     */
    private const BLOCKED_EXTENSIONS = ['svg', 'svgz', 'xml', 'xhtml', 'html', 'htm', 'shtml'];

    public static function getType(): string
    {
        return 'file';
    }

    public static function getLabel(): string
    {
        return 'File Upload';
    }

    public function isMultiple(): bool
    {
        return (bool) ($this->config['multiple'] ?? false);
    }

    /**
     * Allowed lowercase extensions (no dot), or [] for "any".
     *
     * @return list<string>
     */
    public function allowedExtensions(): array
    {
        $raw = $this->config['allowedExtensions'] ?? [];
        if (is_string($raw)) {
            $raw = preg_split('/[\s,]+/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $ext) {
            $ext = strtolower(ltrim(trim((string) $ext), '.'));
            if ($ext !== '') {
                $out[] = $ext;
            }
        }
        return array_values(array_unique($out));
    }

    /** Maximum allowed size per file in bytes: the configured `maxSize` (MB), else a default ceiling. */
    public function maxBytes(): int
    {
        $mb = $this->config['maxSize'] ?? null;
        return (is_numeric($mb) && $mb > 0) ? (int) ((float) $mb * 1024 * 1024) : self::DEFAULT_MAX_BYTES;
    }

    /**
     * Validate the uploaded files for this field (server-enforced): required,
     * count, per-file upload error, size, and extension allowlist.
     *
     * @param array<int, UploadedFile> $files
     * @return string[]
     */
    public function validateUpload(array $files): array
    {
        // Drop "no file selected" placeholders.
        $files = array_values(array_filter(
            $files,
            static fn(UploadedFile $f): bool => $f->error !== UPLOAD_ERR_NO_FILE,
        ));

        $errors = [];

        if ($files === []) {
            if ($this->config['required'] ?? false) {
                $errors[] = Craft::t('simple-form', 'This field is required.');
            }
            return $errors;
        }

        $maxFiles = $this->isMultiple() ? self::MAX_FILES : 1;
        if (count($files) > $maxFiles) {
            $errors[] = $maxFiles === 1
                ? Craft::t('simple-form', 'Only one file may be uploaded.')
                : Craft::t('simple-form', 'At most {count} files may be uploaded.', ['count' => $maxFiles]);
        }

        $allowed = $this->allowedExtensions();
        $maxBytes = $this->maxBytes();

        foreach ($files as $file) {
            if ($file->error !== UPLOAD_ERR_OK) {
                $errors[] = Craft::t('simple-form', 'Upload failed for “{name}”.', ['name' => $file->name]);
                continue;
            }
            $ext = strtolower((string) $file->getExtension());
            // Always reject browser-rendered/executable extensions, even when the
            // field's allowlist is empty ("any"): the content sniff below can be
            // evaded by finfo misclassification (e.g. an SVG read as text/xml).
            if (in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
                $errors[] = Craft::t('simple-form', 'File type “.{ext}” is not allowed.', ['ext' => $ext]);
                continue;
            }
            if ($allowed !== [] && !in_array($ext, $allowed, true)) {
                $errors[] = Craft::t('simple-form', 'File type “.{ext}” is not allowed.', ['ext' => $ext]);
            }
            // F10 (CWE-434): the extension above is taken from the client-supplied
            // filename, so a script can masquerade as e.g. .jpg. Sniff the real
            // content type and reject anything that is server-executable. (Craft's
            // own asset pipeline validates again on save; this fails fast first.)
            if ($this->isExecutableContent($file->tempName)) {
                $errors[] = Craft::t('simple-form', 'The contents of “{name}” are not an allowed file type.', ['name' => $file->name]);
            }
            if ($file->size > $maxBytes) {
                $errors[] = Craft::t('simple-form', '“{name}” exceeds the maximum size.', ['name' => $file->name]);
            }
        }

        return $errors;
    }

    /**
     * Sniff a file's real MIME type from its bytes (not its name) and decide
     * whether it is server-executable/script content that must never be stored
     * (F10). Detection failures are treated as non-executable so a transient
     * finfo error doesn't block a legitimate upload — the extension allowlist
     * and Craft's asset validation remain in force.
     */
    private function isExecutableContent(string $tempPath): bool
    {
        if ($tempPath === '' || !is_file($tempPath)) {
            return false;
        }

        try {
            // $checkExtension = false → detect from content, not the filename.
            $mime = FileHelper::getMimeType($tempPath, null, false);
        } catch (\Throwable) {
            return false;
        }

        if ($mime === null) {
            return false;
        }

        $blocked = [
            'application/x-php',
            'text/x-php',
            'application/x-httpd-php',
            'application/x-httpd-php-source',
            'text/x-python',
            'application/x-python-code',
            'text/x-perl',
            'text/x-shellscript',
            'application/x-sh',
            'application/x-executable',
            'application/x-dosexec',
            'application/x-mach-binary',
            'application/vnd.microsoft.portable-executable',
            'text/html',
            'application/xhtml+xml',
            'image/svg+xml',
        ];

        return in_array(strtolower($mime), $blocked, true);
    }

    public function renderInput(string $name, mixed $value = null): string
    {
        // id is always the bare field name so the group's <label for> targets it;
        // the [] for multiple only affects the posted name.
        $multiple = $this->isMultiple();
        $attrs = sprintf(
            'id="%s" name="%s"',
            htmlspecialchars($name),
            htmlspecialchars($multiple ? $name . '[]' : $name),
        );
        if ($this->config['required'] ?? false) {
            $attrs .= ' required';
        }
        if ($multiple) {
            $attrs .= ' multiple';
        }
        $allowed = $this->allowedExtensions();
        if ($allowed !== []) {
            $accept = implode(',', array_map(static fn(string $e): string => '.' . $e, $allowed));
            $attrs .= sprintf(' accept="%s"', htmlspecialchars($accept));
        }

        return sprintf('<input type="file" %s class="text fullwidth">', $attrs);
    }
}
