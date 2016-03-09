<?php

function upgrade_module_1_8(Prestaneo $module) {
    $return = true;
    $module->setConfirmations("System has been moved to utils.");

    Db::getInstance()->Execute('ALTER TABLE `'. _DB_PREFIX_.MOD_SYNC_NAME.'_actions` ENGINE = InnoDB');

    if(!$module->installProgressDatabase())
        $return = false;

    $errors = $module->getErrors();

    if(count($errors) && $return)
        $module->setError('Module has been upgraded, precedent error(s) are non blocking error(s)');

    return $return;
}