#!/usr/bin/php

<?php
require_once 'config.php';

use import\types\mobileApp AS app;
use import\currency AS currency;
use import\exceptions AS ex;
use import\helpers AS help;
use import\territory\territory AS territory;

/**
 * Appland Catalog Import Class
 */
class applandCatalog extends applandConfig {
    
    /***************************************************************************
     * OPTIONS                                                                 *
     ***************************************************************************/
    
    /**
     * Auth Values
     * 
     * @var ARRAY
     */
    private $auth = array(
        'secret' => 'HcMzW0durbxbxtyCb1XMBH1NyiUYaysP',
    );
    
    /**
     * Appland API Endpoint URLs
     * 
     * @var ARRAY
     */
    private $url = array(
        'list'=>'http://feed.appland.se/api/feed/c/listV1/store/TFC/start/{START}/count/{PAGE_SIZE}/p/simpleListV1/tfcListV1/a/tfc/{SECRET}',
        'detail'=>'http://feed.appland.se/api/feed/c/detailV1/store/TFC/id/{ITEM_ID}/p/clone/a/tfc/{SECRET}',
        'listDetail'=>'http://feed.appland.se/api/feed/c/listDetailV1/store/TFC/start/{START}/count/{PAGE_SIZE}/p/simpleListV1/imageUriFullSizeV1/tfcListV1/a/tfc/{SECRET}',
    );
    
    /**
     * Use Cached Product Data Instead of New Download
     * 
     * @var BOOL
     */
    private $useCachedApps = true;
    
    /**
     * Catalogue Pagination Size
     * 
     * @var INT
     */
    private $pageSize = 100;
    
    /**
     * Download limit
     * 
     * -1 for unlimited
     * 
     * @var INT
     */
    private $limit = 1000000000;
    
    /**
     * Skip Pages
     * 
     * 0 to process all
     * 
     * @var INT
     */
    private $skipToPage = 0;
    
    /**
     * Times to retry a failing cURL request
     * 
     * @var int
     */
    private $curlRetries = 10;
    
    /**
     * Catalogue Pagination Sort By
     * 
     * @var type 
     */
    private $pageSort = 'id';
    
    /**
     * Cache Magazine Data to Disk
     * 
     * @var BOOL
     */
    private $cacheApps = true;
    
    /**
     * Determine whether to download and process images or not
     *
     * @var BOOL
     */
    private $downloadImages = true;
    
    /**
     * Time that images are valid for in seconds (604800 is 7 days)
     *
     * @var INT
     */
    private $imageTtl = 42336000; // 70 days
    
    /**
     * Determine whether to save to S3 for CDN access or not
     *
     * @var BOOL
     */
    private $saveToS3 = true;
    
    /**
     * Determine whether to check the DB for existing products or not
     *
     * When checking, DB load will be higher and the process will take longer,
     * however it will also prevent duplicates so should only be disabled for
     * an initial import
     *
     * @var BOOL
     */
    private $checkDb = true;
    
    /**
     * Emagazines DB table
     * 
     * @var STRING
     */
    private $mobileTable = 'mobile';
    
    /**
     * Territory Availability DB table
     * 
     * @var STRING
     */
    private $territoryTable = 'mobile_territories';
    
    /**
     * Categories DB table
     * 
     * @var STRING
     */
    private $categoryTable = 'mobile_categories';

    /**
     * Permissions DB table
     * 
     * @var STRING
     */
    private $issuesTable = 'mobile_permissions';
    
    /**
     * Category Lookup DB table
     * 
     * @var STRING
     */
    private $categoryLookupTable = 'mobile_category';
    
    /**
     * Return curl request header in $response->info
     * 
     * @var BOOL
     */
    private $curlReqHeader = true;
    
    /**
     * Return curl response header in $response->info
     * 
     * @var BOOL
     */
    private $curlResHeader = true;
    
    /***************************************************************************
     * END OPTIONS                                                             *
     ***************************************************************************/
    
    /***************************************************************************
     * PROPERTIES                                                              *
     ***************************************************************************/
    
    private $authSession;
    private $currentPosition;
    private $product;
    private $positionCounter;
    private $categories;
    private $products;
    private $tfcCategories = array();
    private $tfcPlatforms = array();
    private $storageEngine;
    
    /***************************************************************************
     * END PROPERTIES                                                          *
     ***************************************************************************/
    
    
    /**
     * Appland Catalog Constructor
     */
    public function __construct($storageEngine)
    {
        $this->processStart(__FILE__);
        parent::__construct();
        $this->resetLog();
        $this->dbCheck();
        
        $this->out('Appland import started');
        $this->storageEngine = $storageEngine;
    }
    
    
    /**
     * Handle output from ProcessXMLFeed
     * 
     * @param STRING $name
     * @param STRING $msg
     * @return \applandCatalog
     */
    private function processOut($name, $msg=null)
    {
        if ($name !== $this->currentPosition){
            $this->out($msg);
            $this->positionCounter = 0;
        } else {
            $this->positionCounter++;
            if ($this->positionCounter%100 == 0){ // Show actual position every 100
                $this->out(' ['.$this->positionCounter.'] ', TFC_ECHO);
            } elseif ($this->positionCounter%10 == 0){ // Show a simple . for every 10
                $this->out('.', TFC_ECHO);
            }
        }
        return $this;
    }
    
    
    
    public function processInBatch($pageSize=null)
    {
        if (!is_int($pageSize)) { $pageSize = $this->pageSize; }
        
        $position   = 0;
        $page       = 1;
        
        while ($position < $this->limit || $this->limit === -1) {
            if ($position+$pageSize > $this->limit) {
                $nextPage = $this->limit;
                $pageSize = $this->limit-$position;
            } else {
                $nextPage = $position+$pageSize;
            }
            
            if ($nextPage - $position === 1) {
                $msg = "Getting item ".($position+1);
            } else {
                $msg = "Getting items ".($position+1)." to ".$nextPage;
            }
            $this->out($msg." (page $page)", TFC_INFO_DETAIL);
            
            $page++;
            if ($page <= $this->skipToPage){
                $this->out("Skipping to next page", TFC_INFO_DETAIL);
                $position += $this->pageSize;
                continue;
            }
            
            $this->response = $this->curlRequest(
                str_replace(
                    array('{START}', '{PAGE_SIZE}', '{SECRET}'),
                    array($position, $pageSize, $this->auth['secret']),
                    $this->url['listDetail']),
                array()
            );
            
            if (isset($this->response->data)) {
                $decoded    = json_decode($this->response->data, true);
                if ($decoded) {
                    foreach ($decoded AS $d) {
                        $position++;
                        $this->newApp($d);
                        try {
                            if ($this->product) {
                                $this->product->save();
                            }
                        } catch (ex\mobileAppException $e) {
                            $this->out($e->getMessage(), TFC_CRITICAL);
                        }
                        if ($position >= 10) {
                            #die("\n\nDone\n\n");
                        }
                    }
                } else {
                    file_put_contents('data_decode_error.txt', print_r($this->response, true));
                    $this->out("Unable to decode data from Appland API", TFC_CRITICAL);
                }
            } else {
                $this->out("No date received from Appland API", TFC_CRITICAL);
            }
        }
        
        $this->out("Batch complete", TFC_INFO);
    }
    
    
    private function newApp($data)
    {
        $this->product = new app($this->db);
        try {
            
            if (!is_array($data['description'])) {
                $this->out("Aborting app. Description is not an array.", TFC_INFO_DETAIL);
                file_put_contents("DebugLog.txt", print_r($this->response, true), FILE_APPEND);
                $this->product = null;
                return $this;
            }
            $desc = reset($data['description']);
            if (!$desc) {
                $this->out("Description error: ".$data['description'], TFC_ERROR);
                $this->out("Aborting app", TFC_INFO_DETAIL);
                return $this;
            }
            #file_put_contents('app.json', json_encode($data));
            $descriptions = array();
            foreach ($data['description'] AS $code=>$details) {
                $description = import\language\language::getLang($code);
                $description->title = $details['title'];
                $description->short = $details['shortDescription'];
                $description->long  = $details['description'];
                $descriptions[] = $description;
            }
            $extension = pathinfo($data['imageUri']['icon'], PATHINFO_EXTENSION);
            
            $imagesDir      = realpath(tfcConfig::$_CFG_IMAGES_PATH).DIRECTORY_SEPARATOR;
            $tmpImgDir      = realpath($imagesDir.tfcConfig::$_CFG_PATH_TMP_IMAGES).DIRECTORY_SEPARATOR;
            $tmpFileName    = end(explode("/", $data['imageUri']['icon']));
            $subDirs        = $this->_CFG_PROVIDER_ID['mobile'].'/'.$this->getImagePath($this->_CFG_IMAGES_PATH['mobile'], $data['appId']);
            $mediumImage    = $data['appId']."_medium.png";//.$extension;
            $largeImage     = $data['appId']."_big.png";//.$extension;
                        
            /**
             * Images should go here (example data):
             * 
             * Image File:  unnamed_2.5328058b1e720.png                     $tmpFileName
             * Images Dir:  E:\httpdocs\tfc.dev\feed_imports\images\        $imagesDir
             * Tmp Dir:     E:\httpdocs\tfc.dev\feed_imports\images\tmp     $tmpImgDir
             * Sub Dir:     18/19/00 (provider/pr/id)                       $subDirs
             * Medium Img:  19002_medium.png                                $mediumImage
             * Large Img:   19002_big.png                                   $largeImage
             */
            
            // Set properties now
            $this->product->setProviderId($this->_CFG_PROVIDER_ID['mobile'])
                          ->setProviderProductId($data['appId'])
                          ->setTitle((string)$desc['title'])
                          ->setDescription($descriptions)
                          ->setPublisher((string)$data['company'])
                          ->setPlatform($this->newPlatform($data['clientPlatform']))
                          ->setPrice($this->newCurrency($data['currency'], $data['price']))
                          ->setCategory((int)$data['category']['parentCategory'], $data['category']['parentCategoryName'])
                          ->setSubCategory((int)$data['category']['subCategory'], $data['category']['subCategoryName'])
                          ->setReleaseDate(new \DateTime(isset($data['lastModified']) ? '@'.$data['lastModified'] : '@00000000'))
                          ->setDevices(isset($data['androidFeatures'], $data['androidFeatures']['devices']) ? $data['androidFeatures']['devices'] : array())
                          ->setImageDir($this->getImagePath($this->_CFG_IMAGES_PATH['mobile'], $data['appId']))
                          ->setImage(new help\images($data['imageUri']['icon'], $this->storageEngine,
                                // Provide Image config
                                array(
                                    'tmpFile' => $tmpFileName,  // The filename ONLY of the temporary file to save the image as
                                    'tmpDir' => $tmpImgDir,     // Where to save the temporary image
                                    'imgDir' => $imagesDir,     // Where to save the processed images
                                    'ttl' => $this->imageTtl,   // How long cached temp images are valid for
                                    'engineDir' => $subDirs,    // Subdirectories to save the files within
                                    'resizes' => array(
                                        // Medium
                                        array(
                                            'x' => 127, // Resize X
                                            'y' => 127, // Resize Y
                                            'saveAs' => $mediumImage // Filename for medium resized image
                                        ),
                                        // Large
                                        array(
                                            'x' => 210, // Resize X
                                            'y' => 210, // Resize Y
                                            'saveAs' => $largeImage // Filename for medium resized image
                                        ),
                                    ),
                                )
                            ))
                          #->setScreenShots($screenshots)
                          ->setTerritories(array(territory::ALL))
                          ->setPermissions(isset($data['androidFeatures'], $data['androidFeatures']['permissions']) ? $data['androidFeatures']['permissions'] : array())
                    ;
            
            
        } catch (ex\mobileAppException $e) {
            $this->out($e->getMessage(), TFC_WARNING);
        } catch (ex\currencyException $e) {
            $this->out($e->getMessage(), TFC_WARNING);
        } catch (ex\storageEngineException $e) {
            $this->out($e->getMessage(), TFC_WARNING);
        } catch (ex\imageException $e) {
            $this->out($e->getMessage(), TFC_INFO_DETAIL);
        }
        
        return $this;
    }
    
    
    private function newPlatform($id)
    {
        if (!is_int($id)) { throw new ex\mobileAppException('Platform ID must be an integer. "'.$id.'" passed to '.__METHOD__); }
        
        switch ($id) {
            case 1:
                $platform = 'Android';
                break;
            default:
                $platform = 'Unknown';
        }
        return $platform;
    }
    
    
    /**
     * New Currency Object
     * 
     * @param string $currency
     * @param float|int $value
     * @return \import\currency\usd
     * @throws import\exceptions\currencyException
     */
    private function newCurrency($currency, $value)
    {
        switch (strtolower($currency)) {
            case 'cad':
                $price = new currency\cad(($value/100));
                break;
            case 'eur':
                $price = new currency\eur(($value/100));
                break;
            case 'gbp':
                $price = new currency\gbp(($value/100));
                break;
            case 'usd':
                $price = new currency\usd(($value/100));
                break;
            default:
                throw NEW ex\currencyException('Unknown currency type: '.$data['currency']);
        }
        return $price;
    }
    
    
    /**
     * Make Paginated cURL Request
     * 
     * @param string $url
     * @param bool $options
     * @return array
     */
    private function curlRequestPaginated($url, $options=false)
    {
        $total      = 1;
        $position   = 0;
        $page       = 1;
        $data       = array();
        
        while ($position < $this->limit || $this->limit === -1) {
            $extra = $total > 1
                   ? " of $total items"
                   : '';
            $this->out("Getting items ".($position+1)." to ".($position+$this->pageSize).$extra." (page $page)", TFC_INFO_DETAIL);
            
            $response = $this->curlRequest(
                            str_replace(array('{START}', '{PAGE_SIZE}', '{SECRET}'),
                                        array($position, $this->pageSize, $this->auth['secret']),
                                        $url),
                            $options);
            
            if (isset($response->data)) {
                $decoded    = json_decode($response->data, true);
                if ($decoded) {
                    #$total      = $decoded['totalSize'];
                    $position  += $this->pageSize;
                    $page++;
                    foreach ($decoded AS $d) {
                        $data[] = $d;
                    }
                } else {
                    return $data;
                }
            } else {
                return $data;
            }
        }
        return $data;
    }
    
    
    /**
     * Make Single cURL Request
     * 
     * @param string $url
     * @param array $options
     * @return \stdClass
     */
    private function curlRequest($url, $options=false)
    {
        for ($retry = 0; $retry <= $this->curlRetries; $retry++) {
            if($options == false || !is_array($options)){
                $options = array();
            }

            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, (isset($options['CURLOPT_RETURNTRANSFER']) ? $options['CURLOPT_RETURNTRANSFER'] : true));
            curl_setopt($curl, CURLOPT_HEADER, $this->curlResHeader);
            curl_setopt($curl, CURLINFO_HEADER_OUT, $this->curlReqHeader);
            if (isset($options['CURLOPT_POSTFIELDS']) && !empty($options['CURLOPT_POSTFIELDS'])) {
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_POSTFIELDS, $options['CURLOPT_POSTFIELDS']);
            } else {
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, (isset($options['CURLOPT_CUSTOMREQUEST']) ? $options['CURLOPT_CUSTOMREQUEST'] : 'GET'));
            }

            if (isset($options['CURLOPT_HTTPHEADER']) && !empty($options['CURLOPT_HTTPHEADER'])) {
                curl_setopt($curl, CURLOPT_HTTPHEADER, $options['CURLOPT_HTTPHEADER']);
            }

            $final = new stdClass();

            $final->data = curl_exec($curl);
            $final->info = curl_getinfo($curl);
            
            if ($final->info['http_code'] == 200) {

                if($this->curlResHeader == true){
                    $final->info['response_header'] = substr($final->data, 0, $final->info['header_size']);
                    $final->data = substr($final->data, $final->info['header_size']);
                }

                return $final;
            } else {
                $this->out("Retrying cURL. ".($retry+1)." of ".$this->curlRetries, TFC_INFO_DETAIL);
            }
        }
        $this->out("Unable to retrieve data from Appland after ".$this->curlRetries." attempts". TFC_CRITICAL);
    }
    
    
    /**
     * Set Page To Skip To
     * 
     * @param int $page
     * @return \applandCatalog
     */
    public function skipToPage($page)
    {
        $this->skipToPage = (int)$page;
        return $this;
    }
    
    
    /**
     * Set cURL Retry Count
     * 
     * @param int $retries
     * @return \applandCatalog
     */
    public function setCurlRetries($retries)
    {
        $this->curlRetries = (int)$retries;
        return $this;
    }
    
    
    
    public function setLimit($limit)
    {
        $this->limit = (int)$limit;
        return $this;
    }
    
    
    public function setPageSize($pageSize)
    {
        $this->pageSize = (int)$pageSize;
        return $this;
    }
    
    
    /**
     * Seconds to Time Array
     *
     * Returns an array with total, hours, minutes, seconds and DB ready time
     *
     * @param   INT     $seconds
     * @return  ARRAY
     */
    private function secondsToTimeArray($seconds)
    {
        $seconds = (int)$seconds;
        $runTime['total']   = $seconds;
        $runTime['hours']   = floor($seconds / 3600);
        $runTime['minutes'] = floor(($seconds - ($runTime['hours']*3600)) /60);
        $runTime['seconds'] = floor(($seconds - ($runTime['hours']*3600) - ($runTime['minutes'] * 60)));
        $runTime['dbReady'] = sprintf("%02d", $runTime['hours']).':'.sprintf("%02d", $runTime['minutes']).':'.sprintf("%02d", $runTime['seconds']);
        return $runTime;
    }
    
    
    
    
    /**
     * Appland Catalog Destructor
     */
    public function __destruct()
    {
        if (isset($this->categories)){
            $echoPrepare = "\n";
            foreach ($this->categories AS $category){
                $echoPrepare .= $category['name']."\n";
            }
            $this->out("Categories:".$echoPrepare);
        }
        if (isset($this->added)){
            $echoPrepare = "\n";
            foreach ($this->added AS $added){
                $echoPrepare .= "$added\n";
            }
            $this->out("Added:".$echoPrepare);
        }
        if (isset($this->removed)){
            $echoPrepare = "\n";
            foreach ($this->removed AS $removed){
                $echoPrepare .= "$removed\n";
            }
            $this->out("Removed:".$echoPrepare);
        }
        
        $this->timerEnd = microtime(TRUE);
        $duration = round(($this->timerEnd - $this->timerStart));
        $runTime = $this->secondsToTimeArray($duration);
        
        $this->out("Appland import finished in ".$runTime['dbReady']);
        $this->out('Peak memory usage: '.(round((memory_get_peak_usage()/1024)/1024, 2)).'MB', TFC_INFO_DETAIL);
        
        $this->processEnd();
    }
    
}






$feed = new applandCatalog(
    new help\s3(array(
            'bucket'    => tfcConfig::$awsConfig['s3']['bucket'],
            'key'       => tfcConfig::$awsConfig['key'],
            'secret'    => tfcConfig::$awsConfig['secret'],
            'region'    => tfcConfig::$awsConfig['region']
        )
    )
);
$feed->setPageSize(50);
$feed->skipToPage(1281);
#$feed->setLimit(1000);
$feed->setCurlRetries(10);
$feed->processInBatch();
$feed->processEnd();