<?php

/**
 * Class UtilsSystem
 *
 * Calcule
 */

Class UtilsProgress extends Utils
{
    public static $progressCache = array();

    public function start()
    {
        $idAction = $this->_getIdAction();
        if(!$idAction)
            return false;
        return $this->progress(0, $idAction);
    }

    public function end()
    {
        $idAction = $this->_getIdAction();
        if(!$idAction)
            return false;
        return $this->progress(100, $idAction, true);
    }

    public function progress($percent, $idAction = false, $delete = false)
    {
        if(!$idAction)
            $idAction = $this->_getIdAction();
        if(!$idAction)
            return false;

        if(!array_key_exists($idAction, self::$progressCache))
        {
            self::$progressCache[$idAction] = $percent;
            //avoid double entries
            Db::getInstance()->execute('DELETE FROM '._DB_PREFIX_ . MOD_SYNC_NAME . '_progress
                                        WHERE id_action = '.intval($idAction));
            return Db::getInstance()->insert(MOD_SYNC_NAME.'_progress', array(
                    'percentage' => $percent,
                    'id_action' => $idAction
                )
            );
        }
        elseif($delete)
        {
            if(array_key_exists($idAction, self::$progressCache))
                unset(self::$progressCache[$idAction]);
            return Db::getInstance()->execute('DELETE FROM '._DB_PREFIX_ . MOD_SYNC_NAME . '_progress
                                     WHERE id_action = '.intval($idAction));
        }
        elseif($this->_checkNotCached($idAction, $percent))
        {
            self::$progressCache[$idAction] = $percent;
            return Db::getInstance()->update(MOD_SYNC_NAME.'_progress', array(
                    'percentage' => $percent,
                    'id_action' => $idAction
                )
            );
        }
        return false;
    }


    public function progressByCount($totalLength, $currentOffset, $idAction = false, $delete = false)
    {
        if(!$idAction)
            $idAction = $this->_getIdAction();
        if(!$idAction)
            return false;

        $percent = $this->_calculatePercentage($totalLength, $currentOffset);
        return $this->progress($percent, $idAction, $delete);
    }

    protected function _getIdAction()
    {
        $backTrace       = debug_backtrace();
        $backTraceLength = count($backTrace);
        $callerOffset    = 1;
        while($backTrace[$callerOffset]['class'] == get_class($this) && $callerOffset < $backTraceLength)
            $callerOffset++;

        if($callerOffset==$backTraceLength)
            return false;

        $callerFile = str_replace(array(_PS_MODULE_DIR_, '\\'), array('', '/'), $backTrace[$callerOffset-1]['file']);
        if(substr($callerFile, 0, 1) == '/')
            $callerFile = substr($callerFile, 0, 1);
        $moduleName = substr($callerFile, 0, strpos($callerFile, '/'));
        $name       = $backTrace[$callerOffset]['class'];

        $sql = 'SELECT id
                FROM '._DB_PREFIX_.MOD_SYNC_NAME.'_actions
                WHERE   `name`        LIKE "'.pSQL($name).'"
                AND     `module_name` LIKE "'.pSQL($moduleName).'"';
        return Db::getInstance()->getValue($sql);
    }

    protected function _checkNotCached($idAction, $progress)
    {
        if(!array_key_exists($idAction, self::$progressCache))
            return true;

        if(self::$progressCache[$idAction] != $progress)
        {
            self::$progressCache[$idAction] = $progress;
            return true;
        }
        return false;
    }

    protected function _calculatePercentage(int $total, int $done)
    {
        return round($done * 100 / $total);
    }
}