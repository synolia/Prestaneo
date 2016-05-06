<?php

function upgrade_module_0_1_3(Prestaneo $module) {
    $return = true;

    $fetch = Db::getInstance()->executeS('SHOW COLUMNS FROM `' .  _DB_PREFIX_ . MappingTmpAttributes::$definition['table'] . '` LIKE "id_product"');
    if ((!is_array($fetch) || count($fetch)==0)) {
        $oldTmpAttributes = Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . MappingTmpAttributes::$definition['table'] . '`');
        $oldProductGroups = Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'mapping_products_groups`');

        if (!is_array($oldTmpAttributes) || !is_array($oldProductGroups)) {
            $module->setError('Can not get old mapping values');
            return false;
        }

        /** @var MappingTmpAttributes[] $newTmpAttributes */
        $newTmpAttributes = array();

        foreach ($oldProductGroups as $productGroup) {
            $newTmpAttribute = new MappingTmpAttributes();

            $newTmpAttribute->id_product = $productGroup['id_product'];
            $newTmpAttribute->code       = $productGroup['group_code'];

            foreach ($oldTmpAttributes as $oldTmpAttribute) {
                if ($oldTmpAttribute['code'] == $productGroup['group_code']) {
                    $newTmpAttribute->axis = $oldTmpAttribute['axis'];
                    break;
                }
            }

            $newTmpAttributes[] = $newTmpAttribute;
        }

        if (!Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'mapping_products_groups`;')) {
            $module->setError('Could not remove old mapping tables');
            return false;
        }

        if (!Db::getInstance()->execute('
            ALTER TABLE `' . _DB_PREFIX_ . MappingTmpAttributes::$definition['table'] . '`
            CHANGE `id_mapping` `id_product` int(11) NOT NULL;
        ')) {
            $module->setError('Could not change column name in ' . MappingTmpAttributes::$definition['table']);
            return false;
        }

        if (!Db::getInstance()->execute('TRUNCATE TABLE `' . _DB_PREFIX_ . MappingTmpAttributes::$definition['table'] . '`')) {
            $module->setError('Could not empty table ' . MappingTmpAttributes::$definition['table']);
            return false;
        }

        foreach ($newTmpAttributes as $newTmpAttribute) {
            if (!$newTmpAttribute->save()) {
                $module->setError('Could not save mapping for ' . $newTmpAttribute->code);
            } else {
                $product = new Product($newTmpAttribute->id_product);
                $product->reference = $newTmpAttribute->code;

                if (!$product->save()) {
                    $module->setError('Could not update product reference for ' . $newTmpAttribute->code);
                }
            }
        }
    }

    if (!Db::getInstance()->execute('
            INSERT IGNORE INTO `' . _DB_PREFIX_ . MappingAttributes::$definition['table'] . '` (`champ_akeneo`,`champ_prestashop`, `required`) VALUES
            ("label","name", 1)'
    )) {
        $module->setError('Could not add name mapping for variant import');
    }

    if (!Configuration::updateValue(MOD_SYNC_NAME . '_MAPPING_PRODUCT_SPECIAL_FIELDS', 'groups,categories,image,file,cross_sell_product,cross_sell_group')) {
        $module->setError('Could not update special fields for product');
    }

    if (!Configuration::updateValue(MOD_SYNC_NAME . '_MAPPING_MAPPINGTMPATTRIBUTES_SPECIAL_FIELDS', 'name')) {
        $module->setError('Could not update special fields for tmp attributes');
    }

    // Errors
    $errors = $module->getErrors();

    // Notifications
    if(count($errors) && $return)
        $module->setError('Module has been upgraded, precedent error(s) are non blocking error(s)');

    return $return;
}