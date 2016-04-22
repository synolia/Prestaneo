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

                if (!$fileTransfer->getFiles(Configuration::get(MOD_SYNC_NAME . '_IMPORT_VARIANT_FTP_PATH'), $path, '*.csv')) {
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

        $requiredFields = MappingAttributes::getRequiredFields('akeneo');
        $headers        = $this->_cleanDataLine(array_shift($dataLines));
        $missingFields  = $this->_checkMissingRequiredFields($requiredFields, $headers);

        if (!empty($missingFields)) {
            ('Missing required fields : ' . implode(', ', $missingFields));
            $this->_mover->finishAction(basename($currentFile), false);
            return false;
        }

        self::_resetMappingTmpAttributes();

        $this->_offsets = array_flip($headers);

        $this->_mapOffsets('MappingTmpAttributes', MappingAttributes::getAllPrestashopFields());

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

            if ($data === false || $data == array(null)) {
                continue;
            }
            $data = $this->_cleanDataLine($data);

            $code = $data[$this->_offsets['default']['code']];
            $axis = $data[$this->_offsets['default']['axis']];

            if (empty($code) || empty($axis)) {
                $this->logError('Code and/or axis are empty on line ' . ($line + 2));
                $errorCount++;
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
                $this->logError('Error while saving : ' . $code . '/' . $axis . ' (line ' . ($line + 2) . ')');
                $errorCount++;
            } elseif (_PS_MODE_DEV_) {
                $this->log('Variant ' . $code . ' added');
            }
        }
        $endStatus = ($errorCount == $lastErrorCount);
        $this->_mover->finishAction(basename($currentFile), $endStatus);
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