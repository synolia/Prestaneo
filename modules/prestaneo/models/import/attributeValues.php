<?php

class ImportAttributeValues extends ImportAbstract
{

    public static $order = 4;

    /**
     * Imports attributes and features values
     *
     * @return bool
     * @throws Exception
     */
    public function process()
    {
        $delimiter = Configuration::get(MOD_SYNC_NAME . '_IMPORT_DELIMITER') ? Configuration::get(MOD_SYNC_NAME . '_IMPORT_DELIMITER') : ';';

        $folderUtil = Utils::exec('folder');
        $path       = $this->_manager->getPath() . '/files/';

        if ($folderUtil->isFolderEmpty($path)) {
            $fileTransferHost = Configuration::get(MOD_SYNC_NAME . '_ftphost');
            if (!empty($fileTransferHost)) {
                if (_PS_MODE_DEV_) {
                    $this->log('Fetching files from FTP');
                }
                $fileTransfer = $this->_getFtpConnection();

                if (!$fileTransfer->getFiles(Configuration::get(MOD_SYNC_NAME . '_IMPORT_ATTRIBUTE_VALUES_FTP_PATH'), $path, '*.csv')) {
                    $this->logError('There was an error while retrieving the files from the FTP');
                    $folderUtil->delTree($path, false);
                    return false;
                }
            }
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

        $nameField = MappingAttributeValues::getAkeneoField('name');
        $nameOffsets = array();

        foreach ($this->_langs as $isoCode => $idLang) {
            if (array_key_exists($nameField . '-' . $isoCode, $this->_offsets)) {
                $nameOffsets[$this->_langs[$isoCode]] = $this->_offsets[$nameField . '-' . $isoCode];
            }
        }

        $this->_mapOffsets(array('Attribute', 'FeatureValue'), MappingAttributeValues::getAllPrestashopFields());

        $this->_offsets['special']['name'] = $nameOffsets;

        $distinctAxes = MappingTmpAttributes::getDistinctAxes();
        if ($distinctAxes === false) {
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

            $code      = $data[$this->_offsets['special']['code']];
            $attribute = $data[$this->_offsets['special']['attribute_group']];

            $names = array();
            foreach ($this->_offsets['special']['name'] as $langId => $offset) {
                $names[$langId] = $data[$offset];
            }

            if (in_array($attribute, $distinctAxes)) {
                if (!$this->_addOrUpdateAttributeValue($attribute, $code, $names, $defaultShopId)) {
                    $errorCount++;
                }
            }

            if (!$this->_addOrUpdateFeatureValue($attribute, $code, $names)) {
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

        $attributeId = MappingCodeAttributeValues::getIdByCodeAndGroup($code, $attributeGroupName);

        if (!$attributeId) {
            $attribute                     = new Attribute();
            $attribute->id_attribute_group = $attributeGroupId;
            $attribute->position           = Attribute::getHigherPosition($attributeGroupId) + 1;
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
                    $mappingAttribute                       = new MappingCodeAttributeValues();
                    $mappingAttribute->code                 = $code;
                    $mappingAttribute->id_attribute_value   = $attribute->id;
                    $mappingAttribute->code_attribute_group = $attributeGroupName;

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
     *
     * @return bool
     */
    protected function _addOrUpdateFeatureValue($featureName, $code, $values)
    {
        $featureId = MappingCodeFeatures::getIdByCode($featureName);

        if (!$featureId) {
            $this->logError('Attribute group ' . $featureName . ' does not exist');
            return false;
        }

        $featureValueId = MappingCodeFeatureValues::getIdByCodeAndFeature($code, $featureName);

        if (!$featureValueId) {
            $featureValue             = new FeatureValue();
            $featureValue->id_feature = $featureId;
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
                $mappingFeature->code_feature     = $featureName;

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