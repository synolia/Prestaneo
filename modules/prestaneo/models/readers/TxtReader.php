<?php

/**
 * Class TxtReader
 *
 * To use it, call the public template method FileReader::getData();
 */
Class TxtReader extends FileReader
{
    public $fieldSeparator;
    public $lineBytesNumber;

    public function __construct($manager, $separator = ';', $lineBytesNumber=4096)
    {
        parent::__construct($manager);
        $this->fieldSeparator  = $separator;
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
                return 0;
            }

            $start = count($arrayResult);
            while (!feof($fileHandle))
            {
                $line                = fgets($fileHandle,4096);
                $txtFieldsValues     = explode($this->fieldSeparator , $line);
                $arrayResult[]       = $txtFieldsValues;
            }
            $end = count($arrayResult)-1;

            $this->setFileDataOffsets($start, $end, $file);

            $this->setCurrentFileName($file);
            fclose($fileHandle);
        }
        return $arrayResult;
    }
}