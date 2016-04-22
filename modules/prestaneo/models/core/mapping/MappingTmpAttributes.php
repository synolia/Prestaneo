<?php

class MappingTmpAttributes extends MappingCodeAbstract
{
    public $code;
    public $axis;

    public static $definition = array(
        'table'   => 'mapping_tmp_attributes',
        'primary' => 'id_mapping',
        'fields'  => array(
            'code' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName'),
            'axis' => array('type' => self::TYPE_STRING),
        )
    );

    /**
     * Get distinct axes
     *
     * @return array|bool
     */
    public static function getDistinctAxes() {
        $rsAxes = self::getAll();

        if ($rsAxes === false) {
            return false;
        }

        $axes = array();
        foreach ($rsAxes as $axis) {
            $axes = array_merge($axes, explode(',', $axis['axis']));
        }

        return array_unique($axes);
    }

    public static function getAxisById($id)
    {
        if (empty($id) || !$id || $id <= 0)
            return false;

        $sql = 'SELECT `axis`
                FROM ' . _DB_PREFIX_ . self::$definition['table'] . '
                WHERE `' . self::$definition['primary'] . '` = ' . pSQL($id);
        return (int)Db::getInstance()->getValue($sql);
    }

    public static function getAxisByCode($code)
    {
        if (empty($code) || !$code)
            return false;

        $sql = 'SELECT `axis`
                FROM ' . _DB_PREFIX_ . self::$definition['table'] . '
                WHERE `code` = "' . pSQL($code) . '"';
        return Db::getInstance()->getValue($sql);
    }
}