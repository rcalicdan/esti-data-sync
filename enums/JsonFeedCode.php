<?php
if (!defined('ABSPATH')) {
    exit;
}
enum JsonFeedCode: int
{
    case TRANSACTION_FOR_SALE = 131;
    case TRANSACTION_FOR_RENT = 132;
    case LABEL_NEW = 1;
    case LABEL_SOLD = 55;
    case LABEL_RESERVED = 57;
}
