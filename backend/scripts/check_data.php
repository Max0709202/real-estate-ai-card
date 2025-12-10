<?php
/**
 * Check imported license data
 */
require_once __DIR__ . '/../config/database.php';

$db = (new Database())->getConnection();

echo "=== License Data Check ===\n\n";

// Count records
$stmt = $db->query("SELECT COUNT(*) as cnt FROM real_estate_licenses");
$count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
echo "Total records: {$count}\n\n";

// Show sample data
$stmt = $db->query("SELECT prefecture, renewal_number, registration_number, company_name, address FROM real_estate_licenses LIMIT 10");
echo "Sample data:\n";
echo str_repeat('-', 100) . "\n";
printf("%-10s | %-8s | %-12s | %-30s | %-30s\n", "Prefecture", "Renewal", "Reg. No.", "Company", "Address");
echo str_repeat('-', 100) . "\n";

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    printf("%-10s | %-8s | %-12s | %-30s | %-30s\n",
        mb_substr($row['prefecture'], 0, 10),
        $row['renewal_number'],
        $row['registration_number'],
        mb_substr($row['company_name'] ?? '', 0, 30),
        mb_substr($row['address'] ?? '', 0, 30)
    );
}

