<?php

namespace Bolt\Extension\intendit\foxycart;

use Bolt\Extension\SimpleExtension;

/**
 * ExtensionName extension class.
 *
 * @author Your Name <you@example.com>
 */
class foxycartExtension extends SimpleExtension
{
    protected function registerTwigFunctions()
    {
        return [
            'foxycart' => ['foxycart', ['is_variadic' => true]]
        ];
    }

    function foxycart($var_name, $var_value, $var_code, $var_parent_code = "", $for_value = false) {
        $config = $this->getConfig();
        if (!isset($config ['api_key'])) {
            return "No API key set";
        }
        $api_key = $config ['api_key'];
        $encodingval = htmlspecialchars($var_code . $var_parent_code . $var_name . $var_value);
        $label = ($for_value) ? $var_value : $var_name;
        return $label . '||' . hash_hmac('sha256', $encodingval, $api_key) . ($var_value === "--OPEN--" ? "||open" : "");
    }

}
