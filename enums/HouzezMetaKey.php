<?php
if (!defined('ABSPATH')) {
    exit;
}
enum HouzezMetaKey: string
{
    // Core IDs
    case JSON_ID = '_esti_json_id';
    case PROPERTY_ID = 'fave_property_id';

        // Price
    case PRICE = 'fave_property_price';
    case SECOND_PRICE = 'fave_second_price';
    case PRICE_PREFIX = 'fave_property_price_prefix';
    case PRICE_POSTFIX = 'fave_property_price_postfix';
    case PRICE_PER_SQFT = 'fave_property_price_per_sqft';
    case PRICE_PER_SQFT_POSTFIX = 'fave_property_price_per_sqft_postfix';
    case CURRENCY = 'fave_currency_info';

        // Size & Area
    case SIZE = 'fave_property_size';
    case SIZE_PREFIX = 'fave_property_size_prefix';
    case SIZE_POSTFIX = 'fave_property_size_postfix';
    case LAND_AREA = 'fave_property_land';
    case LAND_AREA_POSTFIX = 'fave_property_land_postfix';

        // Rooms & Details
    case BEDROOMS = 'fave_property_bedrooms';
    case BATHROOMS = 'fave_property_bathrooms';
    case RESTROOMS = 'fave_property_restrooms';
    case YEAR_BUILT = 'fave_property_year_built';
    case FLOOR_NO = 'fave_property_floor_no';

        // Location & Map
    case LOCATION_COORDS = 'fave_property_location';
    case LATITUDE = 'fave_property_latitude';
    case LONGITUDE = 'fave_property_longitude';
    case ADDRESS = 'fave_property_address';
    case MAP_ADDRESS = 'fave_property_map_address';
    case MAP_ENABLED = 'fave_property_map';

        // Additional Features
    case ADDITIONAL_FEATURES_ENABLE = 'fave_additional_features_enable';
    case ADDITIONAL_FEATURES = 'fave_additional_features';

        // Images
    case GALLERY_IMAGES = 'fave_property_images';

        // Other common Houzez meta
    case AGENT_DISPLAY_OPTION = 'fave_agent_display_option';
    case FEATURED_PROPERTY = 'fave_featured';
}
