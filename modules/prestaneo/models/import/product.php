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

        $folderUtil = Utils::exec('Folder');
        $path       = $this->_manager->getPath() . '/files/';

        if ($folderUtil->isFolderEmpty($path)) {
            $fileTransferHost = Configuration::get(MOD_SYNC_NAME . '_ftphost');
            if (!empty($fileTransferHost)) {
                if (_PS_MODE_DEV_) {
                    $this->log('Fetching files from FTP');
                }
                $fileTransfer = $this->_getFtpConnection();
                $distantPath  = Configuration::get(MOD_SYNC_NAME . '_IMPORT_PRODUCT_FTP_PATH');

                $files = $fileTransfer->getFolderContent(array(), $distantPath);

                if (in_array('files', $files)) {
                    $success = $fileTransfer->recursiveGetFiles($distantPath . '/files/', $path . 'files/');
                    $success &= $fileTransfer->getFiles($distantPath, $path, '*.csv');
                } else {
                    $success = $fileTransfer->getFiles($distantPath, $path, array('*.zip', '*.csv'));
                }

                if (!$success) {
                    $this->logError('There was an error while retrieving the files from the FTP');
                    $folderUtil->delTree($path, false);
                    return false;
                }
            }
        }

        $archives = $folderUtil->getAllFilesInFolder($path, '*.zip');

        if (!empty($archives)) {
            $hasImages = true;
            Utils::exec('Zip')->extractAll($archives, $path);
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
            $folderUtil->delTree($path, false);
            return false;
        }

        $requiredFields = MappingProducts::getRequiredFields('akeneo');
        $headers        = $this->_cleanDataLine(array_shift($dataLines));
        $missingFields  = $this->_checkMissingRequiredFields($requiredFields, $headers);

        if (!empty($missingFields)) {
            $this->logError('Missing required fields : ' . implode(', ', $missingFields));
            $this->_mover->finishAction(basename($currentFile), false);
            $folderUtil->delTree($path, false);
            return false;
        }

        $offsets = array_flip($headers);

        //Getting the list of the picture fields in the csv
        $mappedPictureFields  = MappingProducts::getImageFields();
        $pictureFields = array();
        foreach ($mappedPictureFields as $mappedPictureField) {
            if (array_key_exists($mappedPictureField['champ_akeneo'], $offsets)) {
                $pictureFields[$mappedPictureField['champ_akeneo']] = $offsets[$mappedPictureField['champ_akeneo']];
            }
        }

        if(!isset($hasImages))
            $hasImages = true;
        if (empty($pictureFields)) {
            $hasImages = false;
        }

        $axisOffsets = array();

        foreach (MappingTmpAttributes::getAll() as $mapping) {
            $axes = explode(',', $mapping['axis']);
            foreach ($axes as $axis) {
                if (array_key_exists($axis, $offsets)) {
                    $axisOffsets[$mapping['code']][$axis] = $offsets[$axis];
                }
            }
        }

        $defaultCurrency = Currency::getDefaultCurrency()->iso_code;

        if (isset($offsets['price-' . $defaultCurrency])) {
            $priceOffset = $offsets['price-' . $defaultCurrency];
            unset($offsets['price-' . $defaultCurrency]);
        } elseif (isset($offsets['price'])) {
            $priceOffset = $offsets['price'];
            unset($offsets['price']);
        } else {
            $priceOffset = 'emptyValue';
        }

        $this->_getLangsInCsv($headers);

        $featureOffsets = $this->_getFeatureOffsets();

        $this->_mapOffsets('Product', MappingProducts::getAllPrestashopFields(), array('image'));

        $defaultLangId       = Configuration::get('PS_LANG_DEFAULT');
        $processedProductIds = array();

        $referenceOffset     = isset($this->_offsets['default']['reference']) ?           $this->_offsets['default']['reference'] :           'emptyValue';
        $ean13Offset         = isset($this->_offsets['default']['ean13']) ?               $this->_offsets['default']['ean13'] :               'emptyValue';
        $idShopDefaultOffset = isset($this->_offsets['default']['id_shop_default']) ?     $this->_offsets['default']['id_shop_default'] :     'emptyValue';
        $categoryOffset      = isset($this->_offsets['default']['id_category_default']) ? $this->_offsets['default']['id_category_default'] : 'emptyValue';

        unset(
            $this->_offsets['default']['reference'],
            $this->_offsets['default']['ean13'],
            $this->_offsets['default']['id_shop_default'],
            $this->_offsets['default']['id_category_default']
        );

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
            $reference = $data[$referenceOffset];
            $groupCode = $data[$this->_offsets['special']['groups']];

            $data['emptyValue'] = '';

            if (empty($reference)) {
                $this->logError('Missing reference on line ' . ($line + 2));
                $errorCount++;
                continue;
            } elseif (_PS_MODE_DEV_) {
                $this->log('Importing ' . $reference);
            }

            //Is out of the product update because it is needed even if the line is only a combination of an existing product
            $ean13 = $data[$ean13Offset];
            if (empty($ean13) || !Validate::isEan13($ean13)) {
                $this->logNotification($reference . ' : ean13 not valid');
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

            $defaultShopId = (!empty($data[$idShopDefaultOffset]) ? $data[$idShopDefaultOffset] : Context::getContext()->shop->id);
            Shop::setContext(Shop::CONTEXT_SHOP, $defaultShopId);

            if ($isNewProduct) {
                foreach ($this->_offsets['lang'] as $field => $offsets) {
                    foreach ($offsets as $idLang => $offset) {
                        $product->{$field}[$idLang] = $data[$offset];
                    }
                }

                foreach ($this->_offsets['default'] as $field => $offset) {
                    $product->{$field} = $data[$offset];
                }

                foreach ($this->_offsets['date'] as $field => $offset) {
                    if (Validate::isDate($data[$offset])) {
                        $product->{$field} = $data[$offset];
                    } else {
                        $this->logError('Wrong date format for field ' . $field . ' : ' . $data['offset'] . '(for product ' . $reference . ')');
                        $errorCount++;
                    }
                }

                //Adding missing attributes and default values for some
                $product->reference = $reference;
                $product->ean13     = $ean13;

                //If no name is given, the default one is the reference
                if (!is_array($product->name)) {
                    $product->name = array($defaultLangId => $product->name);
                }

                if (!array_filter($product->name)) {
                    $product->name[$defaultLangId] = $reference;
                }

                //link_rewrites are needed so if they are not present, we set them from the name
                if (!is_array($product->link_rewrite)) {
                    $product->link_rewrite = array($defaultLangId => $product->link_rewrite);
                }

                if (!array_filter($product->link_rewrite)) {
                    $links = array();
                    foreach ($product->name as $idLang => $value) {
                        $links[$idLang] = Tools::link_rewrite($value);
                    }
                    $product->link_rewrite = $links;
                }

                if (empty($data[$categoryOffset])) {
                    $categories    = array();
                    $categoryCodes = explode(',', $data[$this->_offsets['special']['categories']]);

                    foreach ($categoryCodes as $categoryCode) {
                        $categoryId = (int)MappingCodeCategories::getIdByCode($categoryCode);
                        if ($categoryId > 0) {
                            $categories[] = $categoryId;
                        } else {
                            $this->logError($reference . ' : category ' . $categoryCode . ' does not exist');
                            $errorCount++;
                        }
                    }
                } else {
                    $categories = preg_split('#,#', $data[$categoryOffset], -1, PREG_SPLIT_NO_EMPTY);
                }

                if (!empty($categories)) {
                    $product->id_category_default = $categories[0];
                } else {
                    $product->id_category_default = 2;
                }

                if (!empty($groupCode)) {
                    $product->price = 0;
                } else {
                    $product->price = $data[$priceOffset];
                }

                switch ($product->visibility) {
                    case "1":
                        $product->visibility = "none";
                        break;
                    case "2":
                        $product->visibility = "catalog";
                        break;
                    case "3":
                        $product->visibility = "search";
                        break;
                    default:
                        $product->visibility = "both";
                        break;
                }

                if (!isset($this->_offsets['default']['shop'])) {
                    $product->shop = $defaultShopId;
                }

                if (!isset($this->_offsets['default']['id_shop_list'])) {
                    $product->id_shop_list[] = $defaultShopId;
                }

                // Force re-indexing search process with new datas
                $product->indexed = 0;

                //Must be true if product is available for order
                $product->show_price = ($product->available_for_order || $product->show_price);

                try {
                    if (!$product->save()) {
                        $this->logError($reference . ' : could not be saved');
                        $errorCount++;
                        continue;
                    } elseif (_PS_MODE_DEV_) {
                        $this->log($reference . ' : saved');
                    }
                } catch (PrestaShopDatabaseException $e) {
                    $this->logError($e->getMessage());
                    $errorCount++;
                    continue;
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

                foreach ($featureOffsets['select'] as $featureCode => $featureInfos) {
                    if (!empty($groupCode) && array_key_exists($featureCode, $axisOffsets[$groupCode])) {
                        continue; //Skipping variant attributes
                    }

                    $featureValueCode = $data[$featureInfos['offset']];

                    if (!empty($featureValueCode)) {
                        $featureValueId   = MappingCodeFeatureValues::getIdByCodeAndFeature($featureValueCode, $featureCode);

                        if (!$featureValueId) {
                            $this->logError($reference . ' : feature value ' . $featureValueCode . ' does not exist for feature ' . $featureCode);
                            $errorCount++;
                            continue;
                        }

                        if (!Product::addFeatureProductImport($product->id, $featureInfos['id_feature'], $featureValueId)) {
                            $this->logError($reference . ' : could not associate to feature value ' . $featureValueCode . ' for feature ' . $featureCode);
                            $errorCount++;
                            continue;
                        }

                        if (_PS_MODE_DEV_) {
                            $this->log($reference . ' : feature value ' . $featureValueCode . ' added for feature ' . $featureCode);
                        }
                    }
                }

                //Custom feature values
                foreach ($featureOffsets['default'] as $featureCode => $featureInfos) {
                    $featureValues = array();
                    $emptyValue    = true;

                    if (is_array($featureInfos['offset'])) {
                        foreach ($featureInfos['offset'] as $idLang => $offset) {
                            $featureValues[$idLang] = $data[$offset];
                            $emptyValue &= empty($data[$offset]);
                        }
                    } else {
                        $featureValues[$defaultLangId] = $data[$featureInfos['offset']];
                        $emptyValue = empty($data[$featureInfos['offset']]);
                    }

                    if (!$emptyValue) {
                        $featureValueId = $this->_addFeatureValue($featureInfos['id_feature'], $featureValues);

                        if (!$featureValueId) {
                            $this->logError($reference . ' : could not save feature value for ' . $featureCode);
                            $errorCount++;
                            continue;
                        }

                        if (!Product::addFeatureProductImport($product->id, $featureInfos['id_feature'], $featureValueId)) {
                            $this->logError($reference . ' : could not associate to feature value ' . $featureCode . ' for feature ' . $featureCode);
                            $errorCount++;
                        }

                        if (_PS_MODE_DEV_) {
                            $this->log($reference . ' : custom feature value ' . $featureValues[$defaultLangId] . ' added for feature ' . $featureCode);
                        }
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

                    if (!empty($groupCode) && $this->_resetCombinations) {
                        if (!$product->deleteProductAttributes()) {
                            $this->logError($reference . ' : error while resetting product combinations');
                            $errorCount++;
                        } elseif (_PS_MODE_DEV_) {
                            $this->log($reference . ' : combinations deleted');
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

                foreach ($pictureFields as $pictureField => $offset) {
                    $imageRelativePath = $data[$offset];
                    if (empty($imageRelativePath)) {
                        continue;
                    }
                    $imagePath = $path . $imageRelativePath;

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

                foreach ($axisOffsets[$groupCode] as $axis => $offset) {
                    $attributeValueId = MappingCodeAttributeValues::getIdByCodeAndGroup($data[$offset], $axis);

                    if (!$attributeValueId) {
                        $this->logError($reference . ' : attribute value ' . $data[$offset] . ' does not exist for attribute ' . $axis);
                        $errorCount++;
                    } else {
                        $attributeIds[] = $attributeValueId;
                    }
                }

                $price = (isset($priceOffset) ? $data[$priceOffset] : 0);

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
        $folderUtil->delTree($path, false);

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

    protected function _getFeatureOffsets()
    {
        $features     = array(
            'select'  => array(),
            'default' => array()
        );

        $filter       = '#^(.+?)-(\w{2,3})$#';
        $offsetFields = array_keys($this->_offsets);

        foreach (MappingCodeFeatures::getAll() as $mapping) {
            $matching = preg_grep('#^' . $mapping['code'] . '(-\w{2,3})?$#', $offsetFields);

            if (!empty($matching)) {

                if ($mapping['type'] == 'pim_catalog_simpleselect') {
                    $featureType = 'select';
                } else {
                    $featureType = 'default';
                }

                $features[$featureType][$mapping['code']]['id_feature'] = $mapping['id_feature'];

                foreach ($matching as $field) {
                    if (preg_match($filter, $field, $results)) {
                        $features[$featureType][$mapping['code']]['offset'][$this->_langs[$results[2]]] = $this->_offsets[$field];
                    } else {
                        $features[$featureType][$mapping['code']]['offset'] = $this->_offsets[$field];
                    }
                }
            }
        }
        return $features;
    }
}