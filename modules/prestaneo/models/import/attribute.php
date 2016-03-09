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
        $delimiter = Configuration::get('PS_IMPORT_DELIMITER') ? Configuration::get('PS_IMPORT_DELIMITER') : ';';

        $fileTransferHost = Configuration::get(MOD_SYNC_NAME.'_ftphost');
        if(!empty($fileTransferHost)) {
            $fileTransfer = $this->_getFtpConnection();

            $fileTransfer->getFiles(Configuration::get('PS_IMPORT_ATTRIBUTEFTPPATH'), $this->_manager->getPath().'/files/', '*.csv');
        }
        $reader    = new CsvReader($this->_manager, $delimiter);
        $dataLines = $reader->getData();

        if (!is_array($dataLines) || empty($dataLines)){
            $this->log('Nothing to import or file is not valid CSV');
            $this->_mover->finishAction(basename($reader->getCurrentFileName()), false);
            return false;
        }

        $requiredFields = MappingFeatures::getRequiredFields('akeneo');
        $headers        = $this->_cleanDataLine(array_shift($dataLines));
        $missingFields  = $this->_checkMissingRequiredFields($requiredFields, $headers);

        if (!empty($missingFields)) {
            $this->log('Missing required fields : ' . implode(', ', $missingFields));
            $this->_mover->finishAction(basename($reader->getCurrentFileName()), false);
            return false;
        }

        $this->_offsets = array_flip($headers);

        $this->_getLangsInCsv($headers);
        $this->_mapOffsets(MappingFeatures::getAllPrestashopFields());

        $arrDistinctAxes = MappingTmpAttributes::getDistinctAxes();
        if ($arrDistinctAxes === false) {
            $this->log("There was a problem while retrieving axes");
            $this->_mover->finishAction(basename($reader->getCurrentFileName()), false);
            return false;
        }

        $positionCode   = $this->_offsets['code'];
        $currentFile    = $reader->getCurrentFileName(0);
        $lastErrorCount = 0;

        foreach ($dataLines as $line => $data) {
            $nextFile = $reader->getCurrentFileName($line);
            if ($nextFile != $currentFile) {
                $treatmentResult = 1;
                if(count($this->_errors) > $lastErrorCount)
                    $treatmentResult = 0;
                $this->_mover->finishAction(basename($currentFile), $treatmentResult, 'import');
                $lastErrorCount = count($this->_errors);
                $currentFile    = $nextFile;
            }

            if (empty($data)) {
                continue;
            }
            $data = $this->_cleanDataLine($data);

            $code = $data[$positionCode];

            if (empty($code)) {
                $this->log("Missing code on line " . ($line + 2));
                continue;
            }

            if (MappingProducts::existCodeAkeneo($code)) {
                if (_PS_MODE_DEV_) {
                    $this->log("PrestaShop native field $code");
                }
                continue;
            } elseif (_PS_MODE_DEV_) {
                $this->log("New field $code");
            }

            $names = array();

            foreach ($this->_langs as $id_lang => $iso_code) {
                $suffixLang      = '-' . $this->_labels[$iso_code];
                $names[$id_lang] = $data[$this->_offsets['name' . $suffixLang]];
            }

            //Add or update AttributeGroup
            if (in_array($code, $arrDistinctAxes)) {
                $attributeGroupId = MappingCodeAttributes::getIdByCode($code);

                if ($attributeGroupId !== false) {
                    $attributeGroup = new AttributeGroup($attributeGroupId);
                    $attributeGroupExists = true;
                } else {
                    $attributeGroup = new AttributeGroup();
                    $attributeGroupExists = false;
                }

                $attributeGroup->group_type  = $this->_defaultAttributeGroupType;
                $attributeGroup->name        = $names;
                $attributeGroup->public_name = $names;

                if (!$attributeGroup->save()) {
                    $this->log('Could not save attribute group ' . $code);
                    continue;
                }

                if (!$attributeGroupExists) {
                    $mapping = new MappingCodeAttributes();
                    $mapping->code               = $code;
                    $mapping->id_attribute_group = $attributeGroup->id;

                    if (!$mapping->add()) {
                        $this->log('Could not save mapping for ' . $code);
                        continue;
                    }
                }

                continue;
            }

            //Add or update Feature

            $idFeature = MappingCodeFeatures::getIdByCode($code);

            if ($idFeature === false) {
                $feature = new Feature();
                $featureExists = false;
                $feature->position = Feature::getHigherPosition() + 1;
            } else {
                $feature = new Feature($idFeature);
                $featureExists = true;
            }

            $feature->name = $names;

            if (!$feature->save()) {
                $this->log('Could not save new feature ' . $code);
                continue;
            }

            if (!$featureExists) {
                $mapping = new MappingCodeFeatures();
                $mapping->id_feature = $feature->id;
                $mapping->code       = $code;

                if (!$mapping->add()) {
                    $this->log('Could not save mapping for feature ' . $code);
                    continue;
                }
            }
        }

        $this->_mover->finishAction(basename($reader->getCurrentFileName()), true);
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
}