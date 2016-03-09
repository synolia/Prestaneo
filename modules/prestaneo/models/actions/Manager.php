<?php

/*
 * Cette class permet de passer toutes les configs aux autres objets
 */

Class Manager
{
    protected $_path;
    protected static $_CachedPath=null;

    public function __construct($path=false, $noCache=false)
    {
        if($path==false && !is_null(self::$_CachedPath))
        {
            $path = self::$_CachedPath;
        }
        elseif($path==false)
        {
            $backTrace       = debug_backtrace();
            $backTraceOffset = (array_key_exists('file', $backTrace[1])?2:1);
            if($backTrace[$backTraceOffset]['class']!=MOD_SYNC)
            {
                $classParts = call_user_func_array(
                    array(MOD_SYNC, 'getPartsByClassName'),
                    array($backTrace[$backTraceOffset]['class'])
                );
                $path = _PS_MODULE_DIR_.MOD_SYNC_NAME.'/'.strtolower(implode('/', $classParts));
            }
        }
        $this->_path = $path;
        if(is_null(self::$_CachedPath))
            self::$_CachedPath = $this->_path;
    }

    public function setPath($path)
    {
        $this->_path = $path;
    }

    public function getPath()
    {
        return $this->_path;
    }

}