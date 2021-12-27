<?php

/*
 * Copyright (C) 2015 Deciso B.V.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

namespace OPNsense\Base\FieldTypes;

use Phalcon\Validation\Validator\InclusionIn;
use OPNsense\Base\Validators\CsvListValidator;

/**
 * Class ModelRelationField defines a relation to another entity within the model, acts like a select item.
 * @package OPNsense\Base\FieldTypes
 */
class ModelRelationField extends BaseListField
{
    /**
     * @var bool field content should remain sort order
     */
    private $internalIsSorted = false;

    /**
     * @var array|null model settings to use for validation
     */
    private $mdlStructure = null;

     /**
      * @var boolean selected options from the same model
      */
    private $internalOptionsFromThisModel = false;

    /**
     * @var string cache relations
     */
    private $internalCacheKey = "";

    /**
     * @var array collected options
     */
    private static $internalCacheOptionList = array();

    /**
     * load model option list
     * @param boolean $force force option load if we already seen this model before
     */
    private function loadModelOptions($force = false)
    {
        // only collect options once per source/filter combination, we use a static to save our unique option
        // combinations over the running application.
        if (!isset(self::$internalCacheOptionList[$this->internalCacheKey]) || $force) {
            self::$internalCacheOptionList[$this->internalCacheKey] = array();
            foreach ($this->mdlStructure as $modelData) {
                // only handle valid model sources
                if (!isset($modelData['source']) || !isset($modelData['items']) || !isset($modelData['display'])) {
                    continue;
                }

                // handle optional/missing classes, i.e. from plugins
                $className = str_replace('.', '\\', $modelData['source']);
                if (!class_exists($className)) {
                    continue;
                }
                if (
                    $this->getParentModel() !== null &&
                        strcasecmp(get_class($this->getParentModel()), $className) === 0
                ) {
                    // model options from the same model, use this model in stead of creating something new
                    $modelObj = $this->getParentModel();
                    $this->internalOptionsFromThisModel = true;
                } else {
                    $modelObj = new $className();
                }

                $groupKey = isset($modelData['group']) ? $modelData['group'] : null;
                $displayKey = $modelData['display'];
                $groups = array();

                $searchItems = $modelObj->getNodeByReference($modelData['items']);
                if (!empty($searchItems)) {
                    foreach ($modelObj->getNodeByReference($modelData['items'])->iterateItems() as $node) {
                        if (!isset($node->getAttributes()['uuid']) || $node->$displayKey == null) {
                            continue;
                        }

                        if (isset($modelData['filters'])) {
                            foreach ($modelData['filters'] as $filterKey => $filterValue) {
                                $fieldData = $node->$filterKey;
                                if (!preg_match($filterValue, $fieldData) && $fieldData != null) {
                                    continue 2;
                                }
                            }
                        }

                        if (!empty($groupKey)) {
                            if ($node->$groupKey == null) {
                                continue;
                            }
                            $group = (string)$node->$groupKey;
                            if (isset($groups[$group])) {
                                continue;
                            }
                            $groups[$group] = 1;
                        }

                        $uuid = $node->getAttributes()['uuid'];
                        self::$internalCacheOptionList[$this->internalCacheKey][$uuid] =
                            (string)$node->$displayKey;
                    }
                }
                unset($modelObj);
            }

            if (!$this->internalIsSorted) {
                natcasesort(self::$internalCacheOptionList[$this->internalCacheKey]);
            }
        }
        // Set for use in BaseListField->getNodeData()
        $this->internalOptionList = self::$internalCacheOptionList[$this->internalCacheKey];
    }

    /**
     * Set model as reference list, use uuid's as key
     * @param $mdlStructure nested array structure defining the usable datasources.
     */
    public function setModel($mdlStructure)
    {
        // only handle array type input
        if (!is_array($mdlStructure)) {
            return;
        } else {
            $this->mdlStructure = $mdlStructure;
            // set internal key for this node based on sources and filter criteria
            $this->internalCacheKey = md5(serialize($mdlStructure));
        }
    }

    /**
     * load model options when initialized
     */
    protected function actionPostLoadingEvent()
    {
        $this->loadModelOptions();
    }

    /**
     * select if sort order should be maintained
     * @param $value boolean value Y/N
     */
    public function setSorted($value)
    {
        $this->internalIsSorted = trim(strtoupper($value)) == "Y";
    }

    /**
     * get valid options, descriptions and selected value
     * performs sorting on internalOptionsList
     * Doing this here instead of in loadModelOptions()
     * otherwise internalIsSorted would have to be set
     * the first time loadModelOptions() runs. This allows
     * it to change later.
     * @return array
     */
    public function getNodeData()
    {
        if (
            isset($this->internalOptionList) &&
            is_array($this->internalOptionList)
        ) {
            // Get selected items into an array.
            $datanodes = explode(',', $this->internalValue);
            if ($this->internalIsSorted) {
                // Establish an array of keys with the selected keys as the first entires.
                $optKeys = $datanodes;
                foreach (array_keys($this->internalOptionList) as $key) {
                    // Append each non-selected key to the array one-by-one.
                    if (!in_array($key, $optKeys)) {
                        $optKeys[] = $key;
                    }
                }
                // Perform reordering of the option list based on the newly ordered keys array.
                $ordered_option_list = array();
                // Iterate through each key and inject the key:value pair from internalOptionList.
                foreach ($optKeys as $key) {
                    // Prevent arbitrary $key values, check that $key is in internalOptionList.
                    if (in_array($key, array_keys($this->internalOptionList))) {
                        $ordered_option_list[$key] = $this->internalOptionList[$key];
                    }
                }
                $this->internalOptionList = $ordered_option_list;
            }
        }

        return parent::getNodeData();
    }

    /**
     * retrieve field validators for this field type
     * @return array returns Text/regex validator
     */
    public function getValidators()
    {
        if ($this->internalValue != null) {
            // if our options come from the same model, make sure to reload the options before validating them
            $this->loadModelOptions($this->internalOptionsFromThisModel);
        }
        // Use validators from BaseListField, includes validations for multi-select, and single-select.
        return parent::getValidators();
    }
}
