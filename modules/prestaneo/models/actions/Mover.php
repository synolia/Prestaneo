<?php

/*
 * Déplace les fichiers temporaires vers des dossiers de success ou d'erreurs (pour chaque import / export)
 */

class Mover
{
    protected $_path;
    protected $_fullFilePath = false;

    public function __construct($manager)
    {
        $this->_path = $manager->getPath();
    }

    public function finishAction($file, $result, $type = 'import')
    {
        $timeFolder = time();

        if(!$result)
            $folder = '/error';
        else
            $folder = '/success';

        $timePath = $this->_path.$folder.'/'.$timeFolder;
        if(!file_exists($timePath))
            mkdir($timePath, 0775, true);

        if($type == 'import')
        {
            rename($this->_path.'/files/'.$file, $timePath.'/'.$file);
        }
        if($type == 'export')
        {
            rename($this->_path.'/temp/'.$file, $timePath.'/'.$file);
        }
        $this->_fullFilePath = $timePath.'/'.$file;


        return $timePath.'/'.$file;
    }

    public function getFullFilePath()
    {
        return $this->_fullFilePath;
    }
}