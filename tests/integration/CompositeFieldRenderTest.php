<?php

namespace fabianhaef\simpleform\tests\integration;

use fabianhaef\simpleform\TwigExtension;

/**
 * #126 — front-end render smoke for the composite Name/Address field types:
 * a form with a Name (first + last) and an Address (with a country `<select>`)
 * renders each sub-input inside a `<fieldset>`, each with its own id and an
 * explicit `<label for>`. Neutral placeholder labels only.
 *
 * @group requires-craft
 */
class CompositeFieldRenderTest extends SimpleFormTestCase
{
    public function testCompositeFieldsRenderLabelledSubInputs(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Composite Render', 'composite_render');

        $nameId = $this->createField($form->id, 'name', 'fullName', 'Name', false, [
            'subFields' => [
                'first' => ['enabled' => true, 'required' => true, 'label' => 'First'],
                'last' => ['enabled' => true, 'required' => true, 'label' => 'Last'],
                'middle' => ['enabled' => false, 'required' => false, 'label' => 'Middle'],
            ],
        ]);

        $addressId = $this->createField($form->id, 'address', 'mailing', 'Address', false, [
            'subFields' => [
                'line1' => ['enabled' => true, 'required' => true, 'label' => 'Line 1'],
                'city' => ['enabled' => true, 'required' => true, 'label' => 'City'],
                'postalCode' => ['enabled' => true, 'required' => true, 'label' => 'Postal'],
                'country' => ['enabled' => true, 'required' => true, 'label' => 'Country'],
                'line2' => ['enabled' => false, 'required' => false, 'label' => 'Line 2'],
                'state' => ['enabled' => false, 'required' => false, 'label' => 'Region'],
            ],
        ]);

        $html = (new TwigExtension())->renderForm('composite_render');

        // Name: a <fieldset> of labelled sub-inputs, each with its own id.
        $this->assertStringContainsString('<fieldset class="sf-composite" aria-labelledby="field_' . $nameId . '-legend">', $html);
        $this->assertStringContainsString('<label for="field_' . $nameId . '-first">First</label>', $html);
        $this->assertStringContainsString('id="field_' . $nameId . '-first" name="field_' . $nameId . '[first]"', $html);
        $this->assertStringContainsString('<label for="field_' . $nameId . '-last">Last</label>', $html);
        // Disabled middle never renders.
        $this->assertStringNotContainsString('field_' . $nameId . '-middle', $html);

        // Address: country renders a populated <select>; disabled parts absent.
        $this->assertStringContainsString('<label for="field_' . $addressId . '-country">Country</label>', $html);
        $this->assertStringContainsString('<select id="field_' . $addressId . '-country" name="field_' . $addressId . '[country]"', $html);
        $this->assertStringContainsString('value="US"', $html);
        $this->assertStringNotContainsString('field_' . $addressId . '-line2', $html);
        $this->assertStringNotContainsString('field_' . $addressId . '-state', $html);
    }
}
