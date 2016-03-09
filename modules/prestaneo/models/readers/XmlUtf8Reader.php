<?php

/**
 * Class XmlUtf8Reader
 *
 * To use it, call the public template method FileReader::getData();
 */
Class XmlUtf8Reader extends FileReader
{
    public function __construct($manager)
    {
        parent::__construct($manager);
    }

    protected function _parseFiles($files)
    {
        $arrayResult = array();
        libxml_use_internal_errors(true);
        foreach ($files as $file)
        {
            $this->_logger->writeLog(date('d/m/Y H:i:s').' Traitement '.$file);

            if (!$fileContents = file_get_contents($this->_path.'/files/'.$file))
            {
                $this->_logger->writeLog('Impossible d\'ouvrir le fichier '.$file.'');
                $this->_mover->finishAction($file, false);
                continue;
            }
            $xmlResult = simplexml_load_string($fileContents, 'SimpleXMLElement', LIBXML_NOBLANKS | LIBXML_NOCDATA);
            if ($xmlResult===false)
            {
                $xmlErrors = libxml_get_errors();
                $this->_logger->writeLog('Impossible d\'interpreter les données xml dans le fichier '.$file.' => '.$xmlErrors[0]['message']);
                $this->_mover->finishAction($file, false);
                continue;
            }

            $arrayResult[$file] = $xmlResult;
            $this->_logger->writeLog(date('d/m/Y H:i:s').' Fin de lecture du fichier '.$file.' avec succes');
            $this->setCurrentFileName($file);
        }
        return $arrayResult;
    }
}