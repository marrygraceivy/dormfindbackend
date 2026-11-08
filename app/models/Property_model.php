<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Property_model extends Model {

    public function get_all_properties_from_db() {
        $properties = $this->db->table('properties p')
            ->select('p.property_id as id, p.title, p.description, p.address, p.monthly_rent as price, p.total_capacity, p.available_beds, p.status, p.owner_id,
                       u.first_name, u.last_name, u.phone, u.role, 
                       pi.image_url')
            ->left_join('users u', 'p.owner_id = u.user_id')
            ->left_join('property_images pi', 'p.property_id = pi.property_id AND pi.is_primary = 1')
            ->get_all();

        if (!empty($properties)) {
            foreach ($properties as &$prop) {
                // Lokasyon batay sa address
                if (stripos($prop['address'], 'Masipit') !== false) {
                    $prop['location'] = 'Masipit';
                } elseif (stripos($prop['address'], 'Lalud') !== false) {
                    $prop['location'] = 'Lalud';
                } elseif (stripos($prop['address'], 'Tawiran') !== false) {
                    $prop['location'] = 'Tawiran';
                } else {
                    $prop['location'] = 'C5';
                }

                $prop['type'] = 'Dormitory';
                $prop['amenities'] = 'Wi-Fi included • On-site laundry • Study lounge • Furnished room';

                // Landlord Mapping (Ivy Reyes, Marianne Umandal, Mean Laplano, Gail Martinez)
                if ($prop['location'] === 'Masipit') {
                    $prop['landlord'] = 'Ivy Reyes';
                    $prop['contact'] = '09637249530';
                    $prop['landlord_role'] = 'Property Owner';
                } elseif ($prop['location'] === 'Lalud') {
                    $prop['landlord'] = 'Marianne Umandal';
                    $prop['contact'] = '09362951758';
                    $prop['landlord_role'] = 'Building Owner';
                } elseif ($prop['location'] === 'Tawiran') {
                    $prop['landlord'] = 'Mean Laplano';
                    $prop['contact'] = '09375187392';
                    $prop['landlord_role'] = 'Property Manager';
                } else {
                    $prop['landlord'] = 'Gail Martinez';
                    $prop['contact'] = '09428956105';
                    $prop['landlord_role'] = 'Landlord';
                }

                // Main Dorm Image (dorm1.jpg hanggang dorm10.jpg)
                $mainImg = !empty($prop['image_url']) ? $prop['image_url'] : 'dorm' . $prop['id'] . '.jpg';
                $prop['image'] = $mainImg;

                // Kunin ang gallery images mula sa database
                $db_images = $this->db->table('property_images')
                    ->select('image_url')
                    ->where('property_id', $prop['id'])
                    ->where('is_primary', 0)
                    ->get_all();

                $prop_images = [];
                if (!empty($db_images)) {
                    foreach ($db_images as $img) {
                        $prop_images[] = $img['image_url'];
                    }
                }

                $fallback_props = ['prop1.jpg', 'prop2.jpg', 'prop3.jpg', 'prop4.jpg'];
                while (count($prop_images) < 4) {
                    $prop_images[] = $fallback_props[count($prop_images) % count($fallback_props)];
                }

                $prop['prop_pics'] = array_merge([$mainImg], array_slice($prop_images, 0, 4));
            }
        }

        return $properties;
    }
}