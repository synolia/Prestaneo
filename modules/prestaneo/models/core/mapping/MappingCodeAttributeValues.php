<?php

class MappingCodeAttributeValues extends MappingCodeAbstract
{
    public $id_attribute_value;
    public $code_attribute_group;
    public $code;

    public static $definition = array(
        'table'   => 'mapping_code_attribute_values',
        'primary' => 'id_attribute_value',
        'fields'  => array(
            'id_attribute_value'   => array('type' => self::TYPE_INT,    'validate' => 'isUnsignedId'),
            'code_attribute_group' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName'),
            'code'                 => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName'),
        )
    );

    /**
     * @param string $code
     * @param string $attributeGroupCode
     *
     * @return bool|string
     */
    public static function getIdByCodeAndGroup($code, $attributeGroupCode)
    {
        if(!is_numeric($code)){
            if (empty($code) || !$code || empty($attributeGroupCode) || !$attributeGroupCode)
                return false;
        }

        $sql = 'SELECT `' . self::$definition['primary'] . '`
                FROM ' . _DB_PREFIX_ . self::$definition['table'] . '
                WHERE
                    `code` = "' . pSQL($code) . '"
                    AND `code_attribute_group` = "' . pSQL($attributeGroupCode) . '";';

        return Db::getInstance()->getValue($sql);
    }
}
