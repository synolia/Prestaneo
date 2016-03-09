<?php

class MappingCodeFeatures extends MappingCodeAbstract
{
    public $id_feature;
    public $code;

    public static $definition = array(
        'table'   => 'mapping_code_features',
        'primary' => 'id_feature',
        'fields'  => array(
            'id_feature' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
            'code'       => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName'),
        )
    );
}