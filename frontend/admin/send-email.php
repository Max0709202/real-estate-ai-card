<?php
/**
 * Email Invitation Management Page
 */
require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/config/database.php';
require_once __DIR__ . '/../../backend/includes/functions.php';

startSessionIfNotStarted();

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>メール招待管理 - 管理画面</title>
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
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            <h1>メール招待管理</h1>
            <div class="header-actions">
                <a href="dashboard.php" class="btn btn-secondary">← ダッシュボードに戻る</a>
            </div>
            <p class="header-description">CSVファイルをインポートして、ユーザーに招待メールを送信します</p>
        </div>

        <!-- Upload Section -->
        <div class="upload-section">
            <h2>CSVファイルをインポート</h2>
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
                <button class="btn btn-send-email" onclick="sendSelectedEmails()">
                    選択したユーザーにメール送信
                </button>
                <button class="btn btn-select-all" onclick="selectAll()">
                    すべて選択
                </button>
                <button class="btn btn-deselect-all" onclick="deselectAll()">
                    選択解除
                </button>
                <button class="btn btn-refresh" onclick="refreshData()">
                    更新
                </button>
            </div>

            <div style="overflow-x: auto;">
                <table class="email-table">
                    <thead>
                        <tr>
                            <th class="checkbox-cell">
                                <input type="checkbox" id="select-all-checkbox" onchange="toggleSelectAll(this)">
                            </th>
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
                            <td colspan="7" style="text-align: center; padding: 40px; color: #718096;">
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
        let invitationsData = [];

        // Load data on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadInvitations();
            setupUploadHandlers();
        });

        // Setup upload handlers
        function setupUploadHandlers() {
            const uploadZone = document.getElementById('upload-zone');
            const fileInput = document.getElementById('csv_file');

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
            document.getElementById('csv-upload-form').addEventListener('submit', async function(e) {
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
            const formData = new FormData(document.getElementById('csv-upload-form'));
            const resultDiv = document.getElementById('upload-result');

            resultDiv.innerHTML = '<p style="color: #3182ce;">アップロード中...</p>';

            try {
                const response = await fetch('../../backend/api/admin/import-email-csv.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'include'
                });

                const result = await response.json();

                if (result.success) {
                    resultDiv.innerHTML = `
                        <div style="background: #c6f6d5; padding: 15px; border-radius: 4px; border-left: 4px solid #38a169;">
                            <p style="margin: 0; color: #22543d;"><strong>✅ 成功:</strong> ${result.message}</p>
                            <p style="margin: 5px 0 0 0; color: #22543d;">
                                インポート: ${result.data.imported}件 |
                                更新: ${result.data.updated}件 |
                                スキップ: ${result.data.skipped}件
                            </p>
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
                const response = await fetch('../../backend/api/admin/get-email-invitations.php', {
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

            if (invitationsData.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px; color: #718096;">
                            データがありません。CSVファイルをインポートしてください。
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

                return `
                    <tr data-id="${inv.id}">
                        <td class="checkbox-cell">
                            <input type="checkbox" class="row-checkbox" value="${inv.id}" ${inv.email_sent == 1 ? 'disabled' : ''}>
                        </td>
                        <td>${index + 1}</td>
                        <td>${inv.username || '-'}</td>
                        <td>${inv.email}</td>
                        <td>
                            <select class="role-select" onchange="updateRole(${inv.id}, this.value)" ${inv.email_sent == 1 ? 'disabled' : ''}>
                                <option value="new" ${inv.role_type === 'new' ? 'selected' : ''}>新規</option>
                                <option value="existing" ${inv.role_type === 'existing' ? 'selected' : ''}>既存</option>
                                <option value="free" ${inv.role_type === 'free' ? 'selected' : ''}>無料</option>
                            </select>
                        </td>
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
            try {
                const response = await fetch('../../backend/api/admin/update-role.php', {
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
                const response = await fetch('../../backend/api/admin/send-invitation-email.php', {
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
            document.querySelectorAll('.row-checkbox:not(:disabled)').forEach(cb => cb.checked = true);
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

