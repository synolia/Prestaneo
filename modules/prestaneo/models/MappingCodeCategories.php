<?php

class MappingCodeCategories extends MappingCodeAbstract
{
    public $id_category;
    public $code;

    public static $definition = array(
        'table'   => 'mapping_code_categories',
        'primary' => 'id_category',
        'fields'  => array(
            'id_category' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
            'code'        => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName'),
        )
    );

    public static function getCategoriesToDeactivate($arrIds)
    {
        if (empty($arrIds) || !$arrIds || !is_array($arrIds))
            return false;

        $sql = 'SELECT *
                FROM ' . _DB_PREFIX_ . self::$definition['table'] . ' mcc
                LEFT JOIN ' . _DB_PREFIX_ . 'category c ON (mcc.`id_category` = c.`id_category`)
                WHERE c.`active` = 1
                AND c.`id_category` NOT IN (' . implode(',', $arrIds) . ')';
        return Db::getInstance()->executeS($sql);
    }
}