<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/PostForMeAPI.php';

header('Content-Type: application/json');

try {
    $automationId = isset($_GET['automation_id']) ? (int)$_GET['automation_id'] : 0;
    $targetAutomationAccounts = [];
    $trackedPostIds = [];
    $trackedPostIdSet = [];
    if ($automationId > 0) {
        try {
            $aStmt = $pdo->prepare("SELECT postforme_account_ids FROM automation_settings WHERE id = ? LIMIT 1");
            $aStmt->execute([$automationId]);
            $rawAccounts = $aStmt->fetchColumn();
            $decodedAccounts = json_decode((string)$rawAccounts, true);
            if (is_array($decodedAccounts)) {
                $targetAutomationAccounts = array_values(array_filter(array_map('strval', $decodedAccounts)));
            }

            $tStmt = $pdo->prepare("SELECT post_id FROM postforme_posts WHERE automation_id = ? AND post_id IS NOT NULL");
            $tStmt->execute([$automationId]);
            $trackedPostIds = array_values(array_filter(array_map('strval', $tStmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
            $trackedPostIdSet = array_fill_keys($trackedPostIds, true);

            // Fallback for old runs where postforme_posts mapping was not persisted.
            if (empty($trackedPostIdSet)) {
                $lStmt = $pdo->prepare("
                    SELECT message
                    FROM automation_logs
                    WHERE automation_id = ?
                      AND action IN ('postforme_success', 'postforme_debug')
                    ORDER BY created_at DESC
                    LIMIT 300
                ");
                $lStmt->execute([$automationId]);
                foreach ($lStmt->fetchAll(PDO::FETCH_COLUMN) as $msg) {
                    $text = (string)$msg;
                    if (preg_match_all('/sp_[A-Za-z0-9]+/', $text, $m)) {
                        foreach (($m[0] ?? []) as $pid) {
                            $trackedPostIdSet[(string)$pid] = true;
                        }
                    }
                }
            }
        } catch (Throwable $e) {}
    }

    // Best-effort remote sync: pull scheduled posts from PostForMe and mirror into local DB.
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'postforme_api_key' LIMIT 1");
        $stmt->execute();
        $apiKey = (string)($stmt->fetchColumn() ?: '');

        if ($apiKey !== '') {
            $pf = new PostForMeAPI($apiKey);
            $pages = [1, 2, 3];

            foreach ($pages as $page) {
                $remote = $pf->listPosts([
                    'status' => 'scheduled',
                    'page' => $page,
                    'per_page' => 100
                ]);

                if (empty($remote['success']) || empty($remote['posts']) || !is_array($remote['posts'])) {
                    break;
                }

                foreach ($remote['posts'] as $rp) {
                    if (!is_array($rp)) {
                        continue;
                    }

                    $postId = (string)($rp['id'] ?? $rp['post_id'] ?? '');
                    if ($postId === '') {
                        continue;
                    }

                    $caption = (string)($rp['caption'] ?? $rp['text'] ?? '');
                    $status = strtolower((string)($rp['status'] ?? $rp['state'] ?? 'scheduled'));
                    if ($status === '') {
                        $status = 'scheduled';
                    }

                    $scheduledRaw = $rp['scheduled_at']
                        ?? $rp['schedule_date']
                        ?? $rp['scheduleDate']
                        ?? $rp['post_at']
                        ?? $rp['postAt']
                        ?? $rp['publish_at']
                        ?? $rp['publishAt']
                        ?? null;
                    $scheduledAt = null;
                    if (!empty($scheduledRaw)) {
                        $ts = strtotime((string)$scheduledRaw);
                        if ($ts !== false) {
                            $scheduledAt = gmdate('Y-m-d H:i:s', $ts);
                        }
                    }

                    $socialAccounts = $rp['social_accounts'] ?? $rp['socialAccounts'] ?? $rp['accounts'] ?? [];
                    $accountIds = [];
                    if (is_array($socialAccounts)) {
                        foreach ($socialAccounts as $acc) {
                            if (is_array($acc)) {
                                $aid = $acc['id'] ?? $acc['account_id'] ?? $acc['social_account_id'] ?? null;
                                if ($aid) {
                                    $accountIds[] = (string)$aid;
                                }
                            } elseif (!empty($acc)) {
                                $accountIds[] = (string)$acc;
                            }
                        }
                    }
                    $accountIdsJson = !empty($accountIds) ? json_encode(array_values(array_unique($accountIds))) : null;

                    $mappedAutomationId = null;
                    if ($automationId > 0) {
                        // Strict per-automation mapping:
                        // only map remote posts that are already tracked locally for this automation.
                        if (isset($trackedPostIdSet[$postId])) {
                            $mappedAutomationId = $automationId;
                        }
                    }

                    $sel = $pdo->prepare("SELECT id, automation_id FROM postforme_posts WHERE post_id = ? LIMIT 1");
                    $sel->execute([$postId]);
                    $existingRow = $sel->fetch(PDO::FETCH_ASSOC);
                    $existingId = $existingRow['id'] ?? null;

                    if ($existingId) {
                        $up = $pdo->prepare("
                            UPDATE postforme_posts
                            SET caption = COALESCE(NULLIF(?, ''), caption),
                                account_ids = COALESCE(?, account_ids),
                                status = ?,
                                scheduled_at = COALESCE(?, scheduled_at),
                                automation_id = COALESCE(automation_id, ?)
                            WHERE id = ?
                        ");
                        $up->execute([$caption, $accountIdsJson, $status, $scheduledAt, $mappedAutomationId, (int)$existingId]);
                    } else {
                        // When opening a specific automation card, do not import unrelated
                        // account-overlap posts into this automation.
                        if ($automationId > 0 && $mappedAutomationId === null) {
                            continue;
                        }
                        $ins = $pdo->prepare("
                            INSERT INTO postforme_posts (post_id, automation_id, caption, account_ids, status, scheduled_at)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $ins->execute([$postId, $mappedAutomationId, $caption, $accountIdsJson, $status, $scheduledAt]);
                    }
                }
            }
        }
    } catch (Throwable $syncError) {
        // Non-fatal: UI should still render local records.
    }
    $params = [];

    $sql = "
        SELECT 
            pp.id,
            pp.post_id,
            pp.automation_id,
            pp.video_path,
            pp.caption,
            pp.account_ids,
            pp.status,
            pp.scheduled_at,
            pp.published_at,
            pp.error_message,
            pp.created_at,
            a.name as automation_name
        FROM postforme_posts pp
        LEFT JOIN automation_settings a ON pp.automation_id = a.id
        WHERE (
            pp.scheduled_at IS NOT NULL
            OR pp.status IN ('pending', 'scheduled', 'partial', 'queued')
        )
    ";

    if ($automationId > 0) {
        $sql .= " AND pp.automation_id = ? ";
        $params[] = $automationId;
    }

    $sql .= "
        ORDER BY 
            CASE 
                WHEN pp.status IN ('pending', 'scheduled', 'partial') AND pp.scheduled_at > NOW() THEN 0
                WHEN pp.status IN ('pending', 'scheduled', 'partial', 'queued') THEN 1
                ELSE 2
            END,
            COALESCE(pp.scheduled_at, pp.created_at) ASC
        LIMIT 100
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $posts = $stmt->fetchAll();

    $accountIds = [];
    foreach ($posts as $post) {
        if (!empty($post['account_ids'])) {
            $ids = json_decode($post['account_ids'], true);
            if (is_array($ids)) {
                $accountIds = array_merge($accountIds, $ids);
            }
        }
    }
    $accountIds = array_unique($accountIds);

    $accountMap = [];
    if (!empty($accountIds)) {
        $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
        $stmt2 = $pdo->prepare("SELECT account_id, platform, account_name, username FROM postforme_accounts WHERE account_id IN ($placeholders)");
        $stmt2->execute(array_values($accountIds));
        foreach ($stmt2->fetchAll() as $acc) {
            $accountMap[$acc['account_id']] = $acc;
        }
    }

    $result = [];
    foreach ($posts as $post) {
        $platforms = [];
        if (!empty($post['account_ids'])) {
            $ids = json_decode($post['account_ids'], true);
            if (is_array($ids)) {
                foreach ($ids as $aid) {
                    if (isset($accountMap[$aid])) {
                        $platforms[] = $accountMap[$aid];
                    } else {
                        $platforms[] = ['account_id' => $aid, 'platform' => 'unknown', 'account_name' => $aid];
                    }
                }
            }
        }
        $post['platforms'] = $platforms;
        
        if (!empty($post['scheduled_at']) && strpos($post['scheduled_at'], 'T') === false && strpos($post['scheduled_at'], 'Z') === false) {
            $post['scheduled_at_utc'] = $post['scheduled_at'];
        }
        $result[] = $post;
    }

    echo json_encode([
        'success' => true, 
        'posts' => $result,
        'server_time' => gmdate('Y-m-d\TH:i:s\Z')
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
