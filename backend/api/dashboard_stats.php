<?php
require_once __DIR__ . '/../../config/config.php';
header('Content-Type: application/json; charset=utf-8');
$me = auth_user();
if(!$me) json_err('unauthorized',401);
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
function current_role_id_dashboard(PDO $pdo, array $me): int {
    $rid = (int)($me['role_id'] ?? ($me['role'] ?? 0));
    if($rid > 0) return $rid;
    $uid = (int)($me['id'] ?? ($me['uid'] ?? 0));
    if($uid > 0){
        try{ $st = $pdo->prepare('SELECT role_id FROM users WHERE id=? LIMIT 1'); $st->execute([$uid]); return (int)$st->fetchColumn(); }catch(Throwable $e){}
    }
    return 0;
}
function table_exists_dashboard(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
}
function default_dashboard_widget_access_dashboard(int $rid, string $code): int {
    if($rid===999) return 1;
    if($rid===1) return in_array($code,['orders_month','orders_week','sales_month','avg_order_value','payments_month','payments_week','installs_week','sales_6m','statuses_current','boss_tasks'],true) ? 1 : 0;
    return in_array($code,['orders_month','orders_week','installs_week'],true) ? 1 : 0;
}
function dashboard_caps_dashboard(PDO $pdo, int $rid): array {
    $defs = [
        'orders_month' => default_dashboard_widget_access_dashboard($rid,'orders_month'),
        'orders_week' => default_dashboard_widget_access_dashboard($rid,'orders_week'),
        'sales_month' => default_dashboard_widget_access_dashboard($rid,'sales_month'),
        'avg_order_value' => default_dashboard_widget_access_dashboard($rid,'avg_order_value'),
        'payments_month' => default_dashboard_widget_access_dashboard($rid,'payments_month'),
        'payments_week' => default_dashboard_widget_access_dashboard($rid,'payments_week'),
        'installs_week' => default_dashboard_widget_access_dashboard($rid,'installs_week'),
        'sales_6m' => default_dashboard_widget_access_dashboard($rid,'sales_6m'),
        'payments_6m' => ($rid===999 ? 1 : 0),
        'statuses_current' => default_dashboard_widget_access_dashboard($rid,'statuses_current'),
        'boss_tasks' => default_dashboard_widget_access_dashboard($rid,'boss_tasks'),
    ];
    if(!$rid || !table_exists_dashboard($pdo,'role_dashboard_permissions')) return $defs;
    try{
        $st = $pdo->prepare('SELECT widget_code, allow_view FROM role_dashboard_permissions WHERE role_id=?');
        $st->execute([$rid]);
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){ $defs[(string)$row['widget_code']] = (int)$row['allow_view'] === 1 ? 1 : 0; }
    }catch(Throwable $e){}
    return $defs;
}
function safe_money_dashboard($v): float { return round((float)$v, 2); }
try{
    $roleId = current_role_id_dashboard($pdo, $me);
    $caps = dashboard_caps_dashboard($pdo, $roleId);
    $hasAddons = table_exists_dashboard($pdo,'order_addons');
    $hasPayments = table_exists_dashboard($pdo,'order_payments');
    $salesJoin = $hasAddons ? "LEFT JOIN (SELECT order_id, COALESCE(SUM(amount),0) AS total_sum FROM order_addons GROUP BY order_id) oa ON oa.order_id = o.id" : "";
    $salesExpr = $hasAddons ? "COALESCE(oa.total_sum, o.total_amount)" : "o.total_amount";
    $monthStart = date('Y-m-01');
    $nextMonthStart = date('Y-m-01', strtotime('+1 month'));
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $weekEndExclusive = date('Y-m-d', strtotime($weekStart . ' +7 days'));
    $weekEndLabel = date('Y-m-d', strtotime($weekEndExclusive . ' -1 day'));
    $stats = [
        'orders_month' => 0,'orders_week' => 0,'sales_month' => 0.0,'avg_order_value' => 0.0,
        'payments_month' => 0.0,'payments_week' => 0.0,'installs_week' => 0,
        'month_label' => date('m.Y'),'week_label' => $weekStart . ' - ' . $weekEndLabel,
        'sales_6m' => [],'payments_6m' => [],'statuses_current' => [],'boss_tasks' => [],
    ];
    $st = $pdo->prepare("SELECT COUNT(*) FROM orders o WHERE COALESCE(o.is_deleted,0)=0 AND COALESCE(o.is_finished,0)=0 AND o.contract_date >= ? AND o.contract_date < ?");
    $st->execute([$monthStart, $nextMonthStart]);
    $stats['orders_month'] = (int)$st->fetchColumn();
    $st = $pdo->prepare("SELECT COUNT(*) FROM orders o WHERE COALESCE(o.is_deleted,0)=0 AND COALESCE(o.is_finished,0)=0 AND o.contract_date >= ? AND o.contract_date < ?");
    $st->execute([$weekStart, $weekEndExclusive]);
    $stats['orders_week'] = (int)$st->fetchColumn();
    $st = $pdo->prepare("SELECT COALESCE(SUM($salesExpr),0) AS sales_sum, COUNT(*) AS orders_cnt FROM orders o $salesJoin WHERE COALESCE(o.is_deleted,0)=0 AND o.contract_date >= ? AND o.contract_date < ?");
    $st->execute([$monthStart, $nextMonthStart]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $sum = (float)($row['sales_sum'] ?? 0);
    $cnt = (int)($row['orders_cnt'] ?? 0);
    $stats['sales_month'] = safe_money_dashboard($sum);
    $stats['avg_order_value'] = $cnt > 0 ? safe_money_dashboard($sum / $cnt) : 0.0;
    if($hasPayments){
        $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM order_payments WHERE pay_date >= ? AND pay_date < ?");
        $st->execute([$monthStart, $nextMonthStart]);
        $stats['payments_month'] = safe_money_dashboard($st->fetchColumn());
        $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM order_payments WHERE pay_date >= ? AND pay_date < ?");
        $st->execute([$weekStart, $weekEndExclusive]);
        $stats['payments_week'] = safe_money_dashboard($st->fetchColumn());
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM orders o WHERE COALESCE(o.is_deleted,0)=0 AND COALESCE(o.is_finished,0)=0 AND COALESCE(o.install_date_new, o.install_date) >= ? AND COALESCE(o.install_date_new, o.install_date) < ?");
    $st->execute([$weekStart, $weekEndExclusive]);
    $stats['installs_week'] = (int)$st->fetchColumn();
    for($i=5; $i>=0; $i--){
        $mStart = date('Y-m-01', strtotime("-{$i} months"));
        $mNext = date('Y-m-01', strtotime($mStart . ' +1 month'));
        $label = date('m.Y', strtotime($mStart));
        $st = $pdo->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM($salesExpr),0) AS sales FROM orders o $salesJoin WHERE COALESCE(o.is_deleted,0)=0 AND o.contract_date >= ? AND o.contract_date < ?");
        $st->execute([$mStart, $mNext]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $cnt = (int)($row['cnt'] ?? 0);
        $sales = safe_money_dashboard($row['sales'] ?? 0);
        $stats['sales_6m'][] = ['month'=>$label,'label'=>$label,'month_label'=>$label,'orders'=>$cnt,'orders_count'=>$cnt,'new_orders'=>$cnt,'sales'=>$sales,'sales_amount'=>$sales];
        if($hasPayments){
            $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM order_payments WHERE pay_date >= ? AND pay_date < ?");
            $st->execute([$mStart, $mNext]);
            $payments = safe_money_dashboard($st->fetchColumn());
            $stats['payments_6m'][] = ['month'=>$label,'label'=>$label,'month_label'=>$label,'payments'=>$payments,'payments_amount'=>$payments];
        }
    }
    if(table_exists_dashboard($pdo,'order_statuses')){
        $st = $pdo->query("SELECT s.name AS status_name, s.color AS status_color, COUNT(o.id) AS cnt FROM order_statuses s LEFT JOIN orders o ON o.status_id = s.id AND COALESCE(o.is_deleted,0)=0 AND COALESCE(o.is_finished,0)=0 GROUP BY s.id, s.name, s.color ORDER BY s.sort_order ASC, s.id ASC");
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
            $stats['statuses_current'][] = ['status'=>(string)($row['status_name'] ?? ''),'name'=>(string)($row['status_name'] ?? ''),'label'=>(string)($row['status_name'] ?? ''),'status_name'=>(string)($row['status_name'] ?? ''),'count'=>(int)($row['cnt'] ?? 0),'cnt'=>(int)($row['cnt'] ?? 0),'orders_count'=>(int)($row['cnt'] ?? 0),'color'=>(string)($row['status_color'] ?? '#999999'),'status_color'=>(string)($row['status_color'] ?? '#999999')];
        }
    }
    if(table_exists_dashboard($pdo,'boss_tasks')){
        $uid = (int)($me['id'] ?? ($me['uid'] ?? 0));
        if($uid > 0){
            $taskCol = null;
            foreach(['task_text','title','text','task','name'] as $cand){
                try{
                    $q = $pdo->prepare("SHOW COLUMNS FROM boss_tasks LIKE ?");
                    $q->execute([$cand]);
                    if($q->fetch(PDO::FETCH_ASSOC)){ $taskCol = $cand; break; }
                }catch(Throwable $e){}
            }
            if($taskCol){
                $sql = "SELECT id, ".$taskCol." AS task_text, status, comment, DATE_FORMAT(created_at,'%Y-%m-%d %H:%i:%s') AS created_at FROM boss_tasks WHERE assigned_user_id=? ";
                try{
                    $qStatus = $pdo->prepare("SHOW COLUMNS FROM boss_tasks LIKE 'status'");
                    $qStatus->execute();
                    if($qStatus->fetch(PDO::FETCH_ASSOC)){
                        $sql .= "AND status='todo' ";
                    }
                }catch(Throwable $e){}
                $sql .= "ORDER BY id DESC LIMIT 20";
                $st = $pdo->prepare($sql);
                $st->execute([$uid]);
                $stats['boss_tasks'] = $st->fetchAll(PDO::FETCH_ASSOC);
            }else{
                $stats['boss_tasks'] = [];
            }
        }
    }
    json_ok(['stats'=>$stats,'caps'=>$caps,'orders_month'=>$stats['orders_month'],'orders_week'=>$stats['orders_week'],'sales_month'=>$stats['sales_month'],'avg_order_value'=>$stats['avg_order_value'],'payments_month'=>$stats['payments_month'],'payments_week'=>$stats['payments_week'],'installs_week'=>$stats['installs_week'],'month_label'=>$stats['month_label'],'week_label'=>$stats['week_label'],'sales_last_6_months'=>$stats['sales_6m'],'payments_last_6_months'=>$stats['payments_6m'],'status_counts'=>$stats['statuses_current']]);
}catch(Throwable $e){
    json_err('server_error',500,['details'=>$e->getMessage()]);
}
