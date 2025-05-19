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
            return [];
        }

        if ($limit !== null && $limit > 0) {
            return array_slice($items, 0, $limit);
        }

        return $items;
    }

    /**
     * Loads, decodes, and validates the JSON file content.
     *
     * @return array|null The 'data' array from JSON, or null on error.
     */
    private function load_and_decode_json_file(): ?array
    {
        if (! file_exists($this->file_path)) {
            return null;
        }

        $json_content = file_get_contents($this->file_path);
        if ($json_content === false) {
            return null;
        }

        $decoded_data = json_decode($json_content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        if (! isset($decoded_data['data']) || ! is_array($decoded_data['data'])) {
            return null;
        }

        return $decoded_data['data'];
    }
}
