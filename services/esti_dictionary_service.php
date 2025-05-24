<?php
if (!defined('ABSPATH')) {
    exit;
}

// Ensure Esti_Sanitizer_Service is loaded before this class
// require_once ESTI_SYNC_PLUGIN_PATH . 'includes/mapper/Esti_Sanitizer_Service.php';
// Ensure DictionaryKey enum is loaded
// require_once ESTI_SYNC_PLUGIN_PATH . 'includes/enums/DictionaryKey.php';


class Esti_Dictionary_Service
{
    private array $dictionary_data;
    private Esti_Sanitizer_Service $sanitizer;

    public function __construct(array $dictionary_data, Esti_Sanitizer_Service $sanitizer)
    {
        $this->dictionary_data = $dictionary_data;
        $this->sanitizer = $sanitizer;
    }

    public function get_dict_value(DictionaryKey $dictionary_key_enum, $item_value_key, string $default = ''): string
    {
        if ($item_value_key === null || $item_value_key === '') {
            return $default;
        }

        $dictionary_key = $dictionary_key_enum->value;
        
        if (!isset($this->dictionary_data[$dictionary_key][$item_value_key])) {
            error_log("Dictionary key '{$dictionary_key}' or item value key '{$item_value_key}' not found.");
            return $default;
        }

        return $this->sanitizer->s_text($this->dictionary_data[$dictionary_key][$item_value_key]);
    }
}