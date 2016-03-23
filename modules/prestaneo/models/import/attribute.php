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

        $fileTransferHost = Configuration::get(MOD_SYNC_NAME.'_ftphost');
        if(!empty($fileTransferHost)) {
            $fileTransfer = $this->_getFtpConnection();

            $fileTransfer->getFiles(Configuration::get(MOD_SYNC_NAME . '_IMPORT_ATTRIBUTE_FTP_PATH'), $this->_manager->getPath().'/files/', '*.csv');
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
        $this->_mapOffsets(MappingFeatures::getAllPrestashopFields());

        $arrDistinctAxes = MappingTmpAttributes::getDistinctAxes();
        if ($arrDistinctAxes === false) {
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

            $code = $data[$this->_offsets['code']];
            $type = $data[$this->_offsets['type']];

            if (empty($code)) {
                $this->logError('Missing code on line ' . ($line + 2));
                continue;
            }

            //Images have a special treatment as there can be many linked to the same PrestaShop field
            if ($type == 'pim_catalog_image') {
                if (!MappingProducts::existCodeAkeneo($code)) {
                    $mapping = new MappingProducts();
                    $mapping->champ_akeneo     = $code;
                    $mapping->champ_prestashop = 'image';

                    if (!$mapping->add()) {
                        $this->logError('Could not save mapping for image field ' . $code);
                        $errorCount++;
                    } elseif (_PS_MODE_DEV_) {
                        $this->log('New image attribute ' . $code . ' added');
                    }
                } elseif (_PS_MODE_DEV_) {
                    $this->log('Image attribute ' . $code . ' already known');
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

            $names = array();

            foreach ($this->_langs as $id_lang => $iso_code) {
                $suffixLang      = '-' . $this->_labels[$iso_code];
                $names[$id_lang] = $data[$this->_offsets['name' . $suffixLang]];
            }

            if (in_array($code, $arrDistinctAxes)) {
                if (!$this->_addOrUpdateAttributeGroup($code, $names)) {
                    $this->logError('Could not save attribute ' . $code);
                    $errorCount++;
                }
            }
            if (!$this->_addOrUpdateFeature($code, $type, $names)) {
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
     * @param array  $names
     *
     * @return bool
     */
    protected function _addOrUpdateAttributeGroup($code, $names)
    {
        $attributeGroupId = MappingCodeAttributes::getIdByCode($code);

        if (!$attributeGroupId) {
            $attributeGroup = new AttributeGroup();
        } else {
            $attributeGroup = new AttributeGroup($attributeGroupId);
        }

        $attributeGroup->group_type  = $this->_defaultAttributeGroupType;
        $attributeGroup->name        = $names;
        $attributeGroup->public_name = $names;

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
     * @param array  $names
     *
     * @return bool
     */
    protected function _addOrUpdateFeature($code, $type, $names)
    {
        $idFeature = MappingCodeFeatures::getIdByCode($code);

        if (!$idFeature) {
            $feature           = new Feature();
            $feature->position = Feature::getHigherPosition() + 1;
        } else {
            $feature = new Feature($idFeature);
        }

        $feature->name = $names;

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