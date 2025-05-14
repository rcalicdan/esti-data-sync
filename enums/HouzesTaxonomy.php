<?php
if (!defined('ABSPATH')) {
    exit;
}
enum HouzezTaxonomy: string
{
    case TYPE = 'property_type';
    case STATUS = 'property_status';
    case LABEL = 'property_label';
    case CITY = 'property_city';
    case AREA = 'property_area';
    case STATE = 'property_state';
    case COUNTRY = 'property_country';
    case FEATURE = 'property_feature';
}