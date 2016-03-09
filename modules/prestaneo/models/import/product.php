<?php
class ImportProduct extends ImportAbstract
{
    protected $_offsetsName = null;
    protected $_fields = null;
    protected $_quantityDefault = 0;
    protected $_delimiter = "";

    public static $icon  = 'cubes';
    public static $order = 4;

    /**
     * Imports products
     *
     * @return bool
     * @throws Exception
     * @throws PrestaShopException
     */
    public function process()
    {
        $this->_delimiter = (Configuration::get('PS_IMPORT_DELIMITER') ? Configuration::get('PS_IMPORT_DELIMITER') : ';');

        $folderPath = $this->_manager->getPath() . '/files/';

        $fileTransferHost = Configuration::get(MOD_SYNC_NAME.'_ftphost');
        if(!empty($fileTransferHost)) {
            $fileTransfer = $this->_getFtpConnection();

            $fileTransfer->getFiles(Configuration::get('PS_IMPORT_PRODUCTFTPPATH'), $folderPath, '*.csv');
            $fileTransfer->getFiles(Configuration::get('PS_IMPORT_PRODUCTFTPPATH'), $folderPath, '*.zip');
        }

        $folderUtil = Utils::exec('Folder');
        $archives   = $folderUtil->getAllFilesInFolder($folderPath, '*.zip');

        Utils::exec('Zip')->extractAll($archives, $folderPath);

        $reader = new CsvReader($this->_manager, $this->_delimiter);
        $reader->setExtension('.csv');

        $dataLines = $reader->getData();
        $fileName  = $reader->getCurrentFileName();

        $resetImages            = Configuration::get('PS_IMPORT_RESETIMAGES');
        $resetFeatures          = Configuration::get('PS_IMPORT_RESETFEATURES');
        $resetCombinations      = Configuration::get('PS_IMPORT_RESETCOMBINATIONS');
        $this->_quantityDefault = Configuration::get('PS_IMPORT_DEFAULTQTYPRODUCT');

        if (!is_array($dataLines) || empty($dataLines)){
            $this->log('Nothing to import or file is not valid CSV');
            $this->_mover->finishAction(basename($reader->getCurrentFileName()), false);
            $this->_cleanWorkingDirectory($archives);
            return false;
        }

        $requiredFields = MappingProducts::getRequiredFields('akeneo');
        $headers        = $this->_cleanDataLine(array_shift($dataLines));
        $missingFields  = $this->_checkMissingRequiredFields($requiredFields, $headers);

        if (!empty($missingFields)) {
            $this->log('Missing required fields : ' . implode(', ', $missingFields));
            $this->_mover->finishAction(basename($reader->getCurrentFileName()), false);
            $this->_cleanWorkingDirectory($archives);
            return false;
        }

        $headers[]      = 'emptyValue';
        $headers[]      = 'nullValue';
        $this->_offsets = array_flip($headers);

        $this->_getLangsInCsv($headers);
        $this->_mapOffsets(MappingProducts::getAllPrestashopFields());

        $mappingCodeFeatures = MappingCodeFeatures::getAll();
        $defaultLanguage     = Configuration::get('PS_LANG_DEFAULT');

        $processedProductIds = array();
        $defaultCurrency     = Currency::getDefaultCurrency()->iso_code;

        if (isset($this->_offsets['price-' . $defaultCurrency])) {
            $priceOffset = $this->_offsets['price-' . $defaultCurrency];
        } elseif (isset($this->_offsets['price'])) {
            $priceOffset = $this->_offsets['price'];
        }

        $this->makeOffsetRedirections($mappingCodeFeatures);

        $currentFile    = $reader->getCurrentFileName(0);
        $lastErrorCount = 0;

        foreach ($dataLines as $line => $data) {
            $nextFile = $reader->getCurrentFileName($line);
            if ($nextFile != $currentFile) {
                $treatmentResult = 1;
                if(count($this->_errors) > $lastErrorCount)
                    $treatmentResult = 0;
                $this->_mover->finishAction(basename($currentFile), $treatmentResult, 'import');
                $lastErrorCount = count($this->_errors);
                $currentFile    = $nextFile;
            }

            if ($data === false || $data == array(null))  {
                $this->log('Product line wrong, skipping : ' . ($line + 2));
                continue;
            }

            $data      = $this->_cleanDataLine($data);
            $reference = $data[$this->_offsets['reference']];
            $groupCode = $data[$this->_offsets['groups']];

            $data[$this->_offsets['emptyValue']] = '';
            $data[$this->_offsets['nullValue']]  = null;

            if (empty($reference)) {
                $this->log('Missing reference on line ' . ($line + 2));
                continue;
            } elseif (_PS_MODE_DEV_) {
                $this->log('Importing ' . $reference);
            }

            $featureValues          = array();
            $names                  = array();
            $linkRewrites           = array();
            $descriptionShorts      = array();
            $descriptions           = array();
            $metaTitles             = array();
            $metaKeywords           = array();
            $metaDescriptions       = array();
            $availableNowInLang     = array();
            $availableLaterInLang   = array();

            //Is out of the product update because it is needed even if the line is only a combination of an existing product
            $ean13 = $data[$this->_offsets['ean13']];
            if (empty($ean13) || !Validate::isEan13($ean13)) {
                $this->log($reference . ' : ean13 not valid');
                $ean13 = "";
            }

            $isNewProduct = true;
            $knownProduct = false;

            $productId    = MappingProductsGroups::getIdProductByGroupCode($groupCode);

            if ($productId != false) {
                if (isset($processedProductIds[$productId])) {
                    $isNewProduct  = false;
                }

                $knownProduct = true;
                $product      = new Product($productId);
            } else {
                //We may have a product without variant group
                $productId = $this->_getProductIdByReference($reference);
                if ($productId != false) {
                    $product      = new Product($productId);
                    $knownProduct = true;
                } else {
                    $product = new Product();
                }
            }

            $shopDefaultId = (isset($this->_offsets['id_shop_default']) ? $data[$this->_offsets['id_shop_default']] : Context::getContext()->shop->id);

            if ($isNewProduct) {
                foreach ($this->_langs as $langId => $isoCode) {
                    $suffixLang = '-' . $this->_labels[$isoCode];

                    $name = $data[$this->_offsets['name' . $suffixLang]];

                    $names[$langId]                = $name;
                    $linkRewrites[$langId]         = Tools::link_rewrite($name);
                    $descriptionShorts[$langId]    = $data[$this->_offsets['description_short' . $suffixLang]];
                    $descriptions[$langId]         = $data[$this->_offsets['description'       . $suffixLang]];
                    $metaTitles[$langId]           = $data[$this->_offsets['meta_title'        . $suffixLang]];
                    $metaKeywords[$langId]         = $data[$this->_offsets['meta_keywords'     . $suffixLang]];
                    $metaDescriptions[$langId]     = $data[$this->_offsets['meta_description'  . $suffixLang]];
                    $availableNowInLang[$langId]   = $data[$this->_offsets['available_now'     . $suffixLang]];
                    $availableLaterInLang[$langId] = $data[$this->_offsets['available_later'   . $suffixLang]];

                    // Save Features
                    foreach ($mappingCodeFeatures as $mapping) {
                        $codeMapping = $mapping['code'];
                        $currentVal  = $data[$this->_offsets[$codeMapping . $suffixLang]];

                        if (!empty($currentVal)) {
                            $featureValues[$codeMapping][$langId] = $currentVal;
                        }
                    }
                }
                Shop::setContext(Shop::CONTEXT_SHOP, $shopDefaultId);

                $wholesalePrice = $data[$this->_offsets['wholesale_price']];
                $upc            = $data[$this->_offsets['upc']];
                $ecotax         = $data[$this->_offsets['ecotax']];
                $availableDate  = $data[$this->_offsets['available_date']];

                // Controls
                if (empty($upc) || !Validate::isUpc($upc)) {
                    $this->log($reference . ' : upc not valid');
                    $upc = "";
                }
                if (empty($ecotax) || !Validate::isPrice($ecotax)) {
                    $this->log($reference . ' : ecotax not valid');
                    $ecotax = 0;
                }
                if (empty($wholesalePrice) || !Validate::isPrice($wholesalePrice)) {
                    $this->log($reference . ' : wholesale not valid');
                    $wholesalePrice = 0;
                }
                if (!empty($availableDate) && !Validate::isDateFormat($availableDate)) {
                    $dates = explode('/', $availableDate);
                    if (count($dates) == 3) {
                        $availableDate = $dates[2] . '-' . $dates[1] . '-' . $dates[0];
                        $this->log($reference . ' available date reformated : ' . $availableDate);
                    } else {
                        $availableDate = "";
                        $this->log($reference . ' : available date not valid');
                    }
                } else {
                    $availableDate = "";
                    $this->log($reference . ' : available date not valid');
                }

                // Identifiers categories
                $categories    = array();
                $categoryCodes = explode(',', $data[$this->_offsets['categories']]);

                foreach ($categoryCodes as $categoryCode) {
                    $categoryId = (int)MappingCodeCategories::getIdByCode($categoryCode);
                    if ($categoryId > 0) {
                        $categories[] = $categoryId;
                    }
                    else {
                        $this->log($reference . ' : category ' . $categoryCode . ' does not exist');
                    }
                }

                // Id default category
                if (!empty($categories)) {
                    $defaultCategory = $categories[0];
                } else {
                    $defaultCategory = 2;
                }

                // Fields
                if (isset($priceOffset) && empty($groupCode)) {
                    if (empty($data[$priceOffset]) || !Validate::isPrice($data[$priceOffset])) {
                        $this->log($reference . ' : price not valid');
                    } else {
                        $product->price = $data[$priceOffset];
                    }
                }

                $product->reference           = $reference;
                $product->wholesale_price     = $wholesalePrice;
                $product->ean13               = $ean13;
                $product->upc                 = $upc;
                $product->ecotax              = $ecotax;
                $product->available_date      = $availableDate;
                $product->id_category_default = $defaultCategory;

                $product->active              = $data[$this->_offsets['active']];
                $product->id_tax_rules_group  = $data[$this->_offsets['id_tax_rules_group']];
                $product->width               = $data[$this->_offsets['width']];
                $product->height              = $data[$this->_offsets['height']];
                $product->depth               = $data[$this->_offsets['depth']];
                $product->weight              = $data[$this->_offsets['weight']];
                $product->available_for_order = $data[$this->_offsets['available_for_order']];
                $product->show_price          = $data[$this->_offsets['show_price']];
                $product->online_only         = $data[$this->_offsets['online_only']];

                $product->condition           = 'new';

                switch ($data[$this->_offsets['visibility']]) {
                    case "1":
                    case "none":
                        $product->visibility = "none";
                        break;
                    case "2":
                    case "catalog":
                        $product->visibility = "catalog";
                        break;
                    case "3":
                    case "search":
                        $product->visibility = "search";
                        break;
                    default:
                        $product->visibility = "both";
                        break;
                }

                // Lang fields
                $product->name              = $names;
                $product->link_rewrite      = $linkRewrites;
                $product->description       = $descriptions;
                $product->description_short = $descriptionShorts;
                $product->meta_title        = $metaTitles;
                $product->meta_keywords     = $metaKeywords;
                $product->meta_description  = $metaDescriptions;
                $product->available_now     = $availableNowInLang;
                $product->available_later   = $availableLaterInLang;

                // Other
                $product->date_add       = date('Y-m-d H:i:s');
                $product->shop           = $shopDefaultId;
                $product->id_shop_list[] = $shopDefaultId;

                // Force re-indexing search process with new datas
                $product->indexed = 0;

                if (!$product->save()) {
                    $this->log($reference . ' : could not be saved');
                    continue;
                } elseif (_PS_MODE_DEV_) {
                    $this->log($reference . ' : saved');
                }

                //Product has been saved
                $processedProductIds[$product->id] = true;

                // Save Features (= Attributs Akeneo)
                if ($resetFeatures) {
                    if (!$product->deleteFeatures()) {
                        $this->log($reference . ' : could not remove features');
                    }
                }

                foreach ($featureValues as $featureCode => $featureValue) {
                    $featureId = MappingCodeFeatures::getIdByCode($featureCode);
                    if (!$featureId) {
                        $this->log($reference . ' : feature ' . $featureCode . ' does not exist');
                        continue;
                    }

                    $featureValueId = $this->_addFeatureValue($featureId, $featureValue);

                    if (!$featureValueId) {
                        $this->log($reference . ' : could not save feature value for ' . $featureCode);
                        continue;
                    }

                    if (!Product::addFeatureProductImport($product->id, $featureId, $featureValueId)) {
                        $this->log($reference . ' : could not associate to feature value ' . $featureCode);
                    }
                }
                if (_PS_MODE_DEV_) {
                    $this->log($reference . ' : features added');
                }

                //Save Associations Categories
                if (!$product->updateCategories($categories)) {
                    $this->log($reference . ' : could not update categories');
                }

                if ($knownProduct) {
                    if ($resetImages) {
                        if (!$product->deleteImages()) {
                            $this->log($reference . ' : could not delete images');
                        } elseif (_PS_MODE_DEV_) {
                            $this->log($reference . ' : images deleted');
                        }
                    }

                    if (!empty($groupCode)) {
                        if ($resetCombinations) {
                            if (!$product->deleteProductAttributes()) {
                                $this->log($reference . ' : error while resetting product combinations');
                            } elseif (_PS_MODE_DEV_) {
                                $this->log($reference . ' : combinations deleted');
                            }
                        }
                    }
                } else {
                    //We save quantities only if the product is totally new
                    StockAvailable::setQuantity($product->id, 0, $this->_quantityDefault, $shopDefaultId);
                }
            }

            //Save Images
            $imageString = $data[$this->_offsets['image']];
            if (!empty($imageString)) {
                $imagePaths = explode(',', $imageString);
                if ($imagePaths) {
                    $isCover         = !((bool)Product::getCover($product->id));
                    $highestPosition = Image::getHighestPosition($product->id);

                    foreach ($imagePaths as $imageRelativePath) {
                        $imagePath = $folderPath . $imageRelativePath;

                        if (!file_exists($imagePath)) {
                            $this->log($reference . ' : image ' . $imagePath . ' does not exist');
                            continue;
                        }

                        $image             = new Image();
                        $image->id_product = $product->id;
                        $image->position   = ++$highestPosition;
                        $image->legend     = $names;
                        $image->cover      = $isCover;

                        if (!$image->add()) {
                            $this->log($reference . ' : could not save image ' . $imagePath);
                            $highestPosition--;
                        } else {
                            if (!$this->copyImg($product->id, $image->id, $imagePath, 'products', true)) {
                                $this->log($reference . ' : could not copy image ' . $imagePath);
                                $image->delete();
                                $highestPosition--;
                            } else {
                                unlink($imagePath);
                            }
                        }
                        $isCover = false;
                    }
                    if (_PS_MODE_DEV_) {
                        $this->log($reference . ' : images added');
                    }
                }
            }

            //Save Combination + Quantity Stock
            if (!empty($groupCode)) {
                $attributeIds = array();
                $axes         = explode(',', MappingTmpAttributes::getAxisByCode($groupCode));

                foreach ($axes as $axis) {
                    $combinationValues = array();

                    foreach ($this->_langs as $langId => $isoCode) {
                        $field = $axis . '-' . $this->_labels[$isoCode];

                        if (isset($this->_offsets[$field])) {
                            $currentVal = $data[$this->_offsets[$field]];
                        } elseif (isset($this->_offsets[$axis])) {
                            $currentVal = $data[$this->_offsets[$axis]];
                        } else {
                            $currentVal = '';
                        }

                        if (!empty($currentVal)) {
                            $combinationValues[$langId] = $currentVal;
                        }
                    }

                    $attributeGroupId = MappingCodeAttributes::getIdByCode($axis);
                    $attributeId = $this->_getAttribute($attributeGroupId, $combinationValues);
                    if (!$attributeId) {
                        $attribute                     = new Attribute();
                        $attribute->id_attribute_group = $attributeGroupId;
                        $attribute->position           = Attribute::getHigherPosition($attributeGroupId) + 1;
                    } else {
                        $attribute = new Attribute($attributeId);
                    }

                    $attribute->name = $combinationValues;

                    if (!$attribute->save()) {
                        $this->log($reference . " : could not save attribute " . $combinationValues[$defaultLanguage]);
                    } else {
                        if (!$attribute->associateTo(array($shopDefaultId))) {
                            $this->log($reference . ' : could not associate attribute ' . $combinationValues[$defaultLanguage] . ' to shop ' . $shopDefaultId);
                        }
                    }

                    $attributeIds[] = (int)$attribute->id;
                }

                $price = (isset($priceOffset) ? $data[$priceOffset] : null);

                $this->addOrUpdateProductAttributeCombination($product, $ean13, $attributeIds, $reference, $price);

                $mappingId = MappingProductsGroups::getMappingByGroupCode($groupCode);
                if ($mappingId === false) {
                    $mapping = new MappingProductsGroups();
                    $mapping->id_product = $product->id;
                    $mapping->group_code = $groupCode;

                    if (!$mapping->save()) {
                        $this->log($reference . ' : could not save mapping with group ' . $groupCode);
                    }
                }

                if (_PS_MODE_DEV_) {
                    $this->log($reference . ' : combination saved');
                }
            }
        }

        // Search Indexation
        if (!Search::indexation()) {
            $this->log('Product reindexation failed');
        } elseif (_PS_MODE_DEV_) {
            $this->log('Products reindexed');
        }

        $this->_mover->finishAction(basename($fileName), true);
        $this->_cleanWorkingDirectory($archives);

        return true;
    }

    /**
     * Get attributeId if exists
     *
     * @param int $attributeGroupId
     * @param array $names
     * @return array|bool|false|null|string
     */
    protected static function _getAttribute($attributeGroupId, $names)
    {
        if (!$names || $attributeGroupId == 0 || !$attributeGroupId)
            return false;

        if (!Combination::isFeatureActive()) {
            return array();
        }
        $sql = '
			SELECT a.`id_attribute`
			FROM `' . _DB_PREFIX_ . 'attribute_group` ag
			LEFT JOIN `' . _DB_PREFIX_ . 'attribute_group_lang` agl
				ON (ag.`id_attribute_group` = agl.`id_attribute_group` AND agl.`id_lang` = ' . pSQL(key($names)) . ')
			LEFT JOIN `' . _DB_PREFIX_ . 'attribute` a
				ON a.`id_attribute_group` = ag.`id_attribute_group`
			LEFT JOIN `' . _DB_PREFIX_ . 'attribute_lang` al
				ON (a.`id_attribute` = al.`id_attribute` AND al.`id_lang` = ' . pSQL(key($names)) . ')
			' . Shop::addSqlAssociation('attribute_group', 'ag') . '
			' . Shop::addSqlAssociation('attribute', 'a') . '
			WHERE al.`name` = \'' . pSQL(current($names)) . '\' AND ag.`id_attribute_group` = ' . (int)$attributeGroupId . '
			ORDER BY agl.`name` ASC, a.`position` ASC
		';
        return Db::getInstance()->getValue($sql);
    }

    /**
     * Add or update feature value
     *
     * @param int $featureId
     * @param array $langFeaturesValues
     *
     * @return bool|int
     * @throws PrestaShopDatabaseException
     */
    protected static function _addFeatureValue($featureId, $langFeaturesValues)
    {
        if (!$featureId || $featureId == 0 || !is_array($langFeaturesValues))
            return false;

        $sql = '
            SELECT fv.`id_feature_value`
            FROM ' . _DB_PREFIX_ . 'feature_value fv
            LEFT JOIN ' . _DB_PREFIX_ . 'feature_value_lang fvl ON (fvl.`id_feature_value` = fv.`id_feature_value`)
            WHERE fvl.`value` = \'' . pSQL(current($langFeaturesValues)) . '\'
                AND fv.`id_feature` = ' . (int)$featureId . '
                AND fvl.`id_lang` = ' . pSQL(key($langFeaturesValues)) . '
            GROUP BY fv.`id_feature_value` LIMIT 1
        ';

        $result = Db::getInstance()->executeS($sql);

        if (!$result || empty($result)) {
            $featureValue             = new FeatureValue();
            $featureValue->id_feature = (int)$featureId;
        } else {
            $featureValue             = new FeatureValue((int)$result[0]['id_feature_value']);
        }

        $featureValue->value  = $langFeaturesValues;
        $featureValue->custom = 0;

        if (!$featureValue->save()) {
            return false;
        } else {
            return $featureValue->id;
        }

    }

    /**
     * Get a product id given its reference or the reference of one of its combinations
     *
     * @param string $reference
     * @return int|bool
     */
    protected static function _getProductIdByReference($reference)
    {
        if (strlen($reference) == 0) {
            return false;
        }

        $sqlReference = pSQL($reference);

        $query = new DbQuery();
        $query->select('p.id_product')
            ->from('product', 'p')
            ->leftJoin('product_attribute', 'pa', 'p.id_product = pa.id_product')
            ->where('p.reference = \''.$sqlReference.'\' OR pa.reference = \''.$sqlReference.'\'')
            ->groupBy('p.id_product')
        ;

        return Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($query);
    }

    /**
     * Add or update a product combination
     *
     * @param Product $product
     * @param string $attributeEan
     * @param array $attributeValueIds , all value ids for ONE combination (example for red (value id=1), XL (value id=9) => array(array(0 => 1, 1 => 9, 2 => 3))
     * @param $reference
     * @param $price
     * @return int product attribute id if success
     * @internal param bool $default
     * @internal param bool $attributeAvailableDate
     */
    protected function addOrUpdateProductAttributeCombination(Product $product, $attributeEan, array $attributeValueIds, $reference, $price)
    {
        if (!$product)
            return false;

        $imageId  = 0;
        $returnId = true;

        // Image combination
        $cover = Product::getCover($product->id);
        if ($cover) {
            $imageId = $cover['id_image'];
        }

        $productAttributeId = $product->productAttributeExists($attributeValueIds, false, null, false, $returnId);

        if ($productAttributeId > 0) {
            $product->updateAttribute($productAttributeId,
                null,//attribute_wholesale_price
                $price,//attribute_price
                null,//attribute_weight
                null,//attribute_unit_impact
                null,//attribute_ecotax
                $imageId,//id_image_attr
                $reference,//attribute_reference
                $attributeEan,
                null,
                '',//attribute_location
                '',//attribute_upc
                null,//attribute_minimal_quantity
                '0000-00-00',
                false,//update_all_fields
                array()//id_shop_list
            );
        } else {
            $productAttributeId = $product->addCombinationEntity(
                0,//attribute_wholesale_price
                $price,//attribute_price
                0,//attribute_weight
                0,//attribute_unit_impact
                0,//attribute_ecotax
                Configuration::get('PS_IMPORT_DEFAULTQTYPRODUCT'),//quantity
                $imageId,//id_image_attr
                $reference,//attribute_reference
                null,
                $attributeEan,
                false,//attribute_default
                '',//attribute_location
                '',//attribute_upc
                1,//attribute_minimal_quantity
                array(),//id_shop_list
                '0000-00-00'
            );

            if ($productAttributeId === false) {
                return false;
            }

            StockAvailable::setQuantity($product->id, $productAttributeId, $this->_quantityDefault);
        }

        StockAvailable::setProductDependsOnStock($product->id, $product->depends_on_stock, null, $productAttributeId);
        StockAvailable::setProductOutOfStock($product->id, $product->out_of_stock, null, $productAttributeId);

        $combination = new Combination($productAttributeId);
        $combination->setAttributes($attributeValueIds);

        $product->checkDefaultAttributes();

        return (int)$productAttributeId;
    }

    /**
     * copyImg copy an image located in $url and save it in a path
     * according to $entity->$id_entity .
     * $id_image is used if we need to add a watermark
     *
     * @param int $id_entity id of product or category (set in entity)
     * @param int $id_image (default null) id of the image if watermark enabled.
     * @param string $url path or url to use
     * @param string $entity 'products' or 'categories'
     * @param bool $regenerate
     * @return bool
     */

    protected function copyImg($id_entity, $id_image = null, $url, $entity = 'products', $regenerate = true)
    {
        $tmpfile         = tempnam(_PS_TMP_IMG_DIR_, 'ps_import');
        $watermark_types = explode(',', Configuration::get('WATERMARK_TYPES'));

        switch ($entity) {
            default:
            case 'products':
                $image_obj = new Image($id_image);
                $path      = $image_obj->getPathForCreation();
                break;
            case 'categories':
                $path = _PS_CAT_IMG_DIR_ . (int)$id_entity;
                break;
            case 'manufacturers':
                $path = _PS_MANU_IMG_DIR_ . (int)$id_entity;
                break;
            case 'suppliers':
                $path = _PS_SUPP_IMG_DIR_ . (int)$id_entity;
                break;
        }

        $url        = urldecode(trim($url));
        $parced_url = parse_url($url);

        if (isset($parced_url['path'])) {
            $uri   = ltrim($parced_url['path'], '/');
            $parts = explode('/', $uri);
            foreach ($parts as &$part) {
                $part = rawurlencode($part);
            }
            unset($part);
            $parced_url['path'] = '/' . implode('/', $parts);
        }

        if (isset($parced_url['query'])) {
            $query_parts = array();
            parse_str($parced_url['query'], $query_parts);
            $parced_url['query'] = http_build_query($query_parts);
        }

        if (!function_exists('http_build_url')) {
            require_once(_PS_TOOL_DIR_ . 'http_build_url/http_build_url.php');
        }

        $url = http_build_url('', $parced_url);

        $orig_tmpfile = $tmpfile;

        if (Tools::copy($url, $tmpfile)) {
            // Evaluate the memory required to resize the image: if it's too much, you can't resize it.
            if (!ImageManager::checkImageMemoryLimit($tmpfile)) {
                @unlink($tmpfile);
                return false;
            }

            $tgt_width = $tgt_height = 0;
            $src_width = $src_height = 0;
            $error     = 0;
            ImageManager::resize($tmpfile, $path . '.jpg', null, null, 'jpg', false, $error, $tgt_width, $tgt_height, 5,
                $src_width, $src_height);
            $images_types = ImageType::getImagesTypes($entity, true);

            if ($regenerate) {
                $previous_path = null;
                $path_infos    = array();
                $path_infos[]  = array($tgt_width, $tgt_height, $path . '.jpg');
                foreach ($images_types as $image_type) {
                    $tmpfile = $this->get_best_path($image_type['width'], $image_type['height'], $path_infos);

                    if (ImageManager::resize($tmpfile, $path . '-' . stripslashes($image_type['name']) . '.jpg', $image_type['width'],
                        $image_type['height'], 'jpg', false, $error, $tgt_width, $tgt_height, 5,
                        $src_width, $src_height)
                    ) {
                        // the last image should not be added in the candidate list if it's bigger than the original image
                        if ($tgt_width <= $src_width && $tgt_height <= $src_height) {
                            $path_infos[] = array($tgt_width, $tgt_height, $path . '-' . stripslashes($image_type['name']) . '.jpg');
                        }
                    }
                    if (in_array($image_type['id_image_type'], $watermark_types)) {
                        Hook::exec('actionWatermark', array('id_image' => $id_image, 'id_product' => $id_entity));
                    }
                }
            }
        } else {
            @unlink($orig_tmpfile);
            return false;
        }
        unlink($orig_tmpfile);
        return true;
    }

    private function get_best_path($tgt_width, $tgt_height, $path_infos)
    {
        $path_infos = array_reverse($path_infos);
        $path       = '';
        foreach ($path_infos as $path_info) {
            list($width, $height, $path) = $path_info;
            if ($width >= $tgt_width && $height >= $tgt_height) {
                return $path;
            }
        }
        return $path;
    }

    /**
     * Adds offset for value that might by missing, making them fallback on offsets that leads to empty or null values
     * This let us avoid multiple isset statement for each products
     *
     * @param array $mappingCodeFeatures
     */
    protected function makeOffsetRedirections($mappingCodeFeatures) {
        foreach ($this->_langs as $langId => $isoCode) {
            $suffixLang = '-' . $this->_labels[$isoCode];

            $nameOffset = 'name' . $suffixLang;

            if (!isset($this->_offsets[$nameOffset])) {
                if (isset($this->_offsets['name'])) {
                    $this->_offsets[$nameOffset] = $this->_offsets['name'];
                } else {
                    $this->_offsets[$nameOffset] = $this->_offsets['reference'];
                }
            }

            if (!isset($this->_offsets['description_short' . $suffixLang])) {
                $this->_offsets['description_short' . $suffixLang] = $this->_offsets['emptyValue'];
            }

            if (!isset($this->_offsets['description' . $suffixLang])) {
                $this->_offsets['description' . $suffixLang] = $this->_offsets['emptyValue'];
            }

            if (!isset($this->_offsets['meta_title' . $suffixLang])) {
                $this->_offsets['meta_title' . $suffixLang] = $this->_offsets['emptyValue'];
            }

            if (!isset($this->_offsets['meta_keywords' . $suffixLang])) {
                $this->_offsets['meta_keywords' . $suffixLang] = $this->_offsets['emptyValue'];
            }

            if (!isset($this->_offsets['meta_description' . $suffixLang])) {
                $this->_offsets['meta_description' . $suffixLang] = $this->_offsets['emptyValue'];
            }

            if (!isset($this->_offsets['available_now' . $suffixLang])) {
                $this->_offsets['available_now' . $suffixLang] = $this->_offsets['emptyValue'];
            }

            if (!isset($this->_offsets['available_later' . $suffixLang])) {
                $this->_offsets['available_later' . $suffixLang] = $this->_offsets['emptyValue'];
            }

            foreach ($mappingCodeFeatures as $mapping) {
                $codeMapping = $mapping['code'];
                $field = $codeMapping . $suffixLang;

                if (!isset($this->_offsets[$field])) {
                    if (isset($this->_offsets[$codeMapping])) {
                        $this->_offsets[$field] = $this->_offsets[$codeMapping];
                    } else {
                        $this->_offsets[$field] = $this->_offsets['emptyValue'];
                    }
                }
            }
        }

        if (!isset($this->_offsets['active'])) {
            $this->_offsets['active'] = $this->_offsets['nullValue'];
        }

        if (!isset($this->_offsets['id_tax_rules_group'])) {
            $this->_offsets['id_tax_rules_group'] = $this->_offsets['nullValue'];
        }

        if (!isset($this->_offsets['width'])) {
            $this->_offsets['width'] = $this->_offsets['nullValue'];
        }

        if (!isset($this->_offsets['height'])) {
            $this->_offsets['height'] = $this->_offsets['nullValue'];
        }

        if (!isset($this->_offsets['depth'])) {
            $this->_offsets['depth'] = $this->_offsets['nullValue'];
        }

        if (!isset($this->_offsets['weight'])) {
            $this->_offsets['weight'] = $this->_offsets['nullValue'];
        }

        if (!isset($this->_offsets['available_for_order'])) {
            $this->_offsets['available_for_order'] = $this->_offsets['nullValue'];
        }

        if (!isset($this->_offsets['show_price'])) {
            $this->_offsets['show_price'] = $this->_offsets['nullValue'];
        }

        if (!isset($this->_offsets['online_only'])) {
            $this->_offsets['online_only'] = $this->_offsets['nullValue'];
        }
    }

    /**
     * Cleans the working directory from any archive or image directory
     *
     * @param array $archives
     */
    protected function _cleanWorkingDirectory($archives)
    {
        $imageFolder = $this->_manager->getPath() . '/files/files';

        if (file_exists($imageFolder)) {
            Utils::exec('Folder')->delTree($imageFolder);
        }

        foreach ($archives as $archive) {
            unlink($archive);
        }
    }
}