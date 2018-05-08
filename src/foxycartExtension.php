<?php

namespace Bolt\Extension\intendit\foxycart;

use Bolt\Asset\Target;
use Bolt\Controller\Zone;
use Bolt\Asset\Snippet\Snippet;
use Bolt\Extension\SimpleExtension;
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
        if (strlen($args[4]) > 0) {
            $for_value = true;
        } else {    
        $for_value = false;
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