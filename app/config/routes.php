<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
$router->get('/', 'DormController::index');
$router->get('api/csrf', 'DormController::csrf');
$router->get('api/readiness', 'DormController::readiness');
$router->get('api/health', 'DormController::health');
$router->get('api/config', 'DormController::config');
$router->post('api/auth/register', 'DormController::register');
$router->post('api/auth/send-email-otp', 'DormController::sendEmailOtp');
$router->post('api/auth/login', 'DormController::login');
$router->post('api/auth/forgot-password', 'DormController::forgotPassword');
$router->post('api/auth/reset-password', 'DormController::resetPassword');
$router->post('api/auth/refresh', 'DormController::refresh');
$router->post('api/auth/logout', 'DormController::logout');
$router->get('api/auth/me', 'DormController::me');
$router->put('api/profile', 'DormController::updateProfile');
$router->post('api/profile/email-otp', 'DormController::sendProfileEmailOtp');
$router->put('api/profile/email', 'DormController::changeProfileEmail');
$router->get('api/properties', 'DormController::properties');
$router->post('api/properties', 'DormController::saveProperty');
$router->get('api/properties/{id}', 'DormController::property');
$router->put('api/properties/{id}', 'DormController::saveProperty');
$router->delete('api/properties/{id}', 'DormController::deleteProperty');
$router->get('api/landlord/properties', 'DormController::ownedProperties');
$router->post('api/properties/{id}/images', 'DormController::uploadImage');
$router->delete('api/properties/{id}/images/{image}', 'DormController::deleteImage');
$router->put('api/properties/{id}/images/{image}/primary', 'DormController::primaryImage');
$router->get('api/roommates/preferences', 'DormController::preferences');
$router->put('api/roommates/preferences', 'DormController::savePreferences');
$router->get('api/roommates/matches', 'DormController::matches');
$router->get('api/inquiries', 'DormController::inquiries');
$router->post('api/inquiries', 'DormController::createInquiry');
$router->put('api/inquiries/{id}', 'DormController::updateInquiry');
$router->post('api/chat/token', 'DormController::chatToken');
$router->get('api/chat/contacts', 'DormController::contacts');
$router->post('api/chat/conversation', 'DormController::conversation');
$router->get('api/admin/overview', 'DormController::adminOverview');
$router->put('api/admin/users/{id}', 'DormController::adminUser');
$router->put('api/admin/properties/{id}', 'DormController::adminProperty');
$router->delete('api/admin/properties/{id}', 'DormController::adminDeleteProperty');
$router->post('api/contracts', 'DormController::createContract');
$router->get('api/contracts/{id}', 'DormController::contract');
$router->post('api/contracts/{id}/sign', 'DormController::signContract');
$router->get('api/notifications', 'DormController::notifications');
$router->get('api/reports', 'DormController::reports');

// Migration commands are available only through PHP CLI, never through public URLs.
if (PHP_SAPI === 'cli') {
    $router->get('rent-reminders', 'RentReminderController::run');
    $router->get('create-migration/{migration_class}', 'MigrationController::create_migration');
    $router->get('migrate', 'MigrationController::migrate');
    $router->get('rollback', 'MigrationController::rollback');
    $router->get('rollback-all', 'MigrationController::rollback_all');
    $router->get('refresh', 'MigrationController::refresh');
    $router->get('status', 'MigrationController::status');
}
