<?php
// ==========================================
// BACKEND: PHP API LOGIC (دیتابیس و مدیریت کاربران)
// ==========================================
date_default_timezone_set('Asia/Tehran');

$data_dir = __DIR__ . '/data';
if(!is_dir($data_dir)) mkdir($data_dir, 0777, true);

$receipts_dir = $data_dir . '/receipts';
if(!is_dir($receipts_dir)) mkdir($receipts_dir, 0777, true);

$db_file = $data_dir . '/users.json';
$settings_file = $data_dir . '/settings.json';
$tx_file = $data_dir . '/transactions.json';
$log_file = $data_dir . '/log.json';
$support_file = $data_dir . '/support.json';

// توابع مدیریت دیتابیس با سیستم بک‌آپ دو-فایلی (هم‌زمان)
function db_read($filepath, $is_assoc = false) {
    $backup_file = str_replace('.json', '_backup.json', $filepath);
    $data = null;
    if (file_exists($filepath)) $data = json_decode(file_get_contents($filepath), true);
    if ($data === null && file_exists($backup_file)) { // ریکاوری در صورت خرابی
        $data = json_decode(file_get_contents($backup_file), true);
        if ($data !== null) db_write($filepath, $data, $is_assoc);
    }
    return $data === null ? [] : $data;
}

function db_write($filepath, $data, $is_assoc = false) {
    $json = json_encode($data, $is_assoc ? JSON_FORCE_OBJECT : 0);
    file_put_contents($filepath, $json);
    file_put_contents(str_replace('.json', '_backup.json', $filepath), $json); // ساخت بک‌آپ هم‌زمان
}

// سیستم ثبت لاگ رویدادها
function add_log($msg) {
    global $log_file;
    $logs = db_read($log_file, false);
    $timestamp = date('Y-m-d H:i:s');
    $logs[] = "[$timestamp] $msg";
    if(count($logs) > 300) array_shift($logs); // نگهداری ۳۰۰ لاگ آخر
    db_write($log_file, $logs, false);
}

// بررسی وضعیت ادمین بودن
function is_admin($db, $token) {
    return isset($db[$token]) && !empty($db[$token]['is_admin']);
}

// ساخت دیتابیس‌ها اگر وجود نداشتند
if(!file_exists($db_file)) db_write($db_file, [], true);
if(!file_exists($settings_file)) db_write($settings_file, ['zarinpal_merchant' => '', 'is_free_mode' => false, 'card_number' => '', 'card_holder' => '', 'global_message' => '', 'default_payment' => 'zarinpal'], true);
if(!file_exists($tx_file)) db_write($tx_file, [], false);
if(!file_exists($log_file)) { db_write($log_file, [], false); add_log("System Database Initialized."); }
if(!file_exists($support_file)) db_write($support_file, [], true);

// خواندن تنظیمات عمومی جهت ارسال به فرانت‌اند
$global_settings = db_read($settings_file, true);
$pub_settings = [
    'card_number' => $global_settings['card_number'] ?? '',
    'card_holder' => $global_settings['card_holder'] ?? '',
    'is_free_mode' => $global_settings['is_free_mode'] ?? false,
    'global_message' => $global_settings['global_message'] ?? '',
    'default_payment' => $global_settings['default_payment'] ?? 'zarinpal'
];

// --- بررسی بازگشت از درگاه پرداخت زرین‌پال (Callback) ---
$payment_alert = null;
if (isset($_GET['Authority']) && isset($_GET['Status'])) {
    $authority = $_GET['Authority'];
    $status = $_GET['Status'];
    
    $txs = db_read($tx_file, false);
    $settings = db_read($settings_file, true);
    $merchant = $settings['zarinpal_merchant'] ?? '';
    
    $tx_idx = -1;
    foreach($txs as $i => $t) {
        if (isset($t['authority']) && $t['authority'] == $authority) {
            $tx_idx = $i; break;
        }
    }
    
    if ($tx_idx !== -1 && $txs[$tx_idx]['status'] == 'pending') {
        if ($status == 'OK') {
            // تایید تراکنش (Verify)
            $data = [
                "merchant_id" => $merchant,
                "amount" => $txs[$tx_idx]['amount'] * 10, // تبدیل تومان به ریال
                "authority" => $authority
            ];
            
            $ch = curl_init('https://api.zarinpal.com/pg/v4/payment/verify.json');
            curl_setopt($ch, CURLOPT_USERAGENT, 'ZarinPal Rest Api v4');
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $result = json_decode(curl_exec($ch), true);
            curl_close($ch);
            
            if (isset($result['data']['code']) && ($result['data']['code'] == 100 || $result['data']['code'] == 101)) {
                $ref_id = $result['data']['ref_id'];
                $txs[$tx_idx]['status'] = 'success';
                $txs[$tx_idx]['ref_id'] = $ref_id;
                db_write($tx_file, $txs, false);
                
                // بروزرسانی اشتراک کاربر
                $db = db_read($db_file, true);
                $token = $txs[$tx_idx]['user'];
                $months = $txs[$tx_idx]['months'];
                $msToAdd = $months * 30 * 24 * 60 * 60 * 1000;
                $now = floor(microtime(true) * 1000);
                $currentExp = isset($db[$token]['expire']) ? $db[$token]['expire'] : 0;
                $baseDate = $currentExp > $now ? $currentExp : $now;
                $db[$token]['expire'] = $baseDate + $msToAdd;
                db_write($db_file, $db, true);
                
                add_log("ZarinPal success for $token. Ref: $ref_id");
                $payment_alert = ['type' => 'success', 'msg' => "پرداخت با موفقیت انجام شد.\nشماره پیگیری: $ref_id"];
            } else {
                $txs[$tx_idx]['status'] = 'failed';
                db_write($tx_file, $txs, false);
                $err_code = $result['errors']['code'] ?? 'Unknown';
                add_log("ZarinPal failed verify for {$txs[$tx_idx]['user']}. Code: $err_code");
                $payment_alert = ['type' => 'error', 'msg' => "تراکنش ناموفق بود.\nکد خطا: $err_code"];
            }
        } else {
            $txs[$tx_idx]['status'] = 'failed';
            db_write($tx_file, $txs, false);
            add_log("ZarinPal canceled by user {$txs[$tx_idx]['user']}");
            $payment_alert = ['type' => 'error', 'msg' => 'پرداخت توسط شما لغو شد.'];
        }
    }
}

// --- پردازش درخواست‌های API AJAX ---
if(isset($_GET['action'])) {
    $db = db_read($db_file, true);
    $action = $_GET['action'];
    $input = json_decode(file_get_contents('php://input'), true);

    // Endpoint برای نمایش تصاویر رسید آپلود شده
    if ($action == 'view_receipt') {
        $file = $_GET['file'] ?? '';
        $filepath = $receipts_dir . '/' . basename($file);
        if(!empty($file) && file_exists($filepath)) {
            $mime = mime_content_type($filepath);
            header("Content-Type: $mime");
            readfile($filepath);
            exit;
        }
        header("HTTP/1.0 404 Not Found"); exit;
    }

    header('Content-Type: application/json');

    if($action == 'login') {
        $phone = $input['phone'] ?? '';
        $pass = $input['password'] ?? '';
        $is_admin_check = empty($db) ? true : false;
        
        $settings = db_read($settings_file, true);
        $is_free_mode = $settings['is_free_mode'] ?? false;

        if(!isset($db[$phone])) {
            $db[$phone] = ['password' => $pass, 'expire' => 0, 'is_admin' => $is_admin_check];
            db_write($db_file, $db, true);
            add_log("New user registered: $phone");
            echo json_encode(['success' => true, 'token' => $phone, 'expire' => 0, 'is_admin' => $is_admin_check, 'is_free_mode' => $is_free_mode]);
        } else {
            if($db[$phone]['password'] === $pass) {
                add_log("User logged in: $phone");
                echo json_encode(['success' => true, 'token' => $phone, 'expire' => $db[$phone]['expire'], 'is_admin' => $db[$phone]['is_admin'] ?? false, 'is_free_mode' => $is_free_mode]);
            } else {
                add_log("Failed login attempt for: $phone");
                echo json_encode(['success' => false, 'error' => 'رمز عبور اشتباه است']);
            }
        }
        exit;
    }

    if($action == 'init_pay') {
        $token = $input['token'] ?? '';
        if(!$token || !isset($db[$token])) { echo json_encode(['success' => false, 'error' => 'کاربر نامعتبر است.']); exit; }
        
        $settings = db_read($settings_file, true);
        if(!empty($settings['is_free_mode'])) {
            echo json_encode(['success' => false, 'error' => 'سیستم در حالت رایگان است و نیازی به پرداخت نیست.']); exit;
        }

        $merchant = $settings['zarinpal_merchant'] ?? '';
        if(empty($merchant) || strlen($merchant) < 30) {
            echo json_encode(['success' => false, 'error' => 'درگاه پرداخت توسط مدیر تنظیم نشده است.']); exit;
        }

        $months = $input['months'];
        $price = $input['price'] ?? 0;
        $amount_rial = $price * 10;
        $callback_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . strtok($_SERVER['REQUEST_URI'], '?');

        $data = [
            "merchant_id" => $merchant,
            "amount" => $amount_rial,
            "callback_url" => $callback_url,
            "description" => "خرید اشتراک $months ماهه دی‌ان‌اس پرو",
            "metadata" => ["mobile" => $token]
        ];

        $ch = curl_init('https://api.zarinpal.com/pg/v4/payment/request.json');
        curl_setopt($ch, CURLOPT_USERAGENT, 'ZarinPal Rest Api v4');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        
        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            add_log("Zarinpal Curl Error for $token: $curl_error");
            echo json_encode(['success' => false, 'error' => 'خطای ارتباط با سرور زرین‌پال: ' . $curl_error]);
            exit;
        }

        $result = json_decode($response, true);

        if (isset($result['data']['code']) && $result['data']['code'] == 100) {
            $authority = $result['data']['authority'];
            $tx_id = uniqid();
            $txs = db_read($tx_file, false);
            $txs[] = [
                'id' => $tx_id,
                'user' => $token,
                'amount' => $price,
                'months' => $months,
                'date' => date('Y-m-d H:i:s'),
                'status' => 'pending',
                'type' => 'zarinpal',
                'authority' => $authority
            ];
            db_write($tx_file, $txs, false);
            add_log("Zarinpal Pay init by $token. Amount: $price");
            echo json_encode(['success' => true, 'url' => 'https://www.zarinpal.com/pg/StartPay/' . $authority]);
        } else {
            add_log("Zarinpal API Error for $token. Response: " . json_encode($result));
            echo json_encode(['success' => false, 'error' => 'خطا در اتصال به درگاه زرین‌پال. کد خطا: ' . ($result['errors']['code'] ?? 'ناشناخته')]);
        }
        exit;
    }

    if($action == 'submit_receipt') {
        $token = $_POST['token'] ?? '';
        if(!$token || !isset($db[$token])) { echo json_encode(['success' => false, 'error' => 'کاربر نامعتبر است.']); exit; }

        $months = (int)($_POST['months'] ?? 1);
        $price = (int)($_POST['price'] ?? 0);
        $text = $_POST['receipt_text'] ?? '';
        $image_path = '';

        if (isset($_FILES['receipt_image']) && $_FILES['receipt_image']['error'] == 0) {
            $ext = strtolower(pathinfo($_FILES['receipt_image']['name'], PATHINFO_EXTENSION));
            if(in_array($ext, ['jpg', 'jpeg', 'png'])) {
                $filename = uniqid('rcpt_') . '.' . $ext;
                if(move_uploaded_file($_FILES['receipt_image']['tmp_name'], $receipts_dir . '/' . $filename)) {
                    $image_path = $filename;
                }
            } else {
                echo json_encode(['success' => false, 'error' => 'فرمت فایل مجاز نیست (فقط تصاویر JPG و PNG قبول می‌شوند).']); exit;
            }
        }

        if(empty(trim($text)) && empty($image_path)) {
            echo json_encode(['success' => false, 'error' => 'لطفاً حداقل متن پیامک یا تصویر رسید را وارد کنید.']); exit;
        }

        $tx_id = uniqid('card_');
        $txs = db_read($tx_file, false);
        $txs[] = [
            'id' => $tx_id,
            'user' => $token,
            'amount' => $price,
            'months' => $months,
            'date' => date('Y-m-d H:i:s'),
            'status' => 'pending',
            'type' => 'card',
            'receipt_text' => $text,
            'receipt_image' => $image_path
        ];
        db_write($tx_file, $txs, false);
        add_log("Card receipt submitted by $token. Amount: $price");
        echo json_encode(['success' => true]); exit;
    }

    // --- پشتیبانی چت آنلاین ---
    if ($action == 'get_support') {
        $token = $_GET['token'] ?? '';
        if(!$token) { echo json_encode([]); exit; }
        $support = db_read($support_file, true);
        if(is_admin($db, $token)) {
            echo json_encode($support); // Admin sees all chats
        } else {
            echo json_encode($support[$token] ?? []); // User sees only their chat
        }
        exit;
    }

    if ($action == 'send_support') {
        $token = $input['token'] ?? '';
        $text = $input['text'] ?? '';
        $target = $input['target'] ?? $token; 
        if(!$token || empty(trim($text))) { echo json_encode(['success' => false]); exit; }
        
        $user_is_admin = is_admin($db, $token);
        if(!$user_is_admin) $target = $token; // Force target to self if not admin

        $support = db_read($support_file, true);
        if(!isset($support[$target])) $support[$target] = [];
        
        $support[$target][] = [
            'sender' => $user_is_admin ? 'admin' : 'user',
            'text' => htmlspecialchars($text),
            'date' => date('Y-m-d H:i:s')
        ];
        db_write($support_file, $support, true);
        
        if(!$user_is_admin) add_log("Support message sent by user: $token");
        echo json_encode(['success' => true]);
        exit;
    }

    if($action == 'my_txs') {
        $token = $_GET['token'] ?? '';
        $txs = db_read($tx_file, false);
        $my_txs = array_values(array_filter($txs, function($tx) use ($token) { return isset($tx['user']) && $tx['user'] === $token; }));
        echo json_encode($my_txs);
        exit;
    }

    if($action == 'me') {
        $token = $_GET['token'] ?? '';
        if(!$token || !isset($db[$token])) { echo json_encode(['logged_in' => false]); exit; }
        
        $settings = db_read($settings_file, true);
        $is_free_mode = $settings['is_free_mode'] ?? false;

        echo json_encode(['logged_in' => true, 'phone' => $token, 'expire' => $db[$token]['expire'], 'is_admin' => is_admin($db, $token), 'is_free_mode' => $is_free_mode]);
        exit;
    }

    // --- پنل مدیریت ---
    function check_admin($db, $input) {
        $token = $input['token'] ?? ($_GET['token'] ?? '');
        if(is_admin($db, $token)) return true;
        add_log("Unauthorized admin access attempt by $token");
        echo json_encode(['success' => false, 'error' => 'دسترسی غیرمجاز']);
        exit;
    }

    if($action == 'save_settings') {
        check_admin($db, $input);
        $settings = db_read($settings_file, true);
        $settings['zarinpal_merchant'] = $input['merchant'] ?? '';
        $settings['is_free_mode'] = $input['is_free_mode'] ?? false;
        $settings['card_number'] = $input['card_number'] ?? '';
        $settings['card_holder'] = $input['card_holder'] ?? '';
        $settings['default_payment'] = $input['default_payment'] ?? 'zarinpal';
        db_write($settings_file, $settings, true);
        add_log("Admin {$input['token']} updated gateway/card settings");
        echo json_encode(['success' => true]); exit;
    }

    if($action == 'save_global_message') {
        check_admin($db, $input);
        $settings = db_read($settings_file, true);
        $settings['global_message'] = $input['message'] ?? '';
        db_write($settings_file, $settings, true);
        add_log("Admin {$input['token']} updated global message");
        echo json_encode(['success' => true]); exit;
    }

    if($action == 'get_settings') {
        check_admin($db, $_GET);
        $settings = db_read($settings_file, true);
        echo json_encode($settings); exit;
    }

    if($action == 'get_stats') {
        check_admin($db, $_GET);
        $txs = db_read($tx_file, false);
        $active_users = 0;
        $now = floor(microtime(true) * 1000);
        foreach($db as $u) { if(isset($u['expire']) && $u['expire'] > $now) $active_users++; }
        
        $total_revenue = 0;
        $pending_txs = 0;
        foreach($txs as $t) { 
            if(($t['status'] ?? '') === 'success') $total_revenue += $t['amount']; 
            if(($t['status'] ?? '') === 'pending') $pending_txs++;
        }
        echo json_encode(['success' => true, 'total_users' => count($db), 'active_users' => $active_users, 'total_revenue' => $total_revenue, 'pending_txs' => $pending_txs]); exit;
    }

    if($action == 'get_users') {
        check_admin($db, $_GET);
        $users_list = [];
        foreach($db as $phone => $data) {
            $users_list[] = ['phone' => $phone, 'expire' => $data['expire'], 'is_admin' => $data['is_admin'] ?? false, 'password' => $data['password'] ?? ''];
        }
        echo json_encode($users_list); exit;
    }

    if($action == 'update_user') {
        check_admin($db, $input);
        $phone = $input['target_phone'];
        $days = (int)$input['days'];
        $new_pass = $input['password'] ?? '';
        
        if(isset($db[$phone])) {
            $now = floor(microtime(true) * 1000);
            $db[$phone]['expire'] = $days > 0 ? $now + ($days * 24 * 60 * 60 * 1000) : 0;
            
            if(!empty($new_pass)) {
                $db[$phone]['password'] = $new_pass;
            }
            
            db_write($db_file, $db, true);
            add_log("Admin {$input['token']} updated user $phone. Days: $days");
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false]);
        }
        exit;
    }

    if($action == 'delete_user') {
        check_admin($db, $input);
        $phone = $input['target_phone'];
        if(isset($db[$phone])) { 
            unset($db[$phone]); 
            db_write($db_file, $db, true); 
            add_log("Admin {$input['token']} deleted user $phone");
            echo json_encode(['success' => true]); 
        }
        else { echo json_encode(['success' => false]); }
        exit;
    }

    if($action == 'get_transactions') {
        check_admin($db, $_GET);
        $txs = db_read($tx_file, false);
        echo json_encode($txs); exit;
    }

    if($action == 'get_logs') {
        check_admin($db, $_GET);
        $logs = db_read($log_file, false);
        echo json_encode(array_reverse($logs)); // نمایش لاگ‌های جدید در بالا
        exit;
    }

    if($action == 'update_tx_status') {
        check_admin($db, $input);
        $tx_id = $input['tx_id'];
        $new_status = $input['status'];
        $txs = db_read($tx_file, false);
        foreach($txs as &$t) {
            if($t['id'] === $tx_id) {
                $old_status = $t['status'];
                $t['status'] = $new_status;
                
                // اگر تراکنش کارتی بود و از حالت انتظار یا ناموفق به حالت موفق تبدیل شد، اشتراک فعال شود
                if (($t['type'] ?? '') === 'card' && $old_status !== 'success' && $new_status === 'success') {
                    $token = $t['user'];
                    $months = $t['months'];
                    $msToAdd = $months * 30 * 24 * 60 * 60 * 1000;
                    $now = floor(microtime(true) * 1000);
                    $currentExp = isset($db[$token]['expire']) ? $db[$token]['expire'] : 0;
                    $baseDate = $currentExp > $now ? $currentExp : $now;
                    $db[$token]['expire'] = $baseDate + $msToAdd;
                    db_write($db_file, $db, true);
                    add_log("Admin verified card tx $tx_id. Granted $months months to $token");
                }

                db_write($tx_file, $txs, false);
                add_log("Admin changed tx $tx_id status to $new_status");
                echo json_encode(['success' => true]); exit;
            }
        }
        echo json_encode(['success' => false]); exit;
    }

    if($action == 'delete_tx') {
        check_admin($db, $input);
        $tx_id = $input['tx_id'];
        $txs = db_read($tx_file, false);
        $txs = array_values(array_filter($txs, function($t) use ($tx_id) { return isset($t['id']) && $t['id'] !== $tx_id; }));
        db_write($tx_file, $txs, false);
        add_log("Admin {$input['token']} deleted tx $tx_id");
        echo json_encode(['success' => true]); exit;
    }

    exit;
}
?>

<!-- ========================================== -->
<!-- FRONTEND: HTML, CSS, JS (رابط کاربری برنامه) -->
<!-- ========================================== -->
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>دی‌ان‌اس پرو (DNS Pro)</title>
    
    <!-- PWA Manifest & Meta -->
    <meta name="theme-color" content="#3b82f6">
    <link rel="manifest" href="data:application/manifest+json;charset=utf-8,%7B%22name%22%3A%22DNS%20Pro%22%2C%22short_name%22%3A%22DNS%20Pro%22%2C%22start_url%22%3A%22.%2F%22%2C%22display%22%3A%22standalone%22%2C%22background_color%22%3A%22%23ffffff%22%2C%22theme_color%22%3A%22%233b82f6%22%2C%22icons%22%3A%5B%7B%22src%22%3A%22data%3Aimage%2Fsvg%2Bxml%3Bbase64%2CPHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMDAgMTAwIj48cmVjdCB3aWR0aD0iMTAwIiBoZWlnaHQ9IjEwMCIgcng9IjIyIiBmaWxsPSIjM2I4MmY2Ii8%2BPHRleHQgeD0iNTAlIiB5PSI2NSUiIGZvbnQtZmFtaWx5PSJBcmlhbCwgc2Fucy1zZXJpZiIgZm9udC1zaXplPSIzOCIgZm9udC13ZWlnaHQ9IjkwMCIgZmlsbD0iI2ZmZmZmZiIgdGV4dC1hbmNob3I9Im1pZGRsZSI%2BRE5TPC90ZXh0Pjwvc3ZnPg%3D%3D%22%2C%22sizes%22%3A%22192x192%20512x512%22%2C%22type%22%3A%22image%2Fsvg%2Bxml%22%7D%5D%7D">
    
    <link rel="icon" href="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMDAgMTAwIj48cmVjdCB3aWR0aD0iMTAwIiBoZWlnaHQ9IjEwMCIgcng9IjIyIiBmaWxsPSIjM2I4MmY2Ii8+PHRleHQgeD0iNTAlIiB5PSI2NSUiIGZvbnQtZmFtaWx5PSJBcmlhbCwgc2Fucy1zZXJpZiIgZm9udC1zaXplPSIzOCIgZm9udC13ZWlnaHQ9IjkwMCIgZmlsbD0iI2ZmZmZmZiIgdGV4dC1hbmNob3I9Im1pZGRsZSI+RE5TPC90ZXh0Pjwvc3ZnPg==">
    
    <!-- Sync Theme Before Render -->
    <script>
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        }
    </script>

    <!-- بهینه‌سازی فونت‌ها جهت بارگذاری سریع با Preconnect -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/vazirmatn@33.0.0/Vazirmatn-font-face.css" rel="stylesheet" type="text/css" />
    <link href="https://unpkg.com/vazirmatn@33.0.0/Vazirmatn-font-face.css" rel="stylesheet" type="text/css" />
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0" rel="stylesheet" />
    
    <script src="https://cdn.tailwindcss.com"></script>
    
    <script>
        // تزریق متغیرهای عمومی سیستم به صورت امن از سمت سرور
        const PUBLIC_SETTINGS = <?php echo json_encode($pub_settings); ?>;

        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { fontFamily: { sans: ['Vazirmatn', 'Tahoma', 'system-ui', 'sans-serif'] }, colors: { primary: '#3b82f6', darkBg: '#0f172a', darkCard: '#1e293b', darkElevated: '#334155' } } }
        }
    </script>
    <style>
        body, html { -webkit-tap-highlight-color: transparent; font-family: 'Vazirmatn', 'Tahoma', 'system-ui', sans-serif; -webkit-touch-callout: none; -webkit-user-select: none; user-select: none; margin: 0; padding: 0; height: 100%; width: 100%; background-color: #ffffff; }
        .dark body, .dark html { background-color: #0f172a; }
        .material-symbols-rounded { font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
        input, select, textarea { -webkit-user-select: auto; user-select: auto; }
        .page-view { animation: fadeInScale 0.3s cubic-bezier(0.4, 0, 0.2, 1); will-change: transform, opacity; }
        .page-view:not(.active) { display: none !important; }
        @keyframes fadeInScale { from { opacity: 0; transform: scale(0.97) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
        .connect-btn { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.7); animation: buttonBreathe 3s infinite; transition: all 0.3s ease; }
        .connect-btn:active { transform: scale(0.92); animation: none; }
        @keyframes buttonBreathe { 0% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.5); } 50% { box-shadow: 0 0 0 20px rgba(59, 130, 246, 0); } 100% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0); } }
        .pulse-ring { animation: pulse 2s infinite cubic-bezier(0.4, 0, 0.2, 1); }
        @keyframes pulse { 0% { transform: scale(0.9); opacity: 0.5; } 100% { transform: scale(1.5); opacity: 0; } }
        .app-container { max-width: 450px; margin: 0 auto; position: relative; background-color: transparent; box-shadow: 0 0 30px rgba(0,0,0,0.05); }
        .dark .app-container { box-shadow: 0 0 30px rgba(0,0,0,0.6); }
        ::-webkit-scrollbar { width: 0px; background: transparent; }
        .bottom-sheet { transform: translateY(100%); transition: transform 0.4s cubic-bezier(0.2, 0.8, 0.2, 1), opacity 0.4s ease; opacity: 0; visibility: hidden; will-change: transform, opacity; }
        .bottom-sheet.open { transform: translateY(0); opacity: 1; visibility: visible; }
        .sheet-overlay { opacity: 0; visibility: hidden; transition: opacity 0.3s ease; will-change: opacity; }
        .sheet-overlay.open { opacity: 1; visibility: visible; }
        .pro-tab-content { display: none; animation: fadeInScale 0.3s ease; }
        .pro-tab-content.active { display: block; }
    </style>
</head>
<body class="text-gray-800 dark:text-gray-100 transition-colors duration-300">

<!-- Splash Screen -->
<div id="splashScreen" class="fixed inset-0 z-[9999] flex flex-col items-center justify-center bg-white dark:bg-darkBg transition-opacity duration-500">
    <div class="w-24 h-24 bg-blue-50 dark:bg-darkCard rounded-3xl flex items-center justify-center mb-6 shadow-sm border border-blue-100 dark:border-gray-800 animate-pulse">
        <span class="material-symbols-rounded text-6xl text-blue-500">security</span>
    </div>
    <h1 class="text-3xl font-extrabold tracking-tight text-gray-900 dark:text-white">نات استودیو</h1>
    <p class="text-gray-500 dark:text-gray-400 mt-2 text-sm">در حال پردازش...</p>
</div>

<div class="app-container flex flex-col h-[100dvh] overflow-hidden relative">
    
    <header class="flex justify-between items-center px-5 py-4 z-10 transition-colors">
        <div class="flex items-center gap-2">
            <span class="material-symbols-rounded text-blue-500 text-3xl">security</span>
            <h1 class="text-xl font-extrabold tracking-tight">دی‌ان‌اس پرو</h1>
        </div>
        <div class="flex items-center gap-3">
            <button id="notificationBtn" onclick="showGlobalMessage()" class="relative w-10 h-10 rounded-full bg-gray-100 dark:bg-darkCard hover:bg-gray-200 dark:hover:bg-gray-800 flex items-center justify-center transition-all">
                <span class="material-symbols-rounded text-gray-600 dark:text-gray-300 text-[22px]">notifications</span>
                <span id="notifBadge" class="hidden absolute top-2 right-2 w-2.5 h-2.5 bg-red-500 rounded-full border-2 border-white dark:border-darkCard"></span>
            </button>
            <button id="themeToggleBtn" class="w-10 h-10 rounded-full bg-gray-100 dark:bg-darkCard hover:bg-gray-200 dark:hover:bg-gray-800 flex items-center justify-center transition-all">
                <span class="material-symbols-rounded text-gray-600 dark:text-gray-300 dark:hidden text-[22px]">dark_mode</span>
                <span class="material-symbols-rounded text-yellow-400 hidden dark:block text-[22px]">light_mode</span>
            </button>
        </div>
    </header>

    <main class="flex-1 overflow-y-auto pb-28 relative">
        
        <!-- View: Home -->
        <div id="view-home" class="page-view active h-full flex flex-col items-center justify-center p-6 relative">
            <div class="absolute top-4 left-6 right-6 bg-gradient-to-r from-purple-600 to-indigo-600 rounded-2xl p-4 text-white shadow-xl shadow-purple-500/30 flex items-center justify-between cursor-pointer transform hover:scale-[1.02] transition-all" onclick="navigate('pro-tutorial')">
                <div>
                    <h3 class="font-black text-[17px] mb-1 flex items-center gap-1">
                        <span class="material-symbols-rounded text-yellow-400 text-xl">bolt</span>
                        فعال‌سازی اینترنت پرو
                    </h3>
                    <p class="text-[11px] text-purple-100 font-medium">اتصال قانونی به اینترنت جهانی (بدون فیلتر)</p>
                </div>
                <div class="w-10 h-10 bg-white/20 rounded-full flex items-center justify-center backdrop-blur-sm shrink-0 shadow-inner">
                    <span class="material-symbols-rounded text-xl">public</span>
                </div>
            </div>

            <div class="text-center mb-10 mt-16">
                <h2 class="text-3xl font-black mb-3 text-gray-900 dark:text-white">اتصال پرسرعت</h2>
                <p class="text-gray-500 dark:text-gray-400">برای شروع به لیست دی‌ان‌اس‌ها بروید</p>
            </div>

            <div class="relative flex items-center justify-center w-64 h-64">
                <div class="absolute inset-0 rounded-full border-4 border-blue-500 opacity-20 pulse-ring" id="connectPulse"></div>
                <button id="mainConnectBtn" class="connect-btn w-48 h-48 rounded-full bg-gradient-to-tr from-blue-600 via-blue-500 to-cyan-400 text-white flex flex-col items-center justify-center gap-2 z-10 relative">
                    <span class="material-symbols-rounded text-6xl mb-1">power_settings_new</span>
                    <span class="font-bold text-2xl">اتصال</span>
                </button>
            </div>
            
            <div id="homeStatusText" class="mt-10 px-6 py-2 bg-gray-100 dark:bg-darkCard rounded-full text-lg font-medium text-gray-600 dark:text-gray-300 shadow-inner">
                <span class="inline-block w-2 h-2 rounded-full bg-red-500 ml-2 mb-0.5"></span>وضعیت: قطع
            </div>
        </div>

        <!-- View: Login -->
        <div id="view-login" class="page-view p-6">
            <div class="text-center mt-6 mb-10">
                <div class="w-20 h-20 bg-blue-50 dark:bg-darkCard rounded-3xl mx-auto flex items-center justify-center mb-6 shadow-sm border border-blue-100 dark:border-gray-800">
                    <span class="material-symbols-rounded text-5xl text-blue-500">admin_panel_settings</span>
                </div>
                <h2 class="text-2xl font-bold">ورود به سیستم</h2>
                <p class="text-gray-500 dark:text-gray-400 text-sm mt-2">شماره موبایل و رمز عبور خود را وارد کنید</p>
            </div>

            <form id="loginForm" class="space-y-5">
                <div>
                    <label class="block text-sm font-medium mb-2 text-gray-700 dark:text-gray-300">شماره موبایل</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 right-0 flex items-center pr-4 text-gray-400"><span class="material-symbols-rounded">phone_iphone</span></span>
                        <input type="tel" id="loginPhone" placeholder="09..." required pattern="^09[0-9]{9}$" class="w-full pl-4 pr-12 py-3.5 rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-darkElevated focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-left font-mono transition-shadow" dir="ltr">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-2 text-gray-700 dark:text-gray-300">رمز عبور</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 right-0 flex items-center pr-4 text-gray-400"><span class="material-symbols-rounded">lock</span></span>
                        <input type="password" id="loginPass" placeholder="••••••••" required class="w-full pl-4 pr-12 py-3.5 rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-darkElevated focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-left font-mono transition-shadow" dir="ltr">
                    </div>
                </div>
                
                <div class="flex items-center mt-4 px-1">
                    <input type="checkbox" id="termsCheckbox" class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500 dark:focus:ring-blue-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600" required>
                    <label for="termsCheckbox" class="mr-2 text-[11px] text-gray-600 dark:text-gray-400 leading-relaxed cursor-pointer">
                        <a href="#" onclick="openTermsSheet(); return false;" class="text-blue-500 hover:underline font-bold">قوانین و مقررات</a> سیستم را مطالعه کرده و می‌پذیرم.
                    </label>
                </div>

                <div id="loginError" class="text-red-500 text-sm hidden font-medium flex items-center gap-1">
                    <span class="material-symbols-rounded text-lg">error</span><span>شماره یا رمز عبور اشتباه است.</span>
                </div>

                <button type="submit" id="loginBtn" class="w-full bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white font-bold py-4 rounded-2xl transition-all mt-4 shadow-lg shadow-blue-500/30 flex justify-center items-center gap-2">
                    <span>ورود / عضویت</span><span class="material-symbols-rounded">arrow_forward</span>
                </button>
            </form>
            <p class="text-center text-xs text-gray-400 mt-8 leading-relaxed px-4">
                در صورت نداشتن حساب، با وارد کردن یک رمز دلخواه سیستم به صورت خودکار برای شما حساب می‌سازد.
            </p>
        </div>

        <!-- View: Plans -->
        <div id="view-plans" class="page-view p-6">
            <div class="text-center mt-2 mb-8">
                <span class="material-symbols-rounded text-4xl text-blue-500 mb-2">workspace_premium</span>
                <h2 class="text-2xl font-bold">خرید اشتراک پرو</h2>
                <p class="text-gray-500 dark:text-gray-400 text-sm mt-2">برای اتصال به سرورها اشتراک تهیه کنید</p>
            </div>

            <div class="space-y-4">
                <!-- Plan 1 -->
                <div class="bg-gradient-to-r from-blue-600 to-blue-500 text-white rounded-3xl p-1 shadow-xl shadow-blue-500/20 relative cursor-pointer transform hover:-translate-y-1 transition-all mt-4" onclick="openPurchaseSheet(1, 290000, 'یک ماهه')">
                    <div class="absolute -top-4 left-1/2 -translate-x-1/2 bg-yellow-400 text-yellow-900 text-[11px] font-black px-4 py-1.5 rounded-full uppercase tracking-wider flex items-center gap-1 shadow-lg border-2 border-blue-600 z-20">
                        <span class="material-symbols-rounded text-[14px]">star</span> پرفروش‌ترین انتخاب
                    </div>
                    <div class="bg-blue-600 dark:bg-darkBg rounded-[22px] p-5 flex justify-between items-center relative overflow-hidden">
                        <div class="absolute top-0 left-0 w-32 h-32 bg-white/10 rounded-full blur-2xl -translate-x-10 -translate-y-10"></div>
                        <div class="z-10 relative">
                            <h3 class="text-lg font-bold text-white">یک ماهه</h3>
                            <p class="text-blue-100 text-sm mt-1">دسترسی کامل و استاندارد</p>
                        </div>
                        <div class="text-left bg-white/20 px-4 py-2 rounded-2xl z-10 backdrop-blur-sm">
                            <span class="font-bold text-xl">۲۹۰,۰۰۰</span>
                            <span class="text-xs text-blue-100 mr-1">تومان</span>
                        </div>
                    </div>
                </div>

                <!-- Plan 2 -->
                <div class="bg-white dark:bg-darkCard border border-gray-200 dark:border-gray-700 hover:border-blue-500 dark:hover:border-blue-500 rounded-3xl p-5 shadow-sm transition-all cursor-pointer group mt-6" onclick="openPurchaseSheet(2, 390000, 'دو ماهه')">
                    <div class="flex justify-between items-center">
                        <div>
                            <h3 class="text-lg font-bold text-gray-800 dark:text-white group-hover:text-blue-500 transition-colors">دو ماهه</h3>
                            <p class="text-gray-500 text-sm mt-1">انتخاب اقتصادی</p>
                        </div>
                        <div class="text-left bg-gray-50 dark:bg-darkElevated px-4 py-2 rounded-2xl">
                            <span class="font-bold text-xl">۳۹۰,۰۰۰</span>
                            <span class="text-xs text-gray-500 mr-1">تومان</span>
                        </div>
                    </div>
                </div>

                <!-- Plan 3 -->
                <div class="bg-white dark:bg-darkCard border border-gray-200 dark:border-gray-700 hover:border-blue-500 dark:hover:border-blue-500 rounded-3xl p-5 shadow-sm transition-all cursor-pointer group" onclick="openPurchaseSheet(6, 590000, 'شش ماهه')">
                    <div class="flex justify-between items-center">
                        <div>
                            <h3 class="text-lg font-bold text-gray-800 dark:text-white group-hover:text-blue-500 transition-colors">شش ماهه</h3>
                            <p class="text-gray-500 text-sm mt-1">ویژه گیمرها</p>
                        </div>
                        <div class="text-left bg-gray-50 dark:bg-darkElevated px-4 py-2 rounded-2xl">
                            <span class="font-bold text-xl">۵۹۰,۰۰۰</span>
                            <span class="text-xs text-gray-500 mr-1">تومان</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="mt-8 bg-gray-50 dark:bg-darkCard p-4 rounded-3xl border border-gray-100 dark:border-gray-800 flex items-center gap-3">
                <div class="w-12 h-12 rounded-full bg-green-100 dark:bg-green-900/30 text-green-500 flex items-center justify-center shrink-0"><span class="material-symbols-rounded">verified_user</span></div>
                <div>
                    <h4 class="font-bold text-sm text-gray-800 dark:text-gray-200">پرداخت امن و سریع</h4>
                    <p class="text-[11px] text-gray-500 mt-1">امکان واریز مستقیم یا استفاده از درگاه‌های معتبر و رسمی.</p>
                </div>
            </div>
        </div>

        <!-- View: DNS List -->
        <div id="view-dns" class="page-view p-6">
            <div class="flex items-center gap-3 mb-6">
                <span class="material-symbols-rounded text-3xl text-blue-500">format_list_bulleted</span>
                <div>
                    <h2 class="text-xl font-bold">سرورهای دی‌ان‌اس</h2>
                    <p class="text-gray-500 dark:text-gray-400 text-xs mt-1">برای آموزش کلیک کنید</p>
                </div>
            </div>
            <div class="grid grid-cols-1 gap-3" id="dnsListContainer"></div>
        </div>

        <!-- View: Profile -->
        <div id="view-profile" class="page-view p-6">
            <div class="bg-gradient-to-br from-blue-600 to-blue-400 rounded-3xl p-6 shadow-lg text-white mb-6 relative overflow-hidden">
                <div class="absolute right-0 top-0 w-32 h-32 bg-white/10 rounded-full blur-2xl translate-x-10 -translate-y-10"></div>
                <div class="flex items-center gap-4 relative z-10">
                    <div class="w-16 h-16 bg-white/20 rounded-full flex items-center justify-center border-2 border-white/30 backdrop-blur-sm"><span class="material-symbols-rounded text-3xl">person</span></div>
                    <div>
                        <p class="text-blue-100 text-sm mb-1">کاربر سیستم</p>
                        <h2 id="profPhone" class="text-xl font-bold font-mono tracking-widest" dir="ltr">0912...</h2>
                    </div>
                </div>
                <div class="mt-6 pt-5 border-t border-white/20 flex justify-between items-center relative z-10">
                    <div>
                        <p class="text-blue-100 text-xs mb-1">وضعیت اشتراک</p>
                        <div id="profStatusBadge" class="inline-flex items-center gap-1 font-bold text-sm"></div>
                    </div>
                    <div class="text-left">
                        <p class="text-blue-100 text-xs mb-1">باقی‌مانده</p>
                        <span id="profDays" class="font-bold text-lg">...</span>
                    </div>
                </div>
            </div>

            <div class="space-y-3">
                <button onclick="navigate('pro-tutorial')" class="w-full flex items-center justify-between p-4 bg-white dark:bg-darkCard rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-darkElevated transition-colors">
                    <div class="flex items-center gap-3"><span class="material-symbols-rounded text-purple-500">school</span><span class="font-medium">آموزش اینترنت پرو</span></div>
                    <span class="material-symbols-rounded text-gray-300">chevron_left</span>
                </button>
                <button onclick="navigate('history')" class="w-full flex items-center justify-between p-4 bg-white dark:bg-darkCard rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-darkElevated transition-colors">
                    <div class="flex items-center gap-3"><span class="material-symbols-rounded text-blue-500">history</span><span class="font-medium">تراکنش‌های من</span></div>
                    <span class="material-symbols-rounded text-gray-300">chevron_left</span>
                </button>
                <button onclick="navigate('support'); loadUserChat();" class="w-full flex items-center justify-between p-4 bg-white dark:bg-darkCard rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-darkElevated transition-colors">
                    <div class="flex items-center gap-3"><span class="material-symbols-rounded text-green-500">support_agent</span><span class="font-medium">پشتیبانی آنلاین</span></div>
                    <span class="material-symbols-rounded text-gray-300">chevron_left</span>
                </button>
                <button onclick="openTermsSheet()" class="w-full flex items-center justify-between p-4 bg-white dark:bg-darkCard rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-darkElevated transition-colors">
                    <div class="flex items-center gap-3"><span class="material-symbols-rounded text-orange-500">gavel</span><span class="font-medium">قوانین و مقررات</span></div>
                    <span class="material-symbols-rounded text-gray-300">chevron_left</span>
                </button>
            </div>

            <button onclick="logout()" class="w-full mt-6 bg-red-50 dark:bg-red-900/10 hover:bg-red-100 dark:hover:bg-red-900/30 text-red-600 dark:text-red-400 font-bold py-4 rounded-2xl transition-all border border-red-100 dark:border-red-900/30 flex justify-center items-center gap-2">
                <span class="material-symbols-rounded">logout</span> خروج از حساب کاربری
            </button>
        </div>

        <!-- View: Payment History -->
        <div id="view-history" class="page-view p-6">
            <button onclick="navigate('profile')" class="mb-6 flex items-center gap-2 text-gray-500 hover:text-blue-500 dark:text-gray-400 transition-colors">
                <span class="material-symbols-rounded text-xl">arrow_forward</span><span class="font-bold text-sm">بازگشت</span>
            </button>
            <div class="flex items-center gap-3 mb-6">
                <div class="w-12 h-12 bg-blue-50 dark:bg-darkElevated text-blue-500 rounded-2xl flex items-center justify-center"><span class="material-symbols-rounded">receipt_long</span></div>
                <div>
                    <h2 class="text-xl font-bold">تاریخچه تراکنش‌ها</h2>
                    <p class="text-gray-500 dark:text-gray-400 text-xs mt-1">لیست پرداخت‌های شما</p>
                </div>
            </div>
            <div id="userTxList" class="space-y-3 pb-20"><p class="text-sm text-gray-500 text-center py-4">در حال بارگذاری...</p></div>
        </div>

        <!-- View: User Support Chat -->
        <div id="view-support" class="page-view p-6 flex flex-col h-full relative" style="height: 100vh;">
            <div class="flex items-center justify-between mb-4 mt-2">
                <h2 class="text-xl font-bold flex items-center gap-2"><span class="material-symbols-rounded text-green-500">support_agent</span> پشتیبانی آنلاین</h2>
                <button onclick="navigate('profile')" class="text-gray-500 hover:text-blue-500 flex items-center gap-1 transition-colors"><span class="material-symbols-rounded text-xl">arrow_forward</span><span class="text-sm font-bold">بازگشت</span></button>
            </div>
            <div id="userChatContainer" class="flex-1 bg-gray-50 dark:bg-darkElevated rounded-3xl p-4 shadow-inner border border-gray-100 dark:border-gray-800 overflow-y-auto mb-4 space-y-3 flex flex-col relative pb-6">
                <!-- Messages go here -->
            </div>
            <div class="flex gap-2 relative mb-24">
                <input type="text" id="userChatInput" placeholder="پیام خود را بنویسید..." class="w-full pl-14 pr-4 py-4 rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-darkCard focus:outline-none focus:ring-2 focus:ring-green-500 text-sm shadow-sm">
                <button onclick="sendUserMessage()" class="absolute left-2 top-2 bottom-2 w-10 bg-green-500 text-white rounded-xl flex items-center justify-center shadow-md hover:bg-green-600 transition-colors">
                    <span class="material-symbols-rounded text-[20px]">send</span>
                </button>
            </div>
        </div>

        <!-- View: Pro Tutorial -->
        <div id="view-pro-tutorial" class="page-view p-6">
            <button onclick="navigate('home')" class="mb-4 flex items-center gap-2 text-gray-500 hover:text-blue-500 dark:text-gray-400 transition-colors">
                <span class="material-symbols-rounded text-xl">arrow_forward</span><span class="font-bold text-sm">بازگشت</span>
            </button>
            
            <div class="bg-gradient-to-br from-purple-600 to-indigo-700 rounded-3xl p-6 text-white mb-6 shadow-lg shadow-purple-500/20 relative overflow-hidden">
                <div class="absolute -left-10 -top-10 w-40 h-40 bg-white/10 rounded-full blur-3xl"></div>
                <h2 class="text-xl font-black mb-2 relative z-10 flex items-center gap-2">
                    <span class="material-symbols-rounded text-yellow-400">workspace_premium</span> آموزش اینترنت پرو
                </h2>
                <p class="text-purple-100 text-[13px] leading-relaxed relative z-10 text-justify">
                    اینترنت پرو سرویسی است جهت دسترسی پایدار و بدون محدودیت برنامه‌نویسان و فریلنسرها به اینترنت بین‌الملل. مسیر آموزش و فعال‌سازی خود را انتخاب کنید:
                </p>
            </div>

            <!-- Pro Tutorial Tabs -->
            <div class="flex gap-1.5 mb-6 bg-gray-100 dark:bg-darkElevated p-1.5 rounded-2xl relative overflow-x-auto">
                <button onclick="switchProTab('nsr')" id="proTabBtn-nsr" class="pro-tab-btn active flex-1 min-w-[70px] py-2 rounded-xl text-xs sm:text-sm font-bold bg-white dark:bg-darkCard shadow-sm text-purple-600 dark:text-purple-400 transition-all">سامانه نصر</button>
                <button onclick="switchProTab('mci')" id="proTabBtn-mci" class="pro-tab-btn flex-1 min-w-[70px] py-2 rounded-xl text-xs sm:text-sm font-medium text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 transition-all">همراه اول</button>
                <button onclick="switchProTab('irancell')" id="proTabBtn-irancell" class="pro-tab-btn flex-1 min-w-[70px] py-2 rounded-xl text-xs sm:text-sm font-medium text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 transition-all">ایرانسل</button>
                <button onclick="switchProTab('rightel')" id="proTabBtn-rightel" class="pro-tab-btn flex-1 min-w-[70px] py-2 rounded-xl text-xs sm:text-sm font-medium text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 transition-all">رایتل</button>
            </div>

            <!-- Pro Content: NSR -->
            <div id="proTab-nsr" class="pro-tab-content active space-y-4 pb-20 px-1">
                <div class="bg-white dark:bg-darkCard p-5 rounded-3xl border border-gray-100 dark:border-gray-800 shadow-sm">
                    <h3 class="font-bold text-lg mb-3 flex items-center gap-2 text-gray-800 dark:text-gray-100">
                        <span class="w-8 h-8 rounded-full bg-purple-100 dark:bg-purple-900/30 text-purple-600 flex items-center justify-center font-bold text-sm">۱</span> ثبت‌نام سامانه نصر
                    </h3>
                    <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed mb-4">
                        برای دریافت اینترنت پرو، به سامانه سازمان نظام صنفی رایانه‌ای (بخش فریلنسرها) مراجعه کنید. پس از ورود با شماره موبایل و کد ملی، مشخصات خود را تکمیل نمایید.
                    </p>
                    <a href="https://freelancer.irannsr.org/" target="_blank" class="w-full flex items-center justify-center gap-2 py-3 rounded-xl font-bold text-white bg-purple-600 hover:bg-purple-700 transition-colors shadow-lg shadow-purple-500/30">
                        <span class="material-symbols-rounded text-lg">login</span> ورود مستقیم به سامانه نصر
                    </a>
                </div>
                <div class="bg-white dark:bg-darkCard p-5 rounded-3xl border border-gray-100 dark:border-gray-800 shadow-sm">
                    <h3 class="font-bold text-lg mb-3 flex items-center gap-2 text-gray-800 dark:text-gray-100">
                        <span class="w-8 h-8 rounded-full bg-purple-100 dark:bg-purple-900/30 text-purple-600 flex items-center justify-center font-bold text-sm">۲</span> بارگذاری مدارک و تایید
                    </h3>
                    <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed mb-3">
                        پس از ثبت‌نام اولیه، باید رزومه و مستندات خود (سوابق کاری، نمونه‌کارها) را آپلود کنید. پس از تایید توسط کارشناسان (معمولاً بین ۴ تا ۷ روز کاری)، پیامکی حاوی لینک فعال‌سازی و پرداخت برای شما ارسال می‌شود.
                    </p>
                </div>
            </div>

            <!-- Pro Content: MCI -->
            <div id="proTab-mci" class="pro-tab-content space-y-4 pb-20 px-1">
                <div class="bg-white dark:bg-darkCard p-5 rounded-3xl border border-gray-100 dark:border-gray-800 shadow-sm">
                    <div class="w-12 h-12 bg-blue-50 dark:bg-blue-900/30 text-blue-500 rounded-xl flex items-center justify-center mb-4"><span class="material-symbols-rounded">phone_android</span></div>
                    <h3 class="font-bold text-lg mb-2 text-gray-800 dark:text-gray-100">فعال‌سازی از طریق همراه اول</h3>
                    <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed mb-4">
                        همراه اول فرآیند را از طریق کدهای دستوری و به صورت آنلاین پیاده‌سازی کرده است. برای ثبت درخواست اولیه، کافیست کد دستوری زیر را در شماره‌گیر گوشی خود (با سیم‌کارت همراه اول) شماره‌گیری کنید:
                    </p>
                    <div class="bg-gray-50 dark:bg-darkElevated p-4 rounded-2xl flex justify-between items-center text-center">
                        <span class="font-mono font-bold text-xl text-blue-600 dark:text-blue-400 w-full" dir="ltr">*10*237#</span>
                    </div>
                </div>
                <div class="p-4 bg-blue-50 dark:bg-blue-900/20 text-blue-800 dark:text-blue-200 rounded-2xl text-xs leading-relaxed flex gap-3 shadow-inner">
                    <span class="material-symbols-rounded text-xl shrink-0">info</span>
                    <div>
                        <strong>توضیحات تکمیلی:</strong><br>
                        پس از ثبت درخواست، حداکثر تا ۴۸ ساعت پیامک تایید حاوی لینک پرداخت از سرشماره‌های رسمی همراه اول ارسال می‌گردد. پس از پرداخت هزینه بسته، گوشی را به مدت ۱ دقیقه روی حالت پرواز (Airplane Mode) قرار دهید تا تنظیمات اعمال شود.
                    </div>
                </div>
            </div>

            <!-- Pro Content: Irancell -->
            <div id="proTab-irancell" class="pro-tab-content space-y-4 pb-20 px-1">
                <div class="bg-white dark:bg-darkCard p-5 rounded-3xl border border-gray-100 dark:border-gray-800 shadow-sm">
                    <div class="w-12 h-12 bg-yellow-50 dark:bg-yellow-900/30 text-yellow-500 rounded-xl flex items-center justify-center mb-4"><span class="material-symbols-rounded">web</span></div>
                    <h3 class="font-bold text-lg mb-2 text-gray-800 dark:text-gray-100">پورتال اختصاصی ایرانسل</h3>
                    <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed mb-4">
                        شرکت ایرانسل برای دسترسی ویژه فریلنسرها و توسعه‌دهندگان، بخش اختصاصی را در سایت خود طراحی کرده است. شما باید فرم درخواست سازمان/فریلنسر را در این وب‌سایت پر کنید.
                    </p>
                    <a href="https://irancell.ir/p/294025/" target="_blank" class="w-full flex items-center justify-center gap-2 py-3 rounded-xl font-bold text-yellow-600 bg-yellow-50 hover:bg-yellow-100 dark:bg-yellow-900/30 dark:text-yellow-400 transition-colors shadow-sm">
                        <span class="material-symbols-rounded text-lg">open_in_new</span> ورود به بخش فریلنسرهای ایرانسل
                    </a>
                </div>
                <div class="bg-white dark:bg-darkCard p-5 rounded-3xl border border-gray-100 dark:border-gray-800 shadow-sm">
                    <h3 class="font-bold text-sm mb-2 text-gray-800 dark:text-gray-100">مراحل بعد از ثبت‌نام</h3>
                    <p class="text-gray-600 dark:text-gray-300 text-xs leading-relaxed">
                        پس از ثبت مشخصات در پورتال ایرانسل، تیم پشتیبانی سازمانی با شما تماس گرفته و یا از طریق ایمیل/پیامک وضعیت درخواست شما را مشخص می‌کنند. در صورت تایید، می‌توانید از طریق اپلیکیشن ایرانسل‌من نسبت به خرید بسته‌های پرو اقدام نمایید.
                    </p>
                </div>
            </div>

            <!-- Pro Content: Rightel -->
            <div id="proTab-rightel" class="pro-tab-content space-y-4 pb-20 px-1">
                <div class="bg-white dark:bg-darkCard p-5 rounded-3xl border border-gray-100 dark:border-gray-800 shadow-sm">
                    <div class="w-12 h-12 bg-purple-50 dark:bg-purple-900/30 text-purple-600 rounded-xl flex items-center justify-center mb-4"><span class="material-symbols-rounded">cell_tower</span></div>
                    <h3 class="font-bold text-lg mb-2 text-gray-800 dark:text-gray-100">ثبت‌نام در سامانه رایتل</h3>
                    <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed mb-4">
                        رایتل به صورت مستقیم صفحه ثبت‌نام آزادکاران (فریلنسرها) را در دسترس قرار داده است. در این صفحه با وارد کردن شماره سیم‌کارت رایتلی خود و کد ملی، فرآیند اعتبارسنجی را آغاز کنید.
                    </p>
                    <a href="https://www.rightel.ir/freelancer" target="_blank" class="w-full flex items-center justify-center gap-2 py-3 rounded-xl font-bold text-purple-600 bg-purple-50 hover:bg-purple-100 dark:bg-purple-900/30 dark:text-purple-400 transition-colors shadow-sm">
                        <span class="material-symbols-rounded text-lg">link</span> پورتال فریلنسرهای رایتل
                    </a>
                </div>
                <div class="bg-white dark:bg-darkCard p-5 rounded-3xl border border-gray-100 dark:border-gray-800 shadow-sm">
                    <h3 class="font-bold text-sm mb-2 text-gray-800 dark:text-gray-100">نحوه بررسی و فعال‌سازی</h3>
                    <p class="text-gray-600 dark:text-gray-300 text-xs leading-relaxed">
                        رایتل اطلاعات وارد شده را در بستر سامانه شاهکار و سامانه‌های مربوطه بررسی می‌کند. به محض تکمیل احراز هویت، پیامک تایید با لینک پرداخت و فعال‌سازی سرویس اینترنت آزاد برای شما ارسال می‌شود.
                    </p>
                </div>
            </div>
        </div>

        <!-- View: Tutorial (Normal DNS) -->
        <div id="view-tutorial" class="page-view p-6">
            <button onclick="navigate('dns')" class="mb-6 flex items-center gap-2 text-gray-500 hover:text-blue-500 dark:text-gray-400 transition-colors">
                <span class="material-symbols-rounded text-xl">arrow_forward</span><span class="font-bold text-sm">بازگشت</span>
            </button>
            <div class="bg-white dark:bg-darkCard rounded-3xl p-6 shadow-sm border border-gray-100 dark:border-gray-800 mb-6 relative overflow-hidden">
                <div class="absolute -right-4 -top-4 w-24 h-24 bg-blue-50 dark:bg-blue-900/20 rounded-full blur-2xl"></div>
                <div class="flex items-center gap-4 mb-6 relative z-10">
                    <div class="w-14 h-14 bg-blue-100 dark:bg-darkElevated rounded-2xl flex items-center justify-center text-blue-600 shadow-inner"><span class="material-symbols-rounded text-3xl">dns</span></div>
                    <div><h2 id="tutName" class="text-xl font-black">نام دی‌ان‌اس</h2><p class="text-blue-500 text-sm font-medium mt-1">آموزش در اندروید</p></div>
                </div>
                <div class="space-y-3 relative z-10" dir="ltr">
                    <div class="bg-gray-50 dark:bg-darkElevated p-4 rounded-2xl flex justify-between items-center border border-gray-100 dark:border-gray-700">
                        <span class="text-xs font-bold text-gray-400 tracking-wider">DNS 1</span>
                        <div class="flex items-center gap-3">
                            <span id="tutDns1" class="font-bold font-mono text-lg text-blue-600 dark:text-blue-400">0.0.0.0</span>
                            <button onclick="copyDns('tutDns1', this)" class="text-gray-600 dark:text-gray-300 hover:text-blue-500 bg-gray-200 dark:bg-gray-700 px-3 py-1.5 rounded-lg flex items-center gap-1 transition-colors">
                                <span class="text-xs font-bold">کپی</span>
                                <span class="material-symbols-rounded text-[18px]">content_copy</span>
                            </button>
                        </div>
                    </div>
                    <div class="bg-gray-50 dark:bg-darkElevated p-4 rounded-2xl flex justify-between items-center border border-gray-100 dark:border-gray-700">
                        <span class="text-xs font-bold text-gray-400 tracking-wider">DNS 2</span>
                        <div class="flex items-center gap-3">
                            <span id="tutDns2" class="font-bold font-mono text-lg text-blue-600 dark:text-blue-400">0.0.0.0</span>
                            <button onclick="copyDns('tutDns2', this)" class="text-gray-600 dark:text-gray-300 hover:text-blue-500 bg-gray-200 dark:bg-gray-700 px-3 py-1.5 rounded-lg flex items-center gap-1 transition-colors">
                                <span class="text-xs font-bold">کپی</span>
                                <span class="material-symbols-rounded text-[18px]">content_copy</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <h3 class="font-bold text-lg mb-6 flex items-center gap-2 text-gray-800 dark:text-white px-2"><span class="material-symbols-rounded text-green-500">android</span> مراحل تنظیم:</h3>
            <div class="space-y-4 px-2 pb-20">
                <div class="flex gap-4 items-start"><div class="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-600 font-bold flex items-center justify-center shrink-0 mt-0.5 shadow-inner">۱</div><div class="text-gray-700 dark:text-gray-300 text-sm leading-relaxed pt-1">به بخش <b>تنظیمات (Settings)</b> گوشی خود بروید.</div></div>
                <div class="flex gap-4 items-start"><div class="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-600 font-bold flex items-center justify-center shrink-0 mt-0.5 shadow-inner">۲</div><div class="text-gray-700 dark:text-gray-300 text-sm leading-relaxed pt-1">وارد بخش <b>Network & Internet</b> شوید و روی Wi-Fi کلیک کنید.</div></div>
                <div class="flex gap-4 items-start"><div class="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-600 font-bold flex items-center justify-center shrink-0 mt-0.5 shadow-inner">۳</div><div class="text-gray-700 dark:text-gray-300 text-sm leading-relaxed pt-1">روی نام وای‌فای متصل شده نگه دارید و <b>Modify Network</b> را انتخاب کنید.</div></div>
                <div class="flex gap-4 items-start"><div class="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-600 font-bold flex items-center justify-center shrink-0 mt-0.5 shadow-inner">۴</div><div class="text-gray-700 dark:text-gray-300 text-sm leading-relaxed pt-1">بخش IP Settings را روی <b>Static (ثابت)</b> قرار دهید.</div></div>
                <div class="flex gap-4 items-start"><div class="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-600 font-bold flex items-center justify-center shrink-0 mt-0.5 shadow-inner">۵</div><div class="text-gray-700 dark:text-gray-300 text-sm leading-relaxed pt-1">در فیلدهای DNS، آی‌پی‌های بالا را وارد کرده و Save را بزنید.</div></div>
            </div>
        </div>

        <!-- View: Admin Panel -->
        <div id="view-admin" class="page-view p-6">
            <button onclick="navigate('profile')" class="mb-4 flex items-center gap-2 text-gray-500 hover:text-blue-500 dark:text-gray-400 transition-colors">
                <span class="material-symbols-rounded text-xl">arrow_forward</span><span class="font-bold text-sm">بازگشت به پروفایل</span>
            </button>
            
            <div class="text-center mb-6">
                <span class="material-symbols-rounded text-5xl text-purple-500 mb-2">admin_panel_settings</span><h2 class="text-2xl font-bold">پنل مدیریت سیستم</h2>
            </div>

            <!-- Admin Tabs -->
            <div class="flex gap-2 mb-6 bg-gray-100 dark:bg-darkElevated p-1.5 rounded-2xl relative overflow-x-auto whitespace-nowrap" style="scrollbar-width: none;">
                <button onclick="switchAdminTab('stats')" id="tabBtn-stats" class="admin-tab-btn active px-4 py-2 rounded-xl text-sm font-bold bg-white dark:bg-darkCard shadow-sm text-purple-600 dark:text-purple-400 transition-all">آمار کلی</button>
                <button onclick="switchAdminTab('users')" id="tabBtn-users" class="admin-tab-btn px-4 py-2 rounded-xl text-sm font-medium text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 transition-all">کاربران</button>
                <button onclick="switchAdminTab('txs')" id="tabBtn-txs" class="admin-tab-btn px-4 py-2 rounded-xl text-sm font-medium text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 transition-all">تراکنش‌ها</button>
                <button onclick="switchAdminTab('support')" id="tabBtn-support" class="admin-tab-btn px-4 py-2 rounded-xl text-sm font-medium text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 transition-all">پشتیبانی</button>
                <button onclick="switchAdminTab('message')" id="tabBtn-message" class="admin-tab-btn px-4 py-2 rounded-xl text-sm font-medium text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 transition-all">پیام همگانی</button>
                <button onclick="switchAdminTab('logs')" id="tabBtn-logs" class="admin-tab-btn px-4 py-2 rounded-xl text-sm font-medium text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 transition-all">لاگ‌ها</button>
                <button onclick="switchAdminTab('settings')" id="tabBtn-settings" class="admin-tab-btn px-4 py-2 rounded-xl text-sm font-medium text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 transition-all">تنظیمات</button>
            </div>

            <!-- Tab Content: Stats -->
            <div id="adminTab-stats" class="admin-tab-content block">
                <div class="grid grid-cols-3 gap-2 mb-4">
                    <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-3xl p-4 text-white shadow-lg shadow-blue-500/20 text-center">
                        <span class="material-symbols-rounded text-white/50 mb-1 text-2xl">groups</span><p class="text-blue-100 text-[10px] mb-1">کل کاربران</p><h4 id="adminStat-users" class="text-lg font-black">---</h4>
                    </div>
                    <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-3xl p-4 text-white shadow-lg shadow-green-500/20 text-center">
                        <span class="material-symbols-rounded text-white/50 mb-1 text-2xl">how_to_reg</span><p class="text-green-100 text-[10px] mb-1">تعداد فعال</p><h4 id="adminStat-active" class="text-lg font-black">---</h4>
                    </div>
                    <div class="bg-gradient-to-br from-yellow-500 to-yellow-600 rounded-3xl p-4 text-white shadow-lg shadow-yellow-500/20 text-center">
                        <span class="material-symbols-rounded text-white/50 mb-1 text-2xl">pending_actions</span><p class="text-yellow-100 text-[10px] mb-1">در انتظار تایید</p><h4 id="adminStat-pending" class="text-lg font-black">---</h4>
                    </div>
                </div>
                <div class="bg-gradient-to-br from-purple-600 to-purple-700 rounded-3xl p-5 text-white shadow-lg shadow-purple-500/20 mb-6">
                    <div class="flex justify-between items-center">
                        <div>
                            <span class="material-symbols-rounded text-white/50 mb-2 text-3xl">account_balance</span><p class="text-purple-100 text-xs mb-1">درآمد کل سیستم</p>
                            <h4 class="text-2xl font-black flex items-center gap-1"><span id="adminStat-revenue">---</span> <span class="text-xs font-normal">تومان</span></h4>
                        </div>
                        <div class="w-12 h-12 rounded-full border-4 border-white/20 border-t-white flex items-center justify-center"><span class="material-symbols-rounded text-xl animate-pulse">trending_up</span></div>
                    </div>
                </div>
            </div>

            <!-- Tab Content: Users -->
            <div id="adminTab-users" class="admin-tab-content hidden">
                <div class="bg-white dark:bg-darkCard rounded-3xl p-4 shadow-sm border border-gray-100 dark:border-gray-800">
                    <div id="adminUserList" class="space-y-3 max-h-[350px] overflow-y-auto pr-1">
                        <p class="text-sm text-gray-500 text-center py-4">در حال بارگذاری...</p>
                    </div>
                </div>
            </div>

            <!-- Tab Content: Transactions -->
            <div id="adminTab-txs" class="admin-tab-content hidden">
                <div class="bg-white dark:bg-darkCard rounded-3xl p-4 shadow-sm border border-gray-100 dark:border-gray-800">
                    <div id="adminTxList" class="space-y-3 max-h-[350px] overflow-y-auto pr-1">
                        <p class="text-sm text-gray-500 text-center py-4">در حال بارگذاری...</p>
                    </div>
                </div>
            </div>

            <!-- Tab Content: Support Chat List -->
            <div id="adminTab-support" class="admin-tab-content hidden">
                <div class="bg-white dark:bg-darkCard rounded-3xl p-4 shadow-sm border border-gray-100 dark:border-gray-800">
                    <div id="adminSupportUsersList" class="space-y-2 max-h-[350px] overflow-y-auto pr-1">
                        <p class="text-sm text-gray-500 text-center py-4">در حال بارگذاری...</p>
                    </div>
                    
                    <div id="adminSupportChatArea" class="hidden flex-col h-[400px]">
                        <div class="flex justify-between items-center mb-3 border-b border-gray-100 dark:border-gray-700 pb-2">
                            <span id="adminChatTarget" class="font-bold text-sm font-mono text-purple-600 dark:text-purple-400" dir="ltr"></span>
                            <div class="flex gap-2">
                                <button onclick="toggleAdminChatFullscreen()" class="text-xs bg-gray-100 dark:bg-darkElevated p-1.5 rounded-lg flex items-center justify-center"><span class="material-symbols-rounded text-[16px]" id="adminFullscreenIcon">fullscreen</span></button>
                                <button onclick="closeAdminChat()" class="text-xs bg-gray-100 dark:bg-darkElevated px-3 py-1.5 rounded-lg flex items-center gap-1"><span class="material-symbols-rounded text-[14px]">arrow_forward</span> لیست</button>
                            </div>
                        </div>
                        <div id="adminChatContainer" class="flex-1 overflow-y-auto space-y-2 mb-3 pr-1 flex flex-col p-2 bg-gray-50 dark:bg-darkBg rounded-xl border border-gray-100 dark:border-gray-800"></div>
                        <div class="flex gap-2 relative">
                            <input type="text" id="adminChatInput" placeholder="پاسخ..." class="w-full pl-10 pr-3 py-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-darkElevated text-sm focus:outline-none focus:ring-2 focus:ring-purple-500">
                            <button onclick="sendAdminMessage()" class="absolute left-1.5 top-1.5 bottom-1.5 w-8 bg-purple-500 text-white rounded-lg flex items-center justify-center hover:bg-purple-600 transition-colors">
                                <span class="material-symbols-rounded text-[18px]">send</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab Content: Settings -->
            <div id="adminTab-settings" class="admin-tab-content hidden">
                <div class="bg-white dark:bg-darkCard rounded-3xl p-6 shadow-sm border border-gray-100 dark:border-gray-800">
                    <form id="adminSettingsForm" class="space-y-5">
                        <div class="bg-gray-50 dark:bg-darkElevated p-4 rounded-2xl border border-gray-200 dark:border-gray-700 flex items-center justify-between">
                            <div>
                                <h4 class="font-bold text-sm text-gray-800 dark:text-gray-200">رایگان‌سازی اپلیکیشن</h4>
                                <p class="text-[10px] text-gray-500 mt-1">دور زدن درگاه پرداخت برای همه کاربران</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" id="adminIsFreeMode" class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:-translate-x-0 peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-purple-600"></div>
                            </label>
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-blue-600 dark:text-blue-400 mb-2">اطلاعات درگاه پرداخت</label>
                            <label class="block text-xs font-medium mb-1 text-gray-700 dark:text-gray-300">کد مرچنت زرین‌پال</label>
                            <input type="text" id="zarinpalMerchant" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" class="w-full px-4 py-3 rounded-2xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-darkElevated focus:outline-none focus:ring-2 focus:ring-purple-500 text-left font-mono text-sm" dir="ltr">
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1 text-gray-700 dark:text-gray-300">اولویت پیش‌فرض پرداخت</label>
                            <select id="adminDefaultPayment" class="w-full px-4 py-3 rounded-2xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-darkElevated focus:outline-none focus:ring-2 focus:ring-purple-500 text-sm">
                                <option value="zarinpal">درگاه پرداخت آنلاین (اولویت اول)</option>
                                <option value="card">کارت‌به‌کارت (اولویت اول)</option>
                            </select>
                        </div>
                        <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                            <label class="block text-sm font-bold text-purple-600 dark:text-purple-400 mb-2">اطلاعات کارت‌به‌کارت</label>
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs font-medium mb-1 text-gray-700 dark:text-gray-300">شماره کارت مقصد</label>
                                    <input type="text" id="adminCardNumber" placeholder="---- ---- ---- ----" class="w-full px-4 py-3 rounded-2xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-darkElevated focus:ring-2 focus:ring-purple-500 text-left font-mono text-sm" dir="ltr">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1 text-gray-700 dark:text-gray-300">نام صاحب کارت</label>
                                    <input type="text" id="adminCardHolder" placeholder="مثال: علی رضایی" class="w-full px-4 py-3 rounded-2xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-darkElevated focus:ring-2 focus:ring-purple-500 text-sm">
                                </div>
                            </div>
                        </div>
                        <button type="submit" id="saveAdminBtn" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-bold py-3.5 rounded-2xl transition-all shadow-lg shadow-purple-500/30 flex justify-center items-center gap-2 mt-2">
                            <span class="material-symbols-rounded">save</span><span>ذخیره تنظیمات</span>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Tab Content: Global Message -->
            <div id="adminTab-message" class="admin-tab-content hidden">
                <div class="bg-white dark:bg-darkCard rounded-3xl p-6 shadow-sm border border-gray-100 dark:border-gray-800">
                    <h3 class="font-bold text-sm mb-4 text-gray-800 dark:text-gray-100">ارسال پیام همگانی به کاربران</h3>
                    <p class="text-[11px] text-gray-500 mb-3 leading-relaxed">این پیام در نوار بالای اپلیکیشن کاربر (بخش زنگوله) قرار می‌گیرد و تنها در صورتی که کاربر آن را ندیده باشد، با آیکون قرمز رنگ اطلاع‌رسانی می‌شود.</p>
                    <textarea id="globalMessageInput" placeholder="متن پیام خود را اینجا بنویسید..." class="w-full px-4 py-3 rounded-2xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-darkElevated focus:outline-none focus:ring-2 focus:ring-purple-500 text-sm h-32 resize-none"></textarea>
                    <button onclick="saveGlobalMessage()" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-bold py-3.5 rounded-2xl transition-all shadow-lg shadow-purple-500/30 mt-4 flex items-center justify-center gap-2"><span class="material-symbols-rounded">campaign</span> ثبت پیام همگانی</button>
                </div>
            </div>

            <!-- Tab Content: Logs -->
            <div id="adminTab-logs" class="admin-tab-content hidden">
                <div class="bg-gray-900 rounded-3xl p-4 shadow-sm border border-gray-800 text-left font-mono text-[10px] text-green-400 h-[350px] overflow-y-auto" dir="ltr" id="adminLogsContent">
                    <p class="text-center py-10 opacity-50">در حال بارگذاری...</p>
                </div>
            </div>
            
            <div class="h-20"></div>
        </div>

    </main>

    <!-- Floating Glass Bottom Navigation Bar -->
    <div class="absolute left-6 right-6 z-20 pb-4" style="bottom: env(safe-area-inset-bottom);">
        <nav class="bg-white/85 dark:bg-slate-800/90 backdrop-blur-md border border-white/40 dark:border-gray-700/50 rounded-3xl px-2 py-2 flex justify-around items-center shadow-xl shadow-blue-900/5">
            <button class="nav-btn flex flex-col items-center gap-1 text-gray-400 hover:text-blue-500 transition-colors relative w-16" data-target="home">
                <div class="icon-bg px-4 py-1 rounded-full transition-all duration-300 ease-in-out"><span class="material-symbols-rounded text-[26px]">power_settings_new</span></div>
                <span class="text-[10px] font-bold mt-0.5">اتصال</span>
            </button>
            <button class="nav-btn flex flex-col items-center gap-1 text-gray-400 hover:text-blue-500 transition-colors relative w-16" data-target="dns">
                <div class="icon-bg px-4 py-1 rounded-full transition-all duration-300 ease-in-out"><span class="material-symbols-rounded text-[26px]">format_list_bulleted</span></div>
                <span class="text-[10px] font-bold mt-0.5">سرورها</span>
            </button>
            <button class="nav-btn flex flex-col items-center gap-1 text-gray-400 hover:text-blue-500 transition-colors relative w-16" data-target="profile">
                <div class="icon-bg px-4 py-1 rounded-full transition-all duration-300 ease-in-out"><span class="material-symbols-rounded text-[26px]">person</span></div>
                <span class="text-[10px] font-bold mt-0.5">پروفایل</span>
            </button>
        </nav>
    </div>

    <!-- Generic Background Overlay -->
    <div id="sheetOverlay" class="sheet-overlay fixed inset-0 z-40 bg-black/60 backdrop-blur-sm" onclick="closeAllSheets()"></div>

    <!-- Purchase Bottom Sheet (Dual Payment) -->
    <div id="purchaseSheet" class="bottom-sheet fixed bottom-0 left-0 right-0 z-50 bg-white dark:bg-darkCard rounded-t-3xl p-6 shadow-[0_-10px_40px_rgba(0,0,0,0.1)] dark:border-t dark:border-gray-800 pb-[calc(1.5rem+env(safe-area-inset-bottom))]">
        <div class="w-12 h-1.5 bg-gray-200 dark:bg-gray-700 rounded-full mx-auto mb-5"></div>
        <div class="flex items-center gap-3 mb-5 bg-blue-50 dark:bg-darkElevated p-3 rounded-2xl border border-blue-100 dark:border-gray-700">
            <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900/30 text-blue-600 rounded-full flex items-center justify-center shrink-0"><span class="material-symbols-rounded text-xl">shopping_cart_checkout</span></div>
            <div>
                <h3 class="font-bold text-sm text-gray-800 dark:text-white" id="purchasePlanName">اشتراک پرو</h3>
                <p class="text-[10px] text-gray-500 mt-0.5">روش پرداخت خود را انتخاب کنید.</p>
            </div>
        </div>

        <!-- Payment Method Tabs -->
        <div class="flex gap-2 mb-4 bg-gray-100 dark:bg-darkElevated p-1.5 rounded-xl">
            <button id="payTabBtn-card" class="flex-1 py-2.5 rounded-lg text-sm font-bold bg-white dark:bg-darkCard text-blue-600 dark:text-blue-400 shadow-sm transition-all" onclick="switchPayTab('card')">کارت‌به‌کارت</button>
            <button id="payTabBtn-zarinpal" class="flex-1 py-2.5 rounded-lg text-sm font-medium text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition-all" onclick="switchPayTab('zarinpal')">درگاه آنلاین</button>
        </div>

        <!-- Container: Zarinpal -->
        <div id="payContainer-zarinpal" class="hidden">
            <p class="text-xs text-gray-500 dark:text-gray-400 text-center mb-4 px-2 leading-relaxed">پرداخت از طریق درگاه‌های امن شاپرک انجام شده و اشتراک شما به صورت آنی فعال می‌گردد.</p>
            <button onclick="confirmPurchase()" class="w-full py-4 rounded-2xl font-bold text-white bg-blue-600 hover:bg-blue-700 shadow-lg shadow-blue-500/30 transition-all flex justify-center items-center gap-2">
                <span class="material-symbols-rounded">verified_user</span> پرداخت از طریق درگاه پرداخت
            </button>
        </div>

        <!-- Container: Card to Card -->
        <div id="payContainer-card" class="block space-y-3">
            <div class="bg-white dark:bg-gradient-to-r dark:from-gray-800 dark:to-gray-900 text-gray-800 dark:text-white p-4 rounded-2xl relative overflow-hidden shadow-sm border border-gray-200 dark:border-gray-700">
                
                <div class="flex justify-between items-center mb-3 pb-3 border-b border-gray-100 dark:border-gray-700">
                    <div>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400 mb-1">مبلغ قابل پرداخت</p>
                        <p class="font-bold text-lg text-blue-600 dark:text-blue-400" id="cardPayAmount">0 <span class="text-[10px]">تومان</span></p>
                    </div>
                    <button onclick="copyToClipboard(selectedPlan ? selectedPlan.price * 10 : 0, this)" title="کپی به ریال" class="text-gray-500 dark:text-gray-300 hover:text-blue-600 transition-colors bg-gray-100 dark:bg-white/10 p-1.5 rounded-lg flex items-center gap-1"><span class="text-[10px] font-bold">کپی ریال</span><span class="material-symbols-rounded text-sm">content_copy</span></button>
                </div>

                <p class="text-[11px] text-gray-500 dark:text-gray-400 mb-1">شماره کارت مقصد</p>
                <div class="flex items-center justify-between mt-1">
                    <p class="font-mono text-xl font-bold tracking-widest text-gray-800 dark:text-white" id="publicCardNumber" dir="ltr">---- ---- ---- ----</p>
                    <button onclick="copyToClipboard(PUBLIC_SETTINGS.card_number, this)" class="text-gray-600 dark:text-gray-300 hover:text-blue-600 transition-colors bg-gray-100 dark:bg-white/10 px-3 py-1.5 rounded-lg flex items-center gap-1">
                        <span class="text-xs font-bold">کپی شماره کارت</span>
                        <span class="material-symbols-rounded text-sm">content_copy</span>
                    </button>
                </div>
                <p class="text-xs font-bold mt-3 text-gray-600 dark:text-gray-300" id="publicCardHolder">به نام: ---</p>
            </div>
            
            <div class="p-3 bg-blue-50 dark:bg-blue-900/20 text-blue-800 dark:text-blue-200 rounded-xl text-[11px] leading-relaxed flex gap-2">
                <span class="material-symbols-rounded text-base shrink-0">info</span>
                <span>لطفاً مبلغ را به صورت دقیق واریز نموده و شماره پیگیری یا تصویر رسید خود را در کادرهای زیر ثبت کنید.</span>
            </div>
            
            <div class="space-y-2 mt-2">
                <input type="file" id="receiptImage" accept=".jpg,.jpeg,.png" class="hidden" onchange="document.getElementById('fileNameDisp').innerText = this.files[0] ? this.files[0].name : 'آپلود تصویر رسید بانکی (الزامی)'">
                <label for="receiptImage" class="flex items-center justify-center gap-2 w-full py-3 rounded-xl border-2 border-dashed border-gray-300 dark:border-gray-600 text-xs font-bold text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-darkElevated cursor-pointer transition-colors bg-white dark:bg-darkCard">
                    <span class="material-symbols-rounded text-xl text-blue-500">cloud_upload</span>
                    <span id="fileNameDisp" class="truncate max-w-[200px]">آپلود تصویر رسید بانکی (الزامی)</span>
                </label>

                <p class="text-[10px] text-gray-500 text-center my-2">در صورت عدم امکان آپلود تصویر، فیلد زیر را پر کنید:</p>

                <textarea id="receiptText" class="w-full bg-gray-50 dark:bg-darkElevated border border-gray-200 dark:border-gray-700 rounded-xl p-3 text-xs h-16 resize-none focus:ring-2 focus:ring-blue-500 focus:outline-none placeholder-gray-400" placeholder="شماره پیگیری تراکنش یا شماره کارت پرداختی خود را بنویسید..."></textarea>
            </div>

            <button id="submitReceiptBtn" onclick="submitCardReceipt()" class="w-full py-3.5 mt-2 rounded-2xl font-bold text-white bg-green-600 hover:bg-green-700 shadow-lg shadow-green-500/30 transition-all flex justify-center items-center gap-2">
                <span class="material-symbols-rounded">send</span> ثبت رسید پرداخت
            </button>
        </div>
        
        <button onclick="closeAllSheets()" class="w-full py-3 mt-1 rounded-2xl font-bold text-gray-500 hover:bg-gray-100 dark:hover:bg-darkElevated transition-colors text-sm">انصراف</button>
    </div>

    <!-- Edit User Admin Bottom Sheet -->
    <div id="editUserSheet" class="bottom-sheet fixed bottom-0 left-0 right-0 z-50 bg-white dark:bg-darkCard rounded-t-3xl p-6 shadow-[0_-10px_40px_rgba(0,0,0,0.1)] dark:border-t dark:border-gray-800 pb-[calc(1.5rem+env(safe-area-inset-bottom))]">
        <div class="w-12 h-1.5 bg-gray-200 dark:bg-gray-700 rounded-full mx-auto mb-6"></div>
        <h3 class="text-xl font-bold mb-4">ویرایش اطلاعات کاربر</h3>
        <div class="space-y-4">
            <div>
                <label class="block text-xs font-medium mb-1 text-gray-500">شماره کاربر</label>
                <input type="text" id="editUserPhone" readonly class="w-full px-4 py-3 rounded-2xl border border-gray-200 dark:border-gray-700 bg-gray-100 dark:bg-darkBg text-gray-500 text-left font-mono text-sm" dir="ltr">
            </div>
            <div>
                <label class="block text-xs font-medium mb-1 text-gray-700 dark:text-gray-300">رمز عبور کاربر</label>
                <input type="text" id="editUserPass" class="w-full px-4 py-3 rounded-2xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-darkElevated focus:ring-2 focus:ring-blue-500 text-left font-mono text-sm" dir="ltr">
            </div>
            <div>
                <label class="block text-xs font-medium mb-1 text-gray-700 dark:text-gray-300">اعتبار از امروز (به روز) - صفر برای لغو</label>
                <input type="number" id="editUserDays" min="0" class="w-full px-4 py-3 rounded-2xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-darkElevated focus:ring-2 focus:ring-blue-500 text-left font-mono text-sm" dir="ltr">
            </div>
        </div>
        <div class="flex gap-2 mt-6">
            <button onclick="submitEditUser()" class="flex-1 py-3.5 rounded-2xl font-bold text-white bg-blue-600 hover:bg-blue-700 shadow-lg shadow-blue-500/30 transition-all">ذخیره تغییرات</button>
            <button onclick="closeAllSheets()" class="flex-1 py-3.5 rounded-2xl font-bold text-gray-600 bg-gray-100 dark:bg-darkElevated dark:text-gray-300 hover:bg-gray-200 transition-colors">انصراف</button>
        </div>
    </div>

    <!-- Edit Transaction Admin Bottom Sheet -->
    <div id="editTxSheet" class="bottom-sheet fixed bottom-0 left-0 right-0 z-50 bg-white dark:bg-darkCard rounded-t-3xl p-6 shadow-[0_-10px_40px_rgba(0,0,0,0.1)] dark:border-t dark:border-gray-800 max-h-[90vh] overflow-y-auto pb-[calc(1.5rem+env(safe-area-inset-bottom))]">
        <div class="w-12 h-1.5 bg-gray-200 dark:bg-gray-700 rounded-full mx-auto mb-6"></div>
        <h3 class="text-xl font-bold mb-4">مدیریت تراکنش</h3>
        
        <!-- Receipt Details Block (Only visible for card txs) -->
        <div id="adminReceiptDetails" class="hidden mb-5 p-4 bg-gray-50 dark:bg-darkElevated rounded-2xl border border-gray-200 dark:border-gray-700">
            <h4 class="text-xs font-bold text-gray-500 dark:text-gray-400 mb-3 border-b border-gray-200 dark:border-gray-600 pb-2">اطلاعات رسید بانکی</h4>
            
            <div id="adminRecTextContainer" class="hidden mb-3">
                 <p class="text-xs font-mono text-gray-700 dark:text-gray-200 leading-relaxed whitespace-pre-wrap bg-white dark:bg-darkCard p-3 rounded-xl border border-gray-100 dark:border-gray-600" id="adminRecText"></p>
            </div>
            
            <div id="adminRecImgContainer" class="hidden text-center mt-2">
                 <a id="adminRecImgLink" target="_blank" class="block bg-white dark:bg-darkCard p-1 rounded-xl border border-gray-100 dark:border-gray-600">
                     <img id="adminRecImg" class="max-h-48 mx-auto rounded-lg object-contain w-full">
                 </a>
                 <p class="text-[10px] text-gray-400 mt-1.5">برای مشاهده ابعاد کامل روی عکس کلیک کنید</p>
            </div>
        </div>

        <div class="space-y-4">
            <input type="hidden" id="editTxId">
            <div>
                <label class="block text-sm font-medium mb-2 text-gray-700 dark:text-gray-300">وضعیت جدید را انتخاب کنید:</label>
                <select id="editTxStatus" class="w-full px-4 py-3.5 rounded-2xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-darkElevated focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                    <option value="success">موفق (تایید و فعالسازی)</option>
                    <option value="pending">در انتظار بررسی</option>
                    <option value="failed">ناموفق (رد تراکنش)</option>
                </select>
            </div>
        </div>
        <div class="flex gap-2 mt-6">
            <button onclick="submitEditTx()" class="flex-1 py-3.5 rounded-2xl font-bold text-white bg-blue-600 hover:bg-blue-700 shadow-lg shadow-blue-500/30 transition-all">اعمال تغییرات</button>
            <button onclick="closeAllSheets()" class="flex-1 py-3.5 rounded-2xl font-bold text-gray-600 bg-gray-100 dark:bg-darkElevated dark:text-gray-300 hover:bg-gray-200 transition-colors">انصراف</button>
        </div>
    </div>

    <!-- Dynamic Alert Bottom Sheet -->
    <div id="alertSheet" class="bottom-sheet fixed bottom-0 left-0 right-0 z-50 bg-white dark:bg-darkCard rounded-t-3xl p-6 shadow-[0_-10px_40px_rgba(0,0,0,0.1)] dark:border-t dark:border-gray-800 pb-[calc(1.5rem+env(safe-area-inset-bottom))]">
        <div class="w-12 h-1.5 bg-gray-200 dark:bg-gray-700 rounded-full mx-auto mb-6"></div>
        <div class="text-center mb-8">
            <div id="alertIconContainer" class="w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4"><span id="alertIcon" class="material-symbols-rounded text-3xl">info</span></div>
            <h3 id="alertTitle" class="text-xl font-bold mb-3">پیام سیستم</h3>
            <p id="alertMessage" class="text-sm text-gray-600 dark:text-gray-300 leading-relaxed font-mono whitespace-pre-line"></p>
        </div>
        <button id="alertActionBtn" onclick="closeAllSheets()" class="w-full py-4 rounded-2xl font-bold text-white bg-blue-600 hover:bg-blue-700 shadow-lg shadow-blue-500/30 transition-all mb-2">متوجه شدم</button>
    </div>

    <!-- Terms and Conditions Bottom Sheet -->
    <div id="termsSheet" class="bottom-sheet fixed bottom-0 left-0 right-0 z-50 bg-white dark:bg-darkCard rounded-t-3xl p-6 shadow-[0_-10px_40px_rgba(0,0,0,0.1)] dark:border-t dark:border-gray-800 max-h-[85vh] flex flex-col pb-[calc(1.5rem+env(safe-area-inset-bottom))]">
        <div class="w-12 h-1.5 bg-gray-200 dark:bg-gray-700 rounded-full mx-auto mb-5 shrink-0"></div>
        <div class="flex items-center gap-3 mb-4 shrink-0">
            <div class="w-10 h-10 bg-orange-50 dark:bg-orange-900/30 text-orange-500 rounded-full flex items-center justify-center"><span class="material-symbols-rounded text-xl">gavel</span></div>
            <h3 class="text-xl font-bold">قوانین و مقررات</h3>
        </div>
        
        <div class="flex-1 overflow-y-auto pr-2 space-y-4 text-sm text-gray-600 dark:text-gray-300 leading-relaxed text-justify mb-4">
            <p><strong>۱. ماهیت آموزشی سیستم:</strong> این اپلیکیشن منحصراً یک پلتفرم آموزشی است که روش‌های عبور از تحریم‌ها را از طریق دسترسی‌های آزاد و با بهره‌گیری از هوش مصنوعی (مانند Chat GPT و Gemini) به کاربران آموزش می‌دهد. ما هیچ‌گونه سرور اختصاصی یا سرویس فیزیکی ارائه نمی‌دهیم و خدمات ما محدود به مشاوره و آموزش است.</p>
            <p><strong>۲. شرایط عمومی:</strong> استفاده از خدمات این اپلیکیشن به منزله پذیرش تمامی قوانین مندرج در این صفحه می‌باشد. ما حق تغییر این قوانین را در هر زمان برای خود محفوظ می‌داریم.</p>
            <p><strong>۳. حریم خصوصی:</strong> اطلاعات شما (مانند شماره موبایل و تاریخچه پرداخت‌ها) نزد ما محفوظ بوده و به هیچ‌وجه در اختیار اشخاص ثالث قرار نخواهد گرفت. ما متعهد به حفظ امنیت داده‌های شما هستیم.</p>
            <p><strong>۴. بازگشت وجه:</strong> در صورت بروز مشکل فنی از سمت سیستم‌های آموزشی ما که منجر به قطعی مداوم بیش از ۴۸ ساعت شود، امکان بررسی و بازگشت وجه به نسبت روزهای باقیمانده از اشتراک شما وجود دارد.</p>
            <p><strong>۵. استفاده منصفانه:</strong> کاربر متعهد می‌شود از آموزش‌ها صرفاً برای مقاصد قانونی و دسترسی آزاد به اطلاعات استفاده نماید. هرگونه استفاده برای مواردی که با ماهیت آموزش منافات داشته باشد، منجر به مسدودسازی حساب خواهد شد.</p>
        </div>

        <button onclick="closeAllSheets()" class="w-full py-3.5 rounded-2xl font-bold text-white bg-blue-600 hover:bg-blue-700 shadow-lg shadow-blue-500/30 transition-all shrink-0">مطالعه کردم و می‌پذیرم</button>
    </div>

</div>

<script>
    // --- Server PHP injected variable for Payment Callback ---
    const phpPaymentAlert = <?php echo json_encode($payment_alert); ?>;

    // --- Data & State ---
    const DNS_LIST = [
        { id: 'shekan', name: 'شکن (Shekan)', dns1: '178.22.122.100', dns2: '185.51.200.2', desc: 'تحریم‌شکن قدرتمند ایرانی' },
        { id: 'electro', name: 'الکترو (Electro)', dns1: '78.157.42.100', dns2: '78.157.42.101', desc: 'مخصوص بازی و پینگ پایین' },
        { id: '403', name: '۴۰۳ (403.online)', dns1: '10.202.10.202', dns2: '10.202.10.102', desc: 'پروژه عبور از تحریم توسعه‌دهندگان' },
        { id: 'radar', name: 'رادار (Radar)', dns1: '10.202.10.10', dns2: '10.202.10.11', desc: 'کاهش پینگ بازی‌های آنلاین' },
        { id: 'bogzar', name: 'بگذر (Bogzar)', dns1: '185.55.226.26', dns2: '185.55.225.25', desc: 'سرویس سریع و پایدار' }
    ];

    let userToken = localStorage.getItem('dns_user_token');
    let userExpire = 0;
    let isUserAdmin = false;
    let isFreeMode = false;
    let alertCallback = null; 
    let currentAdminChatTarget = ''; // کاربری که در پنل چت در حال مکالمه است

    // Check Mini App Mode
    const urlParams = new URLSearchParams(window.location.search);
    if(urlParams.get('source') === 'bale' || urlParams.get('token') === 'bale') {
        sessionStorage.setItem('is_bale_app', 'true');
    }
    const isMiniAppMode = sessionStorage.getItem('is_bale_app') === 'true';

    // --- DOM Elements ---
    const htmlEl = document.documentElement;
    const themeBtn = document.getElementById('themeToggleBtn');
    const navBtns = document.querySelectorAll('.nav-btn');
    const views = document.querySelectorAll('.page-view');
    const mainConnectBtn = document.getElementById('mainConnectBtn');
    
    // --- Theme Logic ---
    function initTheme() {
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) htmlEl.classList.add('dark');
    }
    themeBtn.addEventListener('click', () => {
        htmlEl.classList.toggle('dark');
        localStorage.setItem('theme', htmlEl.classList.contains('dark') ? 'dark' : 'light');
    });

    // --- Core API Helpers ---
    async function apiFetch(action, payload = {}) {
        try {
            const res = await fetch(`?action=${action}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
            return await res.json();
        } catch (e) {
            console.error('API Error:', e); return { success: false, error: 'خطای ارتباط با سرور' };
        }
    }

    async function checkAuthStatus() {
        if(!userToken) return false;
        try {
            const res = await fetch(`?action=me&token=${userToken}`);
            const data = await res.json();
            if(data.logged_in) {
                userExpire = data.expire;
                isUserAdmin = data.is_admin || false;
                isFreeMode = data.is_free_mode || false;
                return true;
            } else { logout(false); return false; }
        } catch(e) { return false; }
    }

    function hasActiveSub() { 
        return isFreeMode || (userExpire > Date.now()); 
    }
    
    function getRemainingDays() {
        if(!hasActiveSub()) return 0;
        if(isFreeMode) return 'unlimited';
        return Math.ceil((userExpire - Date.now()) / (1000 * 60 * 60 * 24));
    }

    function copyToClipboard(text, btnElement) {
        if(!text) return;
        const textArea = document.createElement("textarea"); textArea.value = text;
        document.body.appendChild(textArea); textArea.select();
        try {
            document.execCommand('copy');
            const originalHtml = btnElement.innerHTML;
            btnElement.innerHTML = `<span class="material-symbols-rounded text-green-500 text-sm">check</span>`;
            setTimeout(() => { btnElement.innerHTML = originalHtml; }, 1500);
        } catch (err) { console.error('Copy failed', err); }
        document.body.removeChild(textArea);
    }
    
    function copyDns(elementId, btnElement) {
        const text = document.getElementById(elementId).innerText;
        copyToClipboard(text, btnElement);
    }

    // --- Global Message Logic ---
    function checkGlobalMessage() {
        const globalMsg = PUBLIC_SETTINGS.global_message || '';
        const lastRead = localStorage.getItem('last_read_global_msg');
        const badge = document.getElementById('notifBadge');
        if (globalMsg && globalMsg.trim() !== '' && globalMsg !== lastRead) {
            badge.classList.remove('hidden');
        } else {
            badge.classList.add('hidden');
        }
    }

    function showGlobalMessage() {
        const globalMsg = PUBLIC_SETTINGS.global_message || '';
        if(!globalMsg || globalMsg.trim() === '') {
            showBottomSheet('پیام سیستم', 'پیام جدیدی وجود ندارد.', 'notifications', 'gray');
            return;
        }
        localStorage.setItem('last_read_global_msg', globalMsg);
        document.getElementById('notifBadge').classList.add('hidden');
        showBottomSheet('پیام همگانی', globalMsg, 'campaign', 'purple');
    }

    // --- Bottom Sheet Modal System ---
    function closeAllSheets() {
        document.querySelectorAll('.bottom-sheet').forEach(sheet => sheet.classList.remove('open'));
        document.getElementById('sheetOverlay').classList.remove('open');
        selectedPlan = null;
        if (alertCallback) {
            alertCallback();
            alertCallback = null;
        }
    }
    
    function openSheet(sheetId) {
        document.querySelectorAll('.bottom-sheet').forEach(sheet => sheet.classList.remove('open'));
        document.getElementById('sheetOverlay').classList.add('open');
        document.getElementById(sheetId).classList.add('open');
    }
    
    function showBottomSheet(title, message, icon = 'info', color = 'blue', btnText = 'متوجه شدم', callback = null) {
        const iconContainer = document.getElementById('alertIconContainer');
        const iconEl = document.getElementById('alertIcon');
        iconContainer.className = `w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 bg-${color}-50 dark:bg-${color}-900/30 text-${color}-500`;
        iconEl.innerText = icon;
        document.getElementById('alertTitle').innerText = title;
        document.getElementById('alertMessage').innerText = message;
        document.getElementById('alertActionBtn').innerText = btnText;
        alertCallback = callback;
        openSheet('alertSheet');
    }

    function openTermsSheet() {
        openSheet('termsSheet');
    }
    
    function openPurchaseSheet(months, price, name) {
        if(isMiniAppMode) {
            showBottomSheet('محدودیت پرداخت', 'امکان پرداخت درون مینی‌اپ وجود ندارد. لطفاً از طریق مرورگر اصلی گوشی خود وارد سایت شوید.', 'credit_card_off', 'red');
            return;
        }
        
        selectedPlan = { months, price, name };
        document.getElementById('purchasePlanName').innerText = `اشتراک ${name}`;
        
        // تنظیم مقادیر پیش‌فرض کارت در تب کارت‌به‌کارت
        document.getElementById('publicCardNumber').innerText = PUBLIC_SETTINGS.card_number || 'تنظیم نشده';
        document.getElementById('publicCardHolder').innerText = PUBLIC_SETTINGS.card_holder ? `به نام: ${PUBLIC_SETTINGS.card_holder}` : '';
        document.getElementById('cardPayAmount').innerHTML = `${Number(price).toLocaleString('fa-IR')} <span class="text-[10px]">تومان</span>`;
        document.getElementById('receiptText').value = '';
        document.getElementById('receiptImage').value = '';
        document.getElementById('fileNameDisp').innerText = 'آپلود تصویر رسید بانکی (الزامی)';
        
        switchPayTab(PUBLIC_SETTINGS.default_payment || 'zarinpal'); // اعمال اولویت پرداخت تنظیم شده توسط ادمین
        openSheet('purchaseSheet');
    }
    
    function switchPayTab(tab) {
        document.getElementById('payTabBtn-zarinpal').className = 'flex-1 py-2.5 rounded-lg text-sm font-medium text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition-all';
        document.getElementById('payTabBtn-card').className = 'flex-1 py-2.5 rounded-lg text-sm font-medium text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition-all';
        document.getElementById(`payTabBtn-${tab}`).className = 'flex-1 py-2.5 rounded-lg text-sm font-bold bg-white dark:bg-darkCard text-blue-600 dark:text-blue-400 shadow-sm transition-all';
        
        document.getElementById('payContainer-zarinpal').classList.add('hidden');
        document.getElementById('payContainer-card').classList.add('hidden');
        document.getElementById(`payContainer-${tab}`).classList.remove('hidden');
    }

    // --- Navigation Logic ---
    async function navigate(viewId) {
        if(['dns', 'profile', 'tutorial', 'admin', 'history', 'pro-tutorial', 'support'].includes(viewId)) {
            if(!userToken) { viewId = 'login'; }
            else if(!hasActiveSub() && !['profile', 'admin', 'history', 'support'].includes(viewId)) {
                if(viewId === 'pro-tutorial') {
                    showBottomSheet('دسترسی محدود', 'برای مشاهده این آموزش باید اشتراک فعال داشته باشید.', 'lock', 'red');
                    viewId = 'plans';
                } else viewId = 'plans';
            }
        }
        
        if(isFreeMode && (viewId === 'plans' || viewId === 'history')) { viewId = 'profile'; }
        if(viewId === 'profile' && !userToken) viewId = 'login';
        if(viewId === 'admin' && !isUserAdmin) viewId = 'home';

        views.forEach(v => v.classList.remove('active'));
        document.getElementById(`view-${viewId}`).classList.add('active');

        if(['home', 'dns', 'profile'].includes(viewId)) {
            navBtns.forEach(btn => {
                const iconBg = btn.querySelector('.icon-bg');
                if(btn.dataset.target === viewId) {
                    btn.classList.add('text-blue-600', 'dark:text-blue-400'); btn.classList.remove('text-gray-400');
                    iconBg.classList.add('bg-blue-100', 'dark:bg-blue-900/50');
                } else {
                    btn.classList.remove('text-blue-600', 'dark:text-blue-400'); btn.classList.add('text-gray-400');
                    iconBg.classList.remove('bg-blue-100', 'dark:bg-blue-900/50');
                }
            });
        }

        if(viewId === 'profile') renderProfile();
        if(viewId === 'dns') renderDnsList();
        if(viewId === 'history') loadUserHistory();
        if(viewId === 'admin') loadAdminData();
    }

    navBtns.forEach(btn => { btn.addEventListener('click', () => navigate(btn.dataset.target)); });

    // --- Auth Logic ---
    document.getElementById('loginForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const phone = document.getElementById('loginPhone').value;
        const pass = document.getElementById('loginPass').value;
        const termsChecked = document.getElementById('termsCheckbox').checked;
        const errEl = document.getElementById('loginError');
        const btn = document.getElementById('loginBtn');
        
        if(!termsChecked) {
            errEl.classList.remove('hidden'); 
            errEl.querySelector('span:last-child').innerText = 'پذیرش قوانین و مقررات الزامی است.';
            return;
        }

        btn.innerHTML = `<span class="material-symbols-rounded animate-spin">progress_activity</span> در حال بررسی...`;
        btn.disabled = true;

        const res = await apiFetch('login', { phone, password: pass });
        
        btn.innerHTML = `<span>ورود / عضویت</span><span class="material-symbols-rounded">arrow_forward</span>`;
        btn.disabled = false;

        if(res.success) {
            errEl.classList.add('hidden');
            userToken = res.token; 
            userExpire = res.expire; 
            isUserAdmin = res.is_admin || false;
            isFreeMode = res.is_free_mode || false;
            
            localStorage.setItem('dns_user_token', userToken);
            if(hasActiveSub()) navigate('home'); else navigate('plans');
        } else {
            errEl.classList.remove('hidden'); errEl.querySelector('span:last-child').innerText = res.error;
        }
    });

    function logout(redirect = true) {
        userToken = null; userExpire = 0; isUserAdmin = false; isFreeMode = false;
        localStorage.removeItem('dns_user_token');
        sessionStorage.clear();
        if(redirect) { window.location.reload(); }
    }

    // --- Core Features Logic ---
    mainConnectBtn.addEventListener('click', async () => {
        if(!userToken) { navigate('login'); return; }
        await checkAuthStatus();
        if(!hasActiveSub()) { navigate('plans'); return; }
        navigate('dns');
    });

    // Payment Logic (Zarinpal)
    let selectedPlan = null;
    async function confirmPurchase() {
        if (!selectedPlan) return;
        const { months, price } = selectedPlan;
        
        if(isFreeMode) {
            closeAllSheets();
            showBottomSheet('سیستم رایگان', 'سیستم در حال حاضر رایگان است و نیازی به خرید نیست.', 'info', 'blue');
            return;
        }

        const btn = document.querySelector('#payContainer-zarinpal button');
        const origHtml = btn.innerHTML;
        btn.innerHTML = `<span class="material-symbols-rounded animate-spin">progress_activity</span> در حال انتقال به درگاه...`;
        btn.disabled = true;
        
        const res = await apiFetch('init_pay', { token: userToken, months: months, price: price });

        if(res.success && res.url) {
            window.location.href = res.url;
        } else {
            btn.innerHTML = origHtml;
            btn.disabled = false;
            
            closeAllSheets();
            showBottomSheet(
                'خطا در درگاه پرداخت', 
                (res.error || 'خطا در اتصال به درگاه پرداخت.') + '\n\nلطفاً از روش جایگزین (کارت‌به‌کارت) استفاده کنید.', 
                'error', 
                'red', 
                'انتقال به کارت‌به‌کارت',
                () => {
                    openPurchaseSheet(months, price, selectedPlan.name);
                    switchPayTab('card');
                }
            );
        }
    }

    // Payment Logic (Card Transfer)
    async function submitCardReceipt() {
        if (!selectedPlan || !userToken) return;
        
        const fileInput = document.getElementById('receiptImage');
        const textInput = document.getElementById('receiptText').value.trim();
        const file = fileInput.files[0];

        if(!file && !textInput) {
            showBottomSheet('اطلاعات ناقص', 'لطفا تصویر رسید را آپلود کنید یا در صورت عدم امکان، کدرهگیری را بنویسید.', 'error', 'red');
            return;
        }

        const fd = new FormData();
        fd.append('token', userToken);
        fd.append('months', selectedPlan.months);
        fd.append('price', selectedPlan.price);
        fd.append('receipt_text', textInput);
        if(file) fd.append('receipt_image', file);

        const btn = document.getElementById('submitReceiptBtn');
        const origHtml = btn.innerHTML;
        btn.innerHTML = `<span class="material-symbols-rounded animate-spin">progress_activity</span> در حال ارسال...`;
        btn.disabled = true;

        try {
            const res = await fetch('?action=submit_receipt', { method: 'POST', body: fd });
            const data = await res.json();
            
            if(data.success) {
                document.querySelectorAll('.bottom-sheet').forEach(sheet => sheet.classList.remove('open'));
                showBottomSheet('درخواست ثبت شد', 'رفت برای تایید، منتظر باشید.', 'hourglass_top', 'blue', 'متوجه شدم، منتظر می‌مانم', () => {
                    navigate('history');
                });
            } else {
                showBottomSheet('خطا در ارسال', data.error || 'خطای سرور', 'error', 'red');
            }
        } catch(e) {
            showBottomSheet('خطا', 'خطا در برقراری ارتباط با سرور', 'error', 'red');
        }

        btn.innerHTML = origHtml;
        btn.disabled = false;
    }

    // --- Support Chat Functions (User side) ---
    async function loadUserChat() {
        if(!userToken) return;
        const res = await fetch(`?action=get_support&token=${userToken}`);
        const msgs = await res.json();
        renderChatMessages('userChatContainer', msgs, false);
    }

    async function sendUserMessage() {
        const input = document.getElementById('userChatInput');
        const text = input.value.trim();
        if(!text) return;
        input.value = '';
        
        // UI Optmistic update
        const cont = document.getElementById('userChatContainer');
        cont.innerHTML += `<div class="self-end bg-green-500 text-white p-3 rounded-2xl rounded-tr-sm text-sm max-w-[85%] break-words shadow-sm">${text}</div>`;
        cont.scrollTop = cont.scrollHeight;

        await apiFetch('send_support', { token: userToken, text: text });
        loadUserChat(); // refresh to get real dates
    }

    // --- Chat Rendering Helper ---
    function renderChatMessages(containerId, msgs, isAdminView) {
        const container = document.getElementById(containerId);
        if(!msgs || msgs.length === 0) {
            container.innerHTML = `<div class="flex-1 flex flex-col items-center justify-center opacity-40 h-full"><span class="material-symbols-rounded text-5xl mb-2">forum</span><p class="text-sm">پیامی وجود ندارد</p></div>`;
            return;
        }
        
        container.innerHTML = msgs.map(m => {
            const isSelf = isAdminView ? (m.sender === 'admin') : (m.sender === 'user');
            const align = isSelf ? 'self-end' : 'self-start';
            // User self color: green. Admin self color: purple. Opponent color: gray.
            let bgClass = '';
            if (isSelf) {
                bgClass = isAdminView ? 'bg-purple-500 text-white rounded-tr-sm' : 'bg-green-500 text-white rounded-tr-sm';
            } else {
                bgClass = 'bg-gray-100 dark:bg-darkCard border border-gray-200 dark:border-gray-700 text-gray-800 dark:text-gray-100 rounded-tl-sm';
            }
            
            return `
            <div class="${align} ${bgClass} p-3 rounded-2xl text-sm max-w-[85%] break-words shadow-sm relative pb-5">
                ${m.text}
                <div class="absolute bottom-1.5 left-3 text-[9px] opacity-70" dir="ltr">${m.date.split(' ')[1]}</div>
            </div>`;
        }).join('');
        
        // Scroll to bottom
        setTimeout(() => { container.scrollTop = container.scrollHeight; }, 50);
    }


    // Pro Tabs
    function switchProTab(tabId) {
        document.querySelectorAll('.pro-tab-btn').forEach(btn => {
            btn.classList.remove('active', 'bg-white', 'dark:bg-darkCard', 'shadow-sm', 'text-purple-600', 'dark:text-purple-400', 'font-bold');
            btn.classList.add('text-gray-500', 'font-medium');
        });
        const activeBtn = document.getElementById(`proTabBtn-${tabId}`);
        activeBtn.classList.remove('text-gray-500', 'font-medium');
        activeBtn.classList.add('active', 'bg-white', 'dark:bg-darkCard', 'shadow-sm', 'text-purple-600', 'dark:text-purple-400', 'font-bold');

        document.querySelectorAll('.pro-tab-content').forEach(content => { content.classList.remove('active'); });
        document.getElementById(`proTab-${tabId}`).classList.add('active');
    }

    // Admin Panel Logic
    function switchAdminTab(tabId) {
        document.querySelectorAll('.admin-tab-btn').forEach(btn => {
            btn.classList.remove('active', 'bg-white', 'dark:bg-darkCard', 'shadow-sm', 'text-purple-600', 'dark:text-purple-400', 'font-bold');
            btn.classList.add('text-gray-500', 'font-medium');
        });
        const activeBtn = document.getElementById(`tabBtn-${tabId}`);
        activeBtn.classList.remove('text-gray-500', 'font-medium');
        activeBtn.classList.add('active', 'bg-white', 'dark:bg-darkCard', 'shadow-sm', 'text-purple-600', 'dark:text-purple-400', 'font-bold');

        document.querySelectorAll('.admin-tab-content').forEach(content => { content.classList.add('hidden'); content.classList.remove('block'); });
        document.getElementById(`adminTab-${tabId}`).classList.remove('hidden'); document.getElementById(`adminTab-${tabId}`).classList.add('block');
    }

    document.getElementById('adminSettingsForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const merchant = document.getElementById('zarinpalMerchant').value;
        const cardNumber = document.getElementById('adminCardNumber').value;
        const cardHolder = document.getElementById('adminCardHolder').value;
        const freeMode = document.getElementById('adminIsFreeMode').checked;
        const defaultPayment = document.getElementById('adminDefaultPayment').value;
        
        const btn = document.getElementById('saveAdminBtn');
        const originalText = btn.innerHTML;
        btn.innerHTML = `<span class="material-symbols-rounded animate-spin">progress_activity</span> در حال ذخیره...`;
        
        const res = await apiFetch('save_settings', { 
            token: userToken, 
            merchant: merchant, 
            is_free_mode: freeMode,
            card_number: cardNumber,
            card_holder: cardHolder,
            default_payment: defaultPayment
        });
        
        if(res.success) {
            btn.innerHTML = `<span class="material-symbols-rounded">check</span> ذخیره شد`; btn.classList.replace('bg-purple-600', 'bg-green-600');
            isFreeMode = freeMode;
            PUBLIC_SETTINGS.card_number = cardNumber;
            PUBLIC_SETTINGS.card_holder = cardHolder;
            PUBLIC_SETTINGS.default_payment = defaultPayment;
            setTimeout(() => { btn.innerHTML = originalText; btn.classList.replace('bg-green-600', 'bg-purple-600'); }, 2000);
        } else {
            showBottomSheet('خطا', 'خطا در ذخیره تنظیمات', 'error', 'red'); btn.innerHTML = originalText;
        }
    });

    // Admin - Save Global Message
    async function saveGlobalMessage() {
        const message = document.getElementById('globalMessageInput').value.trim();
        const res = await apiFetch('save_global_message', { token: userToken, message: message });
        if(res.success) {
            PUBLIC_SETTINGS.global_message = message;
            showBottomSheet('موفقیت', 'پیام همگانی با موفقیت ثبت شد و برای کاربران به نمایش درمی‌آید.', 'check_circle', 'green');
        }
    }

    // Admin - Edit User Modal
    function openEditUserModal(phone, currentExpireMs, currentPass) {
        document.getElementById('editUserPhone').value = phone;
        document.getElementById('editUserPass').value = currentPass || '';
        let daysLeft = 0;
        if(currentExpireMs > Date.now()) daysLeft = Math.ceil((currentExpireMs - Date.now()) / (1000 * 60 * 60 * 24));
        document.getElementById('editUserDays').value = daysLeft;
        openSheet('editUserSheet');
    }

    async function submitEditUser() {
        const phone = document.getElementById('editUserPhone').value;
        const pass = document.getElementById('editUserPass').value;
        const days = document.getElementById('editUserDays').value;
        const res = await apiFetch('update_user', { token: userToken, target_phone: phone, days: days, password: pass });
        if(res.success) { 
            closeAllSheets(); loadAdminData(); 
            setTimeout(() => { showBottomSheet('موفقیت', 'کاربر با موفقیت ویرایش شد.', 'check_circle', 'green'); }, 300);
        }
    }

    async function deleteUser(phone) {
        if(confirm("آیا از حذف کاربر " + phone + " اطمینان دارید؟")) {
            const res = await apiFetch('delete_user', { token: userToken, target_phone: phone });
            if(res.success) { loadAdminData(); }
        }
    }

    // Admin - Edit Transaction Modal (Includes Receipt Viewer)
    function openEditTxModal(txId) {
        if(!window.adminTxsCache) return;
        const tx = window.adminTxsCache.find(t => t.id === txId);
        if(!tx) return;

        document.getElementById('editTxId').value = tx.id;
        document.getElementById('editTxStatus').value = tx.status;
        
        const receiptBlock = document.getElementById('adminReceiptDetails');
        const textCont = document.getElementById('adminRecTextContainer');
        const imgCont = document.getElementById('adminRecImgContainer');
        
        if((tx.type || '') === 'card') {
            receiptBlock.classList.remove('hidden');
            
            if(tx.receipt_text && tx.receipt_text.trim() !== '') {
                textCont.classList.remove('hidden');
                document.getElementById('adminRecText').innerText = tx.receipt_text;
            } else { textCont.classList.add('hidden'); }
            
            if(tx.receipt_image && tx.receipt_image.trim() !== '') {
                imgCont.classList.remove('hidden');
                const imgUrl = '?action=view_receipt&file=' + tx.receipt_image;
                document.getElementById('adminRecImg').src = imgUrl;
                document.getElementById('adminRecImgLink').href = imgUrl;
            } else { imgCont.classList.add('hidden'); }
        } else {
            receiptBlock.classList.add('hidden');
        }

        openSheet('editTxSheet');
    }

    async function submitEditTx() {
        const txId = document.getElementById('editTxId').value;
        const newStatus = document.getElementById('editTxStatus').value;
        const res = await apiFetch('update_tx_status', { token: userToken, tx_id: txId, status: newStatus });
        if(res.success) { 
            closeAllSheets(); loadAdminData(); 
            setTimeout(() => { showBottomSheet('وضعیت تغییر کرد', 'تغییر وضعیت با موفقیت انجام شد و در صورت لزوم اشتراک فعال گردید.', 'check_circle', 'green'); }, 300);
        }
    }

    async function deleteTx(id) {
        if(confirm("آیا از حذف این تراکنش اطمینان دارید؟")) {
            const res = await apiFetch('delete_tx', { token: userToken, tx_id: id });
            if(res.success) { loadAdminData(); }
        }
    }

    // Admin - Support Handlers
    async function loadAdminSupport() {
        const res = await fetch(`?action=get_support&token=${userToken}`);
        const allChats = await res.json();
        
        const list = document.getElementById('adminSupportUsersList');
        if(Object.keys(allChats).length === 0) {
            list.innerHTML = `<div class="text-center py-10 opacity-50"><span class="material-symbols-rounded text-4xl mb-2">inbox</span><p class="text-sm">تیکتی وجود ندارد.</p></div>`;
            return;
        }
        
        list.innerHTML = Object.keys(allChats).map(phone => {
            const msgs = allChats[phone];
            const lastMsg = msgs[msgs.length - 1] || {text:'---', date:''};
            return `
            <div onclick="openAdminChat('${phone}')" class="p-3 bg-gray-50 dark:bg-darkElevated rounded-xl border border-gray-100 dark:border-gray-700 cursor-pointer hover:border-purple-500 transition-all flex justify-between items-center">
                <div class="overflow-hidden pr-2">
                    <h4 class="font-bold text-sm font-mono text-gray-800 dark:text-gray-100" dir="ltr">${phone}</h4>
                    <p class="text-[11px] text-gray-500 truncate mt-0.5">${lastMsg.text}</p>
                </div>
                <div class="text-[9px] text-gray-400 font-mono shrink-0 pl-1" dir="ltr">${(lastMsg.date||'').split(' ')[0]}</div>
            </div>`;
        }).join('');
    }

    function openAdminChat(phone) {
        currentAdminChatTarget = phone;
        document.getElementById('adminSupportUsersList').classList.add('hidden');
        document.getElementById('adminSupportChatArea').classList.remove('hidden');
        document.getElementById('adminSupportChatArea').classList.add('flex');
        document.getElementById('adminChatTarget').innerText = phone;
        reloadAdminChatMsgs();
    }

    function closeAdminChat() {
        currentAdminChatTarget = '';
        document.getElementById('adminSupportUsersList').classList.remove('hidden');
        document.getElementById('adminSupportChatArea').classList.add('hidden');
        document.getElementById('adminSupportChatArea').classList.remove('flex');
        // If chat area was in fullscreen, remove it
        const chatArea = document.getElementById('adminSupportChatArea');
        if(chatArea.classList.contains('fixed')) { toggleAdminChatFullscreen(); }
        loadAdminSupport();
    }
    
    function toggleAdminChatFullscreen() {
        const area = document.getElementById('adminSupportChatArea');
        const icon = document.getElementById('adminFullscreenIcon');
        if (area.classList.contains('fixed')) {
            area.classList.remove('fixed', 'inset-0', 'z-[100]', 'bg-white', 'dark:bg-darkBg', 'p-4');
            area.classList.add('h-[400px]');
            icon.innerText = 'fullscreen';
        } else {
            area.classList.add('fixed', 'inset-0', 'z-[100]', 'bg-white', 'dark:bg-darkBg', 'p-4');
            area.classList.remove('h-[400px]');
            icon.innerText = 'fullscreen_exit';
        }
    }

    async function reloadAdminChatMsgs() {
        if(!currentAdminChatTarget) return;
        const res = await fetch(`?action=get_support&token=${userToken}`);
        const allChats = await res.json();
        renderChatMessages('adminChatContainer', allChats[currentAdminChatTarget] || [], true);
    }

    async function sendAdminMessage() {
        const input = document.getElementById('adminChatInput');
        const text = input.value.trim();
        if(!text || !currentAdminChatTarget) return;
        input.value = '';
        
        const cont = document.getElementById('adminChatContainer');
        cont.innerHTML += `<div class="self-end bg-purple-500 text-white p-3 rounded-2xl rounded-tr-sm text-sm max-w-[85%] break-words shadow-sm relative pb-5">${text}<div class="absolute bottom-1.5 left-3 text-[9px] opacity-70" dir="ltr">now</div></div>`;
        cont.scrollTop = cont.scrollHeight;

        await apiFetch('send_support', { token: userToken, text: text, target: currentAdminChatTarget });
        reloadAdminChatMsgs();
    }


    async function loadAdminData() {
        if(!isUserAdmin) return;
        try {
            // Stats
            const statsRes = await fetch(`?action=get_stats&token=${userToken}`);
            const statsData = await statsRes.json();
            if(statsData.success) {
                document.getElementById('adminStat-users').innerText = statsData.total_users;
                document.getElementById('adminStat-active').innerText = statsData.active_users;
                document.getElementById('adminStat-pending').innerText = statsData.pending_txs;
                document.getElementById('adminStat-revenue').innerText = statsData.total_revenue.toLocaleString('fa-IR');
            }
            
            // Settings
            const res = await fetch(`?action=get_settings&token=${userToken}`);
            const data = await res.json();
            document.getElementById('zarinpalMerchant').value = data.zarinpal_merchant || '';
            document.getElementById('adminCardNumber').value = data.card_number || '';
            document.getElementById('adminCardHolder').value = data.card_holder || '';
            document.getElementById('adminIsFreeMode').checked = data.is_free_mode || false;
            document.getElementById('globalMessageInput').value = data.global_message || '';
            document.getElementById('adminDefaultPayment').value = data.default_payment || 'zarinpal';
            
            // Users
            const usrRes = await fetch(`?action=get_users&token=${userToken}`);
            const usrData = await usrRes.json();
            const usrContainer = document.getElementById('adminUserList');
            usrContainer.innerHTML = usrData.map(u => {
                let expText = u.expire > Date.now() ? `<span class="text-green-500">فعال تا ${new Date(u.expire).toLocaleDateString('fa-IR')}</span>` : '<span class="text-red-500">منقضی شده</span>';
                return `
                <div class="flex justify-between items-center p-3 bg-gray-50 dark:bg-darkElevated rounded-2xl border border-gray-100 dark:border-gray-700">
                    <div>
                        <div class="text-sm font-mono font-bold ${u.is_admin ? 'text-purple-500' : 'text-gray-800 dark:text-gray-100'}" dir="ltr">${u.phone} ${u.is_admin ? '(مدیر)' : ''}</div>
                        <div class="text-[10px] mt-1">${expText}</div>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="openEditUserModal('${u.phone}', ${u.expire}, '${u.password}')" class="w-8 h-8 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center"><span class="material-symbols-rounded text-sm">edit</span></button>
                        ${!u.is_admin ? `<button onclick="deleteUser('${u.phone}')" class="w-8 h-8 rounded-full bg-red-100 text-red-600 flex items-center justify-center"><span class="material-symbols-rounded text-sm">delete</span></button>` : ''}
                    </div>
                </div>`;
            }).join('');
            
            // Transactions
            const txRes = await fetch(`?action=get_transactions&token=${userToken}`);
            const txData = await txRes.json();
            window.adminTxsCache = txData; // کش برای استفاده در مودال
            const txContainer = document.getElementById('adminTxList');
            if(!txData || txData.length === 0) txContainer.innerHTML = '<p class="text-sm text-gray-500 text-center py-4">تراکنشی یافت نشد.</p>';
            else {
                txContainer.innerHTML = txData.reverse().map(tx => {
                    let isCard = (tx.type || '') === 'card';
                    let statusColor = tx.status === 'success' ? 'text-green-500' : (tx.status === 'pending' ? 'text-yellow-500' : 'text-red-500');
                    let statusText = tx.status === 'success' ? 'موفق' : (tx.status === 'pending' ? 'در انتظار' : 'ناموفق');
                    let typeBadge = isCard ? '<span class="bg-blue-100 text-blue-600 px-1.5 py-0.5 rounded text-[9px] mr-2">کارت‌به‌کارت</span>' : '<span class="bg-purple-100 text-purple-600 px-1.5 py-0.5 rounded text-[9px] mr-2">زرین‌پال</span>';
                    
                    return `
                    <div class="flex flex-col gap-2 p-3 bg-gray-50 dark:bg-darkElevated rounded-2xl border border-gray-100 dark:border-gray-700">
                        <div class="flex justify-between items-center">
                            <div class="text-sm font-mono font-bold text-gray-800 dark:text-gray-100 flex items-center" dir="ltr">${tx.user} ${typeBadge}</div>
                            <div class="text-sm font-bold text-gray-800 dark:text-gray-100">${Number(tx.amount).toLocaleString('fa-IR')} <span class="text-[10px]">تومان</span></div>
                        </div>
                        <div class="flex justify-between items-center">
                            <div class="text-[10px] text-gray-500">${tx.date} | کد: ${tx.ref_id || (isCard ? 'دستی' : '---')}</div>
                            <div class="text-[11px] font-bold ${statusColor}">${statusText} - ${tx.months} ماهه</div>
                        </div>
                        <div class="flex justify-end gap-2 mt-1 border-t border-gray-200 dark:border-gray-600 pt-2">
                            <button onclick="openEditTxModal('${tx.id}')" class="text-[10px] bg-gray-200 dark:bg-gray-700 px-3 py-1 rounded text-gray-700 dark:text-gray-300 font-bold flex items-center gap-1"><span class="material-symbols-rounded text-sm">edit_square</span> بررسی</button>
                            <button onclick="deleteTx('${tx.id}')" class="text-[10px] bg-red-100 px-2 py-1 rounded text-red-600"><span class="material-symbols-rounded text-sm">delete</span></button>
                        </div>
                    </div>`;
                }).join('');
            }
            
            // Logs
            const logRes = await fetch(`?action=get_logs&token=${userToken}`);
            const logsData = await logRes.json();
            document.getElementById('adminLogsContent').innerHTML = logsData.map(l => `<p>${l}</p>`).join('');
            
            // Support
            loadAdminSupport();

        } catch(e) { console.error(e); }
    }

    async function loadUserHistory() {
        if(!userToken || isFreeMode) return; 
        try {
            const container = document.getElementById('userTxList');
            container.innerHTML = '<p class="text-sm text-gray-500 text-center py-4">در حال بارگذاری...</p>';
            const res = await fetch(`?action=my_txs&token=${userToken}`);
            const txData = await res.json();
            if(!txData || txData.length === 0) {
                container.innerHTML = `<div class="text-center py-10 opacity-50"><span class="material-symbols-rounded text-5xl mb-2 text-gray-400">receipt_long</span><p class="text-sm text-gray-500">هنوز پرداختی نداشته‌اید.</p></div>`;
            } else {
                container.innerHTML = txData.reverse().map(tx => {
                    let isCard = (tx.type || '') === 'card';
                    let statusColor = tx.status === 'success' ? 'text-green-500' : (tx.status === 'pending' ? 'text-yellow-500' : 'text-red-500');
                    let statusText = tx.status === 'success' ? 'پرداخت موفق' : (tx.status === 'pending' ? 'در انتظار تایید' : 'تراکنش ناموفق');
                    let icon = tx.status === 'success' ? 'check_circle' : (tx.status === 'pending' ? 'pending' : 'cancel');
                    let typeBadge = isCard ? '<span class="bg-blue-100 text-blue-600 px-2 py-0.5 rounded text-[10px]">کارت‌به‌کارت</span>' : '<span class="bg-purple-100 text-purple-600 px-2 py-0.5 rounded text-[10px]">زرین‌پال</span>';
                    
                    return `
                    <div class="bg-white dark:bg-darkCard p-4 rounded-3xl shadow-sm border border-gray-100 dark:border-gray-800">
                        <div class="flex justify-between items-center mb-3">
                            <div class="flex items-center gap-2 ${statusColor}"><span class="material-symbols-rounded">${icon}</span><span class="font-bold text-sm">${statusText}</span></div>
                            ${typeBadge}
                        </div>
                        <div class="flex justify-between items-end border-t border-gray-50 dark:border-gray-800 pt-3">
                            <div>
                                <p class="text-[11px] text-gray-500 mb-0.5">کد پیگیری / زمان</p>
                                <p class="font-mono font-bold text-xs text-gray-700 dark:text-gray-300" dir="ltr">${tx.ref_id || tx.date.split(' ')[0]}</p>
                            </div>
                            <div class="text-left">
                                <p class="text-[11px] text-gray-500 mb-0.5">مبلغ پرداختی</p>
                                <p class="font-bold text-blue-600 dark:text-blue-400">${Number(tx.amount).toLocaleString('fa-IR')} <span class="text-[10px]">تومان</span></p>
                            </div>
                        </div>
                    </div>`;
                }).join('');
            }
        } catch(e) { console.error(e); }
    }

    function renderDnsList() {
        const container = document.getElementById('dnsListContainer');
        container.innerHTML = '';
        DNS_LIST.forEach(dns => {
            const el = document.createElement('div');
            el.className = 'bg-white dark:bg-darkCard p-4 rounded-3xl shadow-sm border border-gray-100 dark:border-gray-800 flex justify-between items-center cursor-pointer hover:border-blue-500 hover:shadow-md transition-all group';
            el.innerHTML = `<div class="flex items-center gap-4"><div class="w-12 h-12 bg-gray-50 dark:bg-darkElevated text-blue-500 rounded-2xl flex items-center justify-center group-hover:bg-blue-500 group-hover:text-white transition-colors"><span class="material-symbols-rounded">storage</span></div><div><h4 class="font-bold text-gray-800 dark:text-white text-[15px]">${dns.name}</h4><p class="text-[11px] text-gray-500 mt-0.5">${dns.desc}</p></div></div><div class="w-8 h-8 rounded-full bg-gray-50 dark:bg-darkElevated flex items-center justify-center text-gray-400 group-hover:bg-blue-50 group-hover:text-blue-500 dark:group-hover:bg-blue-900/30 transition-colors"><span class="material-symbols-rounded text-lg">chevron_left</span></div>`;
            el.addEventListener('click', () => openTutorial(dns));
            container.appendChild(el);
        });
    }

    function openTutorial(dns) {
        document.getElementById('tutName').innerText = dns.name;
        document.getElementById('tutDns1').innerText = dns.dns1;
        document.getElementById('tutDns2').innerText = dns.dns2;
        navigate('tutorial');
    }

    function renderProfile() {
        document.getElementById('profPhone').innerText = userToken;
        const days = getRemainingDays();
        const badge = document.getElementById('profStatusBadge');
        const daysText = document.getElementById('profDays');
        
        if(days === 'unlimited') {
            badge.innerHTML = `<span class="material-symbols-rounded text-[18px]">workspace_premium</span> کاربر ویژه`; badge.className = 'inline-flex items-center gap-1 font-bold text-sm text-yellow-500';
            daysText.innerText = `نامحدود (رایگان)`; daysText.className = 'font-bold text-xl text-white';
            daysText.onclick = null;
        } else if(days > 0) {
            badge.innerHTML = `<span class="material-symbols-rounded text-[18px]">check_circle</span> فعال`; badge.className = 'inline-flex items-center gap-1 font-bold text-sm text-white';
            daysText.innerText = `${days} روز`; daysText.className = 'font-bold text-xl text-white';
            daysText.onclick = null;
        } else {
            badge.innerHTML = `<span class="material-symbols-rounded text-[18px]">cancel</span> منقضی شده`; badge.className = 'inline-flex items-center gap-1 font-bold text-sm text-red-200';
            daysText.innerText = 'تمدید حساب'; daysText.className = 'font-bold text-sm bg-white/20 px-3 py-1.5 rounded-xl cursor-pointer hover:bg-white/30 transition-colors';
            daysText.onclick = () => navigate('plans');
        }

        let adminBtnContainer = document.getElementById('adminBtnContainer');
        if(isUserAdmin) {
            if(!adminBtnContainer) {
                const btnHtml = `<div id="adminBtnContainer" class="mt-4"><button onclick="navigate('admin'); loadAdminData();" class="w-full flex items-center justify-between p-4 bg-purple-50 dark:bg-purple-900/20 rounded-2xl shadow-sm border border-purple-100 dark:border-purple-800/50 hover:bg-purple-100 dark:hover:bg-purple-900/40 transition-colors"><div class="flex items-center gap-3 text-purple-700 dark:text-purple-400"><span class="material-symbols-rounded">admin_panel_settings</span><span class="font-bold">ورود به پنل مدیریت</span></div><span class="material-symbols-rounded text-purple-400">chevron_left</span></button></div>`;
                document.querySelector('#view-profile .space-y-3').insertAdjacentHTML('afterend', btnHtml);
            }
        } else if(adminBtnContainer) adminBtnContainer.remove();
    }

    // PWA Service Worker (Inline)
    if ('serviceWorker' in navigator) {
        const swCode = `
            const CACHE_NAME = 'dnspro-v3';
            self.addEventListener('install', e => e.waitUntil(caches.open(CACHE_NAME)));
            self.addEventListener('fetch', e => {
                e.respondWith(caches.match(e.request).then(res => res || fetch(e.request)));
            });
        `;
        const blob = new Blob([swCode], { type: 'application/javascript' });
        navigator.serviceWorker.register(URL.createObjectURL(blob)).catch(() => {});
    }

    async function initApp() {
        checkGlobalMessage();
        
        if(userToken) await checkAuthStatus();
        
        // Handle Payment Callback Alert
        if(phpPaymentAlert) {
            if(phpPaymentAlert.type === 'success') {
                showBottomSheet('پرداخت موفق', phpPaymentAlert.msg, 'check_circle', 'green');
                setTimeout(() => navigate('history'), 500);
            } else {
                showBottomSheet('خطا در پرداخت', phpPaymentAlert.msg, 'error', 'red');
                setTimeout(() => navigate('plans'), 500);
            }
        } else {
            navigate('home');
        }

        // Hide Splash Screen
        setTimeout(() => {
            const splash = document.getElementById('splashScreen');
            if (splash) {
                splash.style.opacity = '0';
                setTimeout(() => splash.remove(), 500);
            }
        }, 1500);
    }

    initApp();
</script>
</body>
</html>
