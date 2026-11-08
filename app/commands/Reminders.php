<?php
class Reminders
{
    public static $command='reminders';
    public static $description='Send due rental expiry notifications';
    public function handle() { passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(PUBLIC_DIR.'index.php').' '.escapeshellarg('rent-reminders')); }
}
