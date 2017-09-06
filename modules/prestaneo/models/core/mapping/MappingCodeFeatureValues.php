<?php

class MappingCodeFeatureValues extends MappingCodeAbstract
{
    public $id_feature_value;
    public $code_feature;
    public $code;

    public static $definition = array(
        'table'   => 'mapping_code_feature_values',
        'primary' => 'id_feature_value',
        'fields'  => array(
            'id_feature_value' => array('type' => self::TYPE_INT,    'validate' => 'isUnsignedId'),
            'code_feature'     => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName'),
            'code'             => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName'),
        )
    );

    /**
     * @param string $code
     * @param string $featureCode
     *
     * @return bool|string
     */
    public static function getIdByCodeAndFeature($code, $featureCode)
    {
        if (empty($code) || !$code || empty($featureCode) || !$featureCode)
            return false;

        $sql = 'SELECT `' . self::$definition['primary'] . '`
                FROM ' . _DB_PREFIX_ . self::$definition['table'] . '
                WHERE
                    `code` = "' . pSQL($code) . '"
                    AND `code_feature` = "' . pSQL($featureCode) . '";';

        return Db::getInstance()->getValue($sql);
    }
}