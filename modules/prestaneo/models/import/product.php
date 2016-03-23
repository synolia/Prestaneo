<?php
class ImportProduct extends ImportAbstract
{
    protected $_offsetsName       = null;
    protected $_fields            = null;

    protected $_resetImages       = false;
    protected $_resetFeatures     = false;
    protected $_resetCombinations = false;
    protected $_quantityDefault   = 0;
    protected $_delimiter         = "";

    public static $icon  = 'cubes';
    public static $order = 5;

    /**
     * Imports products
     *
     * @return bool
     * @throws Exception
     * @throws PrestaShopException
     */
    public function process()
    {
        $this->_delimiter = (Configuration::get(MOD_SYNC_NAME . '_IMPORT_DELIMITER') ? Configuration::get(MOD_SYNC_NAME . '_IMPORT_DELIMITER') : ';');

        $folderPath = $this->_manager->getPath() . '/files/';

        $fileTransferHost = Configuration::get(MOD_SYNC_NAME.'_ftphost');
        if(!empty($fileTransferHost)) {
            $fileTransfer = $this->_getFtpConnection();

            $fileTransfer->getFiles(Configuration::get(MOD_SYNC_NAME . '_IMPORT_PRODUCT_FTP_PATH'), $folderPath, '*.csv');
            $fileTransfer->getFiles(Configuration::get(MOD_SYNC_NAME . '_IMPORT_PRODUCT_FTP_PATH'), $folderPath, '*.zip');
        }

        $folderUtil = Utils::exec('Folder');
        $archives   = $folderUtil->getAllFilesInFolder($folderPath, '*.zip');

        if (!empty($archives)) {
            $hasImages = true;
            Utils::exec('Zip')->extractAll($archives, $folderPath);
        } else {
            $hasImages = false;
        }

        $reader = new CsvReader($this->_manager, $this->_delimiter);
        $reader->setExtension('.csv');

        $dataLines   = $reader->getData();
        $currentFile = $reader->getCurrentFileName(0);

        $this->_resetImages       = Configuration::get(MOD_SYNC_NAME . '_IMPORT_RESET_IMAGES');
        $this->_resetFeatures     = Configuration::get(MOD_SYNC_NAME . '_IMPORT_RESET_FEATURES');
        $this->_resetCombinations = Configuration::get(MOD_SYNC_NAME . '_IMPORT_RESET_COMBINATIONS');
        $this->_quantityDefault   = Configuration::get(MOD_SYNC_NAME . '_IMPORT_DEFAULT_QTY_PRODUCT');

        if (!is_array($dataLines) || empty($dataLines)){
            $this->logError('Nothing to import or file is not valid CSV');
            $this->_mover->finishAction(basename($currentFile), false);
            $this->_cleanWorkingDirectory($archives);
            return false;
        }

        $requiredFields = MappingProducts::getRequiredFields('akeneo');
        $headers        = $this->_cleanDataLine(array_shift($dataLines));
        $missingFields  = $this->_checkMissingRequiredFields($requiredFields, $headers);

        if (!empty($missingFields)) {
            $this->logError('Missing required fields : ' . implode(', ', $missingFields));
            $this->_mover->finishAction(basename($currentFile), false);
            $this->_cleanWorkingDirectory($archives);
            return false;
        }

        $headers[]      = 'emptyValue';
        $headers[]      = 'nullValue';
        $this->_offsets = array_flip($headers);

        //Getting the list of the picture fields in the csv
        $mappedPictureFields  = MappingProducts::getImageFields();
        $pictureFields = array();
        foreach ($mappedPictureFields as $mappedPictureField) {
            if (isset($this->_offsets[$mappedPictureField['champ_akeneo']])) {
                $pictureFields[] = $mappedPictureField['champ_akeneo'];
            }
        }

        if (empty($pictureFields)) {
            $hasImages = false;
        }

        $this->_getLangsInCsv($headers);
        $this->_mapOffsets(MappingProducts::getAllPrestashopFields(), array('image'));

        $mappingFeatures     = $this->_getFeatureMappings();
        $defaultLangId       = Configuration::get('PS_LANG_DEFAULT');
        $processedProductIds = array();
        $defaultCurrency     = Currency::getDefaultCurrency()->iso_code;

        if (isset($this->_offsets['price-' . $defaultCurrency])) {
            $priceOffset = $this->_offsets['price-' . $defaultCurrency];
        } elseif (isset($this->_offsets['price'])) {
            $priceOffset = $this->_offsets['price'];
        }

        $this->makeOffsetRedirections($mappingFeatures['default']);

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

            if ($data === false || $data == array(null))  {
                $this->logError('Product line wrong, skipping : ' . ($line + 2));
                $errorCount++;
                continue;
            }

            $data      = $this->_cleanDataLine($data);
            $reference = $data[$this->_offsets['reference']];
            $groupCode = $data[$this->_offsets['groups']];
            $axes      = explode(',', MappingTmpAttributes::getAxisByCode($groupCode));

            $data[$this->_offsets['emptyValue']] = '';
            $data[$this->_offsets['nullValue']]  = null;

            if (empty($reference)) {
                $this->logError('Missing reference on line ' . ($line + 2));
                $errorCount++;
                continue;
            } elseif (_PS_MODE_DEV_) {
                $this->log('Importing ' . $reference);
            }

            //Is out of the product update because it is needed even if the line is only a combination of an existing product
            $ean13 = $data[$this->_offsets['ean13']];
            if (empty($ean13) || !Validate::isEan13($ean13)) {
                $this->logError($reference . ' : ean13 not valid');
                $errorCount++;
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

            $defaultShopId = (isset($this->_offsets['id_shop_default']) ? $data[$this->_offsets['id_shop_default']] : Context::getContext()->shop->id);

            if ($isNewProduct) {
                $names                = array();
                $featureLang          = array();
                $linkRewrites         = array();
                $descriptionShorts    = array();
                $descriptions         = array();
                $metaTitles           = array();
                $metaKeywords         = array();
                $metaDescriptions     = array();
                $availableNowInLang   = array();
                $availableLaterInLang = array();

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

                    foreach ($mappingFeatures['default'] as $mappingFeature) {
                        $value = $data[$this->_offsets[$mappingFeature['code'] . $suffixLang]];
                        if (!empty($value)) {
                            $featureLang[$mappingFeature['code']][$langId] = $value;
                        }
                    }
                }
                Shop::setContext(Shop::CONTEXT_SHOP, $defaultShopId);

                $wholesalePrice = $data[$this->_offsets['wholesale_price']];
                $upc            = $data[$this->_offsets['upc']];
                $ecotax         = $data[$this->_offsets['ecotax']];
                $availableDate  = $data[$this->_offsets['available_date']];

                // Controls
                if (empty($upc) || !Validate::isUpc($upc)) {
                    $this->logError($reference . ' : upc not valid');
                    $errorCount++;
                    $upc = "";
                }
                if (empty($ecotax) || !Validate::isPrice($ecotax)) {
                    $this->logError($reference . ' : ecotax not valid');
                    $errorCount++;
                    $ecotax = 0;
                }
                if (empty($wholesalePrice) || !Validate::isPrice($wholesalePrice)) {
                    $this->logError($reference . ' : wholesale not valid');
                    $errorCount++;
                    $wholesalePrice = 0;
                }
                if (!empty($availableDate) && !Validate::isDateFormat($availableDate)) {
                    $dates = explode('/', $availableDate);
                    if (count($dates) == 3) {
                        $availableDate = $dates[2] . '-' . $dates[1] . '-' . $dates[0];
                        $this->log($reference . ' available date reformated : ' . $availableDate);
                    } else {
                        $availableDate = "";
                        $this->logError($reference . ' : available date not valid');
                        $errorCount++;
                    }
                } else {
                    $availableDate = "";
                    $this->logError($reference . ' : available date not valid');
                    $errorCount++;
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
                        $this->logError($reference . ' : category ' . $categoryCode . ' does not exist');
                        $errorCount++;
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
                        $this->logError($reference . ' : price not valid');
                        $errorCount++;
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
                $product->shop           = $defaultShopId;
                $product->id_shop_list[] = $defaultShopId;

                // Force re-indexing search process with new datas
                $product->indexed = 0;

                if (!$product->save()) {
                    $this->logError($reference . ' : could not be saved');
                    $errorCount++;
                    continue;
                } elseif (_PS_MODE_DEV_) {
                    $this->log($reference . ' : saved');
                }

                //Product has been saved
                $processedProductIds[$product->id] = true;

                // Save Features
                if ($this->_resetFeatures) {
                    if (!$product->deleteFeatures()) {
                        $this->logError($reference . ' : could not remove features');
                        $errorCount++;
                    }
                }

                foreach ($mappingFeatures['select'] as $mappingFeature) {
                    if (in_array($mappingFeature['code'], $axes)) {
                        continue; //Skipping variant attributes
                    }
                    $featureCode      = $mappingFeature['code'];
                    $featureValueCode = $data[$this->_offsets[$featureCode]];

                    if (!empty($featureValueCode)) {
                        $featureValueId   = MappingCodeFeatureValues::getIdByCode($featureValueCode);

                        if (!$featureValueId) {
                            $this->logError($reference . ' : feature value ' . $featureValueCode . ' does not exist for feature ' . $featureCode);
                            $errorCount++;
                            continue;
                        }

                        if (!Product::addFeatureProductImport($product->id, $mappingFeature['id_feature'], $featureValueId)) {
                            $this->logError($reference . ' : could not associate to feature value ' . $featureValueCode . ' for feature ' . $featureCode);
                            $errorCount++;
                            continue;
                        }

                        if (_PS_MODE_DEV_) {
                            $this->log($reference . ' : feature value ' . $featureValueCode . ' added for feature ' . $featureCode);
                        }
                    }
                }

                //Adding features that does not have preset values
                foreach ($featureLang as $featureCode => $featureValues) {
                    if (in_array($featureCode, $axes)) {
                        continue; //Skipping variant attributes
                    }
                    $featureId = MappingCodeFeatures::getIdByCode($featureCode);

                    if (!$featureId) {
                        $this->logError($reference . ' : feature ' . $featureCode . ' does not exist');
                        $errorCount++;
                        continue;
                    }

                    $featureValueId = $this->_addFeatureValue($featureId, $featureValues);

                    if (!$featureValueId) {
                        $this->logError($reference . ' : could not save feature value for ' . $featureCode);
                        $errorCount++;
                        continue;
                    }

                    if (!Product::addFeatureProductImport($product->id, $featureId, $featureValueId)) {
                        $this->logError($reference . ' : could not associate to feature value ' . $featureCode . ' for feature ' . $featureCode);
                        $errorCount++;
                    }

                    if (_PS_MODE_DEV_) {
                        $this->log($reference . ' : feature value ' . $featureLang[$defaultLangId] . ' added for feature ' . $featureCode);
                    }

                }

                //Save Associations Categories
                if (!$product->updateCategories($categories)) {
                    $this->logError($reference . ' : could not update categories');
                    $errorCount++;
                }

                if ($knownProduct) {
                    if ($this->_resetImages) {
                        if (!$product->deleteImages()) {
                            $this->logError($reference . ' : could not delete images');
                            $errorCount++;
                        } elseif (_PS_MODE_DEV_) {
                            $this->log($reference . ' : images deleted');
                        }
                    }

                    if (!empty($groupCode)) {
                        if ($this->_resetCombinations) {
                            if (!$product->deleteProductAttributes()) {
                                $this->logError($reference . ' : error while resetting product combinations');
                                $errorCount++;
                            } elseif (_PS_MODE_DEV_) {
                                $this->log($reference . ' : combinations deleted');
                            }
                        }
                    }
                } else {
                    //We save quantities only if the product is totally new
                    StockAvailable::setQuantity($product->id, 0, $this->_quantityDefault, $defaultShopId);
                }
            }

            $addedImages = array();
            //Save Images
            if ($hasImages) {
                $isCover         = !((bool)Product::getCover($product->id));
                $highestPosition = Image::getHighestPosition($product->id);

                foreach ($pictureFields as $pictureField) {
                    $imageRelativePath = $data[$this->_offsets[$pictureField]];
                    if (empty($imageRelativePath)) {
                        continue;
                    }
                    $imagePath = $folderPath . $imageRelativePath;

                    if (!file_exists($imagePath)) {
                        $this->logError($reference . ' : image ' . $imagePath . ' does not exist');
                        $errorCount++;
                        continue;
                    }

                    $image             = new Image();
                    $image->id_product = $product->id;
                    $image->position   = ++$highestPosition;
                    $image->legend     = $product->name;
                    $image->cover      = $isCover;

                    if (!$image->add()) {
                        $this->logError($reference . ' : could not save image ' . $imagePath);
                        $errorCount++;
                        $highestPosition--;
                    } else {
                        if (!$this->copyImg($product->id, $image->id, $imagePath, 'products', true)) {
                            $this->logError($reference . ' : could not copy image ' . $imagePath);
                            $errorCount++;
                            $image->delete();
                            $highestPosition--;
                        } else {
                            if ($isCover) {
                                //Forcing cache cleaning
                                Cache::clean('Product::getCover_' . $product->id . '-' . $defaultShopId);
                            }
                            $addedImages[] = $image->id;
                        }
                    }
                    unlink($imagePath);
                    $isCover = false;
                }
                if (!empty($addedImages) && _PS_MODE_DEV_) {
                    $this->log($reference . ' : images added');
                }
            }

            //Save Combination + Quantity Stock
            if (!empty($groupCode)) {
                $attributeIds = array();

                foreach ($axes as $axis) {
                    $axisValueCode    = $data[$this->_offsets[$axis]];
                    $attributeValueId = MappingCodeAttributeValues::getIdByCode($axisValueCode);

                    if (!$attributeValueId) {
                        $this->logError($reference . ' : attribute value ' . $axisValueCode . ' does not exist for attribute ' . $axis);
                        $errorCount++;
                    } else {
                        $attributeIds[] = $attributeValueId;
                    }
                }

                $price = (isset($priceOffset) ? $data[$priceOffset] : null);

                $this->addOrUpdateProductAttributeCombination($product, $ean13, $attributeIds, $reference, $price, $addedImages);

                $mappingId = MappingProductsGroups::getMappingByGroupCode($groupCode);
                if ($mappingId === false) {
                    $mapping = new MappingProductsGroups();
                    $mapping->id_product = $product->id;
                    $mapping->group_code = $groupCode;

                    if (!$mapping->save()) {
                        $this->logError($reference . ' : could not save mapping with group ' . $groupCode);
                        $errorCount++;
                    }
                }

                if ($isNewProduct) {
                    $product->checkDefaultAttributes();
                }

                if (_PS_MODE_DEV_) {
                    $this->log($reference . ' : combination saved');
                }
            }
        }

        $endStatus = ($errorCount == $lastErrorCount);
        $this->_mover->finishAction(basename($currentFile), $endStatus);
        $this->_cleanWorkingDirectory($archives);

        // Search Indexation
        if (!Search::indexation()) {
            $this->logError('Product reindexation failed');
        } elseif (_PS_MODE_DEV_) {
            $this->log('Products reindexed');
        }

        return true;
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
     * @param string  $attributeEan
     * @param array   $attributeValueIds , all value ids for ONE combination (example for red (value id=1), XL (value id=9) => array(array(0 => 1, 1 => 9, 2 => 3))
     * @param string  $reference
     * @param float   $price
     * @param array   $images
     *
     * @return int product attribute id if success
     */
    protected function addOrUpdateProductAttributeCombination(Product $product, $attributeEan, array $attributeValueIds, $reference, $price, $images)
    {
        if (!$product)
            return false;

        $returnId = true;

        // Image combination
        if (empty($images)) {
            $cover = Product::getCover($product->id);
            if ($cover) {
                $images[] = $cover['id_image'];
            }
        }

        $productAttributeId = $product->productAttributeExists($attributeValueIds, false, null, false, $returnId);

        if ($productAttributeId > 0) {
            $combination = new Combination($productAttributeId);
            if (!$this->_resetImages) {
                $images = array_unique(array_merge($images, $product->_getAttributeImageAssociations($productAttributeId)));
            }
        } else {
            $combination             = new Combination();
            $combination->id_product = $product->id;
            $combination->price      = $price;
            $combination->add();

            if (!$combination->id) {
                return false;
            }

            $combination->setAttributes($attributeValueIds);
            StockAvailable::setQuantity($product->id, $combination->id, $this->_quantityDefault);
        }

        $combination->reference = $reference;
        $combination->ean13     = $attributeEan;

        $combination->setImages($images);
        $combination->update();

        StockAvailable::setProductDependsOnStock($product->id, $product->depends_on_stock, null, $productAttributeId);
        StockAvailable::setProductOutOfStock($product->id, $product->out_of_stock, null, $productAttributeId);

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
     */
    protected function makeOffsetRedirections($mappings) {
        $nullDefaults = array(
            'active',
            'id_tax_rules_group',
            'width',
            'height',
            'depth',
            'weight',
            'available_for_order',
            'show_price',
            'online_only'
        );

        $langFields = array(
            'description_short',
            'description',
            'meta_title',
            'meta_keywords',
            'meta_description',
            'available_now',
            'available_later'
        );

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

            foreach ($langFields as $langField) {
                if (!isset($this->_offsets[$langField . $suffixLang])) {
                    $this->_offsets[$langField . $suffixLang] = $this->_offsets['emptyValue'];
                }
            }

            foreach ($mappings as $mapping) {
                $mappingName = $mapping['code'] . $suffixLang;

                if (!isset($this->_offsets[$mappingName])) {
                    if (isset($this->_offsets[$mapping['code']])) {
                        $this->_offsets[$mappingName] = $this->_offsets[$mapping['code']];
                    } else {
                        $this->_offsets[$mappingName] = $this->_offsets['emptyValue'];
                    }
                }
            }
        }

        foreach ($nullDefaults as $item) {
            if (!isset($this->_offsets[$item])) {
                $this->_offsets[$item] = $this->_offsets['nullValue'];
            }
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

    /**
     * Gets all the feature mappings and separates them by type
     *
     * For now, only selects have a specific behavior
     * @return array
     */
    protected function _getFeatureMappings()
    {
        $mappingFeatures = array(
            'select'  => array(),
            'default' => array()
        );

        foreach (MappingCodeFeatures::getAll() as $mapping) {
            if ($mapping['type'] == 'pim_catalog_simpleselect') {
                $mappingFeatures['select'][] = $mapping;
            } else {
                $mappingFeatures['default'][] = $mapping;
            }
        }
        return $mappingFeatures;
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
}