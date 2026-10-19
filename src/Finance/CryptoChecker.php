<?php
/** Retired TRON automatic reconciliation: no chain/API or payment mutations. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}
return;
