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

        $folderUtil = Utils::exec('folder');
        $path       = $this->_manager->getPath() . '/files/';

        if ($folderUtil->isFolderEmpty($path)) {
            $fileTransferHost = Configuration::get(MOD_SYNC_NAME . '_ftphost');
            if (!empty($fileTransferHost)) {
                if (_PS_MODE_DEV_) {
                    $this->log('Fetching files from FTP');
                }
                $fileTransfer = $this->_getFtpConnection();

                if (!$fileTransfer->getFiles(Configuration::get(MOD_SYNC_NAME . '_IMPORT_CATEGORY_FTP_PATH'), $path, '*.csv')) {
                    $this->logError('There was an error while retrieving the files from the FTP');
                    $folderUtil->delTree($path, false);
                    return false;
                }
            }
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

        $this->_getLangsInCsv($headers);
        $this->_mapOffsets('Category', MappingCategories::getAllPrestashopFields());

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

        if (!isset($this->_offsets['default']['id_parent'])) {
            $this->_offsets['default']['id_parent'] = 'id_parent';
        }

        if (!isset($this->_offsets['default']['id_shop_default'])) {
            $this->_offsets['default']['id_shop_default'] = 'id_shop_default';
        }

        if (!isset($this->_offsets['default']['active'])) {
            $this->_offsets['default']['active'] = 'active';
        }

        if (!isset($this->_offsets['default']['is_root_category'])) {
            $this->_offsets['default']['is_root_category'] = 'is_root_category';
        }

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

            $codeCategory = $data[$this->_offsets['special']['code']];
            $codeParent   = $data[$this->_offsets['special']['code_parent']];

            if (empty($codeCategory)) {
                $this->logError("Missing category code on line " . ($line+2));
                $errorCount++;
                continue;
            }

            if (
                empty($data[$this->_offsets['default']['id_parent']])
                &&  (
                    !array_key_exists($this->_offsets['default']['is_root_category'], $data)
                    || !$data[$this->_offsets['default']['is_root_category']]
                )
            ) {
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

                $data[$this->_offsets['default']['id_parent']] = $idParent;
                $data[$this->_offsets['default']['is_root_category']] = 0;
            } elseif(!empty($data[$this->_offsets['default']['id_parent']])) {
                //Forcing to 0 if there is a parent
                $data[$this->_offsets['default']['is_root_category']] = 0;
            }

            if (empty($data[$this->_offsets['default']['id_shop_default']])) {
                $data[$this->_offsets['default']['id_shop_default']] = $contextShopId;
            }

            if (!isset($data[$this->_offsets['default']['active']])) {
                $data[$this->_offsets['default']['active']] = 1;
            }

            $idCategory = MappingCodeCategories::getIdByCode($codeCategory);

            if ($idCategory !== false) {
                $categoryExist = Category::existsInDatabase($idCategory, 'category');
                if ($categoryExist) {
                    $category = new Category($idCategory);
                }
                else {
                    //The category does not exist but the mapping does. It will have to be updated
                    $category = new Category();
                }
            } else {
                $category      = new Category();
                $categoryExist = false;
            }

            foreach ($this->_offsets['lang'] as $field => $offsets) {
                $values = array();

                foreach ($offsets as $idLang => $offset) {
                    $values[$idLang] = $data[$offset];
                }

                if ($field == 'name') {
                    $links = array();
                    foreach ($values as $idLang => $value) {
                        $links[$idLang] = Tools::link_rewrite($value);
                    }
                    $category->link_rewrite = $links;
                }

                $category->{$field} = $values;
            }

            foreach ($this->_offsets['default'] as $field => $offset) {
                $category->{$field} = $data[$offset];
            }

            foreach ($this->_offsets['date'] as $field => $offset) {
                if (Validate::isDate($data[$offset])) {
                    $category->{$field} = $data[$offset];
                } else {
                    $this->logError('Wrong date format for field ' . $field . ' : ' . $data[$offset] . '(for category ' . $codeCategory . ')');
                    $errorCount++;
                }
            }

            //Forced to true so we only regenerate once everything is over
            $category->doNotRegenerateNTree = true;

            if (!$category->save()) {
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
                    $mapping->id_category = $category->id;

                    if (!$mapping->add()) {
                        $this->logError('Could not save category mapping for ' . $codeCategory);
                        $errorCount++;
                    } elseif (_PS_MODE_DEV_) {
                        $this->log('Category ' . $codeCategory . ' mapping saved');
                    }
                }
            }

            if (!$this->_associateShop($category, $contextShopId)) {
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
        $idPosition = $this->_offsets['special']['code'];

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
     * @param Category $category
     * @param int $shopId
     * @return bool
     */
    protected function _associateShop(Category $category, $shopId)
    {
        if (!Validate::isLoadedObject($category))
            return false;

        if (!$shopId)
            $shopId = (int)Context::getContext()->shop->id;

        if (!$category->existsInShop($shopId)) {
            return $category->addShop($shopId);
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
