<?php
/**
 * Email Invitation Management Page
 */
require_once __DIR__ . '/../backend/config/config.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../backend/includes/functions.php';

startSessionIfNotStarted();

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();
$stmt = $db->prepare('SELECT role FROM admins WHERE id = ?');
$stmt->execute([$_SESSION['admin_id']]);
$sendEmailPageAdminRole = $stmt->fetchColumn();
$canSendInvitations = ($sendEmailPageAdminRole === 'admin' || (int) $_SESSION['admin_id'] === 1);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo rtrim(BASE_URL, '/'); ?>/favicon.php?size=32&v=2">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo rtrim(BASE_URL, '/'); ?>/favicon.php?size=16&v=2">
    <title>メール管理 - 管理画面</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="../assets/css/mobile.css">
    <link rel="stylesheet" href="../assets/css/modal.css">
    <style>
        .email-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .email-header {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .email-header h1 {
            margin: 0;
            color: #2c5282;
            flex: 1;
            min-width: 200px;
        }

        .email-header .header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .email-header .header-description {
            width: 100%;
            margin: 0;
            color: #718096;
            font-size: 14px;
        }

        .upload-section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .upload-section h2 {
            margin: 0 0 15px 0;
            color: #2c5282;
            font-size: 18px;
        }

        .upload-content {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .upload-info {
            flex: 1;
            min-width: 250px;
            font-size: 13px;
            color: #718096;
            line-height: 1.6;
        }

        .upload-zone {
            border: 2px dashed #cbd5e0;
            border-radius: 8px;
            padding: 20px 30px;
            text-align: center;
            background: #f7fafc;
            cursor: pointer;
            transition: all 0.3s;
            flex: 1;
            min-width: 300px;
        }

        .upload-zone #upload-text,
        .upload-zone #file-info {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
        }

        .upload-zone:hover {
            border-color: #3182ce;
            background: #ebf8ff;
        }

        .upload-zone.drag-over {
            border-color: #3182ce;
            background: #ebf8ff;
        }

        .table-section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow-x: auto;
        }

        .email-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .email-table th {
            background: #2c5282;
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            white-space: nowrap;
        }

        .email-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
        }

        .email-table tr:hover {
            background: #f7fafc;
        }

        .role-select {
            padding: 6px 10px;
            border: 1px solid #cbd5e0;
            border-radius: 4px;
            background: white;
            cursor: pointer;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-sent {
            background: #c6f6d5;
            color: #22543d;
        }

        .status-pending {
            background: #fed7d7;
            color: #742a2a;
        }

        .checkbox-cell {
            text-align: center;
            width: 40px;
        }

        .actions-bar {
            display: flex;
            gap: 12px;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: nowrap;
            justify-content: flex-start;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }

        .btn:active {
            transform: translateY(0);
        }

        /* Send Email Button - Green (Success/Action) */
        .btn-send-email {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }

        .btn-send-email:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
        }

        /* Select All Button - Blue (Primary Action) */
        .btn-select-all {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
        }

        .btn-select-all:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        }

        /* Deselect All Button - Orange/Amber (Clear Action) */
        .btn-deselect-all {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }

        .btn-deselect-all:hover {
            background: linear-gradient(135deg, #d97706 0%, #b45309 100%);
        }

        /* Refresh Button - Cyan/Teal (Update Action) */
        .btn-refresh {
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
            color: white;
        }

        .btn-refresh:hover {
            background: linear-gradient(135deg, #0891b2 0%, #0e7490 100%);
        }

        .stats {
            display: flex;
            gap: 20px;
            margin: 20px 0;
        }

        .stat-card {
            flex: 1;
            background: #f7fafc;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #3182ce;
            display: flex;
            justify-content: flex-start;
            align-items: center;
            gap: 50px;
        }

        .stat-label {
            font-size: 12px;
            color: #718096;
        }

        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #2c5282;
        }

        /* Tablet and Mobile Styles */
        @media (max-width: 1024px) {
            .actions-bar {
                flex-wrap: wrap;
                gap: 10px;
            }

            .btn {
                flex: 1 1 calc(50% - 5px);
                min-width: 140px;
            }
        }

        @media (max-width: 768px) {
            .email-container {
                padding: 15px;
            }

            .upload-content {
                flex-direction: column;
                gap: 15px;
            }

            .upload-info {
                width: 100%;
                min-width: unset;
            }

            .upload-zone {
                width: 100%;
                min-width: unset;
                padding: 25px 20px;
            }

            .stats {
                flex-direction: column;
                gap: 15px;
            }

            .actions-bar {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }

            .btn {
                width: 100%;
                flex: 1 1 100%;
                padding: 14px 20px;
                font-size: 15px;
                justify-content: center;
                min-width: unset;
            }

            .table-section {
                padding: 15px;
            }

            .email-table {
                font-size: 13px;
            }

            .email-table th,
            .email-table td {
                padding: 10px 8px;
            }
        }

        @media (max-width: 768px) {
            .email-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .email-header h1 {
                width: 100%;
                margin-bottom: 10px;
            }

            .email-header .header-actions {
                width: 100%;
            }

            .email-header .header-actions .btn {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .email-container {
                padding: 10px;
            }

            .email-header {
                padding: 15px;
            }

            .email-header h1 {
                font-size: 20px;
            }

            .email-header .header-description {
                font-size: 13px;
            }

            .upload-section {
                padding: 15px;
            }

            .upload-section h2 {
                font-size: 16px;
                margin-bottom: 10px;
            }

            .upload-info {
                font-size: 12px;
            }

            .upload-zone {
                padding: 20px 15px;
            }

            .btn {
                padding: 12px 16px;
                font-size: 14px;
            }

            .email-table {
                font-size: 12px;
            }

            .email-table th,
            .email-table td {
                padding: 8px 6px;
            }
        }

        /* Paid user notification form */
        .paid-notice-section {
            border-left: 4px solid #2c5282;
        }
        .paid-notice-form {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 14px;
        }
        .paid-notice-grid {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 16px;
            align-items: end;
        }
        .paid-notice-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .paid-notice-field label {
            font-size: 13px;
            font-weight: 600;
            color: #2d3748;
        }
        .paid-notice-input,
        .paid-notice-textarea {
            padding: 10px 12px;
            border: 1px solid #cbd5e0;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            width: 100%;
            box-sizing: border-box;
        }
        .paid-notice-textarea {
            min-height: 180px;
            resize: vertical;
            line-height: 1.7;
        }
        .paid-notice-input:focus,
        .paid-notice-textarea:focus {
            outline: none;
            border-color: #3182ce;
            box-shadow: 0 0 0 2px rgba(49, 130, 206, 0.2);
        }
        .paid-notice-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            color: #4a5568;
            font-size: 13px;
        }
        .paid-notice-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .btn-paid-preview {
            background: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%);
            color: white;
        }
        .btn-paid-notice {
            background: linear-gradient(135deg, #2c5282 0%, #1a365d 100%);
            color: white;
        }
        .paid-notice-history {
            margin-top: 18px;
            padding-top: 16px;
            border-top: 1px solid #e2e8f0;
        }
        .paid-notice-history-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
        }
        .paid-notice-history-header h3 {
            margin: 0;
            color: #2c5282;
            font-size: 16px;
        }
        .paid-notice-history-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .paid-notice-history-table th {
            background: #edf2f7;
            color: #2d3748;
            padding: 10px;
            text-align: left;
            font-weight: 600;
            border-bottom: 1px solid #cbd5e0;
        }
        .paid-notice-history-table td {
            padding: 10px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }
        .paid-notice-history-subject {
            max-width: 620px;
            word-break: break-word;
        }
        .paid-notice-pagination {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-top: 12px;
        }
        .paid-notice-page-btn {
            min-width: 36px;
            padding: 7px 10px;
            border: 1px solid #cbd5e0;
            border-radius: 6px;
            background: #fff;
            color: #2d3748;
            cursor: pointer;
            font-weight: 600;
        }
        .paid-notice-page-btn:hover {
            border-color: #3182ce;
            color: #2c5282;
        }
        .paid-notice-page-btn.active {
            background: #2c5282;
            border-color: #2c5282;
            color: #fff;
        }
        @media (max-width: 768px) {
            .paid-notice-grid {
                grid-template-columns: 1fr;
            }
            .paid-notice-actions {
                flex-direction: column;
            }
            .paid-notice-history-header {
                align-items: flex-start;
                flex-direction: column;
            }
        }

        /* 1件送信フォーム */
        .single-send-section .single-send-form {
            display: flex;
            flex-wrap: wrap;
            gap: 16px 24px;
            align-items: flex-end;
        }
        .single-send-row {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 180px;
        }
        .single-send-row.single-send-actions {
            min-width: auto;
            align-self: flex-end;
        }
        .single-send-label {
            font-size: 13px;
            font-weight: 600;
            color: #2d3748;
        }
        .single-send-input,
        .single-send-select {
            padding: 8px 12px;
            border: 1px solid #cbd5e0;
            border-radius: 6px;
            font-size: 14px;
            min-width: 200px;
        }
        .single-send-input:focus,
        .single-send-select:focus {
            outline: none;
            border-color: #3182ce;
            box-shadow: 0 0 0 2px rgba(49, 130, 206, 0.2);
        }
        @media (max-width: 768px) {
            .single-send-form {
                flex-direction: column;
                align-items: stretch;
            }
            .single-send-row {
                min-width: unset;
            }
            .single-send-input,
            .single-send-select {
                min-width: unset;
                width: 100%;
            }
            .single-send-row.single-send-actions {
                align-self: stretch;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            <h1>メール管理</h1>
            <div class="header-actions">
                <a href="dashboard.php" class="btn-logout" style="background: #6c757d; margin-right: 10px;">ダッシュボードに戻る</a>
                <a href="logout.php" class="btn-logout">ログアウト</a>
            </div>
            <p class="header-description">
                <?php if ($canSendInvitations): ?>
                有料ユーザーへの一斉通知、CSVでの一括登録、1件ずつの招待メール送信ができます
                <?php else: ?>
                招待一覧の閲覧のみです。招待メールの送信・登録は管理者（フル権限）のみが実行できます。
                <?php endif; ?>
            </p>
        </div>

        <?php if ($canSendInvitations): ?>
        <!-- Paid user notification section -->
        <div class="upload-section paid-notice-section">
            <h2>有料ユーザーへの一斉通知</h2>
            <p class="single-send-desc" style="margin: 0 0 15px 0; color: #718096; font-size: 13px;">
                入金済み（CR / 振込済 / ST送金）の有効ユーザーへ、障害告知や復旧見込みなどのお知らせを一斉送信します。
            </p>
            <form id="paid-notice-form" class="paid-notice-form">
                <div class="paid-notice-grid">
                    <div class="paid-notice-field">
                        <label for="paid-notice-subject">件名 <span style="color: #e53e3e;">*</span></label>
                        <input type="text" id="paid-notice-subject" class="paid-notice-input" maxlength="120" placeholder="例: 【不動産AI名刺】一時的な不具合に関するお知らせ" required>
                    </div>
                    <div class="paid-notice-actions">
                        <button type="button" class="btn btn-paid-preview" id="paid-notice-count-btn">対象件数を確認</button>
                        <button type="submit" class="btn btn-paid-notice" id="paid-notice-send-btn">有料ユーザーへ送信</button>
                    </div>
                </div>
                <div class="paid-notice-field">
                    <label for="paid-notice-message">本文 <span style="color: #e53e3e;">*</span></label>
                    <textarea id="paid-notice-message" class="paid-notice-textarea" maxlength="10000" placeholder="例:
いつも不動産AI名刺をご利用いただき、ありがとうございます。

現在、一部機能において一時的なエラーが発生しております。
復旧見込み: 本日18時頃

ご不便をおかけし申し訳ございません。" required></textarea>
                </div>
                <div class="paid-notice-meta">
                    <span id="paid-notice-target-count">対象件数: 未確認</span>
                    <span>送信対象: CR / 振込済 / ST送金 かつ有効なユーザー</span>
                </div>
            </form>
            <div id="paid-notice-result" style="margin-top: 12px;"></div>
            <div class="paid-notice-history">
                <div class="paid-notice-history-header">
                    <h3>送信済み案内</h3>
                    <span id="paid-notice-history-summary" style="color: #718096; font-size: 13px;">読み込み中...</span>
                </div>
                <div style="overflow-x: auto;">
                    <table class="paid-notice-history-table">
                        <thead>
                            <tr>
                                <th style="width: 180px;">送信日時</th>
                                <th>件名</th>
                                <th style="width: 120px;">送信件数</th>
                            </tr>
                        </thead>
                        <tbody id="paid-notice-history-tbody">
                            <tr>
                                <td colspan="3" style="text-align: center; color: #718096;">読み込み中...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div id="paid-notice-history-pagination" class="paid-notice-pagination"></div>
            </div>
        </div>

        <!-- Single send section (1件送信) -->
        <div class="upload-section single-send-section">
            <h2>1件送信</h2>
            <p class="single-send-desc" style="margin: 0 0 15px 0; color: #718096; font-size: 13px;">メールアドレスとロールを入力して、その場で招待メールを1件送信します。</p>
            <form id="single-send-form" class="single-send-form">
                <div class="single-send-row">
                    <label for="single-username" class="single-send-label">ユーザー名（任意）</label>
                    <input type="text" id="single-username" name="username" class="single-send-input" placeholder="例: 山田太郎">
                </div>
                <div class="single-send-row">
                    <label for="single-email" class="single-send-label">メールアドレス <span style="color: #e53e3e;">*</span></label>
                    <input type="email" id="single-email" name="email" class="single-send-input" placeholder="例: yamada@example.com" required>
                </div>
                <div class="single-send-row">
                    <label for="single-role" class="single-send-label">ロール</label>
                    <select id="single-role" name="role_type" class="single-send-select">
                        <option value="existing">既存</option>
                        <option value="new">新規</option>
                    </select>
                </div>
                <div class="single-send-row single-send-actions">
                    <button type="submit" class="btn btn-send-email" id="single-send-btn">1件追加して送信</button>
                </div>
            </form>
            <div id="single-send-result" style="margin-top: 12px;"></div>
        </div>

        <!-- Upload Section -->
        <div class="upload-section">
            <h2>CSVファイルをインポート（一括）</h2>
            <div class="upload-content">
                <div class="upload-info">
                    <p style="margin: 0 0 5px 0;"><strong>形式:</strong> ユーザー名, メールアドレス</p>
                    <p style="margin: 0 0 5px 0;"><strong>例:</strong> 山田太郎, yamada@example.com</p>
                    <p style="margin: 0; font-size: 12px; color: #a0aec0;">※1行目はヘッダーとして無視されます</p>
                </div>
                <form id="csv-upload-form" enctype="multipart/form-data" style="flex: 1; min-width: 300px;">
                    <div class="upload-zone" id="upload-zone">
                        <input type="file" name="csv_file" id="csv_file" accept=".csv" style="display: none;">
                        <div id="upload-text">
                            <p style="margin: 0; font-weight: 600; font-size: 14px;">CSVファイルをドラッグ&ドロップ</p>
                            <p style="margin: 0; color: #718096; font-size: 12px;">または</p>
                            <button type="button" class="btn btn-primary" style="margin-top: 5px; padding: 8px 16px; font-size: 13px; width:auto;" onclick="document.getElementById('csv_file').click()">
                                ファイルを選択
                            </button>
                        </div>
                        <div id="file-info" style="display: none;">
                            <p style="font-size: 32px; margin: 0;">✅</p>
                            <p id="file-name" style="margin: 5px 0; font-weight: 600; font-size: 13px; word-break: break-all;"></p>
                            <button type="submit" class="btn btn-success" style="margin-top: 5px; padding: 8px 16px; font-size: 13px; width:auto;">
                                アップロード
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            <div id="upload-result" style="margin-top: 15px;"></div>
        </div>
        <?php endif; ?>

        <!-- Stats Section -->
        <div class="stats">
            <div class="stat-card">
                <div class="stat-label">総件数</div>
                <div class="stat-value" id="stat-total">0</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">送信済み</div>
                <div class="stat-value" id="stat-sent">0</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">未送信</div>
                <div class="stat-value" id="stat-pending">0</div>
            </div>
        </div>

        <!-- Table Section -->
        <div class="table-section">
            <div class="actions-bar">
                <?php if ($canSendInvitations): ?>
                <button type="button" class="btn btn-send-email" onclick="sendSelectedEmails()">
                    選択したユーザーにメール送信
                </button>
                <button type="button" class="btn btn-select-all" onclick="selectAll()">
                    すべて選択
                </button>
                <button type="button" class="btn btn-deselect-all" onclick="deselectAll()">
                    選択解除
                </button>
                <?php endif; ?>
                <button type="button" class="btn btn-refresh" onclick="refreshData()">
                    更新
                </button>
            </div>

            <div style="overflow-x: auto;">
                <table class="email-table">
                    <thead>
                        <tr>
                            <?php if ($canSendInvitations): ?>
                            <th class="checkbox-cell">
                                <input type="checkbox" id="select-all-checkbox" onchange="toggleSelectAll(this)">
                            </th>
                            <?php endif; ?>
                            <th>No.</th>
                            <th>ユーザー名</th>
                            <th>メールアドレス</th>
                            <th>ロール設定</th>
                            <th>メール送信</th>
                            <th>送信日時</th>
                        </tr>
                    </thead>
                    <tbody id="invitations-tbody">
                        <tr>
                            <td colspan="<?php echo $canSendInvitations ? 7 : 6; ?>" style="text-align: center; padding: 40px; color: #718096;">
                                データを読み込んでいます...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="../assets/js/modal.js"></script>
    <script>
        window.canSendInvitations = <?php echo $canSendInvitations ? 'true' : 'false'; ?>;
        let invitationsData = [];
        let paidNoticeHistoryPage = 1;

        // Load data on page load
        // Suppress browser extension errors (content.js, etc.)
        window.addEventListener('unhandledrejection', function(event) {
            const errorMessage = event.reason?.message || event.reason?.toString() || '';
            if (errorMessage.includes('message port closed') ||
                errorMessage.includes('content.js') ||
                errorMessage.includes('Extension context invalidated')) {
                event.preventDefault(); // Suppress the error
                return;
            }
        });

        // Suppress console errors from extensions
        const originalError = console.error;
        console.error = function(...args) {
            const errorString = args.join(' ');
            if (errorString.includes('content.js') ||
                errorString.includes('message port closed') ||
                errorString.includes('Extension context')) {
                return; // Don't log extension errors
            }
            originalError.apply(console, args);
        };

        document.addEventListener('DOMContentLoaded', function() {
            loadInvitations();
            if (window.canSendInvitations) {
                setupUploadHandlers();
                setupSingleSendForm();
                setupPaidNoticeForm();
            }
        });

        function setupPaidNoticeForm() {
            const form = document.getElementById('paid-notice-form');
            const countBtn = document.getElementById('paid-notice-count-btn');
            if (countBtn) {
                countBtn.addEventListener('click', function() {
                    checkPaidNoticeTargetCount();
                });
            }
            if (form) {
                form.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    await sendPaidNotice();
                });
            }
            checkPaidNoticeTargetCount();
            loadPaidNoticeHistory(1);
        }

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function formatPaidNoticeDate(value) {
            if (!value) return '-';
            const date = new Date(String(value).replace(' ', 'T'));
            if (Number.isNaN(date.getTime())) {
                return escapeHtml(value);
            }
            return date.toLocaleString('ja-JP');
        }

        async function loadPaidNoticeHistory(page = 1) {
            const tbody = document.getElementById('paid-notice-history-tbody');
            const summary = document.getElementById('paid-notice-history-summary');
            const pagination = document.getElementById('paid-notice-history-pagination');

            if (!tbody || !summary || !pagination) return;

            paidNoticeHistoryPage = page;
            summary.textContent = '読み込み中...';
            tbody.innerHTML = '<tr><td colspan="3" style="text-align: center; color: #718096;">読み込み中...</td></tr>';
            pagination.innerHTML = '';

            try {
                const response = await fetch('../backend/api/admin/get-paid-user-notification-history.php?page=' + encodeURIComponent(page), {
                    credentials: 'include'
                });
                const result = await response.json();

                if (!result.success) {
                    summary.textContent = '取得失敗';
                    tbody.innerHTML = '<tr><td colspan="3" style="text-align: center; color: #c53030;">送信済み案内を取得できませんでした</td></tr>';
                    return;
                }

                const data = result.data || {};
                const notifications = Array.isArray(data.notifications) ? data.notifications : [];
                const pager = data.pagination || { page: 1, total: 0, total_pages: 1 };
                renderPaidNoticeHistory(notifications, pager);
            } catch (error) {
                console.error('Paid notification history error:', error);
                summary.textContent = '取得失敗';
                tbody.innerHTML = '<tr><td colspan="3" style="text-align: center; color: #c53030;">送信済み案内を取得できませんでした</td></tr>';
            }
        }

        function renderPaidNoticeHistory(notifications, pager) {
            const tbody = document.getElementById('paid-notice-history-tbody');
            const summary = document.getElementById('paid-notice-history-summary');
            const pagination = document.getElementById('paid-notice-history-pagination');
            if (!tbody || !summary || !pagination) return;

            const total = Number(pager.total || 0);
            const totalPages = Math.max(1, Number(pager.total_pages || 1));
            const currentPage = Math.max(1, Number(pager.page || 1));
            summary.textContent = total > 0
                ? '全' + total + '件中 ' + currentPage + ' / ' + totalPages + 'ページ'
                : '送信済み案内はありません';

            if (notifications.length === 0) {
                tbody.innerHTML = '<tr><td colspan="3" style="text-align: center; color: #718096;">送信済み案内はありません</td></tr>';
            } else {
                tbody.innerHTML = notifications.map(item => {
                    const sent = Number(item.sent_count || 0);
                    const failed = Number(item.failed_count || 0);
                    const failedText = failed > 0 ? ' / 失敗 ' + failed + '件' : '';
                    return `
                        <tr>
                            <td style="white-space: nowrap;">${formatPaidNoticeDate(item.sent_at)}</td>
                            <td class="paid-notice-history-subject">${escapeHtml(item.subject || '-')}</td>
                            <td style="white-space: nowrap;">成功 ${sent}件${failedText}</td>
                        </tr>
                    `;
                }).join('');
            }

            pagination.innerHTML = '';
            if (totalPages <= 1) return;

            for (let page = 1; page <= totalPages; page++) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'paid-notice-page-btn' + (page === currentPage ? ' active' : '');
                btn.textContent = page;
                btn.addEventListener('click', function() {
                    loadPaidNoticeHistory(page);
                });
                pagination.appendChild(btn);
            }
        }

        async function checkPaidNoticeTargetCount() {
            const countEl = document.getElementById('paid-notice-target-count');
            if (countEl) countEl.textContent = '対象件数: 確認中...';
            try {
                const response = await fetch('../backend/api/admin/send-paid-user-notification.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ dry_run: true }),
                    credentials: 'include'
                });
                const result = await response.json();
                if (result.success) {
                    const count = result.data && typeof result.data.recipient_count !== 'undefined'
                        ? result.data.recipient_count
                        : 0;
                    if (countEl) countEl.textContent = '対象件数: ' + count + '件';
                    return count;
                }
                if (countEl) countEl.textContent = '対象件数: 取得失敗';
                return 0;
            } catch (error) {
                console.error('Paid notification count error:', error);
                if (countEl) countEl.textContent = '対象件数: 取得失敗';
                return 0;
            }
        }

        async function sendPaidNotice() {
            if (window.canSendInvitations !== true) {
                showWarning('有料ユーザーへの一斉通知は管理者のみが実行できます');
                return;
            }

            const subjectEl = document.getElementById('paid-notice-subject');
            const messageEl = document.getElementById('paid-notice-message');
            const resultDiv = document.getElementById('paid-notice-result');
            const sendBtn = document.getElementById('paid-notice-send-btn');
            const subject = subjectEl ? subjectEl.value.trim() : '';
            const message = messageEl ? messageEl.value.trim() : '';

            if (!subject) {
                showWarning('件名を入力してください');
                return;
            }
            if (!message) {
                showWarning('本文を入力してください');
                return;
            }

            const count = await checkPaidNoticeTargetCount();
            if (count <= 0) {
                showWarning('送信対象の有料ユーザーが見つかりません');
                return;
            }

            if (!confirm('有料ユーザー ' + count + '件へ通知メールを送信します。よろしいですか？')) {
                return;
            }

            if (sendBtn) {
                sendBtn.disabled = true;
                sendBtn.textContent = '送信中...';
            }
            if (resultDiv) {
                resultDiv.innerHTML = '<p style="color: #3182ce; margin: 0;">送信中です。件数が多い場合は時間がかかります...</p>';
            }

            try {
                const response = await fetch('../backend/api/admin/send-paid-user-notification.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ subject, message }),
                    credentials: 'include'
                });
                const result = await response.json();

                if (result.success) {
                    const data = result.data || {};
                    const failedEmails = Array.isArray(data.failed_emails) && data.failed_emails.length
                        ? '<p style="margin: 8px 0 0 0; color: #744210; font-size: 12px;">失敗宛先（一部）: ' + data.failed_emails.join(', ') + '</p>'
                        : '';
                    if (resultDiv) {
                        resultDiv.innerHTML = `
                            <div style="background: #c6f6d5; padding: 15px; border-radius: 4px; border-left: 4px solid #38a169;">
                                <p style="margin: 0; color: #22543d;"><strong>送信完了:</strong> ${result.message}</p>
                                <p style="margin: 5px 0 0 0; color: #22543d;">対象: ${data.recipient_count || 0}件 / 成功: ${data.sent || 0}件 / 失敗: ${data.failed || 0}件</p>
                                ${failedEmails}
                            </div>
                        `;
                    }
                    showSuccess(result.message || '通知を送信しました');
                    loadPaidNoticeHistory(1);
                } else {
                    if (resultDiv) {
                        resultDiv.innerHTML = `
                            <div style="background: #fed7d7; padding: 15px; border-radius: 4px; border-left: 4px solid #c53030;">
                                <p style="margin: 0; color: #742a2a;"><strong>エラー:</strong> ${result.message || '送信に失敗しました'}</p>
                            </div>
                        `;
                    }
                    showError(result.message || '送信に失敗しました');
                }
            } catch (error) {
                console.error('Paid notification send error:', error);
                if (resultDiv) {
                    resultDiv.innerHTML = `
                        <div style="background: #fed7d7; padding: 15px; border-radius: 4px; border-left: 4px solid #c53030;">
                            <p style="margin: 0; color: #742a2a;"><strong>エラー:</strong> 送信中にエラーが発生しました</p>
                        </div>
                    `;
                }
                showError('送信中にエラーが発生しました');
            } finally {
                if (sendBtn) {
                    sendBtn.disabled = false;
                    sendBtn.textContent = '有料ユーザーへ送信';
                }
            }
        }

        function setupSingleSendForm() {
            const form = document.getElementById('single-send-form');
            if (!form) return;
            form.addEventListener('submit', async function(e) {
                e.preventDefault();
                await sendSingleInvitation();
            });
        }

        async function sendSingleInvitation() {
            if (window.canSendInvitations !== true) {
                return;
            }
            const emailInput = document.getElementById('single-email');
            const usernameInput = document.getElementById('single-username');
            const roleSelect = document.getElementById('single-role');
            const resultDiv = document.getElementById('single-send-result');
            const btn = document.getElementById('single-send-btn');

            const email = (emailInput && emailInput.value) ? emailInput.value.trim() : '';
            const username = (usernameInput && usernameInput.value) ? usernameInput.value.trim() : '';
            const roleType = (roleSelect && roleSelect.value) ? roleSelect.value : 'new';

            if (!email) {
                if (typeof showWarning === 'function') {
                    showWarning('メールアドレスを入力してください');
                } else {
                    alert('メールアドレスを入力してください');
                }
                return;
            }
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                if (typeof showWarning === 'function') {
                    showWarning('有効なメールアドレスを入力してください');
                } else {
                    alert('有効なメールアドレスを入力してください');
                }
                return;
            }

            if (btn) {
                btn.disabled = true;
                btn.textContent = '送信中...';
            }
            if (resultDiv) resultDiv.innerHTML = '';

            try {
                const response = await fetch('../backend/api/admin/add-and-send-single-invitation.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ email, username, role_type: roleType }),
                    credentials: 'include'
                });
                const result = await response.json();

                if (result.success) {
                    if (typeof showSuccess === 'function') {
                        showSuccess(result.message || '1件の招待メールを送信しました');
                    } else {
                        alert(result.message || '送信しました');
                    }
                    const singleForm = document.getElementById('single-send-form');
                    if (singleForm) singleForm.reset();
                    loadInvitations();
                } else {
                    if (typeof showError === 'function') {
                        showError(result.message || '送信に失敗しました');
                    } else {
                        alert(result.message || '送信に失敗しました');
                    }
                    if (resultDiv) {
                        resultDiv.innerHTML = '<p style="color: #c53030; margin: 0;">' + (result.message || '送信に失敗しました') + '</p>';
                    }
                }
            } catch (err) {
                console.error('Send single invitation error:', err);
                if (typeof showError === 'function') {
                    showError('送信中にエラーが発生しました');
                } else {
                    alert('送信中にエラーが発生しました');
                }
                if (resultDiv) {
                    resultDiv.innerHTML = '<p style="color: #c53030; margin: 0;">送信中にエラーが発生しました</p>';
                }
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = '1件追加して送信';
                }
            }
        }

        // Setup upload handlers
        function setupUploadHandlers() {
            const uploadZone = document.getElementById('upload-zone');
            const fileInput = document.getElementById('csv_file');
            const csvForm = document.getElementById('csv-upload-form');
            if (!uploadZone || !fileInput || !csvForm) {
                return;
            }

            // Drag and drop
            uploadZone.addEventListener('dragover', function(e) {
                e.preventDefault();
                uploadZone.classList.add('drag-over');
            });

            uploadZone.addEventListener('dragleave', function(e) {
                e.preventDefault();
                uploadZone.classList.remove('drag-over');
            });

            uploadZone.addEventListener('drop', function(e) {
                e.preventDefault();
                uploadZone.classList.remove('drag-over');

                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    fileInput.files = files;
                    showFileInfo(files[0]);
                }
            });

            // File input change
            fileInput.addEventListener('change', function(e) {
                if (e.target.files.length > 0) {
                    showFileInfo(e.target.files[0]);
                }
            });

            // Form submit
            csvForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                await uploadCSV();
            });
        }

        function showFileInfo(file) {
            document.getElementById('file-name').textContent = file.name;
            document.getElementById('upload-text').style.display = 'none';
            document.getElementById('file-info').style.display = 'block';
        }

        async function uploadCSV() {
            if (window.canSendInvitations !== true) {
                return;
            }
            const formData = new FormData(document.getElementById('csv-upload-form'));
            const resultDiv = document.getElementById('upload-result');

            resultDiv.innerHTML = '<p style="color: #3182ce;">アップロード中...</p>';

            try {
                const response = await fetch('../backend/api/admin/import-email-csv.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'include'
                });

                const result = await response.json();

                if (result.success) {
                    const errors = result.data.errors || [];
                    const errorsHtml = errors.length > 0
                        ? `<p style="margin: 8px 0 0 0; color: #744210; font-size: 12px;"><strong>スキップ理由:</strong><br>${errors.slice(0, 10).join('<br>')}${errors.length > 10 ? '<br>...他 ' + (errors.length - 10) + ' 件' : ''}</p>`
                        : '';
                    resultDiv.innerHTML = `
                        <div style="background: #c6f6d5; padding: 15px; border-radius: 4px; border-left: 4px solid #38a169;">
                            <p style="margin: 0; color: #22543d;"><strong>✅ 成功:</strong> ${result.message}</p>
                            <p style="margin: 5px 0 0 0; color: #22543d;">
                                インポート: ${result.data.imported}件 |
                                更新: ${result.data.updated}件 |
                                スキップ: ${result.data.skipped}件
                            </p>
                            ${errorsHtml}
                        </div>
                    `;

                    // Reset form
                    document.getElementById('csv-upload-form').reset();
                    document.getElementById('upload-text').style.display = 'block';
                    document.getElementById('file-info').style.display = 'none';

                    // Reload data
                    setTimeout(() => loadInvitations(), 1000);
                } else {
                    resultDiv.innerHTML = `
                        <div style="background: #fed7d7; padding: 15px; border-radius: 4px; border-left: 4px solid #c53030;">
                            <p style="margin: 0; color: #742a2a;"><strong>❌ エラー:</strong> ${result.message}</p>
                        </div>
                    `;
                }
            } catch (error) {
                resultDiv.innerHTML = `
                    <div style="background: #fed7d7; padding: 15px; border-radius: 4px; border-left: 4px solid #c53030;">
                        <p style="margin: 0; color: #742a2a;"><strong>❌ エラー:</strong> アップロードに失敗しました</p>
                    </div>
                `;
            }
        }

        async function loadInvitations() {
            try {
                const response = await fetch('../backend/api/admin/get-email-invitations.php', {
                    credentials: 'include'
                });

                const result = await response.json();

                if (result.success) {
                    invitationsData = result.data.invitations;
                    renderTable();
                    updateStats();
                } else {
                    showError('データの読み込みに失敗しました');
                }
            } catch (error) {
                console.error('Error:', error);
                showError('データの読み込み中にエラーが発生しました');
            }
        }

        function renderTable() {
            const tbody = document.getElementById('invitations-tbody');
            const canSend = window.canSendInvitations === true;
            const colSpan = canSend ? 7 : 6;
            const emptyHint = canSend
                ? 'データがありません。CSVファイルをインポートしてください。'
                : 'データがありません。';

            if (invitationsData.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="${colSpan}" style="text-align: center; padding: 40px; color: #718096;">
                            ${emptyHint}
                        </td>
                    </tr>
                `;
                return;
            }

            tbody.innerHTML = invitationsData.map((inv, index) => {
                const sentBadge = inv.email_sent == 1
                    ? '<span class="status-badge status-sent">送信済み</span>'
                    : '<span class="status-badge status-pending">未送信</span>';

                const sentDate = inv.sent_at
                    ? new Date(inv.sent_at).toLocaleString('ja-JP')
                    : '-';

                const roleLabel = inv.role_type === 'existing' ? '既存' : '新規';
                const roleCell = canSend
                    ? `<td>
                            <select class="role-select" onchange="updateRole(${inv.id}, this.value)">
                                <option value="new" ${inv.role_type === 'new' ? 'selected' : ''}>新規</option>
                                <option value="existing" ${inv.role_type === 'existing' ? 'selected' : ''}>既存</option>
                            </select>
                        </td>`
                    : `<td>${roleLabel}</td>`;

                const checkboxCell = canSend
                    ? `<td class="checkbox-cell">
                            <input type="checkbox" class="row-checkbox" value="${inv.id}">
                        </td>`
                    : '';

                return `
                    <tr data-id="${inv.id}">
                        ${checkboxCell}
                        <td>${index + 1}</td>
                        <td>${inv.username || '-'}</td>
                        <td>${inv.email}</td>
                        ${roleCell}
                        <td>${sentBadge}</td>
                        <td style="white-space: nowrap;">${sentDate}</td>
                    </tr>
                `;
            }).join('');
        }

        function updateStats() {
            const total = invitationsData.length;
            const sent = invitationsData.filter(inv => inv.email_sent == 1).length;
            const pending = total - sent;

            document.getElementById('stat-total').textContent = total;
            document.getElementById('stat-sent').textContent = sent;
            document.getElementById('stat-pending').textContent = pending;
        }

        async function updateRole(id, roleType) {
            if (window.canSendInvitations !== true) {
                return;
            }
            try {
                const response = await fetch('../backend/api/admin/update-role.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ id, role_type: roleType }),
                    credentials: 'include'
                });

                const result = await response.json();

                if (result.success) {
                    showSuccess('ロールタイプを更新しました');
                    loadInvitations();
                } else {
                    showError('更新に失敗しました');
                }
            } catch (error) {
                console.error('Error:', error);
                showError('更新中にエラーが発生しました');
            }
        }

        async function sendSelectedEmails() {
            if (window.canSendInvitations !== true) {
                if (typeof showWarning === 'function') {
                    showWarning('招待メールの送信は管理者のみが実行できます');
                } else {
                    alert('招待メールの送信は管理者のみが実行できます');
                }
                return;
            }
            const checkboxes = document.querySelectorAll('.row-checkbox:checked');
            const ids = Array.from(checkboxes).map(cb => parseInt(cb.value));

            if (ids.length === 0) {
                showWarning('送信するユーザーを選択してください');
                return;
            }

            if (!confirm(`${ids.length}件のメールを送信しますか？`)) {
                return;
            }

            try {
                const response = await fetch('../backend/api/admin/send-invitation-email.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ ids }),
                    credentials: 'include'
                });

                const result = await response.json();

                if (result.success) {
                    showSuccess(result.message);
                    loadInvitations();
                } else {
                    showError('メール送信に失敗しました');
                }
            } catch (error) {
                console.error('Error:', error);
                showError('メール送信中にエラーが発生しました');
            }
        }

        function selectAll() {
            document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = true);
        }

        function deselectAll() {
            document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = false);
        }

        function toggleSelectAll(checkbox) {
            if (checkbox.checked) {
                selectAll();
            } else {
                deselectAll();
            }
        }

        function refreshData() {
            loadInvitations();
            showSuccess('データを更新しました');
        }
    </script>
</body>
</html>
