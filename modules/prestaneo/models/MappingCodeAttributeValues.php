<?php

class MappingCodeFeatureValues extends MappingCodeAbstract
{
    public $id_feature_value;
    public $code;

    public static $definition = array(
        'table'   => 'mapping_code_feature_values',
        'primary' => 'id_feature_value',
        'fields'  => array(
            'id_feature_value' => array('type' => self::TYPE_INT,    'validate' => 'isUnsignedId'),
            'code'             => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName'),
        )
    );
}