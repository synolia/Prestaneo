<?php

class CacheManager
{
    protected $_manager;

    public function __construct($manager)
    {
        $this->_manager = $manager;
    }

    public function FileCached($type, $action)
    {
        $cache = Configuration::get(MOD_SYNC_NAME.'_cache');

        if(!$cache)
            return;

        $dateFile = strtotime(date('Y-m-d H:i:s') . ' -'.$cache.' minutes');

        $subDirs = glob(_PS_ROOT_DIR_.'/modules/'.MOD_SYNC_NAME.'/'.$type.'/'.$action.'/success/*');

        foreach($subDirs as $successDir)
        {
            if(filemtime($successDir) > $dateFile)
            {
                $arrayDir = scandir($successDir);

                foreach($arrayDir as $fileName)
                {
                    if(is_file(($filePath = $successDir.'/'.$fileName)))
                    {
                        $logger = new Logger($this->_manager);
                        $logger->writeLog(date("d/m/Y H:i:s")." file cached return");

                        return $filePath;
                    }
                }
            }
        }

        return false;
    }

}