<?php

class ImportAttributeValues extends ImportAbstract
{
    public static $order = 4;

    public function process()
    {
        $delimiter = Configuration::get(MOD_SYNC_NAME . '_IMPORT_DELIMITER') ? Configuration::get(MOD_SYNC_NAME . '_IMPORT_DELIMITER') : ';';

        $fileTransferHost = Configuration::get(MOD_SYNC_NAME.'_ftphost');
        if(!empty($fileTransferHost)) {
            $fileTransfer = $this->_getFtpConnection();

            $fileTransfer->getFiles(Configuration::get(MOD_SYNC_NAME . '_IMPORT_ATTRIBUTE_VALUES_FTP_PATH'), $this->_manager->getPath().'/files/', '*.csv');
        }

        $reader      = new CsvReader($this->_manager, $delimiter);
        $dataLines   = $reader->getData();
        $currentFile = $reader->getCurrentFileName(0);

        if (!is_array($dataLines) || empty($dataLines)){
            $this->log('Nothing to import or file is not valid CSV');
            $this->_mover->finishAction(basename($currentFile), false);
            return false;
        }

        $requiredFields = MappingAttributeValues::getRequiredFields('akeneo');
        $headers        = $this->_cleanDataLine(array_shift($dataLines));
        $missingFields  = $this->_checkMissingRequiredFields($requiredFields, $headers);

        if (!empty($missingFields)) {
            $this->log('Missing required fields : ' . implode(', ', $missingFields));
            $this->_mover->finishAction(basename($currentFile), false);
            return false;
        }

        $this->_offsets = array_flip($headers);

        $this->_getLangsInCsv($headers);
        $this->_mapOffsets(MappingAttributeValues::getAllPrestashopFields());

        $arrDistinctAxes = MappingTmpAttributes::getDistinctAxes();
        if ($arrDistinctAxes === false) {
            $this->log("There was a problem while retrieving axes");
            $this->_mover->finishAction(basename($currentFile), false);
            return false;
        }

        $currentFile    = $reader->getCurrentFileName(0);
        $lastErrorCount = 0;
        $errorCount     = 0;
        $defaultShopId  = Context::getContext()->shop->id;

        foreach ($dataLines as $line => $data) {
            $nextFile = $reader->getCurrentFileName($line);
            if ($nextFile != $currentFile) {
                $treatmentResult = 1;
                if($errorCount > $lastErrorCount)
                    $treatmentResult = 0;
                $this->_mover->finishAction(basename($currentFile), $treatmentResult, 'import');
                $lastErrorCount = $errorCount;
                $currentFile    = $nextFile;
            }

            if (empty($data)) {
                continue;
            }

            $data = $this->_cleanDataLine($data);

            $code      = $data[$this->_offsets['code']];
            $attribute = $data[$this->_offsets['attribute_group']];

            $names = array();
            foreach ($this->_langs as $langId => $isoCode) {
                $names[$langId] = $data[$this->_offsets['name-' . $this->_labels[$isoCode]]];
            }

            if (in_array($attribute, $arrDistinctAxes)) {
                if (!$this->_addOrUpdateAttributeValue($attribute, $code, $names, $defaultShopId)) {
                    $errorCount++;
                }
            }

            if (!$this->_addOrUpdateFeatureValue($attribute, $code, $names, $defaultShopId)) {
                $errorCount++;
            }
        }

        $endStatus = ($errorCount == $lastErrorCount);
        $this->_mover->finishAction(basename($currentFile), $endStatus);
        return true;
    }

    /**
     * @param string $attributeGroupName
     * @param string $code
     * @param array  $names
     * @param int    $defaultShopId
     *
     * @return bool
     */
    protected function _addOrUpdateAttributeValue($attributeGroupName, $code, $names, $defaultShopId)
    {
        $attributeGroupId = MappingCodeAttributes::getIdByCode($attributeGroupName);

        if (!$attributeGroupId) {
            $this->logError('Attribute group ' . $attributeGroupName . ' does not exist');
            return false;
        }

        $attributeGroup = new AttributeGroup($attributeGroupId);

        $attributeId = MappingCodeAttributeValues::getIdByCode($code);

        if (!$attributeId) {
            $attribute                     = new Attribute();
            $attribute->id_attribute_group = $attributeGroup->id;
            $attribute->position           = Attribute::getHigherPosition($attributeGroup->id) + 1;
        } else {
            $attribute = new Attribute($attributeId);
        }

        $attribute->name = $names;

        if (!$attribute->save()) {
            $this->logError('Could not save attribute ' . $code . ' of group ' . $attributeGroupName);
            return false;
        } else {
            if (!$attribute->associateTo(array($defaultShopId))) {
                $this->logError('Could not associate attribute ' . $code . ' of group ' . $attributeGroupName . ' to shop ' . $defaultShopId);
                return false;
            } else {
                if (!$attributeId) {
                    $mappingAttribute                     = new MappingCodeAttributeValues();
                    $mappingAttribute->code               = $code;
                    $mappingAttribute->id_attribute_value = $attribute->id;

                    if (!$mappingAttribute->save()) {
                        $this->logError('Could not save mapping for attribute ' . $code . ' of group ' . $attributeGroupName);
                        return false;
                    }
                }
            }
        }
        $this->log('New attribute value saved : ' . $code);
        return true;
    }

    /**
     * Adds or updates a feature value
     *
     * @param string $featureName
     * @param string $code
     * @param array  $values
     * @param int    $defaultShopId
     *
     * @return bool
     */
    protected function _addOrUpdateFeatureValue($featureName, $code, $values, $defaultShopId)
    {
        $featureId = MappingCodeFeatures::getIdByCode($featureName);

        if (!$featureId) {
            $this->logError('Attribute group ' . $featureName . ' does not exist');
            return false;
        }

        $feature = new Feature($featureId);

        $featureValueId = MappingCodeFeatureValues::getIdByCode($code);

        if (!$featureValueId) {
            $featureValue             = new FeatureValue();
            $featureValue->id_feature = $feature->id;
            $featureValue->custom     = 0;
        } else {
            $featureValue = new FeatureValue($featureValueId);
        }

        $featureValue->value = $values;

        if (!$featureValue->save()) {
            $this->logError('Could not save feature value ' . $code . ' for feature ' . $featureName);
            return false;
        } else {
            if (!$featureValueId) {
                $mappingFeature                   = new MappingCodeFeatureValues();
                $mappingFeature->code             = $code;
                $mappingFeature->id_feature_value = $featureValue->id;

                if (!$mappingFeature->save()) {
                    $this->logError('Could not save mapping for feature value ' . $code . ' for feature ' . $featureName);
                    return false;
                }
            }
        }
        $this->log('New feature value saved : ' . $code);
        return true;
    }
}