<?php
/** Compatibility facade: existing stored/customized message keys remain unchanged. */
require_once __DIR__ . '/../messages/loader.php';
$mainValues = achbotMessages('legacy_messages');
$buttonValues = achbotMessages('legacy_buttons');
