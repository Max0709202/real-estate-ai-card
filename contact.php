<?php
require_once __DIR__ . '/backend/config/config.php';
require_once __DIR__ . '/backend/includes/functions.php';

startSessionIfNotStarted();

$errors = [];
$successMessage = '';

$name    = isset($_POST['name']) ? trim($_POST['name']) : '';
$email   = isset($_POST['email']) ? trim($_POST['email']) : '';
$subject = isset($_POST['subject']) ? trim($_POST['subject']) : '';
$body    = isset($_POST['message']) ? trim($_POST['message']) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($name === '') {
        $errors['name'] = 'お名前を入力してください。';
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = '有効なメールアドレスを入力してください。';
    }

    if ($body === '') {
        $errors['message'] = 'お問い合わせ内容を入力してください。';
    }

    if (empty($errors)) {
        $mailTo   = defined('NOTIFICATION_EMAIL') ? NOTIFICATION_EMAIL : (getenv('SMTP_FROM_EMAIL') ?: 'no-reply@ai-fcard.com');
        $mailSubj = $subject !== '' ? $subject : '不動産AI名刺サイトからのお問い合わせ';

        $htmlMessage = '<p>不動産AI名刺サイトよりお問い合わせが届きました。</p>'
            . '<table cellpadding="6" cellspacing="0" border="0" style="border-collapse:collapse;">'
            . '<tr><th align="left">お名前</th><td>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</td></tr>'
            . '<tr><th align="left">メールアドレス</th><td>' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</td></tr>'
            . '<tr><th align="left">件名</th><td>' . htmlspecialchars($subject !== '' ? $subject : '(未入力)', ENT_QUOTES, 'UTF-8') . '</td></tr>'
            . '<tr><th align="left" valign="top">内容</th><td>' . nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')) . '</td></tr>'
            . '</table>';

        $textMessage = "不動産AI名刺サイトよりお問い合わせが届きました。\n\n"
            . "お名前: {$name}\n"
            . "メールアドレス: {$email}\n"
            . "件名: " . ($subject !== '' ? $subject : '(未入力)') . "\n\n"
            . "内容:\n{$body}\n";

        $sent = false;

        try {
            if (function_exists('sendEmail')) {
                $sent = sendEmail(
                    $mailTo,
                    $mailSubj,
                    $htmlMessage,
                    $textMessage,
                    'contact_form',
                    null,
                    null
                );
            }
        } catch (Throwable $e) {
            error_log('Contact form email send error: ' . $e->getMessage());
            $sent = false;
        }

        if ($sent) {
            $successMessage = 'お問い合わせを送信しました。担当者より折り返しご連絡いたします。';
            $name = $email = $subject = $body = '';
        } else {
            $errors['general'] = '送信中にエラーが発生しました。時間をおいて再度お試しください。';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <title>お問い合わせ | 不動産AI名刺</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@300;400;500;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/mobile.css">
    <link rel="stylesheet" href="assets/css/lp.css">
</head>
<body class="contact-page">
    <?php include __DIR__ . '/includes/header.php'; ?>

    <main class="contact-main">
        <section class="contact-hero">
            <div class="container">
                <h1 class="contact-title">お問い合わせ</h1>
                <p class="contact-lead">
                    サービスに関するご質問・ご相談・お見積もりのご依頼など、<br>
                    下記フォームよりお気軽にお問い合わせください。
                </p>
            </div>
        </section>

        <section class="contact-form-section">
            <div class="container">
                <?php if (!empty($successMessage)): ?>
                    <div class="contact-alert contact-alert-success">
                        <?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors['general'])): ?>
                    <div class="contact-alert contact-alert-error">
                        <?php echo htmlspecialchars($errors['general'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <form class="contact-form" action="contact.php" method="post" novalidate>
                    <div class="contact-form-grid">
                        <div class="contact-form-group">
                            <label for="contact-name">お名前 <span class="contact-required">必須</span></label>
                            <input
                                type="text"
                                id="contact-name"
                                name="name"
                                class="contact-input<?php echo isset($errors['name']) ? ' has-error' : ''; ?>"
                                value="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>"
                                placeholder="山田 太郎"
                            >
                            <?php if (isset($errors['name'])): ?>
                                <p class="contact-error-text"><?php echo htmlspecialchars($errors['name'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="contact-form-group">
                            <label for="contact-email">メールアドレス <span class="contact-required">必須</span></label>
                            <input
                                type="email"
                                id="contact-email"
                                name="email"
                                class="contact-input<?php echo isset($errors['email']) ? ' has-error' : ''; ?>"
                                value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>"
                                placeholder="example@domain.jp"
                            >
                            <?php if (isset($errors['email'])): ?>
                                <p class="contact-error-text"><?php echo htmlspecialchars($errors['email'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="contact-form-group">
                        <label for="contact-subject">件名</label>
                        <input
                            type="text"
                            id="contact-subject"
                            name="subject"
                            class="contact-input"
                            value="<?php echo htmlspecialchars($subject, ENT_QUOTES, 'UTF-8'); ?>"
                            placeholder="お問い合わせ内容の概要をご記入ください"
                        >
                    </div>

                    <div class="contact-form-group">
                        <label for="contact-message">お問い合わせ内容 <span class="contact-required">必須</span></label>
                        <textarea
                            id="contact-message"
                            name="message"
                            class="contact-textarea<?php echo isset($errors['message']) ? ' has-error' : ''; ?>"
                            rows="8"
                            placeholder="できるだけ詳しくご記入いただけますと、よりスムーズにご案内できます。"
                        ><?php echo htmlspecialchars($body, ENT_QUOTES, 'UTF-8'); ?></textarea>
                        <?php if (isset($errors['message'])): ?>
                            <p class="contact-error-text"><?php echo htmlspecialchars($errors['message'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="contact-form-footer">
                        <p class="contact-privacy-note">
                            ご入力いただいた情報は、お問い合わせへの回答およびサービスのご案内のみに使用し、<br class="only-pc">
                            当社のプライバシーポリシーに基づき、適切に管理いたします。
                        </p>
                        <button type="submit" class="btn-primary contact-submit-button">
                            送信する
                        </button>
                    </div>
                </form>
            </div>
        </section>
    </main>

    <script src="assets/js/main.js"></script>
</body>
</html>

