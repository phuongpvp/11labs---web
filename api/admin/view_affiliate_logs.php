<?php
require_once __DIR__ . '/../config.php';

try {
    $db = getDB();

    $stmt = $db->query("
        SELECT 
            abl.id,
            abl.bonus_amount,
            abl.plan_quota,
            abl.created_at,
            referrer.email as referrer_email,
            referrer.quota_total as referrer_quota,
            referred.email as referred_email,
            referred.quota_total as referred_quota,
            referred.plan as referred_plan,
            referred.status as referred_status
        FROM affiliate_bonus_logs abl
        JOIN users referrer ON referrer.id = abl.referrer_id
        JOIN users referred ON referred.id = abl.referred_id
        ORDER BY abl.created_at DESC
    ");
    $logs = $stmt->fetchAll();
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Affiliate Bonus Logs</title>
    <link rel="icon" type="image/png" href="../../icon.png">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Outfit', sans-serif;
            background: #09090b;
            color: #e4e4e7;
            padding: 30px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        h1 { font-size: 1.4rem; color: #a78bfa; display: flex; align-items: center; gap: 10px; }
        .back-btn {
            color: #a1a1aa;
            text-decoration: none;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border: 1px solid #27272a;
            border-radius: 8px;
            transition: 0.2s;
        }
        .back-btn:hover { color: #f4f4f5; border-color: #8b5cf6; }
        .summary { color: #a1a1aa; margin-bottom: 20px; font-size: 0.9rem; }
        .summary span { color: #22c55e; font-weight: 700; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        thead th {
            background: #1e1b4b;
            color: #c4b5fd;
            padding: 12px 14px;
            text-align: left;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #7c3aed;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        tbody td {
            padding: 10px 14px;
            border-bottom: 1px solid #27272a;
        }
        tbody tr:hover { background: rgba(139, 92, 246, 0.08); }
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 0.72rem;
            font-weight: 600;
        }
        .badge-green { background: rgba(34,197,94,0.15); color: #22c55e; }
        .badge-yellow { background: rgba(234,179,8,0.15); color: #eab308; }
        .badge-gray { background: rgba(161,161,170,0.15); color: #a1a1aa; }
        .bonus { color: #22c55e; font-weight: 700; }
        .quota { color: #60a5fa; }
        .email { font-family: monospace; font-size: 0.82rem; }
    </style>
</head>
<body>
    <div class="header">
        <h1><i class="fa-solid fa-gift"></i> Lịch sử cộng điểm Affiliate</h1>
        <a href="javascript:history.back()" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Quay lại</a>
    </div>
    <div class="summary">Tổng: <span><?= count($logs) ?></span> bản ghi</div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Người giới thiệu</th>
                <th>Quota hiện tại</th>
                <th>Người được GT</th>
                <th>Quota hiện tại</th>
                <th>Gói hiện tại</th>
                <th>Trạng thái</th>
                <th>Điểm thưởng (mỗi bên)</th>
                <th>Ngày cộng</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($logs)): ?>
                <tr><td colspan="9" style="text-align:center; padding:30px; color:#71717a;">Chưa có bản ghi nào.</td></tr>
            <?php else: ?>
                <?php foreach ($logs as $i => $log): ?>
                    <?php
                        $statusClass = 'badge-gray';
                        $statusLabel = $log['referred_status'];
                        if ($log['referred_status'] === 'active') { $statusClass = 'badge-green'; $statusLabel = 'Active'; }
                        elseif ($log['referred_status'] === 'expired') { $statusClass = 'badge-yellow'; $statusLabel = 'Expired'; }
                    ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td class="email"><?= htmlspecialchars($log['referrer_email']) ?></td>
                        <td class="quota"><?= number_format($log['referrer_quota']) ?></td>
                        <td class="email"><?= htmlspecialchars($log['referred_email']) ?></td>
                        <td class="quota"><?= number_format($log['referred_quota']) ?></td>
                        <td><?= ucfirst($log['referred_plan']) ?></td>
                        <td><span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span></td>
                        <td class="bonus">+<?= number_format($log['bonus_amount']) ?></td>
                        <td><?= date('d/m/Y H:i', strtotime($log['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>
