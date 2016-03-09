<?php

/*
 * execute an install script if it has been never executed
 */

class Installer
{
    public $name = MOD_SYNC_NAME;
    protected $_moduleId;
    protected $_currentInstallClassParts;
    protected $_folderStructureByAction = array(
        'import' => array(
            'success',
            'files',
        ),
    );
    protected $_installed=array();
    protected static $_instance;

    public static function getInstance()
    {
        if(!is_object(self::$_instance))
            self::$_instance = new self();
        return self::$_instance;
    }

    public static function install($classNameToInstall=false)
    {
        if($classNameToInstall)
            return self::getInstance()->installClassIfNecessary($classNameToInstall);
        else
            return new self();
    }

    public static function unistall()
    {
        return self::getInstance()->uninstallAll();
    }

    public function __construct()
    {
        $backtrace     = debug_backtrace();
        $thisClassName = get_class($this);
        $i=0;
        while($thisClassName ==  @$backtrace[$i]['class'])
            $i++;
        if(array_key_exists($i, $backtrace))
            $this->installClassIfNecessary($backtrace[$i]['class']);
    }

    public function installClassIfNecessary($originClass)
    {
        $this->_moduleId = $this->isInstalled($originClass);
        if($this->_moduleId)
            return true;

        $path = $this->getPathByClassName($originClass);
        if(empty($path))
        {
            $result = $this->_createFileDirectories();
            if($result)
                $this->setInstalled($originClass);
            return $result;
        }

        Tools::displayAsDeprecated();
        $class  = 'Install'.$originClass.'';
        $method = 'install'.$originClass.'Action';
        $installResult = $this->_install($originClass, $path, $class, $method);
        if(!$installResult)
        {
            $this->_uninstall($originClass, $path, $class, 'un'.$method);
            return false;
        }
        else
        {
            $this->setInstalled($originClass);
            return true;
        }
    }

    public function uninstallAll()
    {
        $modules = $this->getInstalled();
        foreach($modules as $module)
        {
            $path = $this->getPathByClassName($module['name']);
            if(empty($path))
                continue;
            $class  = 'Install'.$module['name'].'';
            $method = 'uninstall'.$module['name'].'Action';
            $this->_uninstall($module['name'], $path, $class, $method);
            $this->setUninstalled($module['name']);
        }
    }

    public function renderForm($output)
    {
        Tools::displayAsDeprecated();
        return $this->_triggerFunction(__FUNCTION__, $output);
    }

    public function processForm()
    {
        Tools::displayAsDeprecated();
        return $this->_triggerFunction(__FUNCTION__);
    }

    protected function _triggerFunction($functionName, $parameter='')
    {
        $modules = $this->getInstalled();
        foreach($modules as $module)
        {
            $class = $this->getConfigurationByClassName($module['name']);
            if(is_array($class))
            {
                if(!class_exists($class['class']))
                    require $class['path'];
                if (method_exists($class['class'], $functionName))
                {
                    $object = new $class['class']();
                    $parameters = (strlen($parameter)>0)?array($parameter):array();
                    $parameter = call_user_func_array(array($object, $functionName), $parameters);
                }
            }
        }
        return $parameter;
    }

    public function setInstalled($originClass)
    {
        $sql = 'INSERT INTO `'._DB_PREFIX_.MOD_SYNC_NAME.'_modules` (`name`, date_add) VALUES ("'.$originClass.'", NOW())';

        return Db::getInstance()->execute($sql);
    }

    public function setUninstalled($originClass)
    {
        $sql = 'DELETE FROM `'._DB_PREFIX_.MOD_SYNC_NAME.'_modules` WHERE `name` = "'.$originClass.'"';

        return Db::getInstance()->execute($sql);
    }

    public function getInstalled()
    {
        $sql = 'SELECT id, name FROM `'._DB_PREFIX_.MOD_SYNC_NAME.'_modules`';

        return Db::getInstance()->executeS($sql);
    }

    public function getPathByClassName($originClass)
    {
        preg_match_all('#((?:^|[A-Z])[a-z]+)#', $originClass, $pathParts);

        for($nb=count($pathParts[1])-1, $i=0; $i<$nb; $i++)
            $pathParts[1][$i] = strtolower($pathParts[1][$i]);
        $this->_currentInstallClassParts = $pathParts[1];

        return realpath(dirname(__FILE__).'/../install/'.implode('/', $this->_currentInstallClassParts).'.php');
    }

    public function getConfigurationByClassName($originClass)
    {
        preg_match_all('#((?:^|[A-Z])[a-z]+)#', $originClass, $pathParts);

        $subPath = '';
        $subClass = '';
        for($nb=count($pathParts[1])-1, $i=0; $i<$nb; $i++)
        {
            $subPath .= strtolower($pathParts[1][$i]).'/';
            $subClass .= ucfirst($pathParts[1][$i]);
        }

        $path = realpath(dirname(__FILE__).'/../install/'.$subPath.'configuration/'.$pathParts[1][$i].'.php');
        if(empty($path))
            return false;

        $class = 'Install'.$subClass.'Configuration'.ucfirst($pathParts[1][$i]);
        return array(
            'path' => $path,
            'class' => $class,
        );
    }

    public function isInstalled($originClass)
    {
        if(array_key_exists($originClass, $this->_installed))
        {
            return $this->_installed[$originClass];
        }

        $sql = 'SELECT id FROM `'._DB_PREFIX_.MOD_SYNC_NAME.'_modules` WHERE `name` = "'.$originClass.'"';
        $result = Db::getInstance()->getRow($sql);

        if(is_array($result))
        {
            $this->_installed[$originClass] = $result['id'];
            return $result['id'];
        }
        else
            return false;
    }

    protected function _install($originClass, $path, $class, $method)
    {
        include($path);

        $object = new $class();
        $result = call_user_func_array(array($object, $method), array());
        if(!$result)
            return false;
        return $this->_createFileDirectories();
    }

    protected function _createFileDirectories()
    {
        $moduleRootPath = realpath(dirname(__FILE__).'/../../');
        $actionPath     = $moduleRootPath.'/'.implode('/', array_map('strtolower', $this->_currentInstallClassParts)).'/';
        if(!array_key_exists($this->_currentInstallClassParts[0], $this->_folderStructureByAction))
            return false;
        $folders        = $this->_folderStructureByAction[$this->_currentInstallClassParts[0]];
        foreach($folders as $folder)
        {
            $folderPath = $actionPath.$folder;
            if(!file_exists($folderPath))
                if(!mkdir($folderPath, 0777, true))
                    return false;
        }
        return true;
    }

    protected function _uninstall($originClass, $path, $class, $method)
    {
        if(!class_exists($class))
            include($path);

        $object = new $class();
        return call_user_func_array(array($object, $method), array());
    }

    public function recurse_copy($source, $distant, $replace=true)
    {
        Tools::displayAsDeprecated();
        return Utils::exec('folder')->recurse_copy($source, $distant, $replace);
    }

    public function delTree($dir)
    {
        Tools::displayAsDeprecated();
        return Utils::exec('folder')->delTree($dir);
    }

    public function l($string, $specific = false)
    {
        return Translate::getModuleTranslation($this->name, $string, ($specific) ? $specific : $this->name);
    }
}