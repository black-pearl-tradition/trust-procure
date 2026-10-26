<?php

/**
 * Shared helper functions
 * Trust Procurement System
 */

require_once __DIR__ . '/helpers/url.php';
require_once __DIR__ . '/helpers/flash.php';
require_once __DIR__ . '/helpers/audit.php';
require_once __DIR__ . '/helpers/inventory.php';
require_once __DIR__ . '/helpers/security.php';
require_once __DIR__ . '/helpers/uploads.php';
require_once __DIR__ . '/helpers/csrf.php';
require_once __DIR__ . '/helpers/notifications.php';
require_once __DIR__ . '/helpers/status_labels.php';
require_once __DIR__ . '/helpers/catalog.php';
require_once __DIR__ . '/helpers/supplier.php';
require_once __DIR__ . '/helpers/po_documents.php';
require_once __DIR__ . '/helpers/pos.php';

function user_profile_picture_path(?string $filename = null): string
{
    $defaultPath = __DIR__ . '/../assets/images/default-avatar.svg';
    $safeName = trim((string) ($filename ?? ''));
    if ($safeName === '') {
        return $defaultPath;
    }

    $fileName = basename(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $safeName));
    if ($fileName === '' || $fileName === '.' || $fileName === '..') {
        return $defaultPath;
    }

    $candidatePaths = [
        __DIR__ . '/../uploads/users/' . $fileName,
        __DIR__ . '/../includes/uploads/users/' . $fileName,
    ];

    foreach ($candidatePaths as $profilePath) {
        if (file_exists($profilePath)) {
            return $profilePath;
        }
    }

    return $defaultPath;
}

function user_profile_picture_url(?string $filename = null): string
{
    $defaultUrl = url('/assets/images/default-avatar.svg');
    $safeName = trim((string) ($filename ?? ''));
    if ($safeName === '') {
        return $defaultUrl;
    }

    $fileName = basename(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $safeName));
    if ($fileName === '' || $fileName === '.' || $fileName === '..') {
        return $defaultUrl;
    }

    $publicPath = __DIR__ . '/../uploads/users/' . $fileName;
    $legacyPath = __DIR__ . '/../includes/uploads/users/' . $fileName;

    if (file_exists($publicPath)) {
        return url('/uploads/users/' . rawurlencode($fileName));
    }

    if (file_exists($legacyPath)) {
        return url('/includes/uploads/users/' . rawurlencode($fileName));
    }

    return $defaultUrl;
}

function approval_rule_matches_request(array $rule, float $totalAmount, int $categoryId, string $department): bool
{
    $minimumAmount = (float) ($rule['min_amount'] ?? 0);
    if ($totalAmount < $minimumAmount) {
        return false;
    }

    $maximumAmount = $rule['max_amount'] ?? null;
    if ($maximumAmount !== null && $maximumAmount !== '' && $totalAmount > (float) $maximumAmount) {
        return false;
    }

    $ruleCategoryId = (int) ($rule['category_id'] ?? 0);
    if ($ruleCategoryId > 0) {
        if ($categoryId === 0 || $categoryId !== $ruleCategoryId) {
            return false;
        }
    }

    $ruleDepartment = trim((string) ($rule['department'] ?? ''));
    if ($ruleDepartment !== '') {
        $normalizedRuleDepartment = strtolower($ruleDepartment);
        $normalizedRequestDepartment = strtolower(trim($department));

        if ($normalizedRequestDepartment !== '' && $normalizedRuleDepartment !== '') {
            $departmentMatch = $normalizedRequestDepartment === $normalizedRuleDepartment
                || strpos($normalizedRequestDepartment, $normalizedRuleDepartment) !== false
                || strpos($normalizedRuleDepartment, $normalizedRequestDepartment) !== false;

            if (!$departmentMatch) {
                return false;
            }
        }
    }

    return true;
}

function matching_approval_rules(PDO $pdo, float $totalAmount, int $categoryId, string $department): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM approval_rules
         WHERE is_active = 1
           AND min_amount <= ?
           AND (max_amount IS NULL OR max_amount >= ?)
         ORDER BY sequence ASC, min_amount ASC'
    );
    $stmt->execute([$totalAmount, $totalAmount]);

    $rules = [];
    $seenRules = [];
    while ($rule = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!approval_rule_matches_request($rule, $totalAmount, $categoryId, $department)) {
            continue;
        }

        $ruleKey = (int) ($rule['id'] ?? 0);
        if ($ruleKey > 0) {
            $ruleKey = 'id_' . $ruleKey;
        } else {
            $ruleKey = 'name_' . strtolower(trim((string) ($rule['name'] ?? 'rule')));
        }

        if (isset($seenRules[$ruleKey])) {
            continue;
        }

        $seenRules[$ruleKey] = true;
        $rules[] = $rule;
    }

    return $rules;
}

function approval_rule_stage_key(array $rule): string
{
    $name = trim((string) ($rule['name'] ?? ''));
    if ($name !== '') {
        return strtolower(preg_replace('/\s+/', ' ', $name));
    }

    $ruleId = (int) ($rule['id'] ?? 0);
    return $ruleId > 0 ? 'approval_rule_' . $ruleId : 'manual_review';
}

function next_required_approval_rule(array $matchedRules, array $approvedStages): ?array
{
    $completedStages = [];
    foreach ($approvedStages as $stage) {
        $stageName = strtolower(trim((string) $stage));
        if ($stageName !== '') {
            $completedStages[$stageName] = true;
        }
    }

    foreach ($matchedRules as $rule) {
        $stageKey = approval_rule_stage_key($rule);
        if (!isset($completedStages[$stageKey])) {
            return $rule;
        }
    }

    return null;
}

function approval_rule_chain_label(array $rules): string
{
    $labels = [];
    foreach ($rules as $rule) {
        $labels[] = trim((string) ($rule['name'] ?? 'Approval rule'));
    }

    return $labels ? implode(' > ', $labels) : 'manual_review';
}
