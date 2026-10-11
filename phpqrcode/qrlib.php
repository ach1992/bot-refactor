<?php
// phpqrcode/qrlib.php
// Minimal loader for merged phpqrcode (keep only phpqrcode.php)

if (!class_exists('QRcode', false)) {
    require_once __DIR__ . '/phpqrcode.php';
}
