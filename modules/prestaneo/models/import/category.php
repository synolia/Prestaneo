<?php

class ImportCategory extends ImportAbstract
{
    public static $icon  = 'sitemap';
    public static $order = 1;
    /**
     * Import categories and deactivates old ones
     *
     * @return bool
     * @throws Exception
     */
    public function process()
    {
        $delimiter = Configuration::get(MOD_SYNC_NAME . '_IMPORT_DELIMITER') ? Configuration::get(MOD_SYNC_NAME . '_IMPORT_DELIMITER') : ';';

        $fileTransferHost = Configuration::get(MOD_SYNC_NAME.'_ftphost');
        if(!empty($fileTransferHost)) {
            $fileTransfer = $this->_getFtpConnection();

            $fileTransfer->getFiles(Configuration::get(MOD_SYNC_NAME . '_IMPORT_CATEGORY_FTP_PATH'), $this->_manager->getPath().'/files/', '*.csv');
        }

        $reader        = new CsvReader($this->_manager, $delimiter);
        $contextShopId = (int)Context::getContext()->shop->id;
        $dataLines     = $reader->getData();
        $currentFile   = $reader->getCurrentFileName(0);

        if (!is_array($dataLines) || empty($dataLines)){
            $this->logError('Nothing to import or file is not valid CSV');
            $this->_mover->finishAction(basename($currentFile), false);
            return false;
        }

        $requiredFields = MappingCategories::getRequiredFields('akeneo');
        $headers        = $this->_cleanDataLine(array_shift($dataLines));
        $missingFields  = $this->_checkMissingRequiredFields($requiredFields, $headers);

        if (!empty($missingFields)) {
            $this->logError('Missing required fields : ' . implode(', ', $missingFields));
            $this->_mover->finishAction(basename($currentFile), false);
            return false;
        }

        $this->_offsets = array_flip($headers);

        $this->_getLangsInCsv($headers);
        $this->_mapOffsets(MappingCategories::getAllPrestashopFields());

        //Cleaning data before any use
        foreach ($dataLines as &$dataLine) {
            if ($dataLine == array(null)) {
                $dataLine = false;
            }

            if ($dataLine != false) {
                $dataLine = $this->_cleanDataLine($dataLine);
            }
        }

        $oldCategories  = $this->_getCategoriesToDeactivate($dataLines);
        $errorCount     = 0;
        $lastErrorCount = 0;

        foreach ($dataLines as $line => $data) {
            $nextFile = $reader->getCurrentFileName($line);
            if ($nextFile != $currentFile) {
                $treatmentResult = 1;
                if($errorCount > $lastErrorCount)
                    $treatmentResult = 0;
                $this->_mover->finishAction(basename($currentFile), $treatmentResult, 'import');
                $lastErrorCount = $errorCount;
                $currentFile    = $nextFile;
            }

            if ($data === false) {
                continue;
            }
            $codeCategory = $data[$this->_offsets['id_category']];
            $codeParent   = $data[$this->_offsets['id_parent']];

            if (empty($codeCategory)) {
                $this->logError("Missing category code on line " . ($line+2));
                $errorCount++;
                continue;
            }

            if (empty($codeParent)) {
                $idParent = Configuration::get('PS_HOME_CATEGORY');
            } else {
                $idParent = MappingCodeCategories::getIdByCode($codeParent);
                if ($idParent === false) {
                    $this->logError('Parent category for ' . $codeCategory . ' does not exists yet (' . $codeParent . ')');
                    $errorCount++;
                    continue;
                }
            }

            $langName        = array();
            $langLinkRewrite = array();

            foreach ($this->_langs as $id_lang => $iso_code) {
                $suffixLang = '-' . $this->_labels[$iso_code];
                $name       = $data[$this->_offsets['name' . $suffixLang]];

                if (empty($name)) {
                    $langName[$id_lang]        = $codeCategory;
                    $langLinkRewrite[$id_lang] = Tools::link_rewrite($codeCategory);
                } else {
                    $langName[$id_lang]        = $name;
                    $langLinkRewrite[$id_lang] = Tools::link_rewrite($name);
                }
            }

            $idCategory = MappingCodeCategories::getIdByCode($codeCategory);

            if ($idCategory !== false) {
                $categoryExist = Category::existsInDatabase($idCategory, 'category');
                if ($categoryExist) {
                    $objCategory = new Category($idCategory);
                }
                else {
                    //The category does not exist but the mapping does. It will have to be updated
                    $objCategory = new Category();
                }
            } else {
                $objCategory = new Category();
                $categoryExist = false;
            }

            $objCategory->id_parent        = $idParent;
            $objCategory->id_shop_default  = $contextShopId;
            $objCategory->name             = $langName;
            $objCategory->link_rewrite     = $langLinkRewrite;
            $objCategory->active           = 1;
            $objCategory->is_root_category = 0;

            if (!$objCategory->save()) {
                $this->logError('Could not save category ' . $codeCategory);
                $errorCount++;
                continue;
            }
            else {
                if (_PS_MODE_DEV_) {
                    $this->log('Category ' . $codeCategory . ' saved');
                }

                if (!$categoryExist) {
                    $mapping = new MappingCodeCategories();

                    $mapping->code        = $codeCategory;
                    $mapping->id_category = $objCategory->id;

                    if (!$mapping->add()) {
                        $this->logError('Could not save category mapping for ' . $codeCategory);
                        $errorCount++;
                    } elseif (_PS_MODE_DEV_) {
                        $this->log('Category ' . $codeCategory . ' mapping saved');
                    }
                }
            }

            if (!$this->_associateShop($objCategory, $contextShopId)) {
                $this->logError('Could not associate category ' . $codeCategory . ' to shop ' . $contextShopId);
                $errorCount++;
            } elseif (_PS_MODE_DEV_) {
                $this->log('Category ' . $codeCategory . ' associated to shop ' . $contextShopId);
            }
        }

        $endStatus = ($errorCount == $lastErrorCount);
        $this->_mover->finishAction(basename($currentFile), $endStatus);

        if (!$this->_regeneratePositions($contextShopId)) {
            $this->logError('Could not regenerate categories position for shop ' . $contextShopId);
        } elseif (_PS_MODE_DEV_) {
            $this->log('Categories position regenerated for shop ' . $contextShopId);
        }

        if (!$this->_deactivateCategoriesById($oldCategories)) {
            $this->logError('Could not deactivate old categories : ' . implode(', ', $oldCategories));
        } elseif (_PS_MODE_DEV_ && !empty($oldCategories)) {
            $this->log('Old categories deactivated (' . implode(',', $oldCategories) . ')');
        }


        return true;
    }

    /**
     * Deactivates categories from their id
     *
     * @param array $ids
     * @return bool
     */
    protected function _deactivateCategoriesById($ids)
    {
        if (!is_array($ids)) {
            return false;
        }

        if (empty($ids)) {
            //Nothing to do, no reason to get an error
            return true;
        }

        return Db::getInstance()->update('category', array('active' => 0), 'id_category IN (' . implode(', ', $ids) . ')');

    }

    /**
     * Get the list of the old categories that should be deactivated
     *
     * @param array $dataLines
     * @return array|bool
     */
    protected function _getCategoriesToDeactivate($dataLines)
    {
        if (!is_array($dataLines) || empty($dataLines))
            return false;

        $identifiers = array();
        $idPosition = $this->_offsets['id_category'];

        foreach ($dataLines as $data) {
            $code = $data[$idPosition];

            if (empty($code))
                continue;

            $id = MappingCodeCategories::getIdByCode($code);

            if ($id !== false) {
                $identifiers[] = $id;
            }
        }

        if (empty($identifiers)) {
            return array();
        }

        $result = MappingCodeCategories::getCategoriesToDeactivate($identifiers);

        if ($result === false) {
            return false;
        } else {
            $ids = array();
            foreach ($result as $item) {
                $ids[] = $item['id_category'];
            }
            return $ids;
        }
    }

    /**
     * Associate a category to a shop
     *
     * @param Category $objCategory
     * @param int $shopId
     * @return bool
     */
    protected function _associateShop(Category $objCategory, $shopId)
    {
        if (!Validate::isLoadedObject($objCategory))
            return false;

        if (!$shopId)
            $shopId = (int)Context::getContext()->shop->id;

        if (!$objCategory->existsInShop($shopId)) {
            return $objCategory->addShop($shopId);
        }

        return true;
    }

    /**
     * Update all the categories' positions
     *
     * @param int $shopId
     * @return bool
     */
    protected function _regeneratePositions($shopId)
    {
        if (!$shopId)
            $shopId = (int)Context::getContext()->shop->id;

        $return = true;
        $categories = $this->_getCategoriesByShopId($shopId);
        foreach ($categories as $category) {
            if (!$this->_reorderByCategoryId((int)$category['id_category'], (int)$shopId)) {
                $return = false;
            }
        }

        Category::regenerateEntireNtree();
        return $return;
    }

    /**
     * Get all categories for a given shop id
     *
     * @param int $shopId
     * @return array|bool
     * @throws PrestaShopDatabaseException
     */
    protected function _getCategoriesByShopId($shopId)
    {
        if (!$shopId || $shopId <= 0)
            return false;

        $query = new DbQuery();
        $query
            ->select('*')
            ->from('category')
            ->where('id_category IN (SELECT id_category FROM ' . _DB_PREFIX_ . 'category_shop WHERE id_shop = ' . (int)$shopId . ')')
            ->where('id_category >= 2')
            ->orderBy('id_category ASC');

        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($query);
    }

    /**
     * Resets position for every sub category of the given category
     *
     * @param int $categoryId
     * @param int $shopId
     * @return bool
     * @throws PrestaShopDatabaseException
     */
    protected function _reorderByCategoryId($categoryId, $shopId)
    {
        if (!$categoryId)
            return false;

        $return = true;

        $query = new DbQuery();
        $query
            ->select('c.`id_category`')
            ->from('category', 'c')
            ->leftJoin('category_shop', 'cs', '(c.`id_category` = cs.`id_category` AND cs.`id_shop` = ' . (int)$shopId . ')')
            ->where('c.`id_parent` = ' . (int)$categoryId)
            ->orderBy('cs.`id_category`');

        $categories = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($query);

        if ($categories !== false) {
            foreach ($categories as $position => $category) {
                $sql = '
				UPDATE `' . _DB_PREFIX_ . 'category` c
				LEFT JOIN `' . _DB_PREFIX_ . 'category_shop` cs ON (c.`id_category` = cs.`id_category` AND cs.`id_shop` = ' . (int)$shopId . ')
				SET cs.`position` = ' . (int)($position) . '
				WHERE c.`id_category` = ' . (int)$category['id_category'];

                if (!Db::getInstance()->Execute($sql)) {
                    $return = false;
                }
            }
        } else {
            $return = false;
        }

        return $return;
    }

}