<?php
if (! defined('ABSPATH')) {
    exit;
}

class Esti_Data_Reader
{
    private string $file_path;

    public function __construct(string $file_path)
    {
        $this->file_path = $file_path;
    }

    /**
     * Reads data from the JSON file.
     *
     * @param int|null $limit Number of items to return. Null for all.
     * @return array Array of property data items, or an empty array on failure.
     */
    public function get_data(?int $limit = null): array
    {
        $items = $this->load_and_decode_json_file();

        if (empty($items)) {
            error_log('Esti Data Reader: No items found or error loading JSON file: ' . $this->file_path);
            return [];
        }

        error_log('Esti Data Reader: Found ' . count($items) . ' items in JSON file');

        if ($limit !== null && $limit > 0) {
            $sliced = array_slice($items, 0, $limit);
            error_log('Esti Data Reader: Returning ' . count($sliced) . ' items (limited from ' . count($items) . ')');
            return $sliced;
        }

        return $items;
    }

    /**
     * Reads data from the JSON file by index range.
     *
     * @param int $start_index Starting index (inclusive)
     * @param int $end_index Ending index (inclusive)
     * @return array Array of property data items within the range, or empty array on failure/invalid range
     */
    public function get_data_by_range(int $start_index, int $end_index): array
    {
        if ($start_index > $end_index || $start_index < 0 || $end_index < 0) {
            error_log("Esti Data Reader: Invalid range - start: $start_index, end: $end_index");
            return [];
        }

        $items = $this->load_and_decode_json_file();

        if (empty($items)) {
            error_log('Esti Data Reader: No items found for range query');
            return [];
        }

        $total_items = count($items);
        error_log("Esti Data Reader: Total items available: $total_items, requested range: $start_index to $end_index");
        
        // Ensure indices don't exceed array bounds
        $start_index = min($start_index, $total_items - 1);
        $end_index = min($end_index, $total_items - 1);
        
        // Calculate length for array_slice (end_index is inclusive)
        $length = $end_index - $start_index + 1;
        
        $result = array_slice($items, $start_index, $length);
        error_log("Esti Data Reader: Returning " . count($result) . " items from range");
        
        return $result;
    }

    /**
     * Loads, decodes, and validates the JSON file content.
     *
     * @return array|null The 'data' array from JSON, or null on error.
     */
    private function load_and_decode_json_file(): ?array
    {
        if (! file_exists($this->file_path)) {
            error_log('Esti Data Reader: JSON file does not exist: ' . $this->file_path);
            return null;
        }

        $json_content = file_get_contents($this->file_path);
        if ($json_content === false) {
            error_log('Esti Data Reader: Failed to read JSON file: ' . $this->file_path);
            return null;
        }

        if (empty($json_content)) {
            error_log('Esti Data Reader: JSON file is empty: ' . $this->file_path);
            return null;
        }

        $decoded_data = json_decode($json_content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('Esti Data Reader: JSON decode error: ' . json_last_error_msg());
            return null;
        }

        if (! isset($decoded_data['data']) || ! is_array($decoded_data['data'])) {
            error_log('Esti Data Reader: JSON structure invalid - missing or invalid "data" key');
            error_log('Esti Data Reader: Available keys: ' . implode(', ', array_keys($decoded_data)));
            return null;
        }

        error_log('Esti Data Reader: Successfully loaded ' . count($decoded_data['data']) . ' items from JSON');
        return $decoded_data['data'];
    }

    /**
     * Get debug information about the JSON file
     * 
     * @return array Debug information
     */
    public function get_debug_info(): array
    {
        $info = [
            'file_path' => $this->file_path,
            'file_exists' => file_exists($this->file_path),
            'file_readable' => is_readable($this->file_path),
            'file_size' => file_exists($this->file_path) ? filesize($this->file_path) : 0,
        ];

        if ($info['file_exists'] && $info['file_readable']) {
            $content = file_get_contents($this->file_path);
            if ($content !== false) {
                $info['content_length'] = strlen($content);
                $decoded = json_decode($content, true);
                $info['json_valid'] = json_last_error() === JSON_ERROR_NONE;
                $info['json_error'] = json_last_error_msg();
                
                if ($info['json_valid']) {
                    $info['has_data_key'] = isset($decoded['data']);
                    $info['data_is_array'] = isset($decoded['data']) && is_array($decoded['data']);
                    $info['data_count'] = isset($decoded['data']) && is_array($decoded['data']) ? count($decoded['data']) : 0;
                    $info['root_keys'] = array_keys($decoded);
                }
            }
        }

        return $info;
    }
}