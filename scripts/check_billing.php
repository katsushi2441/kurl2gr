<?php
// 課金台帳の振る舞いを確認する。決済APIは呼ばず、引き当て・返金・確定だけを見る。
// 実行: php scripts/check_billing.php
//
// 調査は非同期なので「開始で引き当て → 失敗なら戻す」が壊れると、
// 失敗した調査でも課金される／逆にただ乗りできる、のどちらかが起きる。

$tmp = sys_get_temp_dir() . '/kurl2gr-billing-check-' . getmypid();
@mkdir($tmp, 0700, true);
define('KURL2GR_BILLING_DATA_DIR', $tmp);
define('KURL2GR_BILLING_LEDGER', $tmp . '/credits.json');
require_once __DIR__ . '//../public/kurl2gr_billing.php';

$failures = 0;
function check($label, $actual, $expected) {
    global $failures;
    $ok = $actual === $expected;
    if (!$ok) { $failures++; }
    printf("%s %s (期待 %s / 実際 %s)\n", $ok ? 'ok  ' : 'NG  ', $label,
        var_export($expected, true), var_export($actual, true));
}

$user = 'alice';
check('最初はクレジット0', kurl2gr_bill_credits($user), 0);
check('クレジット0では引き当てできない', kurl2gr_bill_reserve($user, 'job00000001'), false);

kurl2gr_bill_grant($user, 2, 'test', 'seed');
check('付与後は2', kurl2gr_bill_credits($user), 2);

check('引き当てできる', kurl2gr_bill_reserve($user, 'job00000002'), true);
check('引き当てで1減る', kurl2gr_bill_credits($user), 1);
check('同じジョブの二重引き当てはしない', kurl2gr_bill_reserve($user, 'job00000002'), true);
check('二重引き当てで減らない', kurl2gr_bill_credits($user), 1);

check('失敗したら戻る', kurl2gr_bill_refund($user, 'job00000002'), true);
check('返金後は2', kurl2gr_bill_credits($user), 2);
check('二重返金はしない', kurl2gr_bill_refund($user, 'job00000002'), false);
check('二重返金で増えない', kurl2gr_bill_credits($user), 2);

kurl2gr_bill_reserve($user, 'job00000003');
check('成立で確定できる', kurl2gr_bill_settle('job00000003'), true);
check('確定後は戻せない', kurl2gr_bill_refund($user, 'job00000003'), false);
check('確定分は消費されたまま', kurl2gr_bill_credits($user), 1);

// 他人のジョブを自分の返金に使えないこと（クレジットの水増しになる）
kurl2gr_bill_grant('bob', 1, 'test', 'seed');
kurl2gr_bill_reserve('bob', 'job00000004');
check('他人のジョブは返金できない', kurl2gr_bill_refund($user, 'job00000004'), false);
check('他人のクレジットは増えない', kurl2gr_bill_credits($user), 1);

@unlink(KURL2GR_BILLING_LEDGER);
@rmdir($tmp);
echo $failures === 0 ? "\nすべて期待どおり\n" : "\n{$failures} 件が期待と違う\n";
exit($failures === 0 ? 0 : 1);
