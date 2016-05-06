<?php

class ImportAttribute extends ImportAbstract
{
    protected $_defaultAttributeGroupType = "select";

    public static $icon  = 'copy';
    public static $order = 3;

    /**
     * Imports attributes
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

                if (!$fileTransfer->getFiles(Configuration::get(MOD_SYNC_NAME . '_IMPORT_ATTRIBUTE_FTP_PATH'), $path, '*.csv')) {
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
            $this->logError('Nothing to import or file is not valid CSV');
            $this->_mover->finishAction(basename($currentFile), false);
            return false;
        }

        $requiredFields = MappingFeatures::getRequiredFields('akeneo');
        $headers        = $this->_cleanDataLine(array_shift($dataLines));
        $missingFields  = $this->_checkMissingRequiredFields($requiredFields, $headers);

        if (!empty($missingFields)) {
            $this->logError('Missing required fields : ' . implode(', ', $missingFields));
            $this->_mover->finishAction(basename($currentFile), false);
            return false;
        }

        $this->_offsets = array_flip($headers);

        $this->_getLangsInCsv($headers);
        $this->_mapOffsets(array('AttributeGroup', 'Feature'), MappingFeatures::getAllPrestashopFields());

        $distinctAxes = MappingTmpAttributes::getDistinctAxes();
        if ($distinctAxes === false) {
            $this->logError("There was a problem while retrieving axes");
            $this->_mover->finishAction(basename($currentFile), false);
            return false;
        }

        $currentFile    = $reader->getCurrentFileName(0);
        $errorCount     = 0;
        $lastErrorCount = 0;

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

            $code = $data[$this->_offsets['special']['code']];
            $type = $data[$this->_offsets['special']['type']];

            if (empty($code)) {
                $this->logError('Missing code on line ' . ($line + 2));
                continue;
            }

            //Images and files have a special treatment as there can be many linked to the same PrestaShop field
            if ($type == 'pim_catalog_image' || $type == 'pim_catalog_file') {
                $separatorOffset = strrpos($type, '_');
                if ($separatorOffset === false) {
                    $fieldType = $type;
                } else {
                    $fieldType = substr($type, $separatorOffset + 1);
                }

                if (!MappingProducts::existCodeAkeneo($code)) {
                    $mapping = new MappingProducts();
                    $mapping->champ_akeneo     = $code;
                    $mapping->champ_prestashop = $fieldType;
                    $mapping->required         = false;

                    if (!$mapping->add()) {
                        $this->logError('Could not save mapping for ' . $fieldType . ' field ' . $code);
                        $errorCount++;
                    } elseif (_PS_MODE_DEV_) {
                        $this->log('New ' . $fieldType . ' attribute ' . $code . ' added');
                    }
                } elseif (_PS_MODE_DEV_) {
                    $this->log(ucfirst($fieldType) . ' attribute ' . $code . ' already known');
                }
                continue;
            }

            if (MappingProducts::existCodeAkeneo($code)) {
                if (_PS_MODE_DEV_) {
                    $this->log('PrestaShop native field ' . $code);
                }
                continue;
            } elseif (_PS_MODE_DEV_) {
                $this->log('New field ' . $code);
            }

            $values = array();

            foreach ($this->_offsets['lang'] as $field => $offsets) {
                foreach ($offsets as $idLang => $offset) {
                    $values[$field][$idLang] = $data[$offset];
                }
            }

            foreach ($this->_offsets['default'] as $field => $offset) {
                $values[$field] = $data[$offset];
            }

            foreach ($this->_offsets['date'] as $field => $offset) {
                if (Validate::isDate($data[$offset])) {
                    $values[$field] = $data[$offset];
                } else {
                    $this->logError('Wrong date format for field ' . $field . ' : ' . $data['offset'] . '(for ' . $code . ')');
                    $errorCount++;
                }
            }

            if (in_array($code, $distinctAxes)) {
                if (!$this->_addOrUpdateAttributeGroup($code, $values)) {
                    $this->logError('Could not save attribute ' . $code);
                    $errorCount++;
                }
            }
            if (!$this->_addOrUpdateFeature($code, $type, $values)) {
                $this->logError('Could not save feature ' . $code);
                $errorCount++;
            }
        }

        $endStatus = ($errorCount == $lastErrorCount);
        $this->_mover->finishAction(basename($currentFile), $endStatus);
        return true;
    }

    /**
     * Remove all mapping features
     */
    protected static function _resetMappingFeatures()
    {
        $collection = new PrestaShopCollection('MappingCodeFeatures');

        foreach ($collection->getResults() as $mapping) {
            $mapping->delete();
        }
    }

    /**
     * @param string $code
     * @param array  $values
     *
     * @return bool
     */
    protected function _addOrUpdateAttributeGroup($code, $values)
    {
        $attributeGroupId = MappingCodeAttributes::getIdByCode($code);

        if (!$attributeGroupId) {
            $attributeGroup = new AttributeGroup();
        } else {
            $attributeGroup = new AttributeGroup($attributeGroupId);
        }

        $attributeGroup->group_type = $this->_defaultAttributeGroupType;

        foreach ($values as $field => $value) {
            $attributeGroup->{$field} = $value;
        }

        if (!array_key_exists('public_name', $values)) {
            $attributeGroup->public_name = $attributeGroup->name;
        }

        if (!$attributeGroup->save()) {
            $this->logError('Could not save attribute group ' . $code);
            return false;
        }

        if (!$attributeGroupId) {
            $mapping                     = new MappingCodeAttributes();
            $mapping->code               = $code;
            $mapping->id_attribute_group = $attributeGroup->id;

            if (!$mapping->add()) {
                $this->logError('Could not save mapping for ' . $code);
                return false;
            }
        }
        if (_PS_MODE_DEV_) {
            $this->log('New attribute saved : ' . $code);
        }
        return true;
    }

    /**
     * @param string $code
     * @param string $type
     * @param array  $values
     *
     * @return bool
     */
    protected function _addOrUpdateFeature($code, $type, $values)
    {
        $idFeature = MappingCodeFeatures::getIdByCode($code);

        if (!$idFeature) {
            $feature           = new Feature();
            $feature->position = Feature::getHigherPosition() + 1;
        } else {
            $feature = new Feature($idFeature);
        }

        foreach ($values as $field => $value) {
            $feature->{$field} = $value;
        }

        if (!$feature->save()) {
            $this->logError('Could not save new feature ' . $code);
            return false;
        }

        if (!$idFeature) {
            $mapping             = new MappingCodeFeatures();
            $mapping->id_feature = $feature->id;
            $mapping->code       = $code;
            $mapping->type       = $type;

            if (!$mapping->add()) {
                $this->logError('Could not save mapping for feature ' . $code);
                return false;
            }
        }
        if (_PS_MODE_DEV_) {
            $this->log('New feature saved : ' . $code);
        }
        return true;
    }
}