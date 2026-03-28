<?php
require_once __DIR__ . '/../config.php';

// Handle OPTIONS for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

$input = json_decode(file_get_contents('php://input'), true);
$method = $_SERVER['REQUEST_METHOD'];
$token = $input['token'] ?? ($_GET['token'] ?? '');

if (!$token) {
    jsonResponse(['error' => 'Vui lòng đăng nhập.'], 401);
}

$tokenData = verifyToken($token);
if (!$tokenData) {
    jsonResponse(['error' => 'Phiên đăng nhập hết hạn.'], 401);
}

$userId = $tokenData['user_id'];

try {
    $db = getDB();

    // GET: Fetch team info (For both Leader and Member)
    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'get_team';

        if ($action === 'get_team') {
            // Check if I am a member of someone else's team
            $stmtMe = $db->prepare("SELECT u.*, p.email as parent_email 
                                    FROM users u 
                                    LEFT JOIN users p ON u.parent_id = p.id 
                                    WHERE u.id = ?");
            $stmtMe->execute([$userId]);
            $me = $stmtMe->fetch();

            $teamInfo = [
                'is_leader' => false,
                'is_member' => !empty($me['parent_id']),
                'parent_email' => $me['parent_email'] ?? null,
                'team_quota_limit' => $me['team_quota_limit'] ?? 0,
                'team_quota_used' => $me['team_quota_used'] ?? 0,
                'invite_code' => $me['invite_code'],
                'members' => []
            ];

            // If I have an invite code or have members, I am a leader
            $stmtMembers = $db->prepare("SELECT id, email, team_quota_limit, team_quota_used, quota_total, quota_used, created_at 
                                         FROM users 
                                         WHERE parent_id = ?");
            $stmtMembers->execute([$userId]);
            $members = $stmtMembers->fetchAll();

            if (count($members) > 0 || !empty($me['invite_code'])) {
                $teamInfo['is_leader'] = true;
                $teamInfo['members'] = $members;

                // If I am a leader but don't have an invite code yet, generate one
                if (empty($me['invite_code'])) {
                    $newCode = substr(str_shuffle("0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 12);
                    $db->prepare("UPDATE users SET invite_code = ? WHERE id = ?")->execute([$newCode, $userId]);
                    $teamInfo['invite_code'] = $newCode;
                }
            }

            jsonResponse(['status' => 'success', 'team' => $teamInfo]);
        }
    }

    // POST: Manage team (Join, Update Limit, Remove)
    if ($method === 'POST') {
        $action = $input['action'] ?? '';

        if ($action === 'join_team') {
            $code = strtoupper(trim($input['invite_code'] ?? ''));
            if (!$code)
                jsonResponse(['error' => 'Vui lòng nhập mã mời.'], 400);

            // Cannot join your own team
            $stmtParent = $db->prepare("SELECT id, email, expires_at FROM users WHERE invite_code = ? AND id != ?");
            $stmtParent->execute([$code, $userId]);
            $parent = $stmtParent->fetch();

            if (!$parent) {
                jsonResponse(['error' => 'Mã mời không hợp lệ hoặc đã hết hạn.'], 404);
            }

            // Update user to become member and sync expiration date
            $stmtJoin = $db->prepare("UPDATE users SET parent_id = ?, team_quota_limit = 50000, team_quota_used = 0, expires_at = ? WHERE id = ?");
            $stmtJoin->execute([$parent['id'], $parent['expires_at'], $userId]);

            error_log("Team: User $userId joined team of Parent {$parent['id']} ({$parent['email']}). Expiry synced to: {$parent['expires_at']}");

            jsonResponse(['status' => 'success', 'message' => 'Đã gia nhập nhóm của ' . $parent['email']]);
        }

        if ($action === 'update_limit') {
            $memberId = $input['member_id'] ?? 0;
            $newLimit = intval($input['limit'] ?? 0);

            // Log the attempt
            error_log("Team: Leader $userId attempting to update Member $memberId limit to $newLimit");

            // 1. First check if member belongs to leader
            $stmtCheck = $db->prepare("SELECT id FROM users WHERE id = ? AND parent_id = ?");
            $stmtCheck->execute([$memberId, $userId]);
            if (!$stmtCheck->fetch()) {
                jsonResponse(['error' => 'Không có quyền thay đổi thành viên này.'], 403);
            }

            // 2. Perform update
            $stmtUpdate = $db->prepare("UPDATE users SET team_quota_limit = ? WHERE id = ?");
            $stmtUpdate->execute([$newLimit, $memberId]);

            jsonResponse(['status' => 'success', 'message' => 'Đã cập nhật hạn mức.']);
        }

        if ($action === 'remove_member') {
            $memberId = $input['member_id'] ?? 0;

            // Verify I am the parent
            $stmtRemove = $db->prepare("UPDATE users SET parent_id = NULL, team_quota_limit = 0, team_quota_used = 0 WHERE id = ? AND parent_id = ?");
            $stmtRemove->execute([$memberId, $userId]);

            if ($stmtRemove->rowCount() > 0) {
                jsonResponse(['status' => 'success', 'message' => 'Đã mời thành viên rời nhóm.']);
            } else {
                jsonResponse(['error' => 'Thao tác không hợp lệ.'], 403);
            }
        }

        if ($action === 'leave_team') {
            $stmtLeave = $db->prepare("UPDATE users SET parent_id = NULL, team_quota_limit = 0, team_quota_used = 0 WHERE id = ?");
            $stmtLeave->execute([$userId]);
            jsonResponse(['status' => 'success', 'message' => 'Đã rời khỏi nhóm.']);
        }

        if ($action === 'disband_team') {
            // Only the leader (has invite_code or has members) can disband
            $stmtCheck = $db->prepare("SELECT invite_code FROM users WHERE id = ?");
            $stmtCheck->execute([$userId]);
            $me = $stmtCheck->fetch();

            if (empty($me['invite_code'])) {
                jsonResponse(['error' => 'Bạn không phải trưởng nhóm.'], 403);
            }

            $db->beginTransaction();
            try {
                // 1. Remove all members from this group
                $db->prepare("UPDATE users SET parent_id = NULL, team_quota_limit = 0, team_quota_used = 0 WHERE parent_id = ?")
                    ->execute([$userId]);

                // 2. Clear leader's invite_code to exit leader state
                $db->prepare("UPDATE users SET invite_code = NULL WHERE id = ?")
                    ->execute([$userId]);

                $db->commit();
                jsonResponse(['status' => 'success', 'message' => 'Đã giải tán nhóm thành công.']);
            } catch (Exception $e) {
                $db->rollBack();
                jsonResponse(['error' => 'Lỗi khi giải tán nhóm: ' . $e->getMessage()], 500);
            }
        }

        if ($action === 'reset_invite_code') {
            $newCode = substr(str_shuffle("0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 12);
            $db->prepare("UPDATE users SET invite_code = ? WHERE id = ?")->execute([$newCode, $userId]);
            jsonResponse(['status' => 'success', 'invite_code' => $newCode]);
        }
    }

    jsonResponse(['error' => 'Yêu cầu không hợp lệ.'], 400);

} catch (Exception $e) {
    jsonResponse(['error' => 'Lỗi Server: ' . $e->getMessage()], 500);
}
