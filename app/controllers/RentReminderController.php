<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
class RentReminderController extends Controller
{
    public function run()
    {
        if (PHP_SAPI!=='cli') { http_response_code(404); return; }
        $this->call->library('database'); $this->call->library('dorm_services');
        // A scheduler calls this daily; unique keys prevent repeated reminders.
        // Rental dates are interpreted in Philippine time, regardless of the database server's timezone.
        $localToday='DATE(UTC_TIMESTAMP() + INTERVAL 8 HOUR)';
        $rows=$this->db->raw("SELECT i.inquiry_id,i.rent_end,p.title,DATEDIFF(i.rent_end,$localToday) days_left,tenant.user_id tenant_id,tenant.email tenant_email,landlord.user_id landlord_id,landlord.email landlord_email FROM inquiries_applications i JOIN rental_contracts c ON c.inquiry_id=i.inquiry_id JOIN properties p ON p.property_id=i.property_id JOIN users tenant ON tenant.user_id=i.applicant_id JOIN users landlord ON landlord.user_id=p.owner_id WHERE i.status='accepted' AND c.landlord_signed_at IS NOT NULL AND c.student_signed_at IS NOT NULL AND tenant.is_banned=0 AND landlord.is_banned=0 AND DATEDIFF(i.rent_end,$localToday) IN (30,7,3,1,0)")->fetchAll();
        foreach ($rows as $r) {
            $kind='rent_end_'.$r['days_left'];
            $tenantMessage='Your rental for '.$r['title'].' ends on '.$r['rent_end'].'. Contact your landlord about renewal or move-out.';
            $landlordMessage='The rental for '.$r['title'].' ends on '.$r['rent_end'].'. Contact the tenant about renewal or move-out.';
            $this->db->raw('INSERT IGNORE INTO rental_notifications (inquiry_id,recipient_id,kind,message) VALUES (?,?,?,?)',[$r['inquiry_id'],$r['tenant_id'],$kind,$tenantMessage]);
            $this->db->raw('INSERT IGNORE INTO rental_notifications (inquiry_id,recipient_id,kind,message) VALUES (?,?,?,?)',[$r['inquiry_id'],$r['landlord_id'],$kind,$landlordMessage]);
        }
        $pending=$this->db->raw("SELECT n.notification_id,n.message,u.email FROM rental_notifications n JOIN users u ON u.user_id=n.recipient_id WHERE n.emailed=0 AND u.is_banned=0 AND n.kind LIKE 'rent_end_%'")->fetchAll();
        foreach ($pending as $n) if (Dorm_services::email($n['email'],'DormFind rental ending soon',$n['message'])==='accepted') $this->db->raw('UPDATE rental_notifications SET emailed=1 WHERE notification_id=?',[$n['notification_id']]);
        echo 'Rent reminders checked: '.count($rows).PHP_EOL;
    }
}
