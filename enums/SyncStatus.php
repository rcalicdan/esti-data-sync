<?php
if (!defined('ABSPATH')) {
    exit;
}
enum SyncStatus: string
{
    case SKIPPED = 'skipped';
    case SUCCESS = 'success';
    case FAILED = 'failed';
}
