<?php

use Codeception\Actor;

class FunctionalTester extends Actor
{

    public function loginAsAdmin()
    {
        $I = $this;
        $I->amOnPage('/admin/login');

        // Fill in test credentials
        $I->fillField('loginName', 'admin');
        $I->fillField('password', 'password');
        $I->click('Login');

        $I->amOnPage('/admin');
    }

    public function createTestForm($name, $handle, $email = null)
    {
        $I = $this;
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('New Form');

        $I->fillField('name', $name);
        $I->fillField('handle', $handle);

        if ($email) {
            $I->fillField('emailTo', $email);
        }

        $I->click('Save');
    }

    public function submitForm($data)
    {
        $I = $this;
        foreach ($data as $field => $value) {
            $I->fillField('field_' . $field, $value);
        }
        $I->click('Submit');
    }

    public function createMultipleSubmissions($formHandle, $count)
    {
        $I = $this;
        for ($i = 1; $i <= $count; $i++) {
            $I->amOnPage('/forms/' . $formHandle);
            $I->fillField('name', 'Submission ' . $i);
            $I->click('Submit');
        }
    }
}
