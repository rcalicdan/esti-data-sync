<?php
if (!defined('ABSPATH')) {
    exit;
}


class Esti_Agent_Info_Mapper_Service
{
    private Esti_Sanitizer_Service $sanitizer;

    public function __construct(Esti_Sanitizer_Service $sanitizer)
    {
        $this->sanitizer = $sanitizer;
    }

    public function map(array &$mapped, array $item_data): void
    {
        $meta = &$mapped['meta_input'];

        if (empty($item_data['contactId'])) {
            return;
        }

        $contact_id = $this->sanitizer->s_int($item_data['contactId']);
        $meta[HouzezMetaKey::AGENT_DISPLAY_OPTION->value] = 'agency_info';
        $wp_agency_id = $this->_get_wp_agency_id($contact_id);

        if ($wp_agency_id) {
            $meta[HouzezMetaKey::PROPERTY_AGENCY->value] = (string)$wp_agency_id;
        }

        if (!empty($item_data['contactEmail'])) {
            $meta['fave_agent_email'] = $this->sanitizer->s_text($item_data['contactEmail']);
        }

        if (!empty($item_data['contactPhone'])) {
            $meta['fave_agent_mobile'] = $this->sanitizer->s_text($item_data['contactPhone']);
        }

        $contact_name = '';

        if (!empty($item_data['contactFirstname'])) {
            $contact_name = $this->sanitizer->s_text($item_data['contactFirstname']);
            if (!empty($item_data['contactLastname'])) {
                $contact_name .= ' ' . $this->sanitizer->s_text($item_data['contactLastname']);
            }
        } elseif (!empty($item_data['contactLastname'])) {
            $contact_name = $this->sanitizer->s_text($item_data['contactLastname']);
        }


        if ($contact_name) {
            $meta['fave_agent_name'] = $contact_name;
        }
    }

    private function _get_wp_agency_id(int $external_contact_id): ?int
    {
        $contact_to_agency_map = [
            145581 => 2792,
            136583 => 2792,
            147224 => 2792,
            130235 => 2792,
            145584 => 2792,
            80793 => 2792,
            79629 => 2792,
            120476 => 2792
        ];

        return $contact_to_agency_map[$external_contact_id] ?? null;
    }
}
