<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

// Non-secret settings and secret references stay together in LavaLust config;
// the actual credentials remain only in the local .env (never in React).
foreach (['RECAPTCHA_SITE_KEY', 'RECAPTCHA_SECRET_KEY', 'RECAPTCHA_ALLOWED_HOSTNAMES',
    'RECAPTCHA_DEV_BYPASS', 'MAPBOX_PUBLIC_TOKEN', 'CLOUDINARY_CLOUD_NAME',
    'CLOUDINARY_API_KEY', 'CLOUDINARY_API_SECRET', 'FIREBASE_API_KEY',
    'FIREBASE_AUTH_DOMAIN', 'FIREBASE_DATABASE_URL', 'FIREBASE_PROJECT_ID',
    'FIREBASE_APP_ID', 'FIREBASE_SERVICE_ACCOUNT', 'SENDGRID_API_KEY',
    'SENDGRID_FROM_EMAIL', 'SENDGRID_FROM_NAME'] as $key) {
    $config['dorm_' . strtolower($key)] = getenv($key) ?: '';
}
