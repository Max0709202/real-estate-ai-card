<?php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/database.php';
// require __DIR__ . '/../vendor/autoload.php';    
require __DIR__ . '/../includes/functions.php';

if (sendEmail('maxlucky0709@gmail.com', 'SMTP Test', '<h1>Success!</h1>')) {
    echo "Email sent successfully!";
} else {
    echo "Email failed. Check logs.";
}

?>