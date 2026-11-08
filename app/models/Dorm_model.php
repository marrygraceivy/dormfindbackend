<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Data access for the actual dormfind_db columns. Complex joins/locks remain bound SQL. */
class Dorm_model extends Model
{
    private const TABLES = [
        'users' => 'user_id', 'properties' => 'property_id',
        'property_images' => 'image_id', 'property_amenities' => 'property_id',
        'roommate_preferences' => 'preference_id',
        'inquiries_applications' => 'inquiry_id', 'refresh_tokens' => 'id',
        'rental_contracts' => 'contract_id', 'rental_notifications' => 'notification_id',
        'registration_email_otps' => 'otp_id', 'password_reset_tokens' => 'token_id',
        'account_email_otps' => 'otp_id',
    ];

    private function allowed($table)
    {
        if (!isset(self::TABLES[$table])) throw new InvalidArgumentException('Unknown DormFind table.');
        return self::TABLES[$table];
    }

    public function findRow($table, array $where)
    {
        $this->allowed($table);
        return $this->db->table($table)->where($where)->get() ?: null;
    }

    public function rows($table, array $where = [], $order = null)
    {
        $this->allowed($table);
        $query = $this->db->table($table);
        if ($where) $query->where($where);
        if ($order) $query->order_by($order[0], $order[1]);
        return $query->get_all();
    }

    public function createRow($table, array $data)
    {
        $this->allowed($table);
        return $this->db->table($table)->insert($data);
    }

    public function changeRows($table, array $where, array $data)
    {
        $this->allowed($table);
        if (!$where) throw new InvalidArgumentException('A scoped update is required.');
        return $this->db->table($table)->where($where)->update($data);
    }

    public function removeRows($table, array $where)
    {
        $this->allowed($table);
        if (!$where) throw new InvalidArgumentException('A scoped delete is required.');
        return $this->db->table($table)->where($where)->delete();
    }

    public function one($sql, array $args = []) { return $this->db->raw($sql, $args)->fetch() ?: null; }
    public function all($sql, array $args = []) { return $this->db->raw($sql, $args)->fetchAll(); }

    public function savePreference($userId, array $data)
    {
        // Atomic upsert avoids two concurrent questionnaire saves creating duplicates.
        $this->db->raw('INSERT INTO roommate_preferences (user_id,sleep_schedule,cleanliness,noise_tolerance,study_habits,pet_friendly) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE sleep_schedule=VALUES(sleep_schedule),cleanliness=VALUES(cleanliness),noise_tolerance=VALUES(noise_tolerance),study_habits=VALUES(study_habits),pet_friendly=VALUES(pet_friendly)',
            [$userId,$data['sleep_schedule'],$data['cleanliness'],$data['noise_tolerance'],$data['study_habits'],$data['pet_friendly']]);
    }

    public function listings(array $filters)
    {
        foreach (['q', 'amenities', 'min_price', 'max_price'] as $key) {
            if (isset($filters[$key]) && !is_string($filters[$key]) && !is_numeric($filters[$key])) throw new InvalidArgumentException('Invalid search filter.', 422);
        }
        $query = $this->db->table('properties p')
            ->select('p.property_id,p.owner_id,p.title,p.description,p.address,p.latitude,p.longitude,p.monthly_rent,p.total_capacity,p.available_beds,p.status,p.created_at,u.first_name AS owner_first_name,u.last_name AS owner_last_name')
            ->join('users u', 'u.user_id=p.owner_id')
            ->where('u.is_verified', 1)->where('u.is_banned', 0)
            ->where('p.status', 'approved')->where('p.available_beds', '>', 0)
            ->where_exists('SELECT 1 FROM property_images pi WHERE pi.property_id=p.property_id');
        foreach (['min_price' => '>=', 'max_price' => '<='] as $key => $operator) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                if (!is_numeric($filters[$key]) || $filters[$key] < 0) throw new InvalidArgumentException('Enter a valid price.', 422);
                $query->where('p.monthly_rent', $operator, $filters[$key]);
            }
        }
        if (!empty($filters['q'])) {
            $term = '%' . substr($filters['q'], 0, 100) . '%';
            $query->grouped(function ($q) use ($term) { $q->like('p.title', $term)->or_like('p.address', $term); });
        }
        $amenities = array_filter(explode(',', $filters['amenities'] ?? ''));
        foreach (array_values(array_unique(array_slice($amenities, 0, 15))) as $index => $amenity) {
            $alias = 'a' . $index;
            $query->join('property_amenities ' . $alias, $alias . '.property_id=p.property_id')
                ->where($alias . '.amenity', $amenity);
        }
        return $query->order_by('p.created_at', 'DESC')->get_all();
    }

    public function propertyChildren(array $ids): array
    {
        $out = ['images' => [], 'amenities' => []];
        foreach (array_chunk(array_unique($ids), 200) as $chunk) {
            foreach ($this->db->table('property_images')->in('property_id', $chunk)->get_all() as $image) {
                $out['images'][$image['property_id']][] = $image;
            }
            foreach ($this->db->table('property_amenities')->in('property_id', $chunk)->get_all() as $amenity) {
                $out['amenities'][$amenity['property_id']][] = $amenity['amenity'];
            }
        }
        return $out;
    }

    public function throttle(string $key, int $limit, int $seconds): void
    {
        $key = hash('sha256', $key);
        $this->db->raw('INSERT INTO security_rate_limits (bucket,hits,reset_at) VALUES (?,1,UNIX_TIMESTAMP()+?) ON DUPLICATE KEY UPDATE hits=IF(reset_at<=UNIX_TIMESTAMP(),1,hits+1),reset_at=IF(reset_at<=UNIX_TIMESTAMP(),VALUES(reset_at),reset_at)', [$key, $seconds]);
        $row = $this->db->table('security_rate_limits')->where('bucket', $key)->get();
        if ((int)$row['hits'] > $limit) {
            header('Retry-After: ' . max(1, (int)$row['reset_at'] - time()));
            throw new RuntimeException('Too many attempts. Please try again later.', 429);
        }
        if (random_int(1, 100) === 1) $this->db->raw('DELETE FROM security_rate_limits WHERE reset_at<UNIX_TIMESTAMP() LIMIT 1000');
    }
}
