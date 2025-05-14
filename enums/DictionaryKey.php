<?php 
if (!defined('ABSPATH')) {
    exit;
}
enum DictionaryKey: string
{
    case CURRENCY = 'currency';
    case TRANSACTION = 'transaction';
    case TYPES = 'types';
    case MARKET = 'market';
    case HEATING = 'heating';
    case KITCHEN_TYPES = 'kitchenTypes';
    case APARTMENT_EQUIPMENTS = 'apartmentEquipments';
    case BUILDING_CONDITION = 'buildingCondition';
    case BINARY = 'binary'; 
}