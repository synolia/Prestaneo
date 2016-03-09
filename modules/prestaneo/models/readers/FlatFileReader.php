<?php

/**
 * Class FlatFileReader
 *
 * To use it, call the public template method FileReader::getData();
 */
Class FlatFileReader extends FileReader
{
    public $lineBytesNumber;

    public function __construct($manager, $lineBytesNumber=4096)
    {
        parent::__construct($manager);
        $this->lineBytesNumber = $lineBytesNumber;
    }

    protected function _parseFiles($filesNames)
    {
        $arrayResult = array();

        foreach ($filesNames as $file)
        {
            $this->_logger->writeLog(date("d/m/Y H:i:s").' Traitement '.$file);

            if (!$fileHandle = fopen($file, 'r'))
            {
                $this->_logger->writeLog("Impossible d'ouvrir le fichier ($file)");
                $this->_mover->finishAction($file, false);
                return 0;
            }

            $start = count($arrayResult);
            while (!feof($fileHandle))
            {
                $line          = fgets($fileHandle, $this->lineBytesNumber);
                $arrayResult[] = $line;
            }
            $end = count($arrayResult)-1;

            $this->setFileDataOffsets($start, $end, $file);

            $this->setCurrentFileName($file);
            fclose($fileHandle);
        }

        return $arrayResult;
    }
}