<?php

namespace anvildev\simpleform\tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards that every shipped locale catalog stays in sync with the canonical
 * English catalog: same keys, no missing translations, no orphaned keys, and
 * no value left identical to the English source (which would mean "untranslated"
 * for everything except deliberate proper nouns).
 *
 * Source-string-as-key i18n means a missing key degrades to English at runtime,
 * so drift is silent — this test makes it loud.
 */
class TranslationCatalogParityTest extends TestCase
{
    private const TRANSLATIONS_DIR = __DIR__ . '/../../src/translations';

    /** Locales shipped alongside the English source. */
    private const LOCALES = ['de', 'fr', 'es', 'it', 'ja', 'nl', 'pt'];

    /**
     * Values that are intentionally identical to English (proper nouns, brand
     * names, protocol terms) and therefore exempt from the "must differ" check.
     *
     * @var list<string>
     */
    private const UNTRANSLATABLE = [
        'Simple Form',
        'Name',        // identical in several target languages
        'Handle',      // kept as-is in de/it
        'Description', // identical in fr
        'Tokens',      // it
        'Endpoint:',   // de
        'Spam',        // borrowed term — identical in de/es/it
        'Status:',     // identical in German
        'Total',       // identical in fr/es (de=Gesamt, it=Totale)
        'Message',     // identical in fr (de=Nachricht, es=Mensaje, it=Messaggio)
        'Honeypot',    // borrowed term — kept verbatim in all shipped locales
        'CAPTCHA',     // acronym — identical in all shipped locales
        'Notifications', // cognate — identical in fr (de=Benachrichtigungen, etc.)
    ];

    /**
     * @return array<string, mixed>
     */
    private function catalog(string $locale): array
    {
        $path = self::TRANSLATIONS_DIR . "/$locale/simple-form.php";
        $this->assertFileExists($path, "Missing catalog for locale '$locale'");
        $catalog = require $path;
        $this->assertIsArray($catalog, "Catalog for '$locale' must return an array");

        return $catalog;
    }

    public function testAllShippedLocalesExist(): void
    {
        foreach (self::LOCALES as $locale) {
            $this->assertDirectoryExists(
                self::TRANSLATIONS_DIR . "/$locale",
                "Expected a shipped catalog directory for '$locale'",
            );
        }
    }

    /**
     * @dataProvider localeProvider
     */
    public function testLocaleHasExactlyTheEnglishKeys(string $locale): void
    {
        $en = array_keys($this->catalog('en'));
        $translated = array_keys($this->catalog($locale));

        $missing = array_diff($en, $translated);
        $orphaned = array_diff($translated, $en);

        $this->assertSame([], array_values($missing), "Locale '$locale' is missing keys: " . implode(' | ', $missing));
        $this->assertSame([], array_values($orphaned), "Locale '$locale' has orphaned keys: " . implode(' | ', $orphaned));
    }

    /**
     * @dataProvider localeProvider
     */
    public function testLocaleValuesAreNonEmpty(string $locale): void
    {
        foreach ($this->catalog($locale) as $key => $value) {
            $this->assertIsString($value, "Non-string value for '$key' in '$locale'");
            $this->assertNotSame('', trim($value), "Empty translation for '$key' in '$locale'");
        }
    }

    /**
     * @dataProvider localeProvider
     */
    public function testLocaleActuallyTranslatesMostStrings(string $locale): void
    {
        $en = $this->catalog('en');
        $translated = $this->catalog($locale);

        $identical = [];
        foreach ($en as $key => $value) {
            if (in_array($key, self::UNTRANSLATABLE, true)) {
                continue;
            }
            if (($translated[$key] ?? null) === $value) {
                $identical[] = $key;
            }
        }

        // A couple of incidental collisions are tolerable; a flood means the
        // catalog wasn't really translated.
        $this->assertLessThanOrEqual(
            3,
            count($identical),
            "Locale '$locale' leaves too many strings identical to English (untranslated): " . implode(' | ', $identical),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function localeProvider(): array
    {
        $cases = [];
        foreach (self::LOCALES as $locale) {
            $cases[$locale] = [$locale];
        }

        return $cases;
    }
}
