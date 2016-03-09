<?php

class Overrider
{
    private static $_instance;
    private $_moduleInstance;
    private $_originalModuleInstance;
    private $_originalModuleName;

    public function __construct()
    {
        $reflector          = new ReflectionClass(get_class($this));
        $truncatedClassPath = substr($reflector->getFileName(), strlen(_PS_MODULE_DIR_));
        $moduleName         = substr($truncatedClassPath, 0, strpos($truncatedClassPath, '/'));
        if(!is_object($this->_moduleInstance))
            $this->_moduleInstance = Module::getInstanceByName(MOD_SYNC_NAME);
        if(!is_object($this->_originalModuleInstance))
            $this->_originalModuleInstance = Module::getInstanceByName($moduleName);
    }

    public static function getInstance()
    {
        if(!is_object(self::$_instance))
            self::$_instance = new self();
        return self::$_instance;
    }

    public function postProcess()
    {
        return $this->_triggerFunction(__FUNCTION__);
    }

    public function getContent($output)
    {
        return $this->_triggerFunction(__FUNCTION__, $output);
    }

    private function _triggerFunction($functionName, $parameter='')
    {
        //ob_start();
        $includedModels = call_user_func(array($this->_moduleInstance, 'getIncludedPaths'), '');
        foreach($includedModels as $modelFilePath)
        {
            $modelPath = dirname($modelFilePath);
            $modelFile = basename($modelFilePath);
            $overridePath = $modelPath.'/'.'configuration'.'/'.$modelFile;
            if(file_exists($overridePath))
            {
                $this->_originalModuleName = substr(
                    substr($modelPath, strlen(_PS_MODULE_DIR_)),
                    0,
                    strpos(substr($modelPath, strlen(_PS_MODULE_DIR_)), '/')
                );
                $overrideClass = ucfirst(basename($modelPath)).'Configuration';
                $overrideClass.= ucfirst(substr($modelFile, 0, strpos($modelFile, '.')));
                if(!class_exists($overrideClass))
                    require $overridePath;
                if (method_exists($overrideClass, $functionName))
                {
                    $object = new $overrideClass();
                    $parameters = (strlen($parameter)>0)?array($parameter):array();
                    $parameter = call_user_func_array(array($object, $functionName), $parameters);
                }
            }
        }
        //ob_end_clean();
        return $parameter;
    }

    public function __call($name, $arguments)
    {
        if(method_exists($this->_moduleInstance, $name))
        {
            return call_user_func_array(array($this->_moduleInstance, $name), $arguments);
        }
        else
        {
            return call_user_func_array(array($this->_originalModuleInstance, $name), $arguments);
        }
    }

    public function __get($name)
    {
        if(property_exists($this->_moduleInstance, $name))
            return $this->_moduleInstance->$name;
        else
            return @$this->_originalModuleInstance->$name;
    }

    public function __set($name, $value)
    {
        if(property_exists($this->_originalModuleInstance, $name))
            $this->_originalModuleInstance->$name = $value;
        else
            @$this->_moduleInstance->$name;
    }

    public function display($file, $template, $cacheId = null, $compileId = null)
    {
        $arguments = array(
            call_user_func_array(array($this->_moduleInstance, 'getLocalFilePath'), array()),
            $template,
            $cacheId,
            $compileId,
        );
        return call_user_func_array(array($this->_moduleInstance, __FUNCTION__), $arguments);
    }

    public function l($string, $specific = false)
    {
        $translation = Translate::getModuleTranslation($this->_originalModuleName, $string,
                                                                ($specific) ? $specific : $this->_originalModuleName);
        if($translation == $string)
        {
            $translation = Translate::getModuleTranslation(MOD_SYNC_NAME, $string,
                                                                ($specific) ? $specific : MOD_SYNC_NAME);
        }
        return $translation;
    }
}