<?php
/**
 * This class extends the generic model base class behaviour and
 * introduces CRC-specific behaviour.
 *
 * @package CrcFramework
 * @subpackage Model
 * @author Stephen Hearn
 */

namespace TandF\Models;

use TandF\Ecommerce\EcommerceAPI;
use TandF\EE\MarketingContent;
use TandF\Helpers\Log;
use TandF\Helpers\Utility;

abstract class BaseModel {

    protected static $productService = false;

    protected static $cartService = false;

    protected static $priceService = false;

    protected static $currencyService = false;

    protected static $classificationService = false;

    protected static $orderService = false;

    protected static $inventoryService = false;

    protected static $emailService = false;

    protected static $memcached = false;

    protected static $shopperService = false;

    protected static $profileService = false;

    protected static $invoiceManagerService = false;

    protected static $categoryService = false;

    protected static $wishListService = false;

    protected static $logService = false;

    protected static $seriesService = false;

    protected static $countryService = false;

    protected static $ecommerceapi = false;

    protected static $marketingcontent = false;

    protected static $shippingService = false;

    protected static $searchService = false;

    protected static $geoLocatorService = false;

    protected static $logger = false;

    protected static $config = false;

    public function __construct($config) {
        if(self::$config === false) {
            self::$config = $config;
        }

        if(self::$logger === false) {
            self::$logger = new Log(self::$config['log_path'], self::$config['log_file_name'], self::$config['log_threshold'], self::$config['log_date_format']);
        }

        if(self::$ecommerceapi === false) {
            $this->loadEcommerceApi();
        }

        if(self::$memcached === false) {
            $this->getMemcached();
        }

        if(self::$marketingcontent === false) {
            $this->getMarketing();
        }

        if(self::$currencyService === false) {
            self::$currencyService = self::$ecommerceapi->getService('Currency');
        }

        if(self::$classificationService === false) {
            self::$classificationService = self::$ecommerceapi->getService('Classification');
        }
    }

    /**
     * Loads the EcommerceAPI library using config entries read in the constructor.
     */
    protected function loadEcommerceApi()
    {

        $aArgs = array(
            'host'                 => self::$config['ecomm_host'],
            'app_login'            => self::$config['ecomm_login'],
            'app_pass'             => self::$config['ecomm_pass'],
            'extra_config'         => self::$config['ecomm_configuration'],
            //'verbosity' => 1,
        );

        self::$ecommerceapi = new EcommerceAPI($aArgs);
    }

    /**
     * Gets the current memcached connection object
     *
     */
    protected function getMemcached()
    {
        self::$memcached = new \Memcached('crcpress');

        if (!count(self::$memcached->getServerList())) {
            self::$memcached->addServers(self::$config['memcached_servers']);
        }
    }

    /**
     *  Gets the marketing EE API
     */
    protected function getMarketing() {
        $aCMSArgs = array();
        $aCMSArgs['ee_config_timeout'] = self::$config['ee_config_timeout'];
        $aCMSArgs['ee_config_connecttimeout'] = self::$config['ee_config_connecttimeout'];

        //load the marketing content library, which is used to retrieve content from ExpressionEngine
        self::$marketingcontent = new MarketingContent($aCMSArgs);
    }

    /**
     * Loads a model from a package
     *
     * @param array|string $models
     */
    protected function loadModel($models) {
        $models = Utility::ensureArray($models);
        foreach($models as $model) {
            if (!isset($this->$model)) {
                $class = '\TandF\Models\\'. $model;
                $this->$model = new $class(null);
            }
        }
    }

    /**
     * returns restricted items for the shipping region
     *
     * @param string $shipRegion 2 letter iso 3166 country code
     * @param mixed $items
     *
     * @return array
     */
    protected function getRestricted($shipRegion = null, $items = array()) {

        $restricted = array();
        foreach ($items as $i => $item) {
            $binding = $item->getProduct()->getBinding()->getCode();
            $productId = $item->getProduct()->getId();
            $webRestrictCode = 'WRUS';
            $currencySymbol = self::$currencyService->curencyForCountry($shipRegion);
            if ($currencySymbol->getSymbol() == '&pound;') {
                $webRestrictCode = 'WRUK';
            }
            // this covers ebooks
            if (in_array($shipRegion, self::$config['ebook_restricted_regions']) && $binding == 'EBK') {
                $restricted[$item->getProduct()->getIsbn()] = $item->getProduct();
                continue;
            }
            // this covers web restrictions
            if (self::$classificationService->productHasClassification($productId, $webRestrictCode)) {
                $restricted[$item->getProduct()->getIsbn()] = $item->getProduct();
            }
        }

        return $restricted;
    }

} 