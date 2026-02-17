<?php
/**
 * Create Payment Intent API (Stripe)
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../middleware/auth.php';

// Stripe SDK読み込み（Composer経由）
require_once __DIR__ . '/../../vendor/autoload.php';
use Stripe\Stripe;
use Stripe\PaymentIntent;
use Stripe\Customer;
use Stripe\Subscription;
use Stripe\Price;
use Stripe\Product;

header('Content-Type: application/json; charset=UTF-8');

try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method !== 'POST') {
        sendErrorResponse('Method not allowed', 405);
    }

    $userId = requireAuth();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $database = new Database();
    $db = $database->getConnection();

    // ユーザー情報取得（停止されたアカウントの検出も含む）
    $stmt = $db->prepare("
        SELECT u.user_type, u.email, u.phone_number, bc.id as business_card_id,
               s.status as subscription_status, bc.card_status
        FROM users u
        LEFT JOIN business_cards bc ON u.id = bc.user_id
        LEFT JOIN subscriptions s ON u.id = s.user_id
        WHERE u.id = ?
        ORDER BY s.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $userInfo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$userInfo) {
        sendErrorResponse('ユーザー情報が見つかりません', 404);
    }

    // Business card must exist for payment
    if (empty($userInfo['business_card_id'])) {
        sendErrorResponse('ビジネスカードが作成されていません。先にビジネスカード情報を登録してください。', 400);
    }

    // 停止されたアカウントの検出（復活処理）
    $isCanceledAccount = false;
    if (($userInfo['subscription_status'] === 'canceled') || 
        ($userInfo['card_status'] === 'canceled')) {
        $isCanceledAccount = true;
    }

    // 価格計算: 停止されたアカウントの場合は新規ユーザーとして扱う
    $userType = $userInfo['user_type'];
    if ($isCanceledAccount) {
        // 停止されたアカウントの復活は新規ユーザーと同じ料金
        $userType = 'new';
    }

    // payment_typeが明示的に指定されている場合はそれを優先
    // ただし、停止されたアカウントの場合は強制的に'new'として扱う
    $paymentTypeInput = $input['payment_type'] ?? null;
    if ($isCanceledAccount) {
        $paymentTypeInput = 'new'; // 復活の場合は必ず新規料金
    } elseif (!$paymentTypeInput) {
        $paymentTypeInput = $userType;
    }

    // データベーススキーマに合わせて変換（'new' -> 'new_user', 'existing' -> 'existing_user'）
    // 停止されたアカウントの場合は必ず'new_user'として扱う
    if ($isCanceledAccount) {
        $paymentType = 'new_user';
    } else {
        $paymentType = $paymentTypeInput;
        if ($paymentTypeInput === 'new') {
            $paymentType = 'new_user';
        } elseif ($paymentTypeInput === 'existing') {
            $paymentType = 'existing_user';
        }
    }

    $paymentMethod = $input['payment_method'] ?? 'credit_card';

    $amount = 0;
    $taxAmount = 0;
    $totalAmount = 0;
    $monthlyAmount = 0;

    // 停止されたアカウントの復活の場合、必ず新規ユーザー料金を適用
    if ($isCanceledAccount || $paymentType === 'new_user') {
        $amount = PRICING_NEW_USER_INITIAL; // ¥30,000
        $taxAmount = $amount * TAX_RATE;
        $totalAmount = $amount + $taxAmount;

        // 月額料金も計算
        $monthlyAmount = PRICING_NEW_USER_MONTHLY; // ¥500
    } elseif ($paymentType === 'existing_user') {
        $amount = PRICING_EXISTING_USER_INITIAL; // ¥20,000
        $taxAmount = $amount * TAX_RATE;
        $totalAmount = $amount + $taxAmount;
    }

    // Validate that amount is greater than 0
    if ($totalAmount <= 0) {
        sendErrorResponse('決済金額が0円です。', 400);
    }

    // Stripe APIキー設定
    if (empty(STRIPE_SECRET_KEY)) {
        error_log("Stripe Secret Key is not configured");
        sendErrorResponse('Stripe設定が完了していません', 500);
    }
    
    // Check if Stripe SDK is loaded
    if (!class_exists('Stripe\Stripe')) {
        error_log("Stripe SDK not loaded. Check vendor/autoload.php");
        sendErrorResponse('決済システムの初期化に失敗しました', 500);
    }
    
    Stripe::setApiKey(STRIPE_SECRET_KEY);

    // 決済レコードを作成
    $stmt = $db->prepare("
        INSERT INTO payments (user_id, business_card_id, payment_type, amount, tax_amount, total_amount, payment_method, payment_status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
    ");
    
    $stmt->execute([
        $userId,
        $userInfo['business_card_id'],
        $paymentType,
        $amount,
        $taxAmount,
        $totalAmount,
        $paymentMethod
    ]);

    $paymentId = $db->lastInsertId();
    $clientSecret = null;
    $stripeCustomerId = null;
    $stripePaymentIntentId = null;
    $stripeSubscriptionId = null;

    // Update business_cards payment_status based on payment method
    // Credit card will be updated to 'CR' via webhook on success
    // Bank transfer sets to 'BANK_PENDING' immediately
    if ($paymentMethod === 'bank_transfer') {
        $stmt = $db->prepare("
            UPDATE business_cards 
            SET payment_status = 'BANK_PENDING'
            WHERE id = ?
        ");
        $stmt->execute([$userInfo['business_card_id']]);
        
        // Enforce open rule: force close if currently open (BANK_PENDING doesn't allow OPEN)
        enforceOpenPaymentStatusRule($db, $userInfo['business_card_id'], 'BANK_PENDING');
    }

    // Stripe Customer作成または取得
    try {
        $stmt = $db->prepare("SELECT stripe_customer_id FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $userStripe = $stmt->fetch();
    } catch (\Exception $e) {
        // stripe_customer_idカラムが存在しない場合は新規作成
        $userStripe = null;
    }

    if (!empty($userStripe['stripe_customer_id'])) {
        // 既存のCustomerを使用
        $stripeCustomerId = $userStripe['stripe_customer_id'];
        try {
            $customer = Customer::retrieve($stripeCustomerId);
        } catch (\Exception $e) {
            // Customerが見つからない場合は新規作成
            $stripeCustomerId = null;
        }
    }

    if (empty($stripeCustomerId)) {
        // 新規Customer作成
        $customer = Customer::create([
            'email' => $userInfo['email'],
            'phone' => $userInfo['phone_number'] ?? null,
            'metadata' => [
                'user_id' => $userId,
                'business_card_id' => $userInfo['business_card_id']
            ]
        ]);
        $stripeCustomerId = $customer->id;

        // データベースに保存（カラムが存在する場合のみ）
        try {
            $stmt = $db->prepare("UPDATE users SET stripe_customer_id = ? WHERE id = ?");
            $stmt->execute([$stripeCustomerId, $userId]);
        } catch (\Exception $e) {
            // カラムが存在しない場合は無視
            error_log("stripe_customer_id column may not exist: " . $e->getMessage());
        }
    }

    // Stripe PaymentIntent作成
    if ($paymentMethod === 'credit_card') {
        $paymentIntentParams = [
            'amount' => (int)$totalAmount, // JPYは最小単位が1円のため、100倍不要
            'currency' => 'jpy',
            'customer' => $stripeCustomerId,
            'payment_method_types' => ['card'],
            'metadata' => [
                'user_id' => (string)$userId,
                'payment_id' => (string)$paymentId,
                'payment_type' => $paymentType,
                'business_card_id' => (string)$userInfo['business_card_id']
            ],
            'description' => '不動産AI名刺 - ' . (($paymentType === 'new_user' || $userType === 'new') ? '新規ユーザー' : '既存ユーザー') . '初期費用'
        ];

        // 銀行振込の場合は自動決済を無効化
        if ($paymentMethod === 'bank_transfer') {
            $paymentIntentParams['payment_method_types'] = ['customer_balance'];
            $paymentIntentParams['payment_method_data'] = [
                'type' => 'customer_balance'
            ];
            $paymentIntentParams['payment_method_options'] = [
                'customer_balance' => [
                    'funding_type' => 'bank_transfer',
                    'bank_transfer' => [
                        'type' => 'jp_bank_transfer'
                    ]
                ]
            ];
        }

        $paymentIntent = PaymentIntent::create($paymentIntentParams);
        $stripePaymentIntentId = $paymentIntent->id;
        $clientSecret = $paymentIntent->client_secret;

        // データベースにPaymentIntent IDを保存
        $stmt = $db->prepare("UPDATE payments SET stripe_payment_intent_id = ? WHERE id = ?");
        $stmt->execute([$stripePaymentIntentId, $paymentId]);
    } elseif ($paymentMethod === 'bank_transfer') {
        // 銀行振込の場合はPaymentIntentを作成（日本ではStripeの銀行振込機能を使用）
        try {
            // Create PaymentIntent with customer_balance for Japanese bank transfer
            $paymentIntentParams = [
                'amount' => (int)$totalAmount, // JPYは最小単位が1円のため、100倍不要
                'currency' => 'jpy',
                'customer' => $stripeCustomerId,
                'payment_method_types' => ['customer_balance'],
                'payment_method_options' => [
                    'customer_balance' => [
                        'funding_type' => 'bank_transfer',
                        'bank_transfer' => [
                            'type' => 'jp_bank_transfer'
                        ]
                    ]
                ],
                'metadata' => [
                    'user_id' => (string)$userId,
                    'payment_id' => (string)$paymentId,
                    'payment_type' => $paymentType,
                    'business_card_id' => (string)$userInfo['business_card_id']
                ],
                'description' => '不動産AI名刺 - 銀行振込',
                'confirm' => true, // Confirm immediately to get bank transfer instructions
                'payment_method_data' => [
                    'type' => 'customer_balance'
                ]
            ];

            $paymentIntent = PaymentIntent::create($paymentIntentParams);

            $stripePaymentIntentId = $paymentIntent->id;
            $clientSecret = $paymentIntent->client_secret;

            // Log PaymentIntent status for debugging
            error_log("Bank Transfer PaymentIntent created: " . $paymentIntent->id . " Status: " . $paymentIntent->status);
            if (isset($paymentIntent->next_action)) {
                error_log("Next action type: " . ($paymentIntent->next_action->type ?? 'none'));
                error_log("Next action data: " . json_encode($paymentIntent->next_action, JSON_PRETTY_PRINT));
            }

            // データベースにPaymentIntent IDを保存
            $stmt = $db->prepare("UPDATE payments SET stripe_payment_intent_id = ? WHERE id = ?");
            $stmt->execute([$stripePaymentIntentId, $paymentId]);
            
        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log("Stripe API Error creating bank transfer PaymentIntent: " . $e->getMessage());
            error_log("Stripe Error Code: " . $e->getStripeCode());
            error_log("Stripe Error Type: " . $e->getError()->type ?? 'unknown');
            
            // If customer_balance is not enabled, provide helpful error message
            if (strpos($e->getMessage(), 'customer_balance') !== false || 
                strpos($e->getMessage(), 'bank_transfer') !== false) {
                sendErrorResponse('銀行振込機能が有効化されていません。Stripe Dashboardで設定を確認してください。', 500);
            } else {
                sendErrorResponse('銀行振込の設定中にエラーが発生しました: ' . $e->getMessage(), 500);
            }
        }
    }

    // 新規ユーザーまたは停止されたアカウントの復活の場合、サブスクリプションも作成
    if (($paymentType === 'new_user' || $userType === 'new' || $isCanceledAccount) && $paymentMethod === 'credit_card') {
        try {
            $productId = null;
            $priceId = null;

            // Productを検索または作成（Search APIが使えない環境ではallでフォールバック）
            try {
                if (method_exists('Stripe\Product', 'search')) {
                    $products = Product::search([
                        'query' => "name:'不動産AI名刺 月額料金' AND active:'true'",
                    ]);
                    if ($products && count($products->data) > 0) {
                        $productId = $products->data[0]->id;
                    }
                }
            } catch (\Throwable $searchErr) {
                error_log("Product::search fallback: " . $searchErr->getMessage());
            }
            if (!$productId) {
                $productList = Product::all(['limit' => 100, 'active' => true]);
                foreach ($productList->data as $p) {
                    if (isset($p->name) && $p->name === '不動産AI名刺 月額料金') {
                        $productId = $p->id;
                        break;
                    }
                }
            }
            if (!$productId) {
                $product = Product::create([
                    'name' => '不動産AI名刺 月額料金',
                    'description' => '不動産AI名刺の月額利用料金'
                ]);
                $productId = $product->id;
            }

            // Priceを検索または作成
            try {
                if (method_exists('Stripe\Price', 'search') && $productId) {
                    $prices = Price::search([
                        'query' => "product:'{$productId}' AND active:'true' AND currency:'jpy' AND type:'recurring'",
                    ]);
                    if ($prices && count($prices->data) > 0) {
                        $priceId = $prices->data[0]->id;
                    }
                }
            } catch (\Throwable $searchErr) {
                error_log("Price::search fallback: " . $searchErr->getMessage());
            }
            if (!$priceId && $productId) {
                $pricesList = Price::all(['limit' => 20, 'active' => true]);
                foreach ($pricesList->data as $pr) {
                    if (isset($pr->product) && $pr->product === $productId && isset($pr->currency) && $pr->currency === 'jpy' && isset($pr->recurring)) {
                        $priceId = $pr->id;
                        break;
                    }
                }
            }
            if (!$priceId && $productId) {
                $price = Price::create([
                    'product' => $productId,
                    'unit_amount' => (int)$monthlyAmount, // JPYは最小単位が1円のため、100倍不要
                    'currency' => 'jpy',
                    'recurring' => [
                        'interval' => 'month'
                    ]
                ]);
                $priceId = $price->id;
            }

            if (!$priceId) {
                error_log("Create Payment Intent: could not get or create Price for subscription, skipping subscription");
            } else {
            // Subscription作成
            $subscription = Subscription::create([
                'customer' => $stripeCustomerId,
                'items' => [[
                    'price' => $priceId
                ]],
                'metadata' => [
                    'user_id' => (string)$userId,
                    'payment_id' => (string)$paymentId,
                    'business_card_id' => (string)$userInfo['business_card_id']
                ]
            ]);

            $stripeSubscriptionId = $subscription->id;

            // データベースにSubscription IDを保存
            $stmt = $db->prepare("UPDATE payments SET stripe_subscription_id = ? WHERE id = ?");
            $stmt->execute([$stripeSubscriptionId, $paymentId]);

            // subscriptionsテーブルにも保存（停止されたアカウントの復活も含む）
            $stmt = $db->prepare("
                INSERT INTO subscriptions (user_id, business_card_id, stripe_subscription_id, status, amount, billing_cycle, next_billing_date, cancelled_at)
                VALUES (?, ?, ?, 'active', ?, 'monthly', DATE_ADD(NOW(), INTERVAL 1 MONTH), NULL)
                ON DUPLICATE KEY UPDATE
                    stripe_subscription_id = VALUES(stripe_subscription_id),
                    status = 'active',
                    amount = VALUES(amount),
                    next_billing_date = DATE_ADD(NOW(), INTERVAL 1 MONTH),
                    cancelled_at = NULL,
                    updated_at = NOW()
            ");
            $stmt->execute([
                $userId,
                $userInfo['business_card_id'],
                $stripeSubscriptionId,
                $monthlyAmount
            ]);

            // 停止されたアカウントの復活処理：ビジネスカードの状態を更新
            if ($isCanceledAccount) {
                $stmt = $db->prepare("
                    UPDATE business_cards
                    SET card_status = 'active',
                        is_published = 1,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$userInfo['business_card_id']]);
            }
            } // end if ($priceId)
        } catch (\Exception $e) {
            // サブスクリプション作成に失敗しても決済は続行
            error_log("Subscription creation failed: " . $e->getMessage());
        }
    }

    sendSuccessResponse([
        'payment_id' => $paymentId,
        'amount' => $amount,
        'tax_amount' => $taxAmount,
        'total_amount' => $totalAmount,
        'payment_method' => $paymentMethod,
        'client_secret' => $clientSecret,
        'stripe_payment_intent_id' => $stripePaymentIntentId,
        'stripe_customer_id' => $stripeCustomerId,
        'stripe_subscription_id' => $stripeSubscriptionId,
        'message' => '決済処理を開始しました'
    ], '決済処理を開始しました');

} catch (Throwable $e) {
    error_log("Create Payment Intent Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    $errorMessage = 'サーバーエラーが発生しました';
    if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
        $errorMessage .= ': ' . $e->getMessage();
    }
    sendErrorResponse($errorMessage, 500);
}