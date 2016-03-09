<?php

$path       = dirname(__FILE__);
$moduleName = basename($path);
include($path.'/../../config/config.inc.php');
include($path.'/../../init.php');

Module::getInstanceByName($moduleName)->launchAction(Tools::getValue('action'));
