<?php

/*
Class de log qui permet d'ecrire un fichier pour chaque imports / exports
*/

class Logger
{
    protected $_path;

    public function __construct($manager)
    {
        $this->_path = $manager->getPath();
    }

    public function writeLog($txt)
    {
        if((strpos(date('d/m/Y H:i:s'), $txt) === false || strpos(date('Y-m-d H:i:s'), $txt) === false)
        && strpos(date('Y'), $txt) === false && strpos(date('H:i'), $txt) === false)
            $txt = date('d/m/Y H:i:s').' '.$txt;
        $fp = fopen($this->_path.'/log.txt','a+');
        fseek($fp,SEEK_END);

        $chaine = $txt."\r\n";

        fputs($fp,utf8_decode($chaine));

        fclose($fp);
    }

}