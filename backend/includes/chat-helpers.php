<?php
/**
 * Chatbot & plan gating helpers
 * - Resolve card by slug and check plan features
 * - can_use_chatbot, can_use_loan_sim derived from plan_type
 */

/**
 * Get business card by url_slug (for public card page / chat).
 * Returns card row or null.
 *
 * @param PDO $db
 * @param string $slug
 * @return array|null
 */
function getCardBySlugForChat($db, $slug) {
    $stmt = $db->prepare("
        SELECT bc.*, u.status as user_status
        FROM business_cards bc
        JOIN users u ON bc.user_id = u.id
        WHERE bc.url_slug = ? AND u.status = 'active'
    ");
    $stmt->execute([$slug]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);
    return $card ?: null;
}

/**
 * Whether the card's plan allows AI chatbot.
 * Standard plan = yes, Entry = no. If plan_type missing, default to standard (yes).
 *
 * @param array $card business_cards row (with plan_type key if present)
 * @return bool
 */
function canUseChatbot($card) {
    $plan = isset($card['plan_type']) ? $card['plan_type'] : 'standard';
    return strtolower($plan) === 'standard';
}

/**
 * Whether the card's plan allows loan simulator.
 *
 * @param array $card
 * @return bool
 */
function canUseLoanSim($card) {
    $plan = isset($card['plan_type']) ? $card['plan_type'] : 'standard';
    return strtolower($plan) === 'standard';
}

/**
 * Generate a UUID v4 style id for chat session.
 *
 * @return string
 */
function generateChatSessionId() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
