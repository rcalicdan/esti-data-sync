<?php
if (!defined('ABSPATH')) {
    exit;
}
enum HouzezMetaKey: string
{
    // Core
    case JSON_ID = '_esti_json_id';
    case PROPERTY_ID = 'fave_property_id';
    case PRICE = 'fave_property_price';
    case PRICE_PREFIX = 'fave_property_price_prefix';
    case PRICE_POSTFIX = 'fave_property_price_postfix';
    case SECOND_PRICE = 'fave_property_sec_price';
    case CURRENCY = 'fave_currency_info';

        // Details
    case SIZE = 'fave_property_size';
    case SIZE_PREFIX = 'fave_property_size_prefix';
    case LAND_AREA = 'fave_property_land';
    case LAND_AREA_POSTFIX = 'fave_property_land_postfix';
    case BEDROOMS = 'fave_property_bedrooms';
    case BATHROOMS = 'fave_property_bathrooms';
    case RESTROOMS = 'fave_property_restrooms';
    case GARAGE_NUMBER = 'fave_property_garage';
    case GARAGE_SIZE = 'fave_property_garage_size';
    case YEAR_BUILT = 'fave_property_year';
    case FLOOR_NO = 'fave_property_floor_no';
    case TOTAL_FLOORS = 'fave_property_total_floors';

        // Location
    case ADDRESS = 'fave_property_address';
    case ZIP = 'fave_property_zip';
    case LOCATION_COORDS = 'fave_property_location';
    case LATITUDE = 'houzez_geolocation_lat';
    case LONGITUDE = 'houzez_geolocation_long';
    case MAP_ENABLED = 'fave_property_map';
    case MAP_ADDRESS = 'fave_property_map_address';
    case MAP_STREET_VIEW = 'fave_property_map_street_view';

        // Features & Status
    case FEATURED_PROPERTY = 'fave_featured';
    case ADDITIONAL_FEATURES_ENABLE = 'fave_additional_features_enable';
    case ADDITIONAL_FEATURES = 'additional_features';

        // Agents
    case AGENT_DISPLAY_OPTION = 'fave_agent_display_option';
    case AGENTS = 'fave_agents'; 

        // Other Houzez specific
    case VIDEO_IMAGE = 'fave_video_image';
    case HOMESLIDER = 'fave_prop_homeslider'; 
    case PAYMENT_STATUS = 'fave_payment_status'; 
    case ENERGY_CLASS = 'fave_energy_class';

        // Your custom or less common
    case ROOMS = 'fave_property_rooms';
}
