<?php

/**
 *    Copyright (C) 2020 Deciso B.V.
 *
 *    All rights reserved.
 *
 *    Redistribution and use in source and binary forms, with or without
 *    modification, are permitted provided that the following conditions are met:
 *
 *    1. Redistributions of source code must retain the above copyright notice,
 *       this list of conditions and the following disclaimer.
 *
 *    2. Redistributions in binary form must reproduce the above copyright
 *       notice, this list of conditions and the following disclaimer in the
 *       documentation and/or other materials provided with the distribution.
 *
 *    THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 *    INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 *    AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 *    AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 *    OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 *    SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 *    INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 *    CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 *    ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 *    POSSIBILITY OF SUCH DAMAGE.
 *
 */

namespace tests\OPNsense\Base\FieldTypes;

// @CodingStandardsIgnoreStart
require_once 'Field_Framework_TestCase.php';
// @CodingStandardsIgnoreEnd

use OPNsense\Base\FieldTypes\TextField;

class TextFieldTest extends Field_Framework_TestCase
{
    /**
     * test construct
     */
    public function testCanBeCreated()
    {
        $this->assertInstanceOf('\OPNsense\Base\FieldTypes\TextField', new TextField());
    }

    /**
     * empty value fails validation against regex pattern requiring non-empty
     */
    public function testEmptyStringWithNonEmptyMask()
    {
        $field = new TextField();
        $field->setMask('/^[a-z]{1}$/');
        $field->setValue("");

        $this->assertContains('OPNsense\Phalcon\Filter\Validation\Validator\Regex', $this->validate($field));
    }

    /**
     * integer value passes validation against regex pattern requiring integer
     */
    public function testIntegerStringWithIntegerMask()
    {
        $field = new TextField();
        $field->setMask('/^[0-9]{4}$/');
        $field->setValue("1234");

        $this->assertEmpty($this->validate($field));
    }

    /**
     * integer value passes validation with no regex pattern
     */
    public function testIntegerStringWithNoMask()
    {
        $field = new TextField();
        $field->setValue("1234");

        $this->assertEmpty($this->validate($field));
    }

    /**
     * non-empty value passes validation against regex pattern requiring non-empty
     */
    public function testNonEmptyStringWithNonEmptyMask()
    {
        $field = new TextField();
        $field->setMask('/^[a-z]{1}$/');
        $field->setValue("x");

        $this->assertEmpty($this->validate($field));
    }

    /**
     * non-empty value fails validation against regex pattern requiring non-empty
     */
    public function testNomatchNonEmptyStringWithNonEmptyMask()
    {
        $field = new TextField();
        $field->setMask('/^nonempty$/');
        $field->setValue("nomatch");

        $this->assertContains('OPNsense\Phalcon\Filter\Validation\Validator\Regex', $this->validate($field));
    }


    /**
     * empty value passes validation against regex pattern requiring empty
     */
    public function testEmptyStringWithEmptyMask()
    {
        $field = new TextField();
        $field->setMask('/^$/');
        $field->setValue("");

        $this->assertEmpty($this->validate($field));
    }

    /**
     * empty value passes validation, no mask
     */
    public function testEmptyStringWithNoMask()
    {
        $field = new TextField();
        $field->setValue("");

        $this->assertEmpty($this->validate($field));
    }


}
