<?php

class ImportVariant extends ImportAbstract
{
    public static $icon  = 'list';
    public static $order = 2;

    /**
     * Imports variant groups
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

            $fileTransfer->getFiles(Configuration::get('PS_IMPORT_VARIANTFTPPATH'), $this->_manager->getPath().'/files/', '*.csv');
        }
        $reader    = new CsvReader($this->_manager, $delimiter);
        $dataLines = $reader->getData();

        if (!is_array($dataLines) || empty($dataLines)){
            ('Nothing to import or file is not valid CSV');
            $this->_mover->finishAction(basename($reader->getCurrentFileName()), false);
            return false;
        }

        $requiredFields = MappingAttributes::getRequiredFields('akeneo');
        $headers        = $this->_cleanDataLine(array_shift($dataLines));
        $missingFields  = $this->_checkMissingRequiredFields($requiredFields, $headers);

        if (!empty($missingFields)) {
            ('Missing required fields : ' . implode(', ', $missingFields));
            $this->_mover->finishAction(basename($reader->getCurrentFileName()), false);
            return false;
        }

        self::_resetMappingTmpAttributes();

        $this->_offsets = array_flip($headers);

        $this->_mapOffsets(MappingAttributes::getAllPrestashopFields());

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

            if ($data === false || $data == array(null)) {
                continue;
            }
            $data = $this->_cleanDataLine($data);

            $code = $data[$this->_offsets['code']];
            $axis = $data[$this->_offsets['axis']];

            if (empty($code) || empty($axis)) {
                $this->log('Code and/or axis are empty on line ' . ($line + 2));
                continue;
            }

            $id = MappingTmpAttributes::getIdByCode($code);
            if ($id) {
                $mapping = new MappingTmpAttributes($id);
            } else {
                $mapping = new MappingTmpAttributes();
            }

            $mapping->code = $code;
            $mapping->axis = $axis;

            if (!$mapping->save()){
                $this->log('Error while saving : ' . $code . '/' . $axis . ' (line ' . ($line + 2) . ')');
            } elseif (_PS_MODE_DEV_) {
                $this->log('Variant ' . $code . ' added');
            }
        }

        $this->_mover->finishAction(basename($reader->getCurrentFileName()), true);
        return true;
    }

    protected static function _resetMappingTmpAttributes()
    {
        $collection = new PrestaShopCollection('MappingTmpAttributes');

        foreach ($collection->getResults() as $mapping) {
            $mapping->delete();
        }
    }
}