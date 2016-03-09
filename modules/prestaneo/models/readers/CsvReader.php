<?php

/**
 * Class CsvReader
 *
 * To use it, call the public template method FileReader::getData();
 */
class CsvReader extends FileReader
{
    public $fieldSeparator;
    public $enclosure;
    public $lineBytesNumber;

    public function __construct($manager, $separator = ';', $enclosure = '"', $lineBytesNumber=4096)
    {
        parent::__construct($manager);
        $this->fieldSeparator  = $separator;
        $this->enclosure       = $enclosure;
        $this->lineBytesNumber = $lineBytesNumber;
    }

    protected function _parseFiles($filesNames)
    {
        $arrayResult = array();
        foreach($filesNames as $file)
        {
            $this->_logger->writeLog(date("d/m/Y H:i:s").' Traitement '.$file);

            if (!$fileHandle = fopen($this->_path.'/files/'.$file, 'r'))
            {
                $this->_logger->writeLog("Impossible d'ouvrir le fichier ($file)");
                $this->_mover->finishAction($file, false);
                return;
            }

            $start = count($arrayResult);
            while (!feof($fileHandle))
            {
                $csvFieldsValues = fgetcsv($fileHandle, $this->lineBytesNumber, $this->fieldSeparator, $this->enclosure);

                $arrayResult[]   = $csvFieldsValues;
            }
            $end = count($arrayResult)-1;

            $this->setFileDataOffsets($start, $end, $file);

            $this->setCurrentFileName($file);
            fclose($fileHandle);
        }
        return $arrayResult;
    }
}