<?php

class MappingProductsGroups extends MappingAbstract
{
    public $id_product;
    public $id_product_attribute;
    public $group_code;

    public static $definition = array(
        'table'   => 'mapping_products_groups',
        'primary' => 'id_mapping',
        'fields'  => array(
            'id_product'           => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
            'group_code'           => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName'),
        )
    );

    public static function getMappingProductsGroups()
    {
        $sql = 'SELECT *
                FROM ' . _DB_PREFIX_ . self::$definition['table'];
        return Db::getInstance()->executeS($sql);
    }

    public static function getMappingByGroupCode($groupCode)
    {
        if (empty($groupCode) || !$groupCode)
            return false;

        $sql = 'SELECT `' . self::$definition['primary'] . '`
                FROM ' . _DB_PREFIX_ . self::$definition['table'] . '
                WHERE `group_code` = "' . pSQL($groupCode) . '"';
        return Db::getInstance()->getValue($sql);
    }

    public static function getIdProductByGroupCode($code)
    {
        if (empty($code) || !$code)
            return false;

        $sql = 'SELECT `id_product`
                FROM ' . _DB_PREFIX_ . self::$definition['table'] . '
                WHERE `group_code` = "' . pSQL($code) . '"';
        return (int)Db::getInstance()->getValue($sql);
    }

    public static function getGroupCodeByIdProduct($productId)
    {
        if (empty($productId) || !$productId || $productId <= 0)
            return false;

        $sql = 'SELECT `group_code`
                FROM ' . _DB_PREFIX_ . self::$definition['table'] . '
                WHERE `id_product` = ' . $productId;
        return Db::getInstance()->getValue($sql);
    }
}