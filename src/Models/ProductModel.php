<?php

/**
 * This class is for retrieving data for the products from core.
 *
 * @package CrcFramework
 * @subpackage Model
 * @author Stephen Hearn
 */

namespace TandF\Models;

use TandF\EE\AgentException,
    TandF\Helpers\Utility,
    TandF\Helpers\Product,
    TandF\Helpers\View,
    TandF\Helpers\Cover,
    TandF\Ecommerce\Entity\Price,
    TandF\Ecommerce\Entity\BindingStyleGT,
    TandF\Ecommerce\Entity\Currency;

class ProductModel extends BaseModel
{

    public function __construct($config) {
        parent::__construct($config);

        if (self::$productService === false) {
            self::$productService = self::$ecommerceapi->getService('Product');
        }

        if (self::$classificationService === false) {
            self::$classificationService = self::$ecommerceapi->getService('Classification');
        }

        if (self::$inventoryService === false) {
            self::$inventoryService = self::$ecommerceapi->getService('Inventory');
        }

        if (self::$currencyService === false) {
            self::$currencyService = self::$ecommerceapi->getService('Currency');
        }

        if (self::$priceService === false) {
            self::$priceService = self::$ecommerceapi->getService('PricingManager');
        }

        if (self::$categoryService === false) {
            self::$categoryService = self::$ecommerceapi->getService('Category');
        }
    }

    /**
     *
     * @param Either CartItem | InvoiceItem $item
     * @return Product
     */
    public function getProductFromItem($item)
    {
        switch (get_class($item)) {
            case 'TandF\Ecommerce\Entity\CartItem':
                $product = $item->getProduct();
                break;
            case 'TandF\Ecommerce\Entity\InvoiceItem':
            default:
                $product = self::$productService->findBookByIsbn($item->getIsbn());
                break;
        }
        return $product;
    }

    /**
     * @deprecated Use getProduct
     * @param string $sku
     * @return Product
     */
    public function getProductFromSku($sku)
    {
        return self::$productService->findBySku($sku);;
    }

    /**
     * Gets product information from core using an isbn
     *
     * @param int $id ISBN / CATNO of the product
     *
     * @return Product $product
     */
    public function getProduct($id)
    {
        //Determine if it's an ISBN or CATNO
        if(preg_match('/([0-9]{13})/', $id)) {
            $func = 'findBookByIsbn';
        } else {
            $func = 'findBySku';
        }

        if (self::$config['memcached_enabled']) {
            $key        = "crc.product.$id.getProduct";
            $product    = self::$memcached->get($key);
            if (!$product) {
                $product = self::$productService->$func($id);
                self::$memcached->set($key, $product, self::$config['memcached_expire_getproduct']);
            }
        } else {
            $product = self::$productService->$func($id);
        }

        return $product;
    }

    /**
     * Gets product information from core using an id
     *
     * @param int $id id of the product
     *
     * @return Product $product
     */
    public function getProductById($id)
    {

        if (self::$config['memcached_enabled']) {
            $key        = "crc.product.$id.getProduct";
            $product    = self::$memcached->get($key);
            if (!$product) {
                $product = self::$productService->findById($id);
                self::$memcached->set($key, $product, self::$config['memcached_expire_getproduct']);
            }
        } else {
            $product = self::$productService->findById($id);
        }

        return $product;
    }

    /**
     * Gets multi product information from core using a list of isbns
     *
     * @param string $region
     * @param array $isbns
     *
     * @return Product $products
     */
    public function getProducts($region, $isbns)
    {

        if (self::$config['memcached_enabled']) {
            $key        = "crc.product." . implode(',', $isbns) . ".$region.getProducts";
            $products   = self::$memcached->get($key);
            if (!$products) {
                $currency = self::$currencyService->curencyForCountry($region);
                $products = self::$productService->findAllBooksByIsbns($currency->getIsoName(), $isbns);
                self::$memcached->set($key, $products, self::$config['memcached_expire_getproduct']);
            }
        } else {
            $currency = self::$currencyService->curencyForCountry($region);
            $products = self::$productService->findAllBooksByIsbns($currency->getIsoName(), $isbns);
        }

        return Utility::ensureArray($products);
    }

    /**
     * Gets pricing information from memcached using an isbn
     *
     * @param string $isbn
     * @param string $region
     * @param string $wishListItems
     * @param string $promo
     *
     * @return Array $result
     */
    public function getProductsWithPricesFromCache($isbn, $region, $wishListItems, $promo = '')
    {
        if (self::$config['memcached_enabled']) {

            $key = "crc.product." . $isbn . "." . $region . "." . $promo;

            $prices = self::$memcached->get($key);

            if (!$prices) {
                $prices = $this->getProductsWithPrices($isbn, $region, $promo);

                self::$memcached->set($key, $prices, self::$config['memcached_expire_getprices']);
            }

        } else {
            $prices = $this->getProductsWithPrices($isbn, $region, $promo);
        }

        if($prices) {
            $prices = Product::checkInWishList($prices, $wishListItems);
        }

        return $prices;
    }

    /**
     * Gets pricing information from core using an isbn
     *
     * @param string $isbn
     * @param string $region
     * @param string $promo
     *
     * @return Array $result
     */
    public function getProductsWithPrices($isbn, $region, $promo)
    {

        $vitalSourceIsbn = null;
        $crcNetBaseIsbn  = null;
		$ebpdbisbnfound = false;
        $validPrice = false;

        $result = array();

        // get all bindings for this product so we have a list of product IDs to return
        // prices for
        $productInfoIsbns     = array();
        $relevantProductIsbns = array();
        $showEbook          = null;

        $product = $this->getProduct($isbn);

        if($product === false) {
            return array();
        }

        if (is_object($product->getBinding()) && $product->getBinding()->getCode() == 'EBK') {
            // using this function is little confusing  if the product which is being requested by the user is an
            // Ebook then check if that book's pricing should be shown or not and also determine the ebook
            // association of it this will prevent showing ebook with vital source invalid classification
            $showEbook = $this->shouldShowEbook($product, $region);
        }

        $productInfoIsbns[$isbn] = array($product, $showEbook);
        $relevantProductIsbns[]  = $isbn;

        //retrieve related binding
        $relatedBindings = $this->getRelatedBindings($product->getId(), $region);

        //collect relatedBindings ISBN
        $relatedBindingsIsbn[] = $isbn;

        if (!empty($relatedBindings)) {

            foreach ($relatedBindings as $b) {

                //build list of relatedBindings ISBN
                $relatedBindingsIsbn[] = $b->getIsbn();

                $ebkSource = null;
                $showit    = in_array($b->getBinding()->getCode(), self::$config['purchase_bindings']);

                //check if the ebook should be shown or not
                if ($b->getBinding()->getCode() == 'EBK') {

                    $ebkSource = $this->shouldShowEbook($b, $region);
                    $showit    = $ebkSource != null;
                }

                if ($showit) {

                    $productInfoIsbns[$b->getIsbn()] = array($b, $ebkSource);

                    if (in_array($b->getIsbn(), $relevantProductIsbns) === false) {

                        $relevantProductIsbns[] = $b->getIsbn();
                    }
                }
            }
        } else {
            $bindings = array();
        }

        foreach ($productInfoIsbns as $a) {

            if (is_object($a[0]->getBinding()) && $a[0]->getBinding()->getCode() == 'EBK' && $a[0]->getBindingStyle() != null) {

                //check if there's a EBE3
                if ($a[0]->getBindingStyle()->getCode() === 'EBE3' && ($a[1] == 'vitalsource' || $a[1] == 'both')) {

                    // there's a EBE3 so remove EBVB, EBPD or EBEP
                    foreach ($productInfoIsbns as $b) {

                        $isEbook = $b[0]->getBindingStyle() != null &&
                            ($b[0]->getBindingStyle()->getCode() === 'EBPD'
                                || $b[0]->getBindingStyle()->getCode() === 'EBEP'
                                || $b[0]->getBindingStyle()->getCode() === 'EBVB');

                        if ($isEbook && $b[1] == 'vitalsource') {

                            unset($productInfoIsbns[$b[0]->getIsbn()]);
                            $tmp = array_search($b[0]->getIsbn(), $relevantProductIsbns);
                            unset($relevantProductIsbns[$tmp]);
                        } elseif ($isEbook && $b[1] == 'both') {

                            // since its both an ebook and vitalsource book, instead of removing the book
                            // completely we are converting it into a crcnetbase book only
                            $productInfoIsbns[$b[0]->getIsbn()][1] = 'crcnetbase';
                        }
                    }
                } elseif ($a[0]->getBindingStyle()->getCode() === 'EBVB' && ($a[1] == 'vitalsource' || $a[1] == 'both')) {

                    // there's a EBVB so remove EBPD or EBEP
                    foreach ($productInfoIsbns as $b) {

                        $isEbook = $b[0]->getBindingStyle() != null &&
                            ($b[0]->getBindingStyle()->getCode() === 'EBPD'
                                || $b[0]->getBindingStyle()->getCode() === 'EBEP');

                        if ($isEbook && $b[1] == 'vitalsource') {

                            unset($productInfoIsbns[$b[0]->getIsbn()]);
                            $tmp = array_search($b[0]->getIsbn(), $relevantProductIsbns);
                            unset($relevantProductIsbns[$tmp]);
                        } elseif ($isEbook && $b[1] == 'both') {

                            // since its both an ebook and vitalsource book, instead of removing the book
                            // completely we are converting it into a crcnetbase book only
                            $productInfoIsbns[$b[0]->getIsbn()][1] = 'crcnetbase';
                        }
                    }
                } elseif  ($a[0]->getBindingStyle()->getCode() === 'EBPD' && ($a[1] == 'vitalsource' || $a[1] == 'both')) {

                    // there's a EBPD so remove EBEP
                    foreach ($productInfoIsbns as $b) {

                        $isEbook = $b[0]->getBindingStyle() != null && $b[0]->getBindingStyle()->getCode() === 'EBEP';

                        if ($isEbook && $b[1] == 'vitalsource') {

                            unset($productInfoIsbns[$b[0]->getIsbn()]);
                            $tmp = array_search($b[0]->getIsbn(), $relevantProductIsbns);
                            unset($relevantProductIsbns[$tmp]);
                        } elseif ($isEbook && $b[1] == 'both') {

                            // since its both an ebook and vitalsource book, instead of removing the book
                            // completely we are converting it into a crcnetbase book only
                            $productInfoIsbns[$b[0]->getIsbn()][1] = 'crcnetbase';
                        }
                    }
                } elseif (in_array($a[0]->getBindingStyle()->getCode(), array('EBE3', 'EBVB', 'EBPD', 'EBEP', 'EBGE')) === false) {
                    // means not a EBE3 / EBVB / EBPD / EBEP / EBGE so this item should be removed
                    unset($productInfoIsbns[$a[0]->getIsbn()]);
                    $tmp = array_search($a[0]->getIsbn(), $relevantProductIsbns);
                    unset($relevantProductIsbns[$tmp]);
                }
            }
        }

        if(!empty($relevantProductIsbns)) {
            $currency = self::$currencyService->curencyForCountry($region);

            $prices = self::$priceService->lookupPriceListForProductIsbns(array_values($relevantProductIsbns), $currency, $promo);

            $prices = Utility::ensureArray($prices);

            foreach ($prices as $p) {
                if ($p !== false) {

                    $rentalPrices = array();
                    $webRestricted = false;
                    $priceList = array();
                    $lp = '';
                    $binding = '';
                    $bindingstyle = '';
                    if (is_object($productInfoIsbns[$p->isbn][0]->getBinding())) {
                        $binding = $productInfoIsbns[$p->isbn][0]->getBinding()->getCode();
                    }
                    if (is_object($productInfoIsbns[$p->isbn][0]->getBindingStyle())) {
                        $bindingstyle = $productInfoIsbns[$p->isbn][0]->getBindingStyle()->getCode();
                    }
                    $currencySymbol = $currency->getSymbol();

                    if(!empty($p->productId)){
                        $webRestricted  = $this->isWebRestricted($p->productId, $currencySymbol);
                    }

                    $eBookAssociation = $productInfoIsbns[$p->isbn][1];

                    if ($binding == 'EBK' && $eBookAssociation === 'vitalsource') {

                        $vitalSourceIsbn = $p->isbn;
                    } elseif ($binding == 'EBK' && $eBookAssociation === 'crcnetbase') {
                        if ($bindingstyle == 'EBPD') {
                            $crcNetBaseIsbn = $p->isbn;
                            $ebpdbisbnfound = true;
                        } elseif ($bindingstyle == 'EBGE' && ! $ebpdbisbnfound && isset($p->productId)) {
                            $crcNetBaseIsbn = $this->getDigiformadbisbn($p->productId, $bindingstyle);
                        }
                    } elseif ($binding == 'EBK' && $eBookAssociation === 'both') {

                        $vitalSourceIsbn = $p->isbn;
                        if ($bindingstyle == 'EBPD') {
                            $crcNetBaseIsbn = $p->isbn;
                            $ebpdbisbnfound = true;
                        } elseif ($bindingstyle == 'EBGE' && ! $ebpdbisbnfound && isset($p->productId)) {
                            $crcNetBaseIsbn = $this->getDigiformadbisbn($p->productId, $bindingstyle);
                        }
                    } elseif($binding == 'EBK' && $eBookAssociation === null) {
                        $webRestricted = true;
                    }

                    if(isset($p->priceList)) {
                        $priceList = Utility::ensureArray($p->priceList);
                    }

                    //getRentalPrices
                    if($vitalSourceIsbn !== null && $binding == 'EBK') {
                        $rentalPrices = Product::getRentalPrices($priceList);
                    }

                    //Get the LP price
                    foreach($priceList as $pl) {
                        if($pl->getPriceType()->getCode() == 'LP') {
                            $lp = $pl;
                            break;
                        }
                    }

                    //Does this book have any awards
                    $awards = $this->hasAwardClassifications($p->productId);

                    if($lp instanceof Price) {
                        $validPrice = true;

                        $result[] = array(
                            "product"            => $productInfoIsbns[$p->isbn][0],
                            "price"              => number_format($lp->getListPrice(), 2),
                            "discountprice"      => number_format($lp->getRealPrice(), 2),
                            "discounted"         => $lp->getDiscounted(),
                            "discountpercentage" => $lp->getDiscountPercentage(),
                            "savings"            => number_format($lp->getDiscount(),2),
                            "symbol"             => $currency->getSymbol(),
                            "sku"                => $productInfoIsbns[$p->isbn][0]->getSku(),
                            "isbn"               => $p->isbn,
                            "binding"            => $binding,
                            "bindingName"        => View::getBindingNameFromCode($binding),
                            "association"        => $eBookAssociation,
                            'webRestricted'      => $webRestricted,
                            'vitalSourceIsbn'    => $vitalSourceIsbn,
                            'crcNetBaseIsbn'     => $crcNetBaseIsbn,
                            'rentalPrices'       => $rentalPrices,
                            'rentalOnly'         => false,
                            'awards'             => $awards,
                            'relatedBindingsIsbn' => $relatedBindingsIsbn
                            // for Google Preview
                        );
                    } elseif(empty($lp) && !empty($rentalPrices)) {
                        $validPrice = true;

                        $result[] = array(
                            "product"            => $productInfoIsbns[$p->isbn][0],
                            "symbol"             => $currency->getSymbol(),
                            "sku"                => $productInfoIsbns[$p->isbn][0]->getSku(),
                            "isbn"               => $p->isbn,
                            "binding"            => $binding,
                            "bindingName"        => View::getBindingNameFromCode($binding),
                            "association"        => $eBookAssociation,
                            'webRestricted'      => $webRestricted,
                            'vitalSourceIsbn'    => $vitalSourceIsbn,
                            'crcNetBaseIsbn'     => $crcNetBaseIsbn,
                            'rentalPrices'       => $rentalPrices,
                            'rentalOnly'         => true,
                            'awards'             => $awards,
                            'relatedBindingsIsbn' => $relatedBindingsIsbn
                            // for Google Preview
                        );
                    } else {
                        $result[] = array(
                            "product"            => $productInfoIsbns[$p->isbn][0],
                            "symbol"             => $currency->getSymbol(),
                            "sku"                => $productInfoIsbns[$p->isbn][0]->getSku(),
                            "isbn"               => $p->isbn,
                            "binding"            => $binding,
                            "bindingName"        => View::getBindingNameFromCode($binding),
                            "association"        => $eBookAssociation,
                            'webRestricted'      => true,
                            'vitalSourceIsbn'    => $vitalSourceIsbn,
                            'crcNetBaseIsbn'     => $crcNetBaseIsbn,
                            'rentalPrices'       => $rentalPrices,
                            'rentalOnly'         => false,
                            'awards'             => $awards,
                            'relatedBindingsIsbn' => $relatedBindingsIsbn
                            // for Google Preview
                        );
                    }
                }
            }

            // do not even include the price if it is restricted, unless it is the only binding for the search
            // this covers an edge case scenario CRC-3598
            if($validPrice) {
                foreach ($result as $r => $res) {
                    if ($res['webRestricted'] && count($result) > 1) {
                        unset($result[$r]);
                    }
                }
            } else {
                foreach ($result as $r => $res) {
                    if ($res['isbn'] != $isbn) {
                        unset($result[$r]);
                    }
                }
            }


            usort($result,
                Product::build_sorter('binding', Product::getBindingPricesDisplayPrecedence($result)));
        } else {
            $result = false;
        }

        return $result;

    }

    /**
     * Takes an array of products and returns an array with the formatted list of originators and the type
     *
     * @param array $products
     *
     * @return array $originators
     */
    public function getOriginators($products) {

        $originators = array();

        foreach($products as $product) {

            if (self::$config['memcached_enabled']) {
                $key        = "crc.product." . $product->getIsbn() . ".getOriginators";
                $oOriginators    = self::$memcached->get($key);
                if (!$oOriginators) {
                    $oOriginators = Utility::ensureArray(self::$productService->findOriginators($product->getId()));
                    self::$memcached->set($key, $oOriginators, self::$config['memcached_expire_getproduct']);
                }
            } else {
                $oOriginators = self::$productService->findOriginators($product->getId());
            }

            if($oOriginators) {
                $oOriginators = Utility::ensureArray($oOriginators);

                $originators[$product->getId()]['names'] = Product::createOriginatorsString(
                    Product::filterOriginators($oOriginators)
                );

                $originators[$product->getId()]['type'] = Product::getOriginatorType($oOriginators);
            } else {
                $originators[$product->getId()]['names'] = '';
                $originators[$product->getId()]['type']  = '';
            }
        }

        return $originators;
    }

    /**
     * Gets all the originators last names for a single product
     *
     * @param \TandF\Ecommerce\Entity\Product $product
     *
     * @return array $originators
     */
    public function getOriginatorsLastNames($product) {

        $originators = array();

        if($product instanceof \TandF\Ecommerce\Entity\Product) {

            if (self::$config['memcached_enabled']) {
                $key        = "crc.product." . $product->getIsbn() . ".getOriginators";
                $oOriginators    = self::$memcached->get($key);
                if (!$oOriginators) {
                    $oOriginators = Utility::ensureArray(self::$productService->findOriginators($product->getId()));
                    self::$memcached->set($key, $oOriginators, self::$config['memcached_expire_getproduct']);
                }
            } else {
                $oOriginators = Utility::ensureArray(self::$productService->findOriginators($product->getId()));
            }

            foreach (Product::filterOriginators($oOriginators) as $orig) {
                $originators[] = $orig->getLastName();
            }
        }

        return $originators;
    }

    /**
     * Returns an array with download items
     *
     * @param int $productId
     *
     * @return array $result
     */
    public function getDownloads($productId)
    {
        $result = array();

        $downloads = self::$productService->downloadsForProductId($productId);
        if ($downloads !== false) {
            $downloads = Utility::ensureArray($downloads);

            foreach ($downloads as $d) {
                $nicedate = date("F d, Y", strtotime($d->getUpdateDate()));
                $result[] = array(
                    'url' => self::$config['cdn_url'] . "/downloads/{$d->getSku()}/{$d->getFilename()}",
                    'filename' => $d->getFilename(),
                    'id' => $d->getId(),
                    'instruction' => $d->getInstruction(),
                    'platform' => $d->getPlatform(),
                    'sku' => $d->getSku(),
                    'subtitle' => $d->getSubtitle(),
                    'updatedate' => $nicedate
                );
            }
        }

        return $result;
    }

    /**
     * Returns the related binding for the product
     *
     * @param int $productId
     * @param string $region
     *
     * @return array $relatedBindings
     */
    public function getRelatedBindings($productId, $region)
    {
        $relatedBindings = self::$productService->findRelatedBindings(
            $productId,
            $region
        );

        $relatedBindings = Utility::ensureArray($relatedBindings);

        return $relatedBindings;
    }

    /**
     *  requirements for showing an Ebook link are detailed in:
     * http://usmia-jira01.crcpress.local:8080/browse/CRC-2498
     *
     * @param $product
     * @param $region
     *
     * @return null|string a string containing either 'vitalsource' or 'crcnetbase' upon success or NULL if ebook
     *  should not be shown
     */
    protected function shouldShowEbook($product, $region)
    {
        $enabledVitalSource = self::$config['enable_vitalsource'];
        $enabledCrcNetBase = self::$config['enable_crcnetbase'];

        // check Binding is either EBE3,EBVB, EBPD, EBEP or EBGE
        $bind = $product->getBindingStyle();
        if ($bind instanceof BindingStyleGT) {

            if (in_array($bind->getCode(), array('EBE3', 'EBVB', 'EBPD', 'EBEP', 'EBGE')) === false) {

                return null;
            }

        } else {
            return null;
        }

        // check to see if product's classification is one of the three codes that means it's available on either
        // the vitalsource or crcnetbase ebook delivery platforms. productHasAnyClassifications returns an array
        // of booleans where each boolean signals whether the code in the given array is one of the product's
        // classifications or not. if [0], then it's vital source valid, if [1] and [2] then it's crcnetbase valid.

        $classificationCodes = array(
            'VSINV',
            'WB0000',
            'EPRPNB',
			'EBRALL',
			'DRMN'
        );

        $hasClassification = self::$classificationService->productHasAnyClassifications(
            $product->getId(),
            $classificationCodes
        );

        if (is_array($hasClassification) && count($hasClassification) == 5) {

            //check to make vital source invalid is not set as a classification
            $isVitalSourceValid = $hasClassification[0] === true && $enabledVitalSource;

            //region check for vital source
            $isAllowedVitalSourceRegion = !$this->isBindingRestricted('EBK', $region);
            $hasCrcNetBaseClassification = false;
            
            // if crcnetbase is enabled
            if ($enabledCrcNetBase) {
                //CRC-4995,CRC-5044 does it contain all the necessary new netbase classifications to display CRC-5072
                if ($hasClassification[1] === true && $hasClassification[2] === true && $bind->getCode() == 'EBPD') {
                    $hasCrcNetBaseClassification = true;
                } elseif ($bind->getCode() == 'EBGE' && $hasClassification[1] === true && $hasClassification[3] === true && $hasClassification[4] === true && $product->getInventoryStatus()->getCode() == 'LFB' && $this->getDigiformadbisbn($product->getId(), $bind->getCode())) {
                    $hasCrcNetBaseClassification = true;
                } 
            }
            // show vital source book only if it has classification
            $isVitalSourceAllowed = $isVitalSourceValid && $isAllowedVitalSourceRegion;

            if ($isVitalSourceAllowed && $hasCrcNetBaseClassification) {

                return 'both';
            } elseif ($isVitalSourceAllowed) {

                return "vitalsource";
            } elseif ($hasCrcNetBaseClassification) {

                return "crcnetbase";
            }
        }

        return null;
    }

    /**
     * Determines if a binding type is restricted for a specific region
     *
     * @param String $bindingType
     * @param String $region
     * @return boolean
     */
    protected function isBindingRestricted($bindingType, $region)
    {
        $bindingIsRestricted = false;
        $currency            = self::$currencyService->curencyForCountry($region); // The currency for the specified region
        if ($currency != null && $currency instanceof \TandF\Ecommerce\Entity\Currency) {

            $currencyCode       = $currency->getIsoName(); // The currency code for the currency
            $bindingTypeIsEbook = $bindingType == 'EBK';

            if ($bindingTypeIsEbook) {
                $ebookSaleAllowedForCurrency = !in_array
                (
                    $region,
                    self::$config['ebook_restricted_regions']
                );

                if (!$ebookSaleAllowedForCurrency) {
                    $bindingIsRestricted = true;
                }
            }
        }
        return $bindingIsRestricted;
    }

    /**
     * Determines if a product is web restricted
     *
     * @param int $productId
     * @param String $symbol
     * @return boolean
     */
    private function isWebRestricted($productId, $symbol)
    {

        // check for web restrict
        $classifications = self::$classificationService->getProductClassifications($productId);
        foreach ($classifications as $c) {

            if (($symbol == '$' && $c->getCode() == 'WRUS') || ($symbol == '&pound;' && $c->getCode() == 'WRUK')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns an array of subjects that will make up the breadcrumb trail
     *
     * As per requirements on
     * http://confluence.crcpress.local:8060/display/CRCP/Business+logic+for+Breadcrumbs+on+Product+Page
     * we do not want a crumb if it starts with "SC".
     *
     * Logic is to get categories for product, find first category that does not begin with "SC"
     * and then find all ancestors of that  category. Breadcrumb trail is ancestors + first non-SC.
     *
     * @param Product $product
     *
     * @return array $result
     */
    public function getBreadCrumbs($product)
    {
        $result = array();

        $cats = $product->getCategories();
        $use = null;
        foreach ($cats as $c) {
            // instanceof added by Bruce for http://usmia-jira01.crcpress.local:8080/browse/CRC-2715
            if (($c instanceof \TandF\Ecommerce\Entity\Category)) {

                if ((substr($c->getCode(), 0, 2) == "SC") === false) {
                    $use = $c;
                    break;
                }
            }
        }

        if ($use != null) {
            $crumbs = self::$categoryService->findAncestorsOf($use->getId());
            $crumbs = array_reverse($crumbs);

            // now convert to array for use by Smarty
            foreach ($crumbs as $c) {
                // ignore root CRC category
                if ($c->getCode() != "CRC") {

                    $result[] = array(
                        "title" => $c->getTitle(),
                        "code" => self::$config['category_maps'][$c->getCode()],
                    );
                }
            }
            $result[] = array(
                "title" => $use->getTitle(),
                "code" => self::$config['category_maps'][$use->getCode()],
            );
        }

        return $result;
    }

    /**
     * Gets related titles from cache
     *
     * @param int $productId
     * @param string $region
     *
     * @return array $related
     *
     */
    public function getRelatedTitlesFromCache($productId, $region)
    {
        if (self::$config['memcached_enabled']) {

            $key = "crc.product.$productId.$region.getRelatedTitles";
            $related = self::$memcached->get($key);
            if (!$related) {
                $related = $this->getRelatedTitles($productId, $region);
                self::$memcached->set($key, $related, self::$config['memcached_expire_getrelatedtitles']);
            }
        } else {
            $related = $this->getRelatedTitles($productId, $region);
        }

        return $related;
    }
    /**
     * Gets related titles for a product ID
     *
     * @param int $productId
     * @param string $region
     * @return array $result
     */
    public function getRelatedTitles($productId, $region)
    {
        $related = self::$productService->findRelatedBooksFor($productId, $region);
        if ($related === false) {
            return array();
        }

        $related = Utility::ensureArray($related);

        // now convert to array for use by Smarty
        $result = array();
        foreach ($related as $r) {
            $title = $r->getName();
            $title .= $r->getSubtitle() != "" ? ": " . $r->getSubtitle() : "";

            /*$authors = "";
            $tmp = self::$productService->findOriginators($r->getId());
            if (is_array($tmp) === false) {
                $tmp = array(
                    $tmp
                );
            }
            foreach ($tmp as $a) {
                if ($a != null && $a instanceof TandF\Ecommerce\Entity\Originator) {
                    if (in_array(
                        $a->getRole()->getCode(),
                        array(
                            "VE",
                            "HG"
                        )
                    )
                    ) {
                        $authors .= $authors !== "" ? "; " : "";
                        $authors .= str_replace(
                            "  ",
                            " ",
                            $a->getFirstName() . " " . $a->getMiddleInitial() . " " . $a->getLastName()
                        );
                    }
                }
            }*/

            $result[] = array(
                "title" => $title,
                "isbn" => $r->getIsbn(),
                "coverimageurl" => View::getImageLink($r->getIsbn(), 'large'),
                "authors" => $this->getOriginatorsLastNames($r)
            );
        }

        return $result;
    }

    /**
     * http://usmia-jira01.crcpress.local:8080/browse/CRC-2502
     *
     * If one of the authors of this product has an entry in the "Featured Authors" app then
     * we need to show that in the "Author Bio(s)" section. If not we show the author info from
     * the product entity from e-commerce core. If neither exists then we don't show a Bio section.
     *
     * Gets JSON from the featured authors application to check whether there is content
     * available for this product. Note that this uses the ExpessionEngineAgent library
     * to retrieve the JSON even though Featured Authors is not EE-related. The ExpressionEngineAgent
     * library does everything we need: GETs a URL and validates the JSON response.
     *
     * @param string $isbn
     * @return array $fa
     *
     */
    public function getFeaturedAuthors($isbn)
    {
        $fa = null;
        $data = ['http' =>
                     [
                         'method'  => 'POST',
                         'header'  => 'Content-type: application/x-www-form-urlencoded',
                         'content' => http_build_query(array('appsecret' => self::$config['fa_secret']))
                     ],
                 "ssl" =>
                     [
                         "verify_peer" => false,
                         "verify_peer_name" => false,
                     ],
        ];
        try {

            $token = json_decode(file_get_contents(self::$config['fa_url'] . 'services/ws-featured-authors-get-token', false, stream_context_create($data)));
            $data['http']['content'] = http_build_query(array('token' => $token->Data->token));
            $authors = json_decode(file_get_contents(self::$config['fa_url'] . 'services/ws-featured-authors-get-book-contributors/' . $isbn, false, stream_context_create($data)));
            if(isset($authors->Data->count) && $authors->Data->count > 0) {
                foreach ($authors->Data->contributors as $author) {
                    $result = json_decode(file_get_contents(self::$config['fa_url'] . 'services/ws-featured-authors-get-profile/' . $author->id_profile, false, stream_context_create($data)));

                    if(isset($result->Data->profile)) {
                        $fa[] = $result->Data->profile;
                    }
                }
            }
        } catch (\Exception $e) {
            self::$logger->logMessage(
                'error',
                'Exception (' . $e->getMessage() . ') trying to check Featured Authors API for ' . $isbn
            );
        }

        return $fa;
    }

    /**
     * get the companion website url for the product
     *
     * @param $productId
     *
     * @return string containing the url of the companion website if exists or null
     */
    public function getCompanionWebsite($productId)
    {
        $companion_url = null;

        // check if it has companion site classification
        $hasClassification = self::$classificationService->productHasClassification($productId, 'COMPSITE');
        if ($hasClassification) {

            // get classifications for the product
            $classifications = self::$classificationService->getProductClassifications($productId);
            foreach ($classifications as $classification) {

                // iterate over each classification till we find compsite
                if ($classification->getCode() == 'COMPSITE') {

                    $companion_url = $classification->getValueString();

                    //Append http:// if it doesn't have it ...
                    if(strpos($companion_url, 'http') === false) {
                        $companion_url = 'http://' . $companion_url;
                    }

                    // check if valid url
                    if (filter_var($companion_url, FILTER_VALIDATE_URL)) {

                        return $companion_url;
                    }
                }
            }
        }

        return $companion_url;
    }

    /**
     * get the DIGIFORMADBISBN for the product ID
     *
     * @param
     *            $productId
     *            $bindingstyle
     *            
     * @return containing the DIGIFORMADBISBN value if exists or null
     */
    public function getDigiformadbisbn($productId, $bindingstyle)
    {
        $digiformadbisbn = null;
        //CRC-5073 check for digiformisbn only in case of EBGE
        if ($productId && ctype_digit($productId) && $bindingstyle == 'EBGE') {
            // check if it has DIGIFORMADBISBN classification
            $hasClassification = self::$classificationService->productHasClassification($productId, 'DIGIFORMADBISBN');
            if ($hasClassification) {
                
                // get classifications for the product
                $classifications = self::$classificationService->getProductClassifications($productId);
                foreach ($classifications as $classification) {
                    
                    // iterate over each classification till we find DIGIFORMADBISBN
                    if ($classification->getCode() == 'DIGIFORMADBISBN') {
                        
                        $digiformadbisbn = $classification->getValueString();
                        
                        // check if value is a number
                        if (ctype_digit($digiformadbisbn)) {
                            return $digiformadbisbn;
                        }
                    }
                }
            }
        }
        return $digiformadbisbn;
    }

    /**
     * Gets a cover image for an isbn
     *
     * @param string $isbn
     * @param string $id
     * @param string $size
     * @return array $data
     */
    public function getCoverImage($isbn, $id, $size){
        $data = array();

        if(self::$config['memcached_enabled']) {

            $key = "crc.images.$isbn.$size.checkBindings";
            $mResult = self::$memcached->get($key);

            if(!$mResult) {
                $mResult = $this->checkBindings($isbn, $id, $size);
                self::$memcached->set($key, $mResult, self::$config['memcached_expire_coverimages']);
            }
        } else {
            $mResult = $this->checkBindings($isbn, $id, $size);
        }

        if (!$mResult) {

            self::$logger->logMessage('debug', 'No images found using default cover');

            if ($size == 'small') {

                $data['isbn'] = $isbn;
                $data['url'] = self::$config['product_jacket_url_default'];
                $data['id'] = $id;

            } else {

                $data['isbn'] = $isbn;
                $data['url'] = str_replace('default.jpeg', 'notavail-big.jpg',
                    self::$config['product_jacket_url_default']);
                $data['id'] = $id;

            }
        } else {

            $data['isbn'] = $isbn;
            $data['url'] = $mResult;
            $data['id'] = $id;

        }

        return $data;
    }

    /**
     * Uses the ecommerce api to check all available bindings for a product to see if a cover
     * image is available for the binding.
     *
     * Method will return the URL if a valid image is found or will return false
     * if no image exists.
     *
     * @param string $sIsbn Product ISBN
     * @param string $sId Product Id
     * @param string $sSize Image size
     * @return string
     */
    public function checkBindings($sIsbn, $sId, $sSize)
    {

        Cover::setConnectTimeout((int) self::$config['covers_config_connecttimeout']);
        Cover::setTimeout((int) self::$config['covers_config_timeout']);

        $mResult = false;

        if (is_string($sIsbn) && is_string($sSize)) {

            self::$logger->logMessage('debug', 'Checking images for isbn: ' . $sIsbn . ' id is ' . $sId);

            $mResult = Cover::checkCoverImageForIsbn($sIsbn, $sSize);

            if (!$mResult) {

                //Check related bindings for an image
                try {

                    if($sId == 0) {
                        $product    = self::$productService->findBookByIsbn($sIsbn);
                        $sId        = $product->getId();
                    }

                    $mBindings = self::$productService->findRelatedBindings((int) $sId);
                } catch (\Exception $e) {

                    self::$logger->logMessage('error', $e->getMessage() . ' in ' . __METHOD__);
                }

                //Bindings can be returned as an array of Products or a single instance
                if (\is_array($mBindings)) {

                    self::$logger->logMessage('debug', 'Array of related bindings found for: ' . $sIsbn);

                    foreach ($mBindings as $oProduct) {

                        if (($oProduct instanceof \TandF\Ecommerce\Entity\Product)) {

                            $mResult = Cover::checkCoverImageForIsbn($oProduct->getIsbn(), $sSize);

                            if (\is_string($mResult)) {

                                break;
                            }
                        }
                    }
                } elseif (($mBindings instanceof \TandF\Ecommerce\Entity\Product)) {

                    self::$logger->logMessage('debug', 'Found single related binding for : ' . $sIsbn);

                    $mResult = Cover::checkCoverImageForIsbn($mBindings->getIsbn(), $sSize);
                }
            }
        }

        return $mResult;
    }

    /**
     * Return the currency object for the region
     *
     * @param $region
     * @return  Currency Object|bool
     */
    public function getRegionCurrency($region)
    {
        if ($region) {
            $currency = self::$currencyService->curencyForCountry($region);
            return $currency;
        }
        return false;
    }

    /**
     * Determine if a product has a particular classification
     *
     * @param $productId
     * @param $classification
     * @return bool
     */
    public function hasClassification($productId, $classification)
    {
        $rtn = false;
        if ($productId && $classification) {
            if (self::$classificationService->productHasClassification($productId, $classification)) {
                $rtn = true;
            }
        }
        return $rtn;
    }

    /**
     * Returns the list of products that have been updated by GT since the date provided
     *
     * @param string $date Date formatted Y-m-d
     * @param int $start
     * @param int $limit
     * @return array
     */
    public function getUpdatedProducts($date, $start = 0, $limit = 50)
    {
        if ($date) {
            $rtn = array();
            $updated = self::$productService->isbnListUpdatedSince($date, $start, $limit);
            if ($limit === 1) {
                $rtn[] = $updated;
            } else {
                $rtn = $updated;
            }
            return $rtn;
        }
        return false;
    }

    /**
     * Checks if the product is a CPD / Shingo / Choice title
     *
     * @param int $id
     * @return bool
     */
    public function hasAwardClassifications($id)
    {
        $result = [];
        $awards = self::$classificationService->productHasAnyClassifications($id, ['CPD','WDAC','WDAS']);

        //Set the results array to be more readable
        if($awards[0]) {
            $result['CPD'] = true;
        } elseif ($awards[1]) {
            $result['WDAC'] = true;
        } elseif($awards[2]) {
            $result['WDAS'] = true;
        }

        return $result;
    }

    /**
     * Gets product if it's a CPD title
     *
     * @param string $isbn
     * @return bool
     */
    public function getCpdProduct($isbn)
    {
        $product = $this->getProduct($isbn);
        if($product instanceof \TandF\Ecommerce\Entity\Product) {
            $isCpd = self::$classificationService->productHasClassification($product->getId(), 'CPD');
            if($isCpd) {
                return $product;
            }
        }
        return false;
    }


    /**
     * Gets a list of originators for a single product
     *
     * @param Product $product
     *
     * @return array $oOriginators
     */
    public function getOriginatorList($product) {

        if (self::$config['memcached_enabled']) {
            $key        = "crc.product." . $product->getIsbn() . ".getOriginators";
            $oOriginators    = self::$memcached->get($key);
            if (!$oOriginators) {
                $oOriginators = Utility::ensureArray(self::$productService->findOriginators($product->getId()));
                self::$memcached->set($key, $oOriginators, self::$config['memcached_expire_getproduct']);
            }
        } else {
            $oOriginators = Utility::ensureArray(self::$productService->findOriginators($product->getId()));
        }

        return Product::filterOriginators($oOriginators);
    }

    /**
     * Gets all prices (USD & GBP) for an ISBN
     *
     * @param string $isbn
     * @return array $prices
     */
    public function getAllPrices($isbn) {
        $prices = [];

        //Set USD
        $usd = new Currency();
        $usd->setId(1001);
        $usd->setIsoName('USD');

        //Set GBP
        $gbp = new Currency();
        $gbp->setId(1002);
        $gbp->setIsoName('GBP');

        $prices['USD'] = self::$priceService->lookupPriceByISBN($isbn, $usd);
        $prices['GBP'] = self::$priceService->lookupPriceByISBN($isbn, $gbp);
        return $prices;
    }
}