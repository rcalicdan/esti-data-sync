<?php
if (! defined('ABSPATH')) {
    exit;
}

class Esti_Data_Reader
{

    private const LOG_PREFIX = 'Esti Sync: ';

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
            $this->log_error("Data file not found at {$this->file_path}");
            return null;
        }

        $json_content = file_get_contents($this->file_path);
        if ($json_content === false) {
            $this->log_error("Could not read data file at {$this->file_path}");
            return null;
        }

        $decoded_data = json_decode($json_content, true); // true for associative array

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log_error("JSON decode error: " . json_last_error_msg() . " for file {$this->file_path}");
            return null;
        }

        if (! isset($decoded_data['data']) || ! is_array($decoded_data['data'])) {
            $this->log_error("JSON 'data' key not found or is not an array in file {$this->file_path}.");
            return null;
        }

        return $decoded_data['data'];
    }

    /**
     * Logs an error message with a consistent prefix.
     *
     * @param string $message The error message.
     */
    private function log_error(string $message): void
    {
        error_log(self::LOG_PREFIX . $message);
    }
}
