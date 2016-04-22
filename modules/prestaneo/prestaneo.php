<?php

if (!defined('_PS_VERSION_'))
    exit;

/*
 * Include models files
 */

if(!defined('MOD_FTP'))
    define('MOD_FTP', true);
if(!Module::getInstanceByName('cronjobs')){
    define('MOD_CRON', false);
}else{
    define('MOD_CRON', true);
}

define('MOD_SYNC', 'Prestaneo', true);
define('MOD_SYNC_NAME', 'prestaneo', true);
define('MOD_SYNC_DISPLAY_NAME', 'Prestaneo', true);
define('MOD_SYNC_DISPLAY_AUTHOR', 'Synolia', true);

class Prestaneo extends Module
{

    protected $path_folder;
    protected $includeFiles = false;

    protected static $_included = false;
    protected static $_including = false;
    protected static $_modulePaths = array();
    protected static $_registeredModules = array();
    protected static $_registeredActions = array();
    protected static $_readFolders = array();

    protected static $_selfLink = '';

    private static $_includedPaths = array();
    private static $_instance;

    public function __construct()
    {
        $this->name = MOD_SYNC_NAME;
        $this->version = '0.1.2';
        $this->author = MOD_SYNC_DISPLAY_AUTHOR;
        $this->bootstrap = true;

        parent::__construct();
        $this->displayName = $this->l(MOD_SYNC_DISPLAY_NAME);
        $this->description = $this->l(MOD_SYNC_DISPLAY_NAME);
        if (!self::$_including && $this->active)
            self::_autoInclude($this->getLocalPath(), $this->active);

        if(!self::$_selfLink) {
            $this->setSelfLink($this->context->link->getAdminLink('AdminModules', true) . '&configure=' . MOD_SYNC_NAME);
        }
    }

    public static function getInstance()
    {
        if (!is_object(self::$_instance))
            self::$_instance = new self();
        return self::$_instance;
    }

    public function getLocalFilePath()
    {
        return $this->getLocalPath() . '/' . MOD_SYNC_NAME . '.php';
    }

    public function setConfirmations($message)
    {
        $this->_confirmations[] = $message;
    }

    public function setError($message)
    {
        $this->_errors[] = $message;
    }

    protected static function _autoInclude($localPath, $active)
    {
        /*
         * Get file names from sync modules
         */
        self::$_including = true;
        if (self::$_included)
            return;

        $fileObjects = new AppendIterator();
        $modulePaths = self::getSyncModules($localPath, $active);
        foreach ($modulePaths as $modulePath)
        {
            $fileObject = new RecursiveIteratorIterator(
                new SynoSyncAutoIncludeRecursiveFilterIterator(
                    new RecursiveDirectoryIterator($modulePath . 'models')
                ),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            $fileObjects->append($fileObject);
        }

        /*
         * Generate array of core files to include
         */
        $coreFilesSorted  = array();
        $coreFileMaxDepth = 0;

        foreach ($fileObjects as $fullFileName => $fileObject)
        {
            //Check for php core files
            if (!is_dir($fullFileName) && pathinfo($fullFileName, PATHINFO_EXTENSION) == 'php'
                && strpos(str_replace('\\', '/', $fullFileName),'/core/'))
            {
                if(!array_key_exists($fileObjects->getDepth(), $coreFilesSorted))
                {
                    if($fileObjects->getDepth() > $coreFileMaxDepth)
                        $coreFileMaxDepth = $fileObjects->getDepth();
                    $coreFilesSorted[$fileObjects->getDepth()] = array();
                }

                $coreFilesSorted[$fileObjects->getDepth()][] = $fullFileName;
            }
        }

        /*
         * Include core files
         */
        for(; $coreFileMaxDepth>0; $coreFileMaxDepth--)
        {
            foreach($coreFilesSorted[$coreFileMaxDepth] as $depth => $fullFileName)
            {
                if (include($fullFileName))
                    self::$_includedPaths[] = $fullFileName;
            }
        }

        $fileObjects->rewind();

        /*
         * Import all others files, those which does not belongs to /core)
         */
        $fileObjects->setMaxDepth(1);
        foreach ($fileObjects as $fullFileName => $fileObject)
        {
            //Check for php non-core files
            if (!is_dir($fullFileName) && pathinfo($fullFileName, PATHINFO_EXTENSION) == 'php'
                && !strpos(str_replace('\\', '/', $fullFileName),'/core/')
            )
            {
                /*
                 * Include non-core files
                 */
                if (include($fullFileName))
                    self::$_includedPaths[] = $fullFileName;
            }
        }

        self::$_included = true;
        self::$_including = false;
        self::loadSyncActionsAndFolders();
        return;
    }

    public static function getIncludedPaths()
    {
        return self::$_includedPaths;
    }

    public static function getSyncModules($localPath, $active)
    {
        if (count(self::$_modulePaths))
            return self::$_modulePaths;
        $registeredModules = self::getInstance()->getRegisteredModules();
        $result = Db::getInstance()->executeS('
		SELECT m.name, m.active
		FROM `' . _DB_PREFIX_ . 'module` m
		' . Shop::addSqlAssociation('module', 'm') . '');
        foreach ($result as $row)
            if ($row['name'] !== MOD_SYNC_NAME)
                if ($row['active'])
                    if ($module = Module::getInstanceByName($row['name']))
                        if (property_exists($module, 'modSync'))
                            if ($module->modSync)
                            {
                                self::$_modulePaths[$module->getLocalPath()] = $module->getLocalPath();
                                if ($active && !array_key_exists($row['name'], $registeredModules))
                                {
                                    self::getInstance()->registerModule($row['name']);
                                    $registeredModules[$row['name']] = $module->getLocalPath();
                                }
                                elseif (array_key_exists($row['name'], $registeredModules))
                                    $registeredModules[$row['name']] = $module->getLocalPath();
                            }

        if ($active && !array_key_exists(MOD_SYNC_NAME, $registeredModules))
        {
            self::getInstance()->registerModule(MOD_SYNC_NAME);
            $registeredModules[MOD_SYNC_NAME] = $localPath;
        }
        if($active && @$registeredModules[MOD_SYNC_NAME]===false)
            $registeredModules[MOD_SYNC_NAME] = $localPath;
        foreach ($registeredModules as $k => $v)
            if ($v === false)
            {
                self::getInstance()->unregisterModule($k);
                unset($registeredModules[$k]);
            }

        self::$_modulePaths[$localPath] = $localPath;
        self::$_registeredModules = $registeredModules;
        return self::$_modulePaths;
    }

    public static function loadSyncActionsAndFolders()
    {
        self::getInstance()->readImportFolder();
        self::getInstance()->readExportFolder();
        foreach (self::$_registeredActions as $moduleName => $actions)
            foreach ($actions as $actionName => $alwaysExists)
                if (!$alwaysExists)
                    self::getInstance()->unregisterAction($moduleName, $actionName);
    }

    public static function getRegisteredActionsArray()
    {
        self::_autoInclude(self::getInstance()->getLocalPath(), self::getInstance()->active);
        return self::$_registeredActions;
    }

    public function getRegisteredModules()
    {
        if(count(self::$_registeredModules))
            return self::$_registeredModules;
        $sql            = 'SELECT `name` FROM `'._DB_PREFIX_.MOD_SYNC_NAME.'_modules`';
        $modules        = Db::getInstance()->executeS($sql);
        $modulesByName  = array();
        foreach($modules as $module)
            $modulesByName[$module['name']] = false;
        return $modulesByName;
    }

    public function registerModule($moduleName)
    {
        $sql = 'INSERT INTO `'._DB_PREFIX_.MOD_SYNC_NAME.'_modules` (`name`, date_add)
                                    VALUES ("'.pSQL($moduleName).'", NOW())';
        if(!Db::getInstance()->execute($sql))
            return false;
        self::$_registeredModules[$moduleName] = true;
        return true;
    }

    public function unregisterModule($moduleName)
    {
        if(!$this->unregisterAction($moduleName))
            return false;

        $sql = 'DELETE FROM `'._DB_PREFIX_.MOD_SYNC_NAME.'_modules`  WHERE `name` = "'.pSQL($moduleName).'";';
        if(!Db::getInstance()->execute($sql))
            return false;
        if(array_key_exists($moduleName, self::$_registeredModules))
            unset(self::$_registeredModules[$moduleName]);
        return true;
    }

    public function getRegisteredActions()
    {
        if(count(self::$_registeredActions))
            return self::$_registeredActions;
        $sql                 = 'SELECT `name`, `module_name` FROM `'._DB_PREFIX_.MOD_SYNC_NAME.'_actions`';
        $actions             = Db::getInstance()->executeS($sql);
        $actionsByModuleName = array();
        foreach($actions as $action)
            if(!array_key_exists($action['module_name'], $actionsByModuleName))
                $actionsByModuleName[$action['module_name']] = array($action['name'] => false);
            else
                $actionsByModuleName[$action['module_name']][$action['name']] = false;
        return $actionsByModuleName;
    }

    public function registerAction($moduleName, $actionName)
    {
        $sql = 'INSERT INTO `'._DB_PREFIX_.MOD_SYNC_NAME.'_actions` (`name`, `module_name`, date_add)
                                    VALUES ("'.pSQL($actionName).'", "'.pSQL($moduleName).'", NOW())';
        if(!Db::getInstance()->execute($sql))
            return false;
        if(!array_key_exists($moduleName, self::$_registeredActions))
            self::$_registeredActions[$moduleName] = array();
        self::$_registeredActions[$moduleName][$actionName] = true;
        return true;
    }

    public function unregisterAction($moduleName, $actionName=false)
    {
        if(MOD_CRON)
        {
            $sql ='DELETE   `'._DB_PREFIX_.'cronjobs`.*,
                            `'._DB_PREFIX_.MOD_SYNC_NAME.'_cronjobs`.*,
                            `'._DB_PREFIX_.MOD_SYNC_NAME.'_actions`.*
                FROM `'._DB_PREFIX_.'cronjobs`
                    INNER JOIN `'._DB_PREFIX_.MOD_SYNC_NAME.'_cronjobs`
                        ON `'._DB_PREFIX_.'cronjobs`.`id_cronjob` = `'._DB_PREFIX_.MOD_SYNC_NAME.'_cronjobs`.`id_cronjob`
                    INNER JOIN `'._DB_PREFIX_.MOD_SYNC_NAME.'_actions`
                        ON `'._DB_PREFIX_.MOD_SYNC_NAME.'_cronjobs`.`id_sync_actions` = `'._DB_PREFIX_.MOD_SYNC_NAME.'_actions`.`id`
                WHERE `'._DB_PREFIX_.MOD_SYNC_NAME.'_actions`.`module_name` = "'.pSQL($moduleName).'"';
            if($actionName!==false)
                $sql.=' AND `'._DB_PREFIX_.MOD_SYNC_NAME.'_actions`.`name` = "'.pSQL($actionName).'"';
            if(!Db::getInstance()->execute($sql))
                return false;
        }
        else
        {
            $sql = 'DELETE FROM `'._DB_PREFIX_.MOD_SYNC_NAME.'_actions`
                WHERE `module_name` = "'.pSQL($moduleName).'"';
            if($actionName!==false)
                $sql.=' AND `name` = "'.pSQL($actionName).'"';
            if(!Db::getInstance()->execute($sql))
                return false;
        }
        if(array_key_exists($moduleName, self::$_registeredActions))
            if(array_key_exists($actionName, self::$_registeredActions[$moduleName]))
                unset(self::$_registeredActions[$moduleName][$actionName]);
        return true;
    }

    /**
     * return an array containing all information's from hooks that are displayed in the front office
     * @return array
     * @throws PrestaShopDatabaseException
     */
    public function getDisplayNonAdminHooks()
    {
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('
			SELECT * FROM `'._DB_PREFIX_.'hook` h
			WHERE h.`name` LIKE "display%"
			AND   h.`name` NOT LIKE "%Admin%"
			AND   h.`name` NOT LIKE "%BackOffice%"
			AND   h.`name` NOT LIKE "%OverrideTemplate%"
			ORDER BY `name`'
        );
    }

    /**
     * return an array containing all information's from hooks that are displayed in the front office
     * @return array
     * @throws PrestaShopDatabaseException
     */
    public function getAllHooks()
    {
        return array();
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('
			SELECT * FROM `'._DB_PREFIX_.'hook` h
			ORDER BY `name`'
        );
    }

    public static function getPartsByClassName($originClass)
    {
        preg_match_all('#((?:^|[A-Z])[a-z]+)#', $originClass, $pathParts);

        $classParts = array();
        for($nb=count($pathParts[1]), $i=0; $i<$nb; $i++)
        {
            if($i<1)
            {
                $classParts[$i] = strtolower($pathParts[1][$i]);
            }
            else
            {
                if(!array_key_exists(1, $classParts))
                    $classParts[1] = strtolower($pathParts[1][$i]);
                else
                    $classParts[1].= $pathParts[1][$i];
            }
        }
        return $classParts;
    }

    public function install()
    {
        if (!parent::install()
            || !$this->registerHook('actionAdminControllerSetMedia')
            || !$this->registerHook('dashboardZoneTwo')
            || !$this->registerHook('actionCategoryDelete')
            || !$this->registerHook('actionAttributeGroupDelete')
            || !$this->registerHook('actionAttributeDelete')
            || !$this->registerHook('actionFeatureDelete')
            || !$this->registerHook('actionFeatureValueDelete')
            || !$this->registerHook('actionProductDelete')
            || !$this->_installConfigurationKeys()
        )
            return false;


        if(!$this->_installDatabase() ||!Configuration::updateValue(MOD_SYNC_NAME.'_dayshistoryfiles', 3))
            return false;

        if (!$this->_installdefaultValues())
            return false;

        if(!Configuration::updateValue(MOD_SYNC_NAME.'_SHOW_STATUS', 1))
            return false;

        if(!$this->installModuleTabs())
            return false;

        return true;
    }

    protected function _installDefaultValues()
    {
        $return = true;

        $sql = 'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'mapping_categories` (`champ_akeneo`,`champ_prestashop`, `required`) VALUES
            ("code","code", 1),
            ("parent","code_parent", 1),
            ("label","name", 1);';
        if (!Db::getInstance()->execute($sql))
            $return = false;

        $sql = 'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'mapping_attributes` (`champ_akeneo`,`champ_prestashop`, `required`) VALUES
            ("code","code", 1),
            ("axis","axis", 1);';
        if (!Db::getInstance()->execute($sql))
            $return = false;

        $sql = 'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'mapping_attribute_values` (`champ_akeneo`,`champ_prestashop`, `required`) VALUES
            ("code","code", 1),
            ("attribute","attribute_group", 1),
            ("label", "name", 1);';
        if (!Db::getInstance()->execute($sql))
            $return = false;

        $sql = 'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'mapping_features` (`champ_akeneo`,`champ_prestashop`, `required`) VALUES
            ("code","code", 1),
            ("label","name", 1),
            ("type","type", 1);;';
        if (!Db::getInstance()->execute($sql))
            $return = false;

        $sql = 'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'mapping_products` (`champ_akeneo`,`champ_prestashop`, `required`) VALUES
			("sku","reference", 1),
			("categories","categories", 1),
			("enabled","active", 1),
			("name","name", 1),
			("price","price", 1),
			("weight","weight", 0),
			("description","description", 1),
			("groups","groups", 1),
			("picture","image", 0),
			("short_description","description_short", 0),
			("available","available_for_order", 1);';
        if (!Db::getInstance()->execute($sql))
            $return = false;

        return $return;
    }

    protected function _installConfigurationKeys()
    {
        $return = true;
        if (!Configuration::updateValue(MOD_SYNC_NAME . '_ipallowed',                                '')
            || !Configuration::updateValue(MOD_SYNC_NAME . '_ftphost',                               '')
            || !Configuration::updateValue(MOD_SYNC_NAME . '_ftplogin',                              '')
            || !Configuration::updateValue(MOD_SYNC_NAME . '_ftppassword',                           '')
            || !Configuration::updateValue(MOD_SYNC_NAME . '_ftppath',                               '/')
            || !Configuration::updateValue(MOD_SYNC_NAME . '_dayshistoryfiles',                      '')
            || !Configuration::updateValue(MOD_SYNC_NAME . '_cache',                                 '')
            || !Configuration::updateValue(MOD_SYNC_NAME . '_IMPORT_ENCLOSURE',                      '')
            || !Configuration::updateValue(MOD_SYNC_NAME . '_IMPORT_DELIMITER',                      ';')
            || !Configuration::updateValue(MOD_SYNC_NAME . '_IMPORT_PRODUCT_FTP_PATH',               '/product')
            || !Configuration::updateValue(MOD_SYNC_NAME . '_IMPORT_CATEGORY_FTP_PATH',              '/category')
            || !Configuration::updateValue(MOD_SYNC_NAME . '_IMPORT_ATTRIBUTE_FTP_PATH',             '/attribute')
            || !Configuration::updateValue(MOD_SYNC_NAME . '_IMPORT_ATTRIBUTE_VALUES_FTP_PATH',      '/option')
            || !Configuration::updateValue(MOD_SYNC_NAME . '_IMPORT_VARIANT_FTP_PATH',               '/variant')
            || !Configuration::updateValue(MOD_SYNC_NAME . '_IMPORT_DEFAULT_QTY_PRODUCT',            999)
            || !Configuration::updateValue(MOD_SYNC_NAME . '_IMPORT_RESET_FEATURES',                 1)
            || !Configuration::updateValue(MOD_SYNC_NAME . '_IMPORT_RESET_IMAGES',                   1)
            || !Configuration::updateValue(MOD_SYNC_NAME . '_IMPORT_RESET_COMBINATIONS',             1)
            || !Configuration::updateValue(MOD_SYNC_NAME . '_MAPPING_CATEGORY_SPECIAL_FIELDS',       'code,code_parent')
            || !Configuration::updateValue(MOD_SYNC_NAME . '_MAPPING_PRODUCT_SPECIAL_FIELDS',        'groups,categories,image')
            || !Configuration::updateValue(MOD_SYNC_NAME . '_MAPPING_ATTRIBUTEGROUP_SPECIAL_FIELDS', 'code,type')
            || !Configuration::updateValue(MOD_SYNC_NAME . '_MAPPING_FEATURE_SPECIAL_FIELDS',        'code,type')
            || !Configuration::updateValue(MOD_SYNC_NAME . '_MAPPING_ATTRIBUTE_SPECIAL_FIELDS',      'code,attribute_group,name')
            || !Configuration::updateValue(MOD_SYNC_NAME . '_MAPPING_FEATUREVALUE_SPECIAL_FIELDS',   'code,attribute_group,name')
        ) {
            $return = false;
        }

        return $return;
    }

    public function hookActionAdminControllerSetMedia($params)
    {
        Context::getContext()->controller->addCSS($this->_path . 'views/css/'.MOD_SYNC_NAME.'.css','all');
        if (get_class(Context::getContext()->controller) == 'AdminDashboardController') {
            // Timeline
            Context::getContext()->controller->addJs($this->_path . 'views/js/timeline.js');
            Context::getContext()->controller->addJs($this->_path . 'views/js/timeline-locales.js');


            Context::getContext()->controller->addCSS($this->_path . 'views/css/timeline.css');
            // Main
            Context::getContext()->controller->addCSS($this->_path . 'views/css/dashbord_timeline.css','all');
        }

        Context::getContext()->controller->addCSS($this->_path . 'css/' . MOD_SYNC_NAME . '.css', 'all');
        Context::getContext()->controller->addCSS($this->_path . 'css/prestaneoConfig.css');
        Context::getContext()->controller->addJS($this->_path . 'js/' . MOD_SYNC_NAME . '.js');
        Context::getContext()->controller->addJS($this->_path . 'js/prestaneoConfig.js');
    }

    public function installModuleTabs()
    {
        if (!$parentTabId = Tab::getIdFromClassName('AdminSync')) {
            $parentTab             = new Tab();
            $parentTab->id_parent  = 0;
            $parentTab->active     = true;
            $parentTab->module     = MOD_SYNC_NAME;
            $parentTab->class_name = 'AdminSync';
            $parentTab->name       = array();
            foreach (Language::getLanguages(true) as $lang) {
                $parentTab->name[$lang['id_lang']] = $this->l(MOD_SYNC);
            }
            if (!$parentTab->add()) {
                return false;
            }
        } else {
            $parentTab = new Tab($parentTabId);
        }

        if (!Tab::getIdFromClassName('AdminSyncImports')) {
            $importTab             = new Tab();
            $importTab->id_parent  = $parentTab->id;
            $importTab->active     = true;
            $importTab->module     = MOD_SYNC_NAME;
            $importTab->class_name = 'AdminSyncImports';
            $importTab->name = array();
            foreach (Language::getLanguages(false) as $lang) {
                $importTab->name[$lang['id_lang']] = $this->l('Imports');
            }
            if (!$importTab->save()) {
                return false;
            }
        }

        if (!Tab::getIdFromClassName('AdminSyncConfig')) {
            $configurationTab             = new Tab();
            $configurationTab->id_parent  = $parentTab->id;
            $configurationTab->active     = 1;
            $configurationTab->module     = MOD_SYNC_NAME;
            $configurationTab->class_name = 'AdminSyncConfig';
            $configurationTab->name       = array();
            foreach (Language::getLanguages(false) as $lang) {
                $configurationTab->name[$lang['id_lang']] = $this->l('Configuration');
            }
            if (!$configurationTab->save()) {
                return false;
            }
        }

        return true;
    }

    protected function _installDatabase()
    {
        $sql = '
            CREATE TABLE `'._DB_PREFIX_.MOD_SYNC_NAME.'_status` (
                `id_status` int(9) NOT NULL AUTO_INCREMENT ,
                `type` varchar(55) ,
                `status` boolean ,
                `date_add` datetime ,
                `date_end` datetime ,
                `emulate_date` boolean,
                `comments` varchar(255),
                PRIMARY KEY (`id_status`)
            )ENGINE = InnoDB ;
        ';
        if(!Db::getInstance()->Execute($sql))
            return false;

        $sql = '
            CREATE TABLE `'._DB_PREFIX_.MOD_SYNC_NAME.'_modules` (
                `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` varchar(55),
                `date_add` datetime,
                PRIMARY KEY (`id`)
            )ENGINE = InnoDB ;
        ';
        if(!Db::getInstance()->Execute($sql))
            return false;

        $sql = '
            CREATE TABLE `'._DB_PREFIX_.MOD_SYNC_NAME.'_actions` (
                `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` varchar(55),
                `module_name` varchar(55),
                `date_add` datetime,
                PRIMARY KEY (`id`)
            )ENGINE = InnoDB ;
        ';
        if(!Db::getInstance()->Execute($sql))
            return false;

        if(MOD_CRON)
        {
            $sql = '
            CREATE TABLE `'._DB_PREFIX_.MOD_SYNC_NAME.'_cronjobs` (
                `id_sync_cron` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_cronjob` int(10),
                `id_sync_actions` int(11) UNSIGNED,
                PRIMARY KEY (`id_sync_cron`),
                FOREIGN KEY (`id_cronjob`)
                    REFERENCES `'. _DB_PREFIX_. 'cronjobs`(`id_cronjob`)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                FOREIGN KEY (`id_sync_actions`)
                    REFERENCES `'. _DB_PREFIX_.MOD_SYNC_NAME.'_actions`(`id`)
                    ON UPDATE CASCADE ON DELETE CASCADE
            )ENGINE = InnoDB ;';
            if(!Db::getInstance()->Execute($sql))
                return false;
        }

        $sql = '
            CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'mapping_categories` (
                `id_mapping` int(11) NOT NULL AUTO_INCREMENT,
                `champ_akeneo` varchar(255),
                `champ_prestashop` varchar(255),
                `required` bool,
                PRIMARY KEY (`id_mapping`)
            )ENGINE = InnoDB DEFAULT CHARSET=utf8;
        ';
        if (!Db::getInstance()->Execute($sql))
            return false;

        $sql = '
            CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'mapping_code_categories` (
                `id_category` int(11) NOT NULL,
                `code` varchar(255),
                PRIMARY KEY (`id_category`)
            )ENGINE = InnoDB DEFAULT CHARSET=utf8;
        ';
        if (!Db::getInstance()->Execute($sql))
            return false;

        $sql = '
            CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'mapping_features` (
                `id_mapping` int(11) NOT NULL AUTO_INCREMENT,
                `champ_akeneo` varchar(255),
                `champ_prestashop` varchar(255),
                `required` bool,
                PRIMARY KEY (`id_mapping`)
            )ENGINE = InnoDB DEFAULT CHARSET=utf8;
        ';
        if (!Db::getInstance()->Execute($sql))
            return false;

        $sql = '
            CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'mapping_code_features` (
                `id_feature` int(11) NOT NULL,
                `code` varchar(255),
                `type` varchar(255),
                PRIMARY KEY (`id_feature`)
            )ENGINE = InnoDB DEFAULT CHARSET=utf8;
        ';
        if (!Db::getInstance()->Execute($sql))
            return false;

        $sql = '
            CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'mapping_attributes` (
                `id_mapping` int(11) NOT NULL AUTO_INCREMENT,
                `champ_akeneo` varchar(255),
                `champ_prestashop` varchar(255),
                `required` bool,
                PRIMARY KEY (`id_mapping`)
            )ENGINE = InnoDB DEFAULT CHARSET=utf8;
        ';
        if (!Db::getInstance()->Execute($sql))
            return false;

        $sql = '
            CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'mapping_tmp_attributes` (
                `id_mapping` int(11) NOT NULL AUTO_INCREMENT,
                `code` varchar(255),
                `axis` varchar(255),
                PRIMARY KEY (`id_mapping`)
            )ENGINE = InnoDB DEFAULT CHARSET=utf8;
        ';
        if (!Db::getInstance()->Execute($sql))
            return false;

        $sql = '
            CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'mapping_code_attributes` (
                `id_attribute_group` int(11) NOT NULL,
                `code` varchar(255),
                PRIMARY KEY (`id_attribute_group`)
            )ENGINE = InnoDB DEFAULT CHARSET=utf8;
        ';
        if (!Db::getInstance()->Execute($sql))
            return false;

        $sql = '
            CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'mapping_attribute_values` (
                `id_mapping` int(11) NOT NULL AUTO_INCREMENT,
                `champ_akeneo` varchar(255),
                `champ_prestashop` varchar(255),
                `required` bool,
                PRIMARY KEY (`id_mapping`)
            )ENGINE = InnoDB DEFAULT CHARSET=utf8;
        ';
        if (!Db::getInstance()->Execute($sql))
            return false;

        $sql = '
            CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'mapping_code_attribute_values` (
                `id_attribute_value` int(11) NOT NULL,
                `code_attribute_group` varchar(255),
                `code` varchar(255),
                PRIMARY KEY (`id_attribute_value`)
            )ENGINE = InnoDB DEFAULT CHARSET=utf8;
        ';
        if (!Db::getInstance()->Execute($sql))
            return false;

        $sql = '
            CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'mapping_code_feature_values` (
                `id_feature_value` int(11) NOT NULL,
                `code_feature` varchar(255),
                `code` varchar(255),
                PRIMARY KEY (`id_feature_value`)
            )ENGINE = InnoDB DEFAULT CHARSET=utf8;
        ';
        if (!Db::getInstance()->Execute($sql))
            return false;

        $sql = '
            CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'mapping_products` (
                `id_mapping` int(11) NOT NULL AUTO_INCREMENT,
                `champ_akeneo` varchar(255),
                `champ_prestashop` varchar(255),
                `required` bool,
                PRIMARY KEY (`id_mapping`)
            )ENGINE = InnoDB DEFAULT CHARSET=utf8;
        ';
        if (!Db::getInstance()->Execute($sql))
            return false;

        $sql = '
            CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'mapping_products_groups` (
                `id_mapping` int(11) NOT NULL AUTO_INCREMENT,
                `id_product` int(11) NOT NULL,
                `group_code` varchar(255),
                PRIMARY KEY (`id_mapping`)
            )ENGINE = InnoDB DEFAULT CHARSET=utf8;
        ';
        if (!Db::getInstance()->Execute($sql))
            return false;

        return $this->installProgressDatabase();
    }

    public function installProgressDatabase()
    {
        $sql = '
            CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.MOD_SYNC_NAME.'_progress` (
                `id_progress` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_action` int(11) UNSIGNED,
                `percentage` TINYINT,
                `date_add` timestamp DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id_progress`),
                FOREIGN KEY (`id_action`)
                    REFERENCES `'. _DB_PREFIX_.MOD_SYNC_NAME.'_actions`(`id`)
                    ON UPDATE CASCADE ON DELETE CASCADE
        )ENGINE = InnoDB ;';

        if(!Db::getInstance()->Execute($sql))
            return false;
        return true;
    }

    protected function _uninstallModuleTabs()
    {
        $tabIds = array(
            Tab::getIdFromClassName('AdminSyncConfig'),
            Tab::getIdFromClassName('AdminSyncImports'),
            Tab::getIdFromClassName('AdminSync')
        );

        foreach ($tabIds as $tabId) {
            if ($tabId) {
                $tab = new Tab($tabId);
                $tab->delete();
                if (!$tab->delete()) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Drop Sync tables
     */
    protected function _uninstallDatabase()
    {
        $sql = '
            DROP TABLE IF EXISTS
            `'._DB_PREFIX_.MOD_SYNC_NAME.'_cronjobs`,
            `'._DB_PREFIX_.MOD_SYNC_NAME.'_progress`,
            `'._DB_PREFIX_.MOD_SYNC_NAME.'_status`,
            `'._DB_PREFIX_.MOD_SYNC_NAME.'_modules`,
            `'._DB_PREFIX_.MOD_SYNC_NAME.'_actions`,
            `'._DB_PREFIX_.'mapping_categories`,
            `'._DB_PREFIX_.'mapping_code_categories`,
            `'._DB_PREFIX_.'mapping_features`,
            `'._DB_PREFIX_.'mapping_code_features`,
            `'._DB_PREFIX_.'mapping_attributes`,
            `'._DB_PREFIX_.'mapping_tmp_attributes`,
            `'._DB_PREFIX_.'mapping_code_attributes`,
            `'._DB_PREFIX_.'mapping_attribute_values`,
            `'._DB_PREFIX_.'mapping_code_attribute_values`,
            `'._DB_PREFIX_.'mapping_code_feature_values`,
            `'._DB_PREFIX_.'mapping_products`,
            `'._DB_PREFIX_.'mapping_products_groups`;
        ';
        return Db::getInstance()->Execute($sql);
    }

    /**
     * Delete all Crons CREATED VIA Sync, not the others
     */
    protected function _deleteSyncCrons()
    {
        $sql ='DELETE '._DB_PREFIX_.'cronjobs.*, '._DB_PREFIX_.MOD_SYNC_NAME.'_cronjobs.*
                FROM `'._DB_PREFIX_.'cronjobs`
                    INNER JOIN `'._DB_PREFIX_.MOD_SYNC_NAME.'_cronjobs`
                        ON `'._DB_PREFIX_.'cronjobs`.`id_cronjob` = `'._DB_PREFIX_.MOD_SYNC_NAME.'_cronjobs`.`id_cronjob`';

        return Db::getInstance()->Execute($sql);
    }

    public function uninstall()
    {
        if (!parent::uninstall()
            || !$this->_uninstallConfigurationKeys()
            || !$this->_deleteSyncCrons()
            || !$this->_uninstallDatabase()
            || !$this->_uninstallModuleTabs()
        )
            return false;

        return true;
    }

    protected function _uninstallConfigurationKeys() {
        if (!Configuration::deleteByName(MOD_SYNC_NAME . '_ipallowed')
            || !Configuration::deleteByName(MOD_SYNC_NAME . '_ftphost')
            || !Configuration::deleteByName(MOD_SYNC_NAME . '_ftplogin')
            || !Configuration::deleteByName(MOD_SYNC_NAME . '_ftppassword')
            || !Configuration::deleteByName(MOD_SYNC_NAME . '_ftppath')
            || !Configuration::deleteByName(MOD_SYNC_NAME . '_dayshistoryfiles')
            || !Configuration::deleteByName(MOD_SYNC_NAME . '_cache')
            || !Configuration::deleteByName(MOD_SYNC_NAME . '_IMPORT_ENCLOSURE')
            || !Configuration::deleteByName(MOD_SYNC_NAME . '_IMPORT_DELIMITER')
            || !Configuration::deleteByName(MOD_SYNC_NAME . '_IMPORT_PRODUCT_FTP_PATH')
            || !Configuration::deleteByName(MOD_SYNC_NAME . '_IMPORT_CATEGORY_FTP_PATH')
            || !Configuration::deleteByName(MOD_SYNC_NAME . '_IMPORT_ATTRIBUTE_FTP_PATH')
            || !Configuration::deleteByName(MOD_SYNC_NAME . '_IMPORT_ATTRIBUTE_VALUES_FTP_PATH')
            || !Configuration::deleteByName(MOD_SYNC_NAME . '_IMPORT_VARIANT_FTP_PATH')
            || !Configuration::deleteByName(MOD_SYNC_NAME . '_IMPORT_DEFAULT_QTY_PRODUCT')
            || !Configuration::deleteByName(MOD_SYNC_NAME . '_IMPORT_RESET_FEATURES')
            || !Configuration::deleteByName(MOD_SYNC_NAME . '_IMPORT_RESET_IMAGES')
            || !Configuration::deleteByName(MOD_SYNC_NAME . '_IMPORT_RESET_COMBINATIONS')
            || !Configuration::deleteByName(MOD_SYNC_NAME . '_MAPPING_CATEGORY_SPECIAL_FIELDS')
            || !Configuration::deleteByName(MOD_SYNC_NAME . '_MAPPING_PRODUCT_SPECIAL_FIELDS')
            || !Configuration::deleteByName(MOD_SYNC_NAME . '_MAPPING_ATTRIBUTE_GROUP_SPECIAL_FIELDS')
        )
            return false;

        return true;
    }

    protected function _isIpAuthorizedToLaunch()
    {
        if(Configuration::get(MOD_SYNC_NAME.'_ipallowed'))
            $ipAllowed = explode(',', Configuration::get(MOD_SYNC_NAME.'_ipallowed'));
        else
            $ipAllowed = array();
        if(in_array(Tools::getRemoteAddr(), $ipAllowed) || count($ipAllowed) == 0)
            return true;
        else
            return false;
    }

    protected function _getActionArguments()
    {
        $parameters = array();
        if(Tools::getValue('from'))
            $parameters['from'] = Tools::getValue('from');
        if(Tools::getValue('to'))
            $parameters['to'] = Tools::getValue('to');
        if(Tools::getValue('id'))
            $parameters['id'] = Tools::getValue('id');
        //add other parameters below
        return array($parameters);
    }

    protected function _callActionMethod($launch, $type)
    {
        try{
            $className = $type.ucfirst($launch);
            $function  = $className.'Action';
            if(!class_exists($className))
            {
                $className = $type.__CLASS__.ucfirst($launch);
                $function  = $type.__CLASS__.ucfirst($launch);
                $obj       = new $className;
            }
            else
                $obj   = new $className;

            //Synosync 1.8 import() and export() templates method
            if(method_exists($obj, $type))
                $file  = call_user_func_array(array($obj, $type), $this->_getActionArguments());
            else //Backward compatibility
                $file  = call_user_func_array(array($obj, $function), $this->_getActionArguments());

        }catch (Exception $e){
            exit('<b>FATAL ERROR :</b> '.$e->getMessage());
        }
        return $file;
    }

    public function launchAction($launch)
    {
        if(!$this->_isIpAuthorizedToLaunch())
            exit('NOK');
        if(!$launch)
            exit('BAD ARGUMENTS');

        $type = Tools::getValue('type', '');

        $returnAction = false;

        if(Tools::getValue('returnAction'));
        $returnAction = true;

        $manager      = new Manager($this->getLocalPath().$type.'/'.$launch.'');
        $cacheManager = new CacheManager($manager);

        /*
         * Met une entrée en BDD avec le DbLogger
         */
        $dbLogger = new DbLogger();
        $dbLogger->type = $launch;
        $dbLogger->status = 0;
        $dbLogger->add();

        $all_unfinished = DbLogger::getAllUnfinishedByType($launch);
        if ($all_unfinished !== false)
        {
            foreach ($all_unfinished as $row)
            {
                $oldDbLogger               = new DbLogger($row['id_status']);
                $oldDbLogger->date_end     = date('Y-m-d  H:i:s');
                $oldDbLogger->emulate_date = 1;
                $oldDbLogger->comments     = 'Auto finish because of another start';
                $oldDbLogger->update();
            }
        }

        if($fileCached = $cacheManager->FileCached($type, $launch))
            $file = $fileCached;
        else
            $file = $this->_callActionMethod($launch, $type);

        if($file || ($file!==false && $type=='import'))
        {
            $dbLogger->status   = 1;
        }

        // Update date_end and emulate_date
        $dbLogger->date_end     = date('Y-m-d  H:i:s');
        $dbLogger->emulate_date = 0;
        $dbLogger->update();

        if($returnAction)
            return true;

        if(!file_exists($file) && $type=='import')
        {
            exit('done');
        }
        if(Tools::getValue('file_url'))
        {
            $file = str_replace(_PS_ROOT_DIR_.'/', Tools::getHttpHost(true).__PS_BASE_URI__, $file);
            echo $file;
            exit();
        }
        if(Tools::getValue('download') || Tools::getValue('sync_download'))
        {
            ob_end_clean();
            header('Content-Description: File Transfer');
            header('Content-Type: application/force-download');
            header('Content-Length: ' . filesize($file));
            header('Content-Disposition: attachment; filename=' . basename($file));
            readfile($file);
            exit();
        }
        if(strtolower(substr($file, strrpos($file, '.')+1))=='xml')
            header('Content-Type: application/xml; charset=utf-8');
        exit(file_get_contents($file));
    }

    public function getContent()
    {
        $output = '';

        if ($launch = Tools::getValue('launch'))
            $this->launchAction($launch);

        if (Tools::isSubmit('submitMappingCategories')) {
            if ($this->_saveMapping('category', 'MappingCategories') > 0)
                $this->_errors[] = $this->l('Mapping Categories not updated');
            else
                $this->_confirmations[] = $this->l('Mapping Categories updated');
        }
        if (Tools::isSubmit('submitMappingAttributes')) {
            if ($this->_saveMapping('attribute', 'MappingAttributes') > 0)
                $this->_errors[] = $this->l('Mapping Attributes not updated');
            else
                $this->_confirmations[] = $this->l('Mapping Attributes updated');
        }
        if (Tools::isSubmit('submitMappingFeatures')) {
            if ($this->_saveMapping('feature', 'MappingFeatures') > 0)
                $this->_errors[] = $this->l('Mapping Features not updated');
            else
                $this->_confirmations[] = $this->l('Mapping Features updated');
        }
        if (Tools::isSubmit('submitMappingProducts')) {
            if ($this->_saveMapping('product', 'MappingProducts') > 0)
                $this->_errors[] = $this->l('Mapping Products not updated');
            else
                $this->_confirmations[] = $this->l('Mapping Products updated');
        }

        $configurationValues=array(
            MOD_SYNC_NAME . '_ipallowed'                        => MOD_SYNC_NAME.'_ipallowed',
            MOD_SYNC_NAME . '_dayshistoryfiles'                 => MOD_SYNC_NAME.'_dayshistoryfiles',
            MOD_SYNC_NAME . '_ftphost'                          => MOD_SYNC_NAME.'_ftphost',
            MOD_SYNC_NAME . '_ftpport'                          => MOD_SYNC_NAME.'_ftpport',
            MOD_SYNC_NAME . '_ftppath'                          => MOD_SYNC_NAME.'_ftppath',
            MOD_SYNC_NAME . '_ftplogin'                         => MOD_SYNC_NAME.'_ftplogin',
            MOD_SYNC_NAME . '_ftppassword'                      => MOD_SYNC_NAME.'_ftppassword',
            MOD_SYNC_NAME . '_cache'                            => MOD_SYNC_NAME.'_cache',
            MOD_SYNC_NAME . '_IMPORT_ENCLOSURE'                 => 'enclosure',
            MOD_SYNC_NAME . '_IMPORT_DELIMITER'                 => 'delimiter',
            MOD_SYNC_NAME . '_IMPORT_PRODUCT_FTP_PATH'          => 'productftppath',
            MOD_SYNC_NAME . '_IMPORT_CATEGORY_FTP_PATH'         => 'categoryftppath',
            MOD_SYNC_NAME . '_IMPORT_ATTRIBUTE_FTP_PATH'        => 'attributeftppath',
            MOD_SYNC_NAME . '_IMPORT_ATTRIBUTE_VALUES_FTP_PATH' => 'attributevaluesftppath',
            MOD_SYNC_NAME . '_IMPORT_VARIANT_FTP_PATH'          => 'variantftppath',
            MOD_SYNC_NAME . '_IMPORT_RESET_COMBINATIONS'        => 'resetcombinations',
            MOD_SYNC_NAME . '_IMPORT_RESET_IMAGES'              => 'resetimages',
            MOD_SYNC_NAME . '_IMPORT_RESET_FEATURES'            => 'resetfeatures',
            MOD_SYNC_NAME . '_IMPORT_DEFAULT_QTY_PRODUCT'       => 'defaultqtyproduct',
        );
        foreach($configurationValues as $configurationName => $formName)
        {
            $value = Tools::getValue($formName);
            if($value!==false)
                Configuration::updateValue($configurationName, $value);
        }

        if(MOD_CRON)
        {
            if (Tools::isSubmit('submitNewCronJob'))
            {
                $cronJobsName = ucfirst(Tools::getValue('sync_cron_type')).ucfirst(Tools::getValue('sync_cron_action'));
                $cronJobsSync = new CronJobsSync($cronJobsName);
                $cronJobsSync->postProcessNewJob();
                $cronErrors = $cronJobsSync->getErrors();
                if(!empty($cronErrors))
                    $this->_errors = array_merge($this->_errors, $cronErrors);
            }
        }

        $this->_getBackOfficeSentFile();

        Overrider::getInstance()->postProcess();

        if(!count($this->_errors))
            if ($launch = Tools::getValue('post_launch'))
                $this->launchAction($launch);

        $this->context->smarty->assign('form_errors', $this->_errors);

        if (Tools::getValue('controller') == 'AdminModules') {
            $this->smarty->assign('selfLink', $this->getSelfLink());

            $output .= $this->renderView();

            $output .= $this->renderForm();
            $output .= $this->renderTimeLineForm();

            if(MOD_FTP)
            {
                $output .= $this->renderFormFtp();
            }

            $output .= $this->renderFormConfiguration();

            $output = Overrider::getInstance()->getContent($output);
        }

        return $output;
    }

    protected function _getBackOfficeSentFile()
    {
        if(!isset($_FILES))
            return;
        if(!count($_FILES))
            return;
        $files = array_keys($_FILES);
        if(empty($_FILES[$files[0]]['name']))
            return $this->_setErrorMessage('No file was uploaded');
        $fileLanguageId = substr($files[0], strrpos($files[0], '_'));
        $postLanguageId = intval(Tools::getValue('id_lang'));
        if($fileLanguageId != $postLanguageId && $postLanguageId != $this->context->language->id)
        {
            $languageObject = new Language($postLanguageId);
            if($languageObject->id)
            {
                Context::getContext()->language = $languageObject;
                if($postLanguageId != $this->context->language->id)
                    $this->context->language = $languageObject;
            }
        }
        if(!preg_match('#^file_import_([a-zA-Z0-9]+)_[0-9]+$#', $files[0], $folderName))
            return $this->_setErrorMessage('Invalid file name');
        $uploader = new SyncUploader();
        $file = $uploader->upload(
            $_FILES[$files[0]],
            $this->getLocalPath() . '/import/' . $folderName[1] . '/files/'
        );
        if(array_key_exists('error', $file) && !empty($file['error']))
        {
            return $this->_setError($file['error']);
        }
        return;
    }

    protected function _setError($error)
    {
        $this->_errors[] = $error;
        return false;
    }

    protected function _setErrorMessage($message)
    {
        $this->_errors[] = $this->l($message);
        return false;
    }

    /*
     * Permet de lire tous les dossiers d'imports de façon automatique
     */
    public function readImportFolder()
    {
        return $this->_readFolder('import');
    }

    /*
    * Permet de lire tous les dossiers d'exports de façon automatique
    */
    public function readExportFolder()
    {
        return $this->_readFolder('export');
    }

    protected function _readFolder($folder)
    {
        if(array_key_exists($folder, self::$_readFolders))
            return self::$_readFolders[$folder];
        $containerPath            = '/models/'.$folder.'/';
        $type                     = $folder;
        $folderPath               = '/'.$folder.'/%s/';
        $tempFolderPath           = '/'.$folder.'/%s/temp';

        if($folder=='export')
            $folderPath          .= 'success';
        else
            $folderPath          .= 'files';

        $containerLength          = strlen($containerPath);
        $registeredModules        = $this->getRegisteredModules();
        self::$_registeredActions = $this->getRegisteredActions();
        $listFiles                = array();
        $orderOffset              = 9999999;

        foreach(self::$_includedPaths as $includedPath)
        {
            $containerPosition = strpos($includedPath, $containerPath);
            if($containerPosition !== false)
            {
                $containerChildren = substr($includedPath, $containerPosition + $containerLength);
                if($containerChildren == basename($includedPath))
                {
                    $folder                 = strtolower(substr($containerChildren, 0, strpos($containerChildren, '.')));
                    $destinationFolder      = sprintf($folderPath, $folder);
                    $tempDestinationFolder  = sprintf($tempFolderPath, $folder);
                    $extension              = substr($containerChildren, strpos($containerChildren, '.')+1);
                    if(!file_exists($this->getLocalPath().$destinationFolder))
                        mkdir($this->getLocalPath().$destinationFolder, 0765, true);

                    if(!file_exists($this->getLocalPath().$tempDestinationFolder))
                        mkdir($this->getLocalPath().$tempDestinationFolder, 0765, true);

                    if($extension == 'php')
                    {
                        /**
                         * this must now be called manualy :  - Installer::install('MyClassName');
                         *                                    - Installer::unistall();
                         *                                    - Installer::getInstance()->processForm();
                         *                                    - $output = Installer::getInstance()->renderForm($output);
                         */
                        $action     = ucfirst($type).ucfirst($folder);
                        $moduleName = false;

                        foreach($registeredModules as $name => $path)
                            if(strpos($includedPath, $path)===0)
                                $moduleName = $name;

                        if($moduleName !== false && !array_key_exists($moduleName, self::$_registeredActions))
                            $this->registerAction($moduleName, $action);
                        elseif($moduleName !== false && !array_key_exists($action, @self::$_registeredActions[$moduleName]))
                            $this->registerAction($moduleName, $action);
                        elseif($moduleName !== false && array_key_exists($action, @self::$_registeredActions[$moduleName]))
                            self::$_registeredActions[$moduleName][$action] = true;

                        $className = ucfirst($type).ucfirst($folder);

                        if(!class_exists($className))
                            $className = $type.ucfirst($folder);

                        if(!class_exists($className))
                            $className = ucfirst($type).__CLASS__.ucfirst($folder);

                        $reflection = new ReflectionClass($className);

                        $icon  = $reflection->getStaticPropertyValue('icon', 'download-alt');
                        $order = $reflection->getStaticPropertyValue('order', false);
                        if(!isset($order) || !$order)
                            $order = $orderOffset++;

                        $listFile = array(
                            'name'   => $folder,
                            'folder' => $destinationFolder,
                            'icon'   => $icon,
                            'status' => DbLogger::getLastLoggerByType($folder),
                            'order'  => $order,
                        );
                        if(MOD_CRON)
                            $listFile['crons'] = $this->getCronDescription($action);
                        $listFiles[$order] = $listFile;
                        unset($order);
                    }
                }
            }
        }

        ksort($listFiles);
        $sortedListFiles = array();
        foreach($listFiles as $listFile)
            $sortedListFiles[] = $listFile;

        self::$_readFolders[$folder] = $sortedListFiles;
        return self::$_readFolders[$folder];
    }

    public function getCronDescription($action)
    {
        $sql = '
            SELECT crons.description as description, crons.id_cronjob
            FROM `'._DB_PREFIX_.'cronjobs` As crons
            INNER JOIN `'._DB_PREFIX_.MOD_SYNC_NAME.'_cronjobs` As liaison
            ON crons.`id_cronjob` = liaison.`id_cronjob`
            INNER JOIN `'._DB_PREFIX_.MOD_SYNC_NAME.'_actions` As actions
            ON liaison.`id_sync_actions` = actions.`id`
            WHERE  crons.`active` = "1" AND actions.`name` = "'.pSQL($action).'"';

        return Db::getInstance()->executeS($sql);
    }

    /*
     * Vue du back-office
     */
    public function renderView()
    {
        $smartyVariables = array();
        if(MOD_CRON)
        {
            $smartyVariables = array_merge($smartyVariables, array(
                    'form_cron_create'  => $this->renderFormCron(),
                    'mod_cron_enabled'  => MOD_CRON,
                    'title_cron_list'   => $this->l('Cron List')
                )
            );
            $this->context->controller->addJS($this->getPathUri() . 'js/sync.js');
        }

        $smartyVariables = array_merge($smartyVariables,
            array(
                'import'        => $this->readImportFolder(),
                'link'          => $this->context->link,
                'path'          => '/modules/'.$this->name,
                'cronpath'      => $this->context->shop->getBaseURL().'modules/'.$this->name.'/cron.php',
                'export'        => $this->readExportFolder(),
                'mod_sync'      => MOD_SYNC,
                'mod_sync_name' => MOD_SYNC_NAME,
                'languages'     => Language::getLanguages(),
                'language'      => Language::getLanguage($this->context->language->id),
            )
        );
        if(count($smartyVariables))
            $this->context->smarty->assign($smartyVariables);
        $this->content .= $this->display(__FILE__, 'view.tpl');
        return $this->content;
    }

    public function renderForm()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('General configurations'),
                    'icon'  => 'icon-cogs'
                ),
                'input'  => array(
                    array(
                        'type'  => 'text',
                        'label' => $this->l('IP allowed'),
                        'name'  => MOD_SYNC_NAME.'_ipallowed',
                        'class' => 'mw'
                    ),
//                    array(
//                        'type'  => 'text',
//                        'label' => $this->l('Days history files'),
//                        'name'  => MOD_SYNC_NAME.'_dayshistoryfiles',
//                    ),
//                    array(
//                        'type'  => 'text',
//                        'label' => $this->l('Cache (in minutes)'),
//                        'name'  => MOD_SYNC_NAME.'_cache',
//                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                    'class' => 'btn btn-default pull-right')
            ),
        );

        $helper                = new HelperForm();
        $helper->show_toolbar  = false;
        $helper->table         = $this->table;
        $lang                  = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $this->fields_form     = array();
        $helper->identifier    = $this->identifier;
        $helper->submit_action = 'submitDefaultCountry';
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang =
            Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->currentIndex = $this->getSelfLink();
        $helper->fields_value = array(
            MOD_SYNC_NAME.'_ipallowed'        => Configuration::get(MOD_SYNC_NAME.'_ipallowed'),
            MOD_SYNC_NAME.'_dayshistoryfiles' => Configuration::get(MOD_SYNC_NAME.'_dayshistoryfiles'),
            MOD_SYNC_NAME.'_cache'            => Configuration::get(MOD_SYNC_NAME.'_cache'),
        );

        return $helper->generateForm(array($fields_form));
    }

    public function renderFormCron($cancel = true, $back_url = '#', $update = false)
    {
        CronJobsForms::init(new CronJobsSync());

        $helper                           = new HelperForm();
        $helper->show_toolbar             = false;
        $helper->module                   = $this;
        $helper->default_form_language    = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->submit_action            = "submitNewCronJob";
        $helper->currentIndex = $this->getSelfLink();

        if ($update == true)
            $helper->currentIndex .= '&id_cronjob='.(int)Tools::getValue('id_cronjob');

        $helper->tpl_vars      = array(
            'fields_value' => array_merge(
                CronJobsForms::getNewJobFormValues(),
                array(
                    'id_language'        => $this->context->language->id,
                    'languages'          => $this->context->controller->getLanguages(),
                    'back_url'           => $back_url,
                    'type'               => '',
                    'action'             => '',
                    'show_cancel_button' => $cancel,
                    'sync_cron_type'     => '',
                    'sync_cron_action'   => '',
                )
            )
        );

        $form = CronJobsForms::getJobForm();

        $form[0]['form']['input'][] = array(
            'type' => 'text',
            'name' => 'sync_cron_type',
            'label' => 'Import or Export',
            'desc' => '',
        );

        $form[0]['form']['input'][] = array(
            'type' => 'text',
            'name' => 'sync_cron_action',
            'label' => 'Module Name',
            'desc' => '',
        );

        return $helper->generateForm($form);
    }

    public function renderFormFtp()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('FTP configurations'),
                    'icon'  => 'icon-cogs'
                ),
                'input'  => array(
                    array(
                        'type'  => 'text',
                        'label' => $this->l('Server Host Name'),
                        'name'  => MOD_SYNC_NAME.'_ftphost',
                        'class' => 'mw',
                    ),
                    array(
                        'type'  => 'text',
                        'label' => $this->l('Server Port (optionnal)'),
                        'name'  => MOD_SYNC_NAME.'_ftpport',
                        'class' => 'mw',
                    ),
                    array(
                        'type'  => 'text',
                        'label' => $this->l('Server Login'),
                        'name'  => MOD_SYNC_NAME.'_ftplogin',
                        'class' => 'mw',
                    ),
                    array(
                        'type'  => 'text',
                        'label' => $this->l('Server Password'),
                        'name'  => MOD_SYNC_NAME.'_ftppassword',
                        'class' => 'mw',
                    ),
                    array(
                        'type'  => 'text',
                        'label' => $this->l('Server root Path'),
                        'name'  => MOD_SYNC_NAME.'_ftppath',
                        'class' => 'mw',
                    ),
                    array(
                        'type'  => 'text',
                        'label' => $this->l('Path to category files'),
                        'name'  => 'categoryftppath',
                        'class' => 'mw',
                        'hint'  => $this->l('Relative to the server root path')
                    ),
                    array(
                        'type'  => 'text',
                        'label' => $this->l('Path to product files'),
                        'name'  => 'productftppath',
                        'class' => 'mw',
                        'hint'  => $this->l('Relative to the server root path')
                    ),
                    array(
                        'type'  => 'text',
                        'label' => $this->l('Path to attribute files'),
                        'name'  => 'attributeftppath',
                        'class' => 'mw',
                        'hint'  => $this->l('Relative to the server root path')
                    ),
                    array(
                        'type'  => 'text',
                        'label' => $this->l('Path to attribute values files'),
                        'name'  => 'attributevaluesftppath',
                        'class' => 'mw',
                        'hint'  => $this->l('Relative to the server root path')
                    ),
                    array(
                        'type'  => 'text',
                        'label' => $this->l('Path to variant files'),
                        'name'  => 'variantftppath',
                        'class' => 'mw',
                        'hint'  => $this->l('Relative to the server root path')
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                    'class' => 'btn btn-default pull-right')
            ),
        );

        $helper                        = new HelperForm();
        $helper->show_toolbar          = false;
        $helper->table                 = $this->table;
        $lang                          = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $this->fields_form             = array();
        $helper->identifier            = $this->identifier;
        $helper->submit_action         = 'submitDefaultCountry';
        $helper->allow_employee_form_lang =
            Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->currentIndex = $this->getSelfLink();
        $helper->tpl_vars = array(
            'fields_value' => array(
                MOD_SYNC_NAME.'_ftppassword' => Configuration::get(MOD_SYNC_NAME . '_ftppassword'),
                MOD_SYNC_NAME.'_ftplogin'    => Configuration::get(MOD_SYNC_NAME . '_ftplogin'),
                MOD_SYNC_NAME.'_ftphost'     => Configuration::get(MOD_SYNC_NAME . '_ftphost'),
                MOD_SYNC_NAME.'_ftpport'     => Configuration::get(MOD_SYNC_NAME . '_ftpport'),
                MOD_SYNC_NAME.'_ftppath'     => Configuration::get(MOD_SYNC_NAME . '_ftppath'),
                'productftppath'             => Configuration::get(MOD_SYNC_NAME . '_IMPORT_PRODUCT_FTP_PATH'),
                'categoryftppath'            => Configuration::get(MOD_SYNC_NAME . '_IMPORT_CATEGORY_FTP_PATH'),
                'attributeftppath'           => Configuration::get(MOD_SYNC_NAME . '_IMPORT_ATTRIBUTE_FTP_PATH'),
                'attributevaluesftppath'     => Configuration::get(MOD_SYNC_NAME . '_IMPORT_ATTRIBUTE_VALUES_FTP_PATH'),
                'variantftppath'             => Configuration::get(MOD_SYNC_NAME . '_IMPORT_VARIANT_FTP_PATH'),
            ),
        );

        return $helper->generateForm(array($fields_form));
    }

    public function renderTimeLineForm()
    {
        $options = array(
            array(
                'id_option' => 1,
                'name'      => 'Success'
            ),
            array(
                'id_option' => 2,
                'name'      => 'Error'
            ),
            array(
                'id_option' => 3,
                'name'      => 'Success + Error'
            ),
        );
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Settings'),
                    'icon'  => 'icon-cogs'
                ),
                'description' => $this->l('This module manages the display of reports based on their status on the dashboard.'),
                'input' => array(
                    array(
                        'type'  => 'select',
                        'label' => $this->l('Show report status'),
                        'name'  => 'status',
                        'options' => array(
                            'query' => $options,
                            'id'    => 'id_option',
                            'name'  => 'name'
                        )
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                )
            ),
        );
        $helper                           = new HelperForm();
        $helper->show_toolbar             = false;
        $helper->table                    =  $this->table;
        $lang                             = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language    = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $this->fields_form                = array();
        $helper->identifier               = $this->identifier;
        $helper->submit_action            = 'submitModule';
        $helper->currentIndex             = $this->getSelfLink();
        $helper->tpl_vars = array(
            'fields_value' => array(
                'status'   => Tools::getValue('status', Configuration::get(MOD_SYNC_NAME.'_SHOW_STATUS'))
            ),
            'languages'    => $this->context->controller->getLanguages(),
            'id_language'  => $this->context->language->id
        );
        return $helper->generateForm(array($fields_form));
    }

    public function getTimeLineReports()
    {
        $sqlCondition = '';
        $showStatus   = (int)Configuration::get(MOD_SYNC_NAME.'_SHOW_STATUS');

        if ($showStatus === 1)
            $sqlCondition = ' WHERE `status` = 1';
        elseif ($showStatus == 2)
            $sqlCondition = ' WHERE `status` = 0';

        $sql     = 'SELECT * FROM `'._DB_PREFIX_.MOD_SYNC_NAME.'_status`'.$sqlCondition;
        $reports = Db::getInstance()->executeS($sql);

        if ($reports)
        {
            // Add new fields in array
            foreach ($reports as $reportKey => $reportValue)
            {
                if($reports[$reportKey]['date_end'])
                {
                    $report =& $reports[$reportKey];
                    // Start of segment
                    $startTimeLine               = $report['date_add'];
                    list($date, $time)           = explode(' ',$startTimeLine);
                    $report['date_add_expl']     = $date;
                    $date                        = new DateTime($date);
                    $report['date_add_formated'] = $date->format('d/m/Y');
                    $report['date_add_year']     = (int)$date->format('Y');
                    $report['date_add_month']    = (int)$date->format('m') -1; // -1 for time line configuration
                    $report['date_add_day']      = (int)$date->format('d');
                    $report['time']              = $time;
                    list($report['time_hour'], $report['time_min'], $report['time_sec']) = explode(':',$time);
                    // End of segment
                    $endTimeLine                 = $report['date_end'];
                    if($endTimeLine)
                        list($date, $time)           = explode(' ',$endTimeLine);
                    $report['date_end_expl']     = $date;
                    $date                        = new DateTime($date);
                    $report['date_end_formated'] = $date->format('d/m/Y');
                    $report['date_end_year']     = (int)$date->format('Y');
                    $report['date_end_month']    = (int)$date->format('m') -1; // -1 for time line configuration
                    $report['date_end_day']      = (int)$date->format('d');
                    $report['time_end']          = $time;
                    list($report['time_hour_end'], $report['time_min_end'], $report['time_sec_end']) = explode(':',$time);
                }
                else
                    unset($reports[$reportKey]);
            }
        } else {
            return false;
        }
        return array_values($reports);
    }

    public function hookDashboardZoneTwo($params)
    {
        $timeLineReports = $this->getTimeLineReports();
        if(!empty($timeLineReports) && is_array($timeLineReports))
        {
            $this->context->smarty->assign(
                array(
                    'nb_reports'    => count($timeLineReports),
                    'reports'       => $timeLineReports,
                    'show_status'   => (int)Configuration::get(MOD_SYNC_NAME.'_SHOW_STATUS'),
                    'sync_name'     => MOD_SYNC_DISPLAY_NAME,
                    'mod_sync_name' => MOD_SYNC_NAME,
                )
            );
            return $this->display(__FILE__, 'dashboard_zone_two.tpl');
        }
        return '';
    }

    /**
     * Gets the list of the fields of one or many classes for mapping
     *
     * @param string|array $classes
     * @return array
     */
    protected function _getLstOptions($classes)
    {
        $fieldList = array(
            'default' => array(
                'label' => 'Default',
                'input' => array()
            ),
            'lang'    => array(
                'label' => 'Lang',
                'input' => array()
            ),
            'special' => array(
                'label' => $this->l('Special fields'),
                'input' => array()
            )
        );

        if (!is_array($classes)) {
            $classes = array($classes);
        }

        $isFirst = true;
        $fields = array();
        foreach ($classes as $class) {
            $className = ucfirst($class);
            $reflection = new ReflectionClass($className);

            if (!$reflection->hasProperty('definition')) {
                return false;
            }

            $definition = $reflection->getStaticPropertyValue('definition');

            if ($isFirst) {
                $isFirst = false;
                $fields = $definition['fields'];
            } else {
                $fields = array_intersect_assoc($fields, $definition['fields']);
            }
        }

        foreach ($fields as $fieldName => $field) {
            if (
                strncmp($fieldName, 'id_', 3) == 0
                || strncmp($fieldName, 'fk_', 3) == 0
            ) {
                continue;
            }
            if (isset($field['lang']) && $field['lang']) {
                $type = 'lang';
            } else {
                $type = 'default';
            }

            $fieldList[$type]['input'][$fieldName] = array('field' => $fieldName);
        }

        $isFirst = true;
        $specialFields = array();

        foreach ($classes as $class) {
            $specialName   = MOD_SYNC_NAME . '_MAPPING_' . strtoupper($class) . '_SPECIAL_FIELDS';
            $classSpecialFields = preg_split('#,#', Configuration::get($specialName), -1, PREG_SPLIT_NO_EMPTY);

            if ($isFirst) {
                $specialFields = $classSpecialFields;
            } else {
                $specialFields = array_intersect($specialFields, $classSpecialFields);
            }
        }

        foreach ($specialFields as $specialField) {
            $fieldList['special']['input'][$specialField] = array('field' => $specialField);
        }

        //Reordering fields and removing empty groups for better readability
        foreach ($fieldList as $fieldType => &$fields) {
            if (empty($fields['input'])) {
                unset($fieldList[$fieldType]);
            } else {
                ksort($fields['input']);
            }
        }

        return $fieldList;
    }

    /**
     * Add a mapping or update an existing one
     *
     * @param string $typeOf
     * @param string $className
     * @return int
     */
    protected function _saveMapping($typeOf, $className)
    {
        $errors     = 0;
        $akeneo     = Tools::getValue('akeneo_' . $typeOf);
        $prestashop = Tools::getValue('prestashop_' . $typeOf);
        $required   = Tools::getValue('required_' . $typeOf);
        $ids        = Tools::getValue('ids_' . $typeOf);
        $remove     = Tools::getValue('remove_' . $typeOf);

        foreach ($akeneo as $key => $value) {
            $mappingId = (int)$ids[$key];

            if ($mappingId > 0) {
                $object             = new $className($mappingId);
                $object->id_mapping = $mappingId;
            } else {
                $object = new $className();
            }

            // If checkbox remove is checked
            if ($remove && isset($remove[$mappingId])) {
                $object->delete();
            } // Else if mapping is ok and not empty
            elseif (!empty($value) && !empty($prestashop[$key])) {
                $object->champ_akeneo     = $value;
                $object->champ_prestashop = $prestashop[$key];
                $object->required         = isset($required[$key]) ? (bool)$required[$key] : false;
                if (!$object->save())
                    $errors++;
            }
        }
        return (int)$errors;
    }

    public function renderFormProductsImport()
    {
        $fields_form                      = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Settings Import Product'),
                    'icon'  => 'icon-cogs'
                ),
                'input'  => array(
                    array(
                        'type'  => 'text',
                        'label' => $this->l('Default Quantity when adding product'),
                        'name'  => 'defaultqtyproduct',
                        'desc'  => $this->l('Default quantity that will be set when adding a new product'),
                        'class' => 'sw'
                    ),
                    array(
                        'type'   => 'switch',
                        'label'  => $this->l('Reset Combinations'),
                        'name'   => 'resetcombinations',
                        'desc'   => $this->l('Remove combinations for product before a import'),
                        'values' => array(
                            array(
                                'id'    => 'resetcombinations_on',
                                'value' => 1,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id'    => 'resetcombinations_off',
                                'value' => 0,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type'   => 'switch',
                        'label'  => $this->l('Reset Images'),
                        'name'   => 'resetimages',
                        'desc'   => $this->l('Remove all images for product before a import'),
                        'values' => array(
                            array(
                                'id'    => 'resetimages_on',
                                'value' => 1,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id'    => 'resetimages_off',
                                'value' => 0,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type'   => 'switch',
                        'label'  => $this->l('Reset Features'),
                        'name'   => 'resetfeatures',
                        'desc'   => $this->l('Remove all features for product before a import'),
                        'values' => array(
                            array(
                                'id'    => 'resetfeatures_on',
                                'value' => 1,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id'    => 'resetfeatures_off',
                                'value' => 0,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                )
            ),
        );
        $helper                           = new HelperForm();
        $helper->show_toolbar             = false;
        $helper->table                    = $this->table;
        $lang                             = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language    = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $this->fields_form                = array();
        $helper->identifier               = $this->identifier;
        $helper->submit_action            = 'submitSettingsProductsImport';
        $helper->currentIndex             = $this->getSelfLink();
        $helper->tpl_vars                 = array(
            'fields_value' => array(
                'defaultqtyproduct' => Configuration::get(MOD_SYNC_NAME . '_IMPORT_DEFAULT_QTY_PRODUCT'),
                'resetcombinations' => Configuration::get(MOD_SYNC_NAME . '_IMPORT_RESET_COMBINATIONS'),
                'resetimages'       => Configuration::get(MOD_SYNC_NAME . '_IMPORT_RESET_IMAGES'),
                'resetfeatures'     => Configuration::get(MOD_SYNC_NAME . '_IMPORT_RESET_FEATURES'),
            ),
            'languages'    => $this->context->controller->getLanguages(),
            'id_language'  => $this->context->language->id
        );
        return $helper->generateForm(array($fields_form));
    }

    public function renderFormSettingsCsv()
    {
        $fields_form                      = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Settings CSV'),
                    'icon'  => 'icon-cogs'
                ),
                'input'  => array(
                    array(
                        'type'  => 'text',
                        'label' => $this->l('Enclosure csv file'),
                        'name'  => 'enclosure',
                        'class' => 'sw'
                    ),
                    array(
                        'type'  => 'text',
                        'label' => $this->l('Delimiter csv file'),
                        'name'  => 'delimiter',
                        'class' => 'sw'
                    )
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                )
            ),
        );
        $helper                           = new HelperForm();
        $helper->show_toolbar             = false;
        $helper->table                    = $this->table;
        $lang                             = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language    = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $this->fields_form                = array();
        $helper->identifier               = $this->identifier;
        $helper->submit_action            = 'submitSettings';
        $helper->currentIndex             = $this->getSelfLink();
        $helper->tpl_vars                 = array(
            'fields_value' => array(
                'enclosure' => Configuration::get(MOD_SYNC_NAME . '_IMPORT_ENCLOSURE'),
                'delimiter' => Configuration::get(MOD_SYNC_NAME . '_IMPORT_DELIMITER')
            ),
            'languages'    => $this->context->controller->getLanguages(),
            'id_language'  => $this->context->language->id
        );
        return $helper->generateForm(array($fields_form));
    }

    protected function _createDirIfNotExist($path)
    {
        if (!file_exists($path)) {
            if (!mkdir($path, 0777, true))
                $return = false;
            else
                $return = true;
        } else {
            $return = false;
        }
        return $return;
    }

    /**
     * @return string
     */
    public function renderFormConfiguration() {
        $this->context->smarty->assign(array(
            'mod_name'                   => MOD_SYNC_NAME,
            'categoryPrestashopFields'   => $this->_getLstOptions('Category'),
            'featurePrestashopFields'    => $this->_getLstOptions(array('AttributeGroup', 'Feature')),
            'attributePrestashopFields'  => $this->_getLstOptions('MappingTmpAttributes'),
            'productPrestashopFields'    => $this->_getLstOptions('Product'),
            'categoryMinRequiredFields'  => array('code', 'id_parent', 'name'),
            'featureMinRequiredFields'   => array('code', 'name'),
            'attributeMinRequiredFields' => array('code', 'axis'),
            'productMinRequiredFields'   => array('reference', 'name', 'price'),
            'mappingCategory'            => MappingCategories::getAll(),
            'mappingFeature'             => MappingFeatures::getAll(),
            'mappingAttribute'           => MappingAttributes::getAll(),
            'mappingProduct'             => MappingProducts::getAll(),
            'formSettingsCsv'            => $this->renderFormSettingsCsv(),
            'formProductsImport'         => $this->renderFormProductsImport(),
        ));

        return $this->display(__FILE__, 'configuration.tpl');
    }

    public function hookActionCategoryDelete($params)
    {
        $children = array();
        foreach ($params['deleted_children'] as $child) {
            $children[] = $child->id;
        }

        $sql = 'DELETE FROM `' . _DB_PREFIX_ . 'mapping_code_categories`
            WHERE `id_category` IN (' . join(', ', $children) . ')';
        Db::getInstance()->execute($sql);
    }

    public function hookActionAttributeGroupDelete($params)
    {
        $attributeGroupName = MappingCodeAttributes::getCodeById($params['id_attribute_group']);

        $sql = 'DELETE FROM `' . _DB_PREFIX_ . 'mapping_code_attribute_values`
            WHERE `code_attribute_group` = "' . pSQL($attributeGroupName) . '"';
        Db::getInstance()->execute($sql);

        $sql = 'DELETE FROM `' . _DB_PREFIX_ . 'mapping_code_attributes`
            WHERE `id_attribute_group` = ' . pSQL($params['id_attribute_group']);
        Db::getInstance()->execute($sql);
    }

    public function hookActionAttributeDelete($params)
    {
        $sql = 'DELETE FROM `' . _DB_PREFIX_ . 'mapping_code_attribute_values`
            WHERE `id_attribute_value` = ' . pSQL($params['id_attribute']);
        Db::getInstance()->execute($sql);
    }

    public function hookActionFeatureDelete($params)
    {
        $featureName = MappingCodeFeatures::getCodeById($params['id_feature']);

        $sql = 'DELETE FROM `' . _DB_PREFIX_ . 'mapping_code_feature_values`
            WHERE `code_feature` = "' . pSQL($featureName) . '"';
        Db::getInstance()->execute($sql);

        $sql = 'DELETE FROM `' . _DB_PREFIX_ . 'mapping_code_features`
            WHERE `id_feature` = ' . pSQL($params['id_feature']);
        Db::getInstance()->execute($sql);
    }

    public function hookActionFeatureValueDelete($params)
    {
        $sql = 'DELETE FROM `' . _DB_PREFIX_ . 'mapping_code_feature_values`
            WHERE `id_feature_value` = ' . pSQL($params['id_feature_value']);
        Db::getInstance()->execute($sql);
    }

    public function hookActionProductDelete($params)
    {
        $sql = 'DELETE FROM `' . _DB_PREFIX_ . 'mapping_products_groups`
            WHERE `id_product` = ' . pSQL($params['id_product']);
        Db::getInstance()->execute($sql);
    }

    /**
     * @return string
     */
    public static function getSelfLink()
    {
        return self::$_selfLink;
    }

    /**
     * @param string $selfLink
     */
    public static function setSelfLink($selfLink)
    {
        self::$_selfLink = $selfLink;
    }
}

class SynoSyncAutoIncludeRecursiveFilterIterator extends RecursiveFilterIterator {

    public static $_excludeFolders = array('librairies');

    public function accept() {
        /** @var SplFileInfo $file */
        /** @var RecursiveIteratorIterator $iterator */
        $file   = $this->current();
        $return = true;
        foreach(self::$_excludeFolders as $excludeFolder)
            if(strpos($file->getRealPath(), '/'.$excludeFolder.'/') !== false
                || strpos($file->getRealPath(), '\\'.$excludeFolder.'\\') !== false)
                $return = false;
        return $return;
    }

}
