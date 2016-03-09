<?php

function upgrade_module_1_8_1(Prestaneo $module) {
    $return = true;

    // Change Engine InnoDb
    if (!Db::getInstance()->Execute('ALTER TABLE `'. _DB_PREFIX_.MOD_SYNC_NAME.'_status` ENGINE = InnoDB')
    || !Db::getInstance()->Execute('ALTER TABLE `'. _DB_PREFIX_.MOD_SYNC_NAME.'_modules` ENGINE = InnoDB')
    || !Db::getInstance()->Execute('ALTER TABLE `'. _DB_PREFIX_.MOD_SYNC_NAME.'_actions` ENGINE = InnoDB')
    || !Db::getInstance()->Execute('ALTER TABLE `'. _DB_PREFIX_.MOD_SYNC_NAME.'_cronjobs` ENGINE = InnoDB'))
    {
        $module->setError('Can\'t change database engine from MyIsam to InnoDb');
    }

    // New fields
    $fetch = Db::getInstance()->executeS('SHOW COLUMNS FROM `'. _DB_PREFIX_.MOD_SYNC_NAME.'_status` LIKE "date_end"');
    if ((!is_array($fetch) || count($fetch)==0))
    {
        if (!Db::getInstance()->Execute('ALTER TABLE `'. _DB_PREFIX_.MOD_SYNC_NAME.'_status` ADD `date_end` datetime'))
        {
            $module->setError('Can\'t add new column date_end in table status');
            return false;
        }
    }
    $fetch = Db::getInstance()->executeS('SHOW COLUMNS FROM `'. _DB_PREFIX_.MOD_SYNC_NAME.'_status` LIKE "emulate_date"');
    if ((!is_array($fetch) || count($fetch)==0))
    {
        if (!Db::getInstance()->Execute('ALTER TABLE `'. _DB_PREFIX_.MOD_SYNC_NAME.'_status` ADD `emulate_date` TINYINT(1)'))
        {
            $module->setError('Can\'t add new column emulate_date in table status');
            return false;
        }
    }
    $fetch = Db::getInstance()->executeS('SHOW COLUMNS FROM `'. _DB_PREFIX_.MOD_SYNC_NAME.'_status` LIKE "comments"');
    if ((!is_array($fetch) || count($fetch)==0))
    {
        if (!Db::getInstance()->Execute('ALTER TABLE `'. _DB_PREFIX_.MOD_SYNC_NAME.'_status` ADD `comments` varchar(255)'))
        {
            $module->setError('Can\'t add new column comments in table status');
            return false;
        }
    }

    // New Hook For Add New Libraries
    if (!$module->registerHook('actionAdminControllerSetMedia')
    ||  !$module->registerHook('dashboardZoneTwo'))
    {
        $module->setError('Can\'t register to new hooks');
    }

    if(!Configuration::updateValue(MOD_SYNC_NAME.'_SHOW_STATUS', 1))
        $module->setError('Can\'t update '.MOD_SYNC_NAME.'_SHOW_STATUS configuration value');

    // Install New Tabs
    if(!$module->installModuleTabs())
    {
        $module->setError('Can\'t install module tabs');
    }

    // Errors
    $errors = $module->getErrors();

    // Notifications
    if(count($errors) && $return)
        $module->setError('Module has been upgraded, precedent error(s) are non blocking error(s)');

    return $return;
}