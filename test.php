<?php
declare(strict_types=1);
http_response_code(403);
header('Content-Type: text/plain; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
echo '403 Forbidden';
