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
        if (empty($item_data['contactId'])) {
            return;
        }

        $contact_id = $this->sanitizer->s_int($item_data['contactId']);
        $is_agency_contact = $this->isAgencyContact($contact_id);
        $meta_keys = $this->getMetaKeys($is_agency_contact);

        $this->setDisplayOptions($mapped['meta_input'], $contact_id, $is_agency_contact);
        $this->setContactInformation($mapped['meta_input'], $item_data, $meta_keys);
    }

    private function isAgencyContact(int $contact_id): bool
    {
        return $this->getWpAgencyId($contact_id) !== null;
    }

    private function getMetaKeys(bool $is_agency_contact): array
    {
        $prefix = $is_agency_contact ? 'agency' : 'agent';

        return [
            'email' => "fave_{$prefix}_email",
            'mobile' => "fave_{$prefix}_mobile",
            'name' => "fave_{$prefix}_name"
        ];
    }

    private function setDisplayOptions(array &$meta, int $contact_id, bool $is_agency_contact): void
    {
        if ($is_agency_contact) {
            $meta[HouzezMetaKey::AGENT_DISPLAY_OPTION->value] = 'agency_info';
            $wp_agency_id = $this->getWpAgencyId($contact_id);
            $meta[HouzezMetaKey::PROPERTY_AGENCY->value] = (string)$wp_agency_id;
        } else {
            $meta[HouzezMetaKey::AGENT_DISPLAY_OPTION->value] = 'agent_info';
        }
    }

    private function setContactInformation(array &$meta, array $item_data, array $meta_keys): void
    {
        if (!empty($item_data['contactEmail'])) {
            $meta[$meta_keys['email']] = $this->sanitizer->s_text($item_data['contactEmail']);
        }

        if (!empty($item_data['contactPhone'])) {
            $meta[$meta_keys['mobile']] = $this->sanitizer->s_text($item_data['contactPhone']);
        }

        $contact_name = $this->buildContactName($item_data);
        if ($contact_name) {
            $meta[$meta_keys['name']] = $contact_name;
        }
    }

    private function buildContactName(array $item_data): string
    {
        $name_parts = [];

        if (!empty($item_data['contactFirstname'])) {
            $name_parts[] = $this->sanitizer->s_text($item_data['contactFirstname']);
        }

        if (!empty($item_data['contactLastname'])) {
            $name_parts[] = $this->sanitizer->s_text($item_data['contactLastname']);
        }

        return implode(' ', $name_parts);
    }

    private function getWpAgencyId(int $external_contact_id): ?int
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
