<?php
if (!defined('ABSPATH')) {
    exit;
}

class Esti_Sanitizer_Service
{
    public function s_text($value): string
    {
        return sanitize_text_field((string) $value);
    }

    public function s_int($value): int
    {
        return intval($value);
    }

    public function s_float($value): float
    {
        return floatval(str_replace(',', '.', (string) $value));
    }

    public function s_price($value): string
    {
        $cleaned_value = preg_replace('/[^\d.]/', '', (string) $value);
        return sanitize_text_field($cleaned_value);
    }
}