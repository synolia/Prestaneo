<?php

class Utils
{
    private static $_instance;
    private static $_utilsInstances = array();
    private $_utilsPath = false;

    public function __construct()
    {
        $this->_utilsPath = realpath(dirname(__FILE__).'/../utils');
    }

    private function getInstancePath($instance)
    {
        if(substr($instance, 0, -4) == '.php')
            return $this->_utilsPath.'/'.$instance;
        else
            return $this->_utilsPath.'/'.$instance.'.php';
    }

    public static function exec($class)
    {
        $class = ucfirst(preg_replace('#\s+#', '', str_replace('Utils', '', $class)));

        /** @return new($class) desc*/
        return self::execInstance($class);
    }

    private static function execInstance($instance)
    {
        if(!array_key_exists($instance, self::$_utilsInstances))
        {
            $instancePath = self::getInstance()->getInstancePath($instance);
            if(!file_exists($instancePath))
                throw new Exception ('<b>FATAL ERROR :</b> unknown util tool '.$instance);
            if(!class_exists('Utils'.$instance))
                throw new Exception ('<b>FATAL ERROR :</b> unloaded util tool '.$instance.' (system error)');
            $instanceClass = 'Utils'.$instance;
            self::$_utilsInstances[$instance] = new $instanceClass();
        }
        return self::$_utilsInstances[$instance];
    }

    private static function getInstance()
    {
        if(!is_object(self::$_instance))
            self::$_instance = new self();
        return self::$_instance;
    }
}