<?php
/**
 * 画像向き正規化テストスクリプト
 * 
 * このスクリプトは、EXIF向き情報を持つ画像をアップロードして、
 * 正規化が正しく動作することを確認します。
 * 
 * 使用方法:
 * 1. スマートフォンで撮影した画像（EXIF向き情報あり）を準備
 * 2. このスクリプトにPOSTリクエストで画像を送信
 * 3. 結果を確認
 * 
 * 例: curl -X POST -F "file=@/path/to/image.jpg" http://your-domain/php/backend/test-image-orientation.php
 */

// エラー表示を有効化（開発用）
ini_set('display_errors', 1);
error_reporting(E_ALL);

// メモリ制限を増やす
ini_set('memory_limit', '256M');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: text/html; charset=UTF-8');

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>画像向き正規化テスト</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 2px solid #4CAF50;
            padding-bottom: 10px;
        }
        .upload-form {
            margin: 30px 0;
            padding: 20px;
            background: #f9f9f9;
            border-radius: 4px;
        }
        .upload-form input[type="file"] {
            margin: 10px 0;
            padding: 10px;
            border: 2px dashed #4CAF50;
            border-radius: 4px;
            width: 100%;
            box-sizing: border-box;
        }
        .upload-form button {
            background: #4CAF50;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        .upload-form button:hover {
            background: #45a049;
        }
        .result {
            margin: 20px 0;
            padding: 15px;
            border-radius: 4px;
        }
        .result.success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .result.error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        .result.info {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }
        .image-preview {
            margin: 20px 0;
            text-align: center;
        }
        .image-preview img {
            max-width: 100%;
            height: auto;
            border: 2px solid #ddd;
            border-radius: 4px;
            margin: 10px;
        }
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        .info-table th,
        .info-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .info-table th {
            background: #f8f9fa;
            font-weight: bold;
        }
        .info-table tr:hover {
            background: #f5f5f5;
        }
        code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📸 画像向き正規化テスト</h1>
        
        <?php
        // アップロード処理
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['test_image'])) {
            $file = $_FILES['test_image'];
            
            echo '<div class="result info">';
            echo '<h2>処理結果</h2>';
            
            // エラーチェック
            if ($file['error'] !== UPLOAD_ERR_OK) {
                echo '<div class="result error">';
                echo '<strong>エラー:</strong> ファイルアップロードに失敗しました（エラーコード: ' . $file['error'] . '）';
                echo '</div>';
            } else {
                // 一時ファイルパス
                $tmpPath = $file['tmp_name'];
                
                // MIMEタイプ検証
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $tmpPath);
                finfo_close($finfo);
                
                echo '<div class="result info">';
                echo '<h3>📋 アップロード情報</h3>';
                echo '<table class="info-table">';
                echo '<tr><th>ファイル名</th><td>' . htmlspecialchars($file['name']) . '</td></tr>';
                echo '<tr><th>MIMEタイプ</th><td><code>' . htmlspecialchars($mimeType) . '</code></td></tr>';
                echo '<tr><th>ファイルサイズ</th><td>' . number_format($file['size'] / 1024, 2) . ' KB</td></tr>';
                echo '</table>';
                echo '</div>';
                
                // EXIF情報の読み取り（処理前）
                $exifBefore = null;
                $orientationBefore = null;
                if (function_exists('exif_read_data') && $mimeType === 'image/jpeg') {
                    $exifBefore = @exif_read_data($tmpPath);
                    if ($exifBefore && isset($exifBefore['Orientation'])) {
                        $orientationBefore = (int)$exifBefore['Orientation'];
                    }
                }
                
                // 画像情報の取得（処理前）
                $imageInfoBefore = @getimagesize($tmpPath);
                $widthBefore = $imageInfoBefore ? $imageInfoBefore[0] : 0;
                $heightBefore = $imageInfoBefore ? $imageInfoBefore[1] : 0;
                
                echo '<div class="result info">';
                echo '<h3>🔍 処理前の情報</h3>';
                echo '<table class="info-table">';
                echo '<tr><th>画像サイズ</th><td>' . $widthBefore . ' × ' . $heightBefore . ' px</td></tr>';
                if ($orientationBefore !== null) {
                    echo '<tr><th>EXIF向き</th><td><code>' . $orientationBefore . '</code> ';
                    $orientationNames = [
                        1 => 'TopLeft (正しい向き)',
                        2 => 'TopRight (水平反転)',
                        3 => 'BottomRight (180度回転)',
                        4 => 'BottomLeft (垂直反転)',
                        5 => 'LeftTop (90度CCW + 水平反転)',
                        6 => 'RightTop (90度CW回転)',
                        7 => 'RightBottom (90度CW + 水平反転)',
                        8 => 'LeftBottom (90度CCW回転)'
                    ];
                    echo isset($orientationNames[$orientationBefore]) ? $orientationNames[$orientationBefore] : '不明';
                    echo '</td></tr>';
                } else {
                    echo '<tr><th>EXIF向き</th><td>なし（JPEG以外、またはEXIF情報なし）</td></tr>';
                }
                echo '</table>';
                echo '</div>';
                
                // テスト用の保存先
                $testDir = __DIR__ . '/../uploads/test/';
                if (!is_dir($testDir)) {
                    mkdir($testDir, 0755, true);
                }
                
                $testFilePath = $testDir . 'test_' . time() . '_' . basename($file['name']);
                
                // ファイルをコピー（元のファイルを保持）
                copy($tmpPath, $testFilePath);
                
                // 向き正規化の実行
                echo '<div class="result info">';
                echo '<h3>⚙️ 正規化処理</h3>';
                
                $normalized = normalizeImageOrientation($testFilePath, $mimeType);
                
                if ($normalized) {
                    echo '<p>✅ <strong>正規化が完了しました</strong></p>';
                } else {
                    echo '<p>⚠️ <strong>正規化がスキップされました</strong>（JPEG以外、またはEXIF情報なし）</p>';
                }
                echo '</div>';
                
                // EXIF情報の読み取り（処理後）
                $exifAfter = null;
                $orientationAfter = null;
                if (function_exists('exif_read_data') && $mimeType === 'image/jpeg') {
                    $exifAfter = @exif_read_data($testFilePath);
                    if ($exifAfter && isset($exifAfter['Orientation'])) {
                        $orientationAfter = (int)$exifAfter['Orientation'];
                    }
                }
                
                // 画像情報の取得（処理後）
                $imageInfoAfter = @getimagesize($testFilePath);
                $widthAfter = $imageInfoAfter ? $imageInfoAfter[0] : 0;
                $heightAfter = $imageInfoAfter ? $imageInfoAfter[1] : 0;
                
                echo '<div class="result ' . ($normalized ? 'success' : 'info') . '">';
                echo '<h3>📊 処理後の情報</h3>';
                echo '<table class="info-table">';
                echo '<tr><th>画像サイズ</th><td>' . $widthAfter . ' × ' . $heightAfter . ' px</td></tr>';
                if ($orientationAfter !== null) {
                    echo '<tr><th>EXIF向き</th><td><code>' . $orientationAfter . '</code> ';
                    if ($orientationAfter === 1) {
                        echo '✅ TopLeft (正規化済み)';
                    } else {
                        echo '⚠️ 正規化されていない可能性があります';
                    }
                    echo '</td></tr>';
                } else {
                    echo '<tr><th>EXIF向き</th><td>✅ EXIFメタデータが削除されました（正常）</td></tr>';
                }
                echo '</table>';
                echo '</div>';
                
                // サイズ変更の確認
                if ($widthBefore !== $widthAfter || $heightBefore !== $heightAfter) {
                    echo '<div class="result success">';
                    echo '<p>✅ <strong>画像が回転されました</strong></p>';
                    echo '<p>処理前: ' . $widthBefore . ' × ' . $heightBefore . ' px</p>';
                    echo '<p>処理後: ' . $widthAfter . ' × ' . $heightAfter . ' px</p>';
                    echo '</div>';
                }
                
                // 画像プレビュー
                echo '<div class="image-preview">';
                echo '<h3>🖼️ 処理後の画像プレビュー</h3>';
                $relativePath = 'backend/uploads/test/' . basename($testFilePath);
                echo '<img src="../' . htmlspecialchars($relativePath) . '" alt="処理後の画像">';
                echo '<p><small>保存先: <code>' . htmlspecialchars($testFilePath) . '</code></small></p>';
                echo '</div>';
                
                // 成功メッセージ
                if ($normalized && ($orientationAfter === null || $orientationAfter === 1)) {
                    echo '<div class="result success">';
                    echo '<h3>✅ テスト成功</h3>';
                    echo '<p>画像の向きが正しく正規化されました。EXIFメタデータも削除されています。</p>';
                    echo '</div>';
                }
            }
            
            echo '</div>';
        }
        ?>
        
        <div class="upload-form">
            <h2>📤 テスト画像をアップロード</h2>
            <form method="POST" enctype="multipart/form-data">
                <label for="test_image">画像ファイルを選択（JPEG推奨、EXIF向き情報付き）:</label>
                <input type="file" name="test_image" id="test_image" accept="image/jpeg,image/jpg,image/png,image/gif,image/webp" required>
                <button type="submit">テスト実行</button>
            </form>
        </div>
        
        <div class="result info">
            <h3>📝 テスト手順</h3>
            <ol>
                <li>スマートフォンで縦向きまたは横向きに画像を撮影</li>
                <li>その画像をこのフォームでアップロード</li>
                <li>処理結果を確認：
                    <ul>
                        <li>EXIF向き情報が読み取られているか</li>
                        <li>画像が正しい向きに回転されているか</li>
                        <li>EXIFメタデータが削除されているか</li>
                        <li>画像サイズが適切に変更されているか</li>
                    </ul>
                </li>
            </ol>
            
            <h3>🔧 システム情報</h3>
            <table class="info-table">
                <tr>
                    <th>Imagick利用可能</th>
                    <td><?php echo class_exists('Imagick') ? '✅ はい' : '❌ いいえ（GDフォールバック使用）'; ?></td>
                </tr>
                <tr>
                    <th>EXIF拡張機能</th>
                    <td><?php echo function_exists('exif_read_data') ? '✅ はい' : '❌ いいえ'; ?></td>
                </tr>
                <tr>
                    <th>GD拡張機能</th>
                    <td><?php echo function_exists('imagecreatefromjpeg') ? '✅ はい' : '❌ いいえ'; ?></td>
                </tr>
            </table>
        </div>
    </div>
</body>
</html>

