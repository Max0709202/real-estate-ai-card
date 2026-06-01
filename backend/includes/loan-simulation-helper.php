<?php
/**
 * Customer loan simulator input persistence and display helpers.
 * Stores only customer-entered values, not calculated results.
 */

function ensureLoanSimulationInputsTable($db) {
    if (!$db instanceof PDO) return;
    $db->exec("CREATE TABLE IF NOT EXISTS loan_simulation_inputs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        business_card_id INT NOT NULL,
        session_id CHAR(36) NULL,
        visitor_identifier VARCHAR(255) NULL,
        customer_key VARCHAR(320) NOT NULL,
        repayment_desired_loan_amount BIGINT NULL,
        repayment_down_payment BIGINT NULL,
        repayment_type VARCHAR(40) NULL,
        repayment_rate_year DECIMAL(6,3) NULL,
        repayment_term_years INT NULL,
        borrow_annual_income BIGINT NULL,
        borrow_desired_monthly_payment BIGINT NULL,
        last_mode VARCHAR(40) NULL,
        last_payload JSON NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (business_card_id) REFERENCES business_cards(id) ON DELETE CASCADE,
        UNIQUE KEY uniq_loan_sim_customer (business_card_id, customer_key),
        INDEX idx_loan_sim_session (session_id),
        INDEX idx_loan_sim_card_updated (business_card_id, updated_at),
        INDEX idx_loan_sim_visitor (business_card_id, visitor_identifier)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function loanSimulationNormalizeSessionId($sessionId) {
    $sessionId = trim((string)$sessionId);
    return preg_match('/^[A-Fa-f0-9-]{36}$/', $sessionId) ? $sessionId : '';
}

function loanSimulationNormalizeVisitorId($visitorId) {
    $visitorId = trim((string)$visitorId);
    return preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $visitorId) ? $visitorId : '';
}

function loanSimulationCustomerKey($sessionId, $visitorId) {
    $visitorId = loanSimulationNormalizeVisitorId($visitorId);
    if ($visitorId !== '') return 'visitor:' . $visitorId;
    $sessionId = loanSimulationNormalizeSessionId($sessionId);
    if ($sessionId !== '') return 'session:' . $sessionId;
    return '';
}

function loanSimulationNumberOrNull($value) {
    if ($value === null || $value === '') return null;
    $number = (float)$value;
    return is_finite($number) ? (int)round($number) : null;
}

function loanSimulationRateOrNull($value) {
    if ($value === null || $value === '') return null;
    $number = (float)$value;
    return is_finite($number) ? round($number, 3) : null;
}

function loanSimulationResolveSessionId($db, $businessCardId, $sessionId, $visitorId) {
    $sessionId = loanSimulationNormalizeSessionId($sessionId);
    $visitorId = loanSimulationNormalizeVisitorId($visitorId);
    if (!$db instanceof PDO || $businessCardId <= 0) return $sessionId;

    try {
        if ($sessionId !== '') {
            $stmt = $db->prepare('SELECT id FROM chat_sessions WHERE id = ? AND business_card_id = ? LIMIT 1');
            $stmt->execute([$sessionId, $businessCardId]);
            if ($stmt->fetchColumn()) return $sessionId;
            $sessionId = '';
        }
        if ($visitorId !== '') {
            $stmt = $db->prepare('SELECT id FROM chat_sessions WHERE business_card_id = ? AND visitor_identifier = ? ORDER BY last_seen_at DESC, created_at DESC LIMIT 1');
            $stmt->execute([$businessCardId, $visitorId]);
            $found = (string)($stmt->fetchColumn() ?: '');
            if ($found !== '') return $found;
        }
    } catch (Throwable $e) {
        error_log('Loan simulation session resolve error: ' . $e->getMessage());
    }
    return '';
}

function saveLoanSimulationInput($db, $businessCardId, $sessionId, $visitorId, $mode, $fields) {
    if (!$db instanceof PDO || $businessCardId <= 0 || !is_array($fields)) return false;
    $mode = trim((string)$mode);
    if (!in_array($mode, ['repayment', 'borrow_income', 'borrow_monthly'], true)) return false;

    ensureLoanSimulationInputsTable($db);
    $sessionId = loanSimulationResolveSessionId($db, (int)$businessCardId, $sessionId, $visitorId);
    $visitorId = loanSimulationNormalizeVisitorId($visitorId);
    $customerKey = loanSimulationCustomerKey($sessionId, $visitorId);
    if ($customerKey === '') return false;

    $values = [
        'repayment_desired_loan_amount' => null,
        'repayment_down_payment' => null,
        'repayment_type' => null,
        'repayment_rate_year' => null,
        'repayment_term_years' => null,
        'borrow_annual_income' => null,
        'borrow_desired_monthly_payment' => null,
    ];

    if ($mode === 'repayment') {
        $values['repayment_desired_loan_amount'] = loanSimulationNumberOrNull($fields['desired_loan_amount'] ?? null);
        $values['repayment_down_payment'] = loanSimulationNumberOrNull($fields['down_payment'] ?? null);
        $repaymentType = trim((string)($fields['repayment_type'] ?? ''));
        $values['repayment_type'] = in_array($repaymentType, ['equal_installment', 'equal_principal'], true) ? $repaymentType : null;
        $values['repayment_rate_year'] = loanSimulationRateOrNull($fields['rate_year'] ?? null);
        $values['repayment_term_years'] = loanSimulationNumberOrNull($fields['term_years'] ?? null);
    } elseif ($mode === 'borrow_income') {
        $values['borrow_annual_income'] = loanSimulationNumberOrNull($fields['annual_income'] ?? null);
    } elseif ($mode === 'borrow_monthly') {
        $values['borrow_desired_monthly_payment'] = loanSimulationNumberOrNull($fields['desired_monthly_payment'] ?? null);
    }

    $payload = json_encode(array_filter($values, function ($value) {
        return $value !== null && $value !== '';
    }), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    try {
        $stmt = $db->prepare("INSERT INTO loan_simulation_inputs
            (business_card_id, session_id, visitor_identifier, customer_key,
             repayment_desired_loan_amount, repayment_down_payment, repayment_type, repayment_rate_year, repayment_term_years,
             borrow_annual_income, borrow_desired_monthly_payment, last_mode, last_payload)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                session_id = COALESCE(VALUES(session_id), session_id),
                visitor_identifier = COALESCE(VALUES(visitor_identifier), visitor_identifier),
                repayment_desired_loan_amount = COALESCE(VALUES(repayment_desired_loan_amount), repayment_desired_loan_amount),
                repayment_down_payment = COALESCE(VALUES(repayment_down_payment), repayment_down_payment),
                repayment_type = COALESCE(VALUES(repayment_type), repayment_type),
                repayment_rate_year = COALESCE(VALUES(repayment_rate_year), repayment_rate_year),
                repayment_term_years = COALESCE(VALUES(repayment_term_years), repayment_term_years),
                borrow_annual_income = COALESCE(VALUES(borrow_annual_income), borrow_annual_income),
                borrow_desired_monthly_payment = COALESCE(VALUES(borrow_desired_monthly_payment), borrow_desired_monthly_payment),
                last_mode = VALUES(last_mode),
                last_payload = VALUES(last_payload),
                updated_at = CURRENT_TIMESTAMP");
        return $stmt->execute([
            (int)$businessCardId,
            $sessionId !== '' ? $sessionId : null,
            $visitorId !== '' ? $visitorId : null,
            $customerKey,
            $values['repayment_desired_loan_amount'],
            $values['repayment_down_payment'],
            $values['repayment_type'],
            $values['repayment_rate_year'],
            $values['repayment_term_years'],
            $values['borrow_annual_income'],
            $values['borrow_desired_monthly_payment'],
            $mode,
            $payload ?: '{}',
        ]);
    } catch (Throwable $e) {
        error_log('Loan simulation input save error: ' . $e->getMessage());
        return false;
    }
}

function loanSimulationFetchForSession($db, $sessionId, $businessCardId = null) {
    if (!$db instanceof PDO) return null;
    $sessionId = loanSimulationNormalizeSessionId($sessionId);
    if ($sessionId === '') return null;

    try {
        ensureLoanSimulationInputsTable($db);
        $stmt = $db->prepare('SELECT business_card_id, visitor_identifier FROM chat_sessions WHERE id = ? LIMIT 1');
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) return null;
        $cardId = $businessCardId !== null ? (int)$businessCardId : (int)$session['business_card_id'];
        if ($cardId <= 0 || $cardId !== (int)$session['business_card_id']) return null;

        $visitorId = loanSimulationNormalizeVisitorId($session['visitor_identifier'] ?? '');
        if ($visitorId !== '') {
            $stmt = $db->prepare('SELECT * FROM loan_simulation_inputs WHERE business_card_id = ? AND (session_id = ? OR visitor_identifier = ?) ORDER BY updated_at DESC LIMIT 1');
            $stmt->execute([$cardId, $sessionId, $visitorId]);
        } else {
            $stmt = $db->prepare('SELECT * FROM loan_simulation_inputs WHERE business_card_id = ? AND session_id = ? ORDER BY updated_at DESC LIMIT 1');
            $stmt->execute([$cardId, $sessionId]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('Loan simulation fetch for session error: ' . $e->getMessage());
        return null;
    }
}

function loanSimulationFetchCustomersForBusinessCard($db, $businessCardId, $limit = 5) {
    if (!$db instanceof PDO || $businessCardId <= 0) return [];
    $limit = max(1, min(20, (int)$limit));
    try {
        ensureLoanSimulationInputsTable($db);
        $stmt = $db->prepare("
            SELECT lsi.*, cc.customer_name, cc.phone, cc.email, cs.last_seen_at
            FROM loan_simulation_inputs lsi
            LEFT JOIN chat_sessions cs ON cs.id = lsi.session_id
            LEFT JOIN chat_lead_contacts cc ON cc.session_id = lsi.session_id
            WHERE lsi.business_card_id = ?
            ORDER BY lsi.updated_at DESC
            LIMIT {$limit}
        ");
        $stmt->execute([(int)$businessCardId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('Loan simulation business card fetch error: ' . $e->getMessage());
        return [];
    }
}

function loanSimulationFormatYenValue($value) {
    if ($value === null || $value === '') return '';
    $amount = (int)round((float)$value);
    if ($amount === 0) return '0円';
    if (abs($amount) >= 10000) {
        $man = $amount / 10000;
        $formatted = abs($man - round($man)) < 0.05 ? number_format((int)round($man)) : number_format($man, 1);
        return $formatted . '万円';
    }
    return number_format($amount) . '円';
}

function loanSimulationFormatRate($value) {
    if ($value === null || $value === '') return '';
    $formatted = rtrim(rtrim(number_format((float)$value, 3, '.', ''), '0'), '.');
    return $formatted . '%';
}

function loanSimulationFormatRepaymentType($value) {
    $map = [
        'equal_installment' => '元利均等',
        'equal_principal' => '元金均等',
    ];
    return $map[(string)$value] ?? '';
}

function loanSimulationDisplayGroups($row) {
    if (!$row || !is_array($row)) return [];
    $groups = [];
    $repayment = [];
    if ($row['repayment_desired_loan_amount'] !== null && $row['repayment_desired_loan_amount'] !== '') {
        $repayment[] = ['label' => '借入希望額', 'value' => loanSimulationFormatYenValue($row['repayment_desired_loan_amount'])];
    }
    if ($row['repayment_down_payment'] !== null && $row['repayment_down_payment'] !== '') {
        $repayment[] = ['label' => '頭金', 'value' => loanSimulationFormatYenValue($row['repayment_down_payment'])];
    }
    if (!empty($row['repayment_type'])) {
        $repayment[] = ['label' => '返済方式', 'value' => loanSimulationFormatRepaymentType($row['repayment_type'])];
    }
    if ($row['repayment_rate_year'] !== null && $row['repayment_rate_year'] !== '') {
        $repayment[] = ['label' => '金利', 'value' => loanSimulationFormatRate($row['repayment_rate_year'])];
    }
    if ($row['repayment_term_years'] !== null && $row['repayment_term_years'] !== '') {
        $repayment[] = ['label' => '返済期間', 'value' => (int)$row['repayment_term_years'] . '年'];
    }
    if (!empty($repayment)) {
        $groups[] = ['title' => '返済額の試算', 'items' => $repayment];
    }

    $borrowable = [];
    if ($row['borrow_annual_income'] !== null && $row['borrow_annual_income'] !== '') {
        $borrowable[] = ['label' => '年収', 'value' => loanSimulationFormatYenValue($row['borrow_annual_income'])];
    }
    if ($row['borrow_desired_monthly_payment'] !== null && $row['borrow_desired_monthly_payment'] !== '') {
        $borrowable[] = ['label' => '希望月額返済額', 'value' => loanSimulationFormatYenValue($row['borrow_desired_monthly_payment'])];
    }
    if (!empty($borrowable)) {
        $groups[] = ['title' => '借入可能額の試算', 'items' => $borrowable];
    }
    return $groups;
}

function loanSimulationHasDisplayValues($row) {
    return !empty(loanSimulationDisplayGroups($row));
}

function loanSimulationDisplaySummary($row, $maxItems = 4) {
    $parts = [];
    foreach (loanSimulationDisplayGroups($row) as $group) {
        foreach ($group['items'] as $item) {
            if ($item['value'] === '') continue;
            $parts[] = $item['label'] . ': ' . $item['value'];
            if (count($parts) >= $maxItems) break 2;
        }
    }
    return implode(' / ', $parts);
}

function loanSimulationPromptContextForSession($db, $sessionId) {
    $row = loanSimulationFetchForSession($db, $sessionId);
    if (!$row || !loanSimulationHasDisplayValues($row)) return '';
    $lines = ['【住宅ローンシミュレーター入力（顧客が以前入力した値。計算結果ではない）】'];
    foreach (loanSimulationDisplayGroups($row) as $group) {
        foreach ($group['items'] as $item) {
            if ($item['value'] === '') continue;
            $lines[] = $group['title'] . ' - ' . $item['label'] . ': ' . $item['value'];
        }
    }
    if (!empty($row['updated_at'])) {
        $lines[] = '最終入力日時: ' . $row['updated_at'];
    }
    $lines[] = '会話では、同じ項目を聞き直す前に「以前ご入力いただいた内容」として自然に確認・活用してください。';
    return implode("\n", $lines);
}

function loanSimulationApplyToLeadData(&$data, $row) {
    if (!$row || !is_array($row) || !is_array($data)) return;
    $applied = false;
    if (empty($data['desired_loan_amount']) && $row['repayment_desired_loan_amount'] !== null && $row['repayment_desired_loan_amount'] !== '') {
        $data['desired_loan_amount'] = loanSimulationFormatYenValue($row['repayment_desired_loan_amount']);
        $applied = true;
    }
    if (empty($data['down_payment']) && $row['repayment_down_payment'] !== null && $row['repayment_down_payment'] !== '') {
        $data['down_payment'] = loanSimulationFormatYenValue($row['repayment_down_payment']);
        $applied = true;
    }
    if (empty($data['income']) && $row['borrow_annual_income'] !== null && $row['borrow_annual_income'] !== '') {
        $data['income'] = loanSimulationFormatYenValue($row['borrow_annual_income']);
        $applied = true;
    }
    if (empty($data['desired_monthly_payment']) && $row['borrow_desired_monthly_payment'] !== null && $row['borrow_desired_monthly_payment'] !== '') {
        $data['desired_monthly_payment'] = loanSimulationFormatYenValue($row['borrow_desired_monthly_payment']);
        $applied = true;
    }
    if ($applied && empty($data['loan_simulation_used'])) {
        $data['loan_simulation_used'] = 'yes';
    }
}
