<?php
/**
 * Retired automated TRON checker. This legacy filename deliberately remains
 * inert for independently scheduled aaPanel jobs and old deployment layouts.
 * Future chain settlement requires a separate verified, reviewed implementation.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
}
return;
