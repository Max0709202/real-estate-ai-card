<?php
/**
 * Admin Card Edit Page (Admin only)
 */
require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/includes/functions.php';
require_once __DIR__ . '/../../backend/config/database.php';

startSessionIfNotStarted();

// Check if logged in as admin
if (empty($_SESSION['admin_id']) || ($_SESSION['admin_role'] ?? 'client') !== 'admin') {
    header('Location: login.php');
    exit();
}

$adminId = $_SESSION['admin_id'];
$adminEmail = $_SESSION['admin_email'];
$cardId = $_GET['id'] ?? 0;

$database = new Database();
$db = $database->getConnection();

// Get business card
$stmt = $db->prepare("
    SELECT bc.*, u.email as user_email, u.name as user_name
    FROM business_cards bc
    JOIN users u ON bc.user_id = u.id
    WHERE bc.id = ?
");
$stmt->execute([$cardId]);
$card = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$card) {
    header('Location: dashboard.php');
    exit();
}

// Get greetings
$stmt = $db->prepare("SELECT * FROM greeting_messages WHERE business_card_id = ? ORDER BY display_order ASC");
$stmt->execute([$cardId]);
$greetings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get tech tools
$stmt = $db->prepare("SELECT * FROM tech_tool_selections WHERE business_card_id = ? ORDER BY display_order ASC");
$stmt->execute([$cardId]);
$techTools = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get communication methods
$stmt = $db->prepare("SELECT * FROM communication_methods WHERE business_card_id = ? ORDER BY display_order ASC");
$stmt->execute([$cardId]);
$communicationMethods = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>名刺編集 - 管理者</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/edit.css">
    <style>
        .admin-header {
            background: #fff;
            padding: 1rem;
            margin-bottom: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .admin-header a {
            color: #0066cc;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="admin-header">
        <a href="dashboard.php">← ダッシュボードに戻る</a>
        <span style="margin-left: 1rem;">編集者: <?php echo htmlspecialchars($adminEmail); ?></span>
    </div>
    
    <?php 
    // Include the edit form from edit.php but with admin context
    $_SESSION['is_admin_edit'] = true;
    $_SESSION['admin_editing_card_id'] = $cardId;
    $_SESSION['admin_editor_id'] = $adminId;
    $_SESSION['admin_editor_email'] = $adminEmail;
    
    // Set user_id to card owner for compatibility
    $_SESSION['user_id'] = $card['user_id'];
    
    // Load card data into session for edit.php
    $_SESSION['editing_business_card'] = $card;
    ?>
    
    <script>
        // Redirect to regular edit page with admin context
        window.location.href = '../edit.php?admin_edit=1&card_id=<?php echo $cardId; ?>';
    </script>
</body>
</html>








