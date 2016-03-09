<?php

/*
Class qui permet de vider les fichiers de logs et de netoyer les anciens fichiers
*/

class Cleaner
{
    public function cleanerAction()
    {
        $daysHistory = Configuration::get(MOD_SYNC_NAME.'_dayshistoryfiles');
        $dateDelete = strtotime(date('Y-m-d H:i:s') . ' -'.$daysHistory.' days');

        /* Clean import folder */

        $importDirs = glob(_PS_ROOT_DIR_.'/modules/'.MOD_SYNC_NAME.'/import/*');

        foreach($importDirs as $importDir) {

            $subDirs = glob($importDir.'/success/*');
            foreach($subDirs as $successDir) {
                Tools::deleteDirectory($successDir);
            }

            $subDirs = glob($importDir.'/error/*');
            foreach($subDirs as $errorDir) {
                if($daysHistory && !empty($daysHistory)) {
                    if(filemtime($errorDir) > $dateDelete) {
                        continue;
                    }
                }
                Tools::deleteDirectory($errorDir);
            }
        }
    }
}