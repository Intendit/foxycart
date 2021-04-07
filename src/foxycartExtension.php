<?php

namespace Bolt\Extension\intendit\foxycart;

use Bolt\Asset\Target;
use Bolt\Controller\Zone;
use Bolt\Asset\Snippet\Snippet;
use Bolt\Extension\SimpleExtension;
use Bolt\Storage\Entity;
use Silex\Application;
use Silex\ControllerCollection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ExtensionName extension class.
 *
 * @author Intendit <you@example.com>
 */


class foxycartExtension extends SimpleExtension
{



    protected function registerFrontendRoutes(ControllerCollection $collection)
    {
        // All requests to /koala
        $collection->match('/foxycartstockupdate', [$this, 'callbackKoalaCatching']);
        $collection->match('/foxycartstockupdateSSL', [$this, 'foxycartStockUpdateSSL']);
        $collection->match('/foxycartstockCheckSSL', [$this, 'foxycartstockCheckSSL']);
    }


          
    // ======================================================================================
    // RC4 ENCRYPTION CLASS
    // Do not modify.
    // ======================================================================================
    /**
     * RC4Crypt 3.2
     *
     * RC4Crypt is a petite library that allows you to use RC4
     * encryption easily in PHP. It's OO and can produce outputs
     * in binary and hex.
     *
     * (C) Copyright 2006 Mukul Sabharwal [http://mjsabby.com]
     *     All Rights Reserved
     *
     * @link http://rc4crypt.devhome.org
     * @author Mukul Sabharwal <mjsabby@gmail.com>
     * @version $Id: class.rc4crypt.php,v 3.2 2006/03/10 05:47:24 mukul Exp $
     * @copyright Copyright &copy; 2006 Mukul Sabharwal
     * @license http://www.gnu.org/copyleft/gpl.html
     * @package RC4Crypt
     */
    public function callbackKoalaCatching(Application $app, Request $request)
    {    

        $config = $this->getConfig();

        define('FOXY_WEBHOOK_ENCRYPTION_KEY', $config['encryption_key']);
        $data = file_get_contents('php://input');
        $parts = explode(':', $data);
        $mac = $parts[0];
        $iv = $parts[1];
        $data = $parts[2];
         
        $calc_mac = hash('sha256', "$iv:$data");
         
        /* Decrypt data */
        if (hash_equals($calc_mac, $mac)) {
            $iv = hex2bin($iv);
            $key = hex2bin(hash('sha256', FOXY_WEBHOOK_ENCRYPTION_KEY));
         
            if ($data = openssl_decrypt($data, 'aes-256-cbc', $key, 0, $iv)) {
                $parsedData = json_decode($data, true, 512, JSON_UNESCAPED_UNICODE);
                $test = 0;
                if (isset($parsedData["_embedded"]["fx:items"])) {
                    foreach ($parsedData["_embedded"]["fx:items"] as $items) {
                        $code = $items["code"];
                        $quantity = $items["quantity"];

                        // Check if the product contains attributes
                        if (isset($items["_embedded"]["fx:item_options"])) {
                            try {

                                // Find the product
                                $repo = $app['storage']->getRepository('produkter');
                                $qb = $repo->createQueryBuilder();
                                $qb->where('title="'.$items['code'].'"');
                                $products = $repo->findOneWith($qb);        
                                $prodId = $products["id"];
                                $repoAttr = $app['storage']->getRepository('attributkategorier');
                                $qb = $repoAttr->createQueryBuilder();
                                $attrCat = $qb->execute()->fetchAll();
                                $attrArr = [];

                                // Loop the webhook's attributes and find the right quantity column to calculate value of
                                foreach ($items["_embedded"]["fx:item_options"] as $attrs) {
                                    $catName = $attrs["name"];
                                    $catValue = $attrs["value"];

                                    $query = $app['db']->createQueryBuilder()
                                        ->select('*')
                                        ->from('bolt_'.$config["contenttype_category"])
                                        ->where('title LIKE "'.$catName.'"');
                                    $resultscats = $query->execute()->fetchAll();

                                    $query = $app['db']->createQueryBuilder()
                                        ->select('*')
                                        ->from('bolt_'.$config["contenttype_values"])
                                        ->where('title LIKE "'.$catValue.'"');
                                    $results = $query->execute()->fetchAll();

                                    foreach ($results as $result) {
                                        $valueId = $result["id"];
                                        foreach ($resultscats as $valuecats) {
                                            $resultIds = json_decode($valuecats["selectattribute"]);
                                            if(in_array($valueId, $resultIds)) {
                                                array_push($attrArr, $valueId);
                                            }
                                        }
                                    }

                                }
                                sort($attrArr);
                                $attrArr = json_encode($attrArr);
                                $query = $app['db']->createQueryBuilder()
                                    ->select('*')
                                    ->from('bolt_field_value')
                                    ->where("value_json_array LIKE '".$attrArr."'")
                                    ->andWhere("content_id LIKE '".$prodId."'");
                                $checkresults = $query->execute()->fetchAll();            
                                foreach ($checkresults as $groupitem) {
                                    $query = $app['db']->createQueryBuilder()
                                        ->select('*')
                                        ->from('bolt_field_value')
                                        ->where("fieldname LIKE 'quantity'")
                                        ->andWhere("content_id LIKE '".$prodId."'")
                                        ->andWhere("grouping LIKE '".$groupitem["grouping"]."'");
                                    $quantityresults = $query->execute()->fetchAll();
                                    foreach ($quantityresults as $quantityresult) {
                                        $currentQuantity = $quantityresult["value_integer"] - $quantity;
                                        $query = $app['db']->createQueryBuilder()
                                            ->update('bolt_field_value')
                                            ->set('value_integer', $currentQuantity)
                                            ->where("fieldname LIKE 'quantity'")
                                            ->andWhere("content_id LIKE '".$prodId."'")
                                            ->andWhere("grouping LIKE '".$groupitem["grouping"]."'");
                                        $query->execute();                
                                    }
                                }
                            } catch (Exception $e) {
                                // If something wrong with handling the data with this extension, return 500.
                                http_response_code(500);
                                return;
                            }
                        } else { 

                            // If no attributes on product, change the main quantity value in db
                            $repo = $app['storage']->getRepository($config['contenttype_foxy']);
                            $qb = $repo->createQueryBuilder();
                            $qb->where('title="'.$code.'"');
                            $products = $repo->findOneWith($qb);

                            $currentQuantity = $products["quantity"] - $quantity;
                            $query = $app['db']->createQueryBuilder()
                                ->update('bolt_'.$config['contenttype_foxy'])
                                ->set('quantity', $currentQuantity)
                                ->where('id = '.$products["id"].'');
                            $query->execute();                            
                        }
                    }
                }
                return true;
            } else {
                while ($msg = openssl_error_string()) {
                    echo("Openssl error: " . $msg);
                }
                http_response_code(500);
                return;
            }
        } else {
            // Data is corrupted, send response 500 to foxycart.
            echo("Encrypted data corrupted");
            http_response_code(500);
            return;
        }
    }
    public function foxycartStockUpdateSSL(Application $app, Request $request)
    {    

        $config = $this->getConfig();

        define('FOXY_WEBHOOK_ENCRYPTION_KEY', $config['encryption_key']);
        $data = file_get_contents('php://input');
        $parsedData = json_decode($data, true);
        $event = $_SERVER['HTTP_FOXY_WEBHOOK_EVENT'];
         
        // Verify the webhook payload
        $signature = hash_hmac('sha256', $data, FOXY_WEBHOOK_ENCRYPTION_KEY);
        if (!hash_equals($signature, $_SERVER['HTTP_FOXY_WEBHOOK_SIGNATURE'])) {
            echo "Signature verification failed - data corrupted";
            http_response_code(500);
            return;
        }         
            if (is_array($parsedData)) {
                $test = 0;
                if (isset($parsedData["_embedded"]["fx:items"])) {
                    foreach ($parsedData["_embedded"]["fx:items"] as $items) {
                        $code = $items["code"];
                        $quantity = $items["quantity"];

                        // Check if the product contains attributes
                        if (isset($items["_embedded"]["fx:item_options"])) {
                            try {

                                // Find the product
                                $repo = $app['storage']->getRepository($config['contenttype_foxy']);
                                $qb = $repo->createQueryBuilder();
                                $qb->where('title="'.$items['code'].'"');
                                $products = $repo->findOneWith($qb);        
                                $prodId = $products["id"];
                                $repoAttr = $app['storage']->getRepository('attributkategorier');
                                $qb = $repoAttr->createQueryBuilder();
                                $attrCat = $qb->execute()->fetchAll();
                                $attrArr = [];

                                // Loop the webhook's attributes and find the right quantity column to calculate value of
                                foreach ($items["_embedded"]["fx:item_options"] as $attrs) {
                                    $catName = $attrs["name"];
                                    $catValue = $attrs["value"];

                                    $query = $app['db']->createQueryBuilder()
                                        ->select('*')
                                        ->from('bolt_'.$config["contenttype_category"])
                                        ->where('title LIKE "'.$catName.'"');
                                    $resultscats = $query->execute()->fetchAll();

                                    $query = $app['db']->createQueryBuilder()
                                        ->select('*')
                                        ->from('bolt_'.$config["contenttype_values"])
                                        ->where('title LIKE "'.$catValue.'"');
                                    $results = $query->execute()->fetchAll();

                                    foreach ($results as $result) {
                                        $valueId = $result["id"];
                                        foreach ($resultscats as $valuecats) {
                                            $resultIds = json_decode($valuecats["selectattribute"]);
                                            if(in_array($valueId, $resultIds)) {
                                                array_push($attrArr, $valueId);
                                            }
                                        }
                                    }

                                }
                                sort($attrArr);
                                $attrArr = json_encode($attrArr);
                                $query = $app['db']->createQueryBuilder()
                                    ->select('*')
                                    ->from('bolt_field_value')
                                    ->where("value_json_array LIKE '".$attrArr."'")
                                    ->andWhere("content_id LIKE '".$prodId."'");
                                $checkresults = $query->execute()->fetchAll();            
                                foreach ($checkresults as $groupitem) {
                                    $query = $app['db']->createQueryBuilder()
                                        ->select('*')
                                        ->from('bolt_field_value')
                                        ->where("fieldname LIKE 'quantity'")
                                        ->andWhere("content_id LIKE '".$prodId."'")
                                        ->andWhere("grouping LIKE '".$groupitem["grouping"]."'");
                                    $quantityresults = $query->execute()->fetchAll();
                                    foreach ($quantityresults as $quantityresult) {
                                        $currentQuantity = $quantityresult["value_integer"] - $quantity;
                                        $query = $app['db']->createQueryBuilder()
                                            ->update('bolt_field_value')
                                            ->set('value_integer', $currentQuantity)
                                            ->where("fieldname LIKE 'quantity'")
                                            ->andWhere("content_id LIKE '".$prodId."'")
                                            ->andWhere("grouping LIKE '".$groupitem["grouping"]."'");
                                        $query->execute();                
                                    }
                                }
                            } catch (Exception $e) {
                                // If something wrong with handling the data with this extension, return 500.
                                http_response_code(500);
                                return;
                            }
                        } else { 

                            // If no attributes on product, change the main quantity value in db
                            $repo = $app['storage']->getRepository($config['contenttype_foxy']);
                            $qb = $repo->createQueryBuilder();
                            $qb->where('title="'.$code.'"');
                            $products = $repo->findOneWith($qb);

                            $currentQuantity = $products["quantity"] - $quantity;
                            $query = $app['db']->createQueryBuilder()
                                ->update('bolt_'.$config['contenttype_foxy'])
                                ->set('quantity', $currentQuantity)
                                ->where('id = '.$products["id"].'');
                            $query->execute();                            
                        }
                    }
                }
                return true;
            } else {
                // Data is corrupted, send response 500 to foxycart.
                echo("Encrypted data corrupted");
                http_response_code(500);
                return;
            }
    }

    public function foxycartstockCheckSSL(Application $app, Request $request)
    {    

        $config = $this->getConfig();
        define('FOXY_WEBHOOK_ENCRYPTION_KEY', $config['encryption_key']);
        $data = file_get_contents('php://input');
        $parsedData = json_decode($data, true);
        $response = array(
            'ok' => true,
            'details' => 'Sorry, one of the products in your cart is either out of stock or less quantity than in your cart. Please remove the items from your cart and try again.'
        );      
            if (is_array($parsedData)) {              
                if (isset($parsedData["_embedded"]["fx:items"])) {
                    foreach ($parsedData["_embedded"]["fx:items"] as $items) {
                        $code = $items["code"];
                        $quantity = $items["quantity"];
                        // Check if the product contains attributes
                        if (isset($items["_embedded"]["fx:item_options"])) {                            
                            try {
                                $myfile = fopen("/var/bmss/source/3.2foxycart/extensions/vendor/intendit/foxycart/test.txt", "w");
                                fwrite($myfile, 'tes1');
                                fclose($myfile);                                
                                // Find the product
                                $repo = $app['storage']->getRepository($config['contenttype_foxy']);
                                $qb = $repo->createQueryBuilder();
                                $qb->where('title="'.$items['code'].'"');
                                $products = $repo->findOneWith($qb);        
                                $prodId = $products["id"];
                                $repoAttr = $app['storage']->getRepository('attributkategorier');
                                $qb = $repoAttr->createQueryBuilder();
                                $attrCat = $qb->execute()->fetchAll();
                                $attrArr = [];

                                // Loop the webhook's attributes and find the right quantity column to calculate value of
                                foreach ($items["_embedded"]["fx:item_options"] as $attrs) {
                                    $catName = $attrs["name"];
                                    $catValue = $attrs["value"];

                                    $query = $app['db']->createQueryBuilder()
                                        ->select('*')
                                        ->from('bolt_'.$config["contenttype_category"])
                                        ->where('title LIKE "'.$catName.'"');
                                    $resultscats = $query->execute()->fetchAll();

                                    $query = $app['db']->createQueryBuilder()
                                        ->select('*')
                                        ->from('bolt_'.$config["contenttype_values"])
                                        ->where('title LIKE "'.$catValue.'"');
                                    $results = $query->execute()->fetchAll();

                                    foreach ($results as $result) {
                                        $valueId = $result["id"];
                                        foreach ($resultscats as $valuecats) {
                                            $resultIds = json_decode($valuecats["selectattribute"]);
                                            if(in_array($valueId, $resultIds)) {
                                                array_push($attrArr, $valueId);
                                            }
                                        }
                                    }

                                }
                                sort($attrArr);
                                $attrArr = json_encode($attrArr);
                                $query = $app['db']->createQueryBuilder()
                                    ->select('*')
                                    ->from('bolt_field_value')
                                    ->where("value_json_array LIKE '".$attrArr."'")
                                    ->andWhere("content_id LIKE '".$prodId."'");
                                $checkresults = $query->execute()->fetchAll();          
                                foreach ($checkresults as $groupitem) {
                                    $query = $app['db']->createQueryBuilder()
                                        ->select('*')
                                        ->from('bolt_field_value')
                                        ->where("fieldname LIKE 'quantity'")
                                        ->andWhere("content_id LIKE '".$prodId."'")
                                        ->andWhere("grouping LIKE '".$groupitem["grouping"]."'");
                                    $quantityresults = $query->execute()->fetchAll();
                                    foreach ($quantityresults as $quantityresult) {
                                        $currentQuantity = $quantityresult["value_integer"] - $quantity;
                                        if ($currentQuantity < 0) {
                                            $response['ok'] = false;
                                        }
                                    }
                                }
                            } catch (Exception $e) {
                                $response['ok'] = false;
                            }
                        } else {                             
                            // If no attributes on product, change the main quantity value in db
                            $repo = $app['storage']->getRepository($config['contenttype_foxy']);
                            $qb = $repo->createQueryBuilder();
                            $qb->where('title="'.$code.'"');
                            $products = $repo->findOneWith($qb);
                            $currentQuantity = $products["quantity"] - $quantity;                         
                            if ($currentQuantity < 0) {
                                $response['ok'] = false;
                            }
                        }
                    }
                
                }
            } else {            
                $respons['ok'] = false;
            }
            header('Content-Type: application/json');
            $response = new Response(json_encode($response));
            $response->headers->set('Content-Type', 'application/json');
            
            return $response;            
    }
    protected function registerTwigFunctions()
    {
        return [
            'foxycart' => ['foxycart', ['is_variadic' => true]]
        ];
    }

    protected function registerAssets()
    {
        $asset = new Snippet();
        $asset->setCallback([$this, 'callbackSnippet'])
            ->setLocation(Target::END_OF_BODY)
            ->setPriority(5)
        ;

        return [
            $asset,
        ];
    }

    public function callbackSnippet()
    {
        $config = $this->getConfig();
        $cartUrl = $config["foxycart_cart_url"];
        $html = <<< EOM
        <div id="fb-root"></div>
        <script>if(!!(document.getElementsByClassName("foxycart-form").length)){(function (d, s, id) {
          var js, fjs = d.getElementsByTagName(s)[0];
          if (d.getElementById(id)) return;
          js = d.createElement(s); js.id = id;
          js.src = "//cdn.foxycart.com/$cartUrl/loader.js";
          fjs.parentNode.insertBefore(js, fjs);
        }(document, 'script', 'foxycart'))}</script>
EOM;
        return $html;
    }

    function foxycart(array $args = array()) {
        $var_code = $args[2];
        $var_name = $args[0];
        $var_value = $args[1];
        $for_value = false;
        if (isset($args[4]) && strlen($args[4]) > 0) {
            $for_value = true;
        }
        $var_parent_code = "";
        $config = $this->getConfig();
        if (!isset($config['api_key'])) {
            return "No API key set";
        }
        $api_key = $config['api_key'];
        $encodingval = htmlspecialchars($var_code . $var_parent_code . $var_name . $var_value);
        $label = ($for_value) ? $var_value : $var_name;
        return $label . '||' . hash_hmac('sha256', $encodingval, $api_key) . ($var_value === "--OPEN--" ? "||open" : "");
    }

}
