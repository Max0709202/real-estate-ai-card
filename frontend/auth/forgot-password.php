<?php
/**
 * Forgot Password Page
 */
require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/includes/functions.php';

startSessionIfNotStarted();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <title>パスワードリセット - 不動産AI名刺</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/mobile.css">
    <link rel="stylesheet" href="../assets/css/auth-mobile.css">
    <style>
        .forgot-password-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f5f5f5;
        }
        .forgot-password-box {
            background: white;
            padding: 3rem;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 450px;
        }
        .forgot-password-box h1 {
            text-align: center;
            margin-bottom: 1rem;
            color: #333;
        }
        .forgot-password-box p {
            text-align: center;
            color: #666;
            margin-bottom: 2rem;
            line-height: 1.6;
        }
        .form-group {
            margin-bottom: 1.5rem;
        }
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #333;
            font-weight: 500;
        }
        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
            box-sizing: border-box;
        }
        .form-control:focus {
            outline: none;
            border-color: #0066cc;
            box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.1);
        }
        .btn-primary {
            width: 100%;
            padding: 0.75rem;
            background: #0066cc;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 1rem;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.3s;
        }
        .btn-primary:hover {
            background: #0052a3;
        }
        .btn-primary:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        .back-link {
            text-align: center;
            margin-top: 1.5rem;
        }
        .back-link a {
            color: #0066cc;
            text-decoration: none;
            font-size: 0.9rem;
        }
        .back-link a:hover {
            text-decoration: underline;
        }
        .message {
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
            text-align: center;
        }
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .message.info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
    </style>
</head>
<body>
    <div class="forgot-password-container">
        <div class="forgot-password-box">
            <h1>パスワードリセット</h1>
            <p>登録時に使用したメールアドレスを入力してください。<br>パスワードリセット用のリンクを送信します。</p>
            
            <div id="message-container"></div>
            
            <form id="forgot-password-form">
                <div class="form-group">
                    <label for="email">メールアドレス</label>
                    <input type="email" id="email" name="email" class="form-control" required placeholder="example@email.com">
                </div>
                <button type="submit" class="btn-primary" id="submit-btn">リセットリンクを送信</button>
            </form>
            
            <div class="back-link">
                <a href="../login.php">ログインページに戻る</a>
            </div>
        </div>
    </div>
    
    <script>
        const form = document.getElementById('forgot-password-form');
        const messageContainer = document.getElementById('message-container');
        const submitBtn = document.getElementById('submit-btn');
        
        function showMessage(message, type) {
            messageContainer.innerHTML = `<div class="message ${type}">${message}</div>`;
            messageContainer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const email = document.getElementById('email').value;
            
            if (!email) {
                showMessage('メールアドレスを入力してください', 'error');
                return;
            }
            
            submitBtn.disabled = true;
            submitBtn.textContent = '送信中...';
            
            try {
                const response = await fetch('../../backend/api/auth/forgot-password.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ email: email })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showMessage(result.message || 'パスワードリセット用のリンクをメールで送信しました。メールボックスをご確認ください。', 'success');
                    form.reset();
                } else {
                    showMessage(result.message || 'エラーが発生しました。もう一度お試しください。', 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                showMessage('エラーが発生しました。しばらくしてから再度お試しください。', 'error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = 'リセットリンクを送信';
            }
        });
    </script>
</body>
</html>

