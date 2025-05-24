<?php
if (!defined('ABSPATH')) {
    exit;
}
enum DictionaryKey: string
{
    case CURRENCY = 'currency';
    case BUILDING_CONDITION = 'building_condition';
    case HEATING = 'heating';
    case KITCHEN_TYPES = 'kitchen_types';
    case APARTMENT_EQUIPMENTS = 'apartment_equipments';
    case BINARY = 'binary';
    case TYPES = 'types';
    case MARKET = 'market';
    case APARTMENT_OWNERSHIP = 'apartment_ownership';
    case APARTMENT_FURNISHINGS = 'apartment_furnishings';
    case BUILDING_TYPE = 'building_type';
    case BUILDING_MATERIAL = 'building_material';
    case APARTMENT_EQUIPMENT = 'apartment_equipment';
    case APARTMENT_BATHROOM_TYPE = 'apartment_bathroom_type';
}
