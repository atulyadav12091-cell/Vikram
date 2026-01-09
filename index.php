<?php
// ============================================
// PHP Telegram Bot for Render.com (Webhook)
// ============================================

// Get bot token from environment variable or hardcode
define('BOT_TOKEN', getenv('BOT_TOKEN') ?: 'Place_Your_Token_Here');
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('USERS_FILE', __DIR__ . '/users.json');
define('ERROR_LOG', __DIR__ . '/error.log');
define('WEBHOOK_URL', getenv('WEBHOOK_URL') ?: '');

// Ensure files exist and have proper permissions
initializeFiles();

// Error logging
function logError($message) {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    error_log($logMessage, 3, ERROR_LOG);
    // Also output to console for Render logs
    if (php_sapi_name() !== 'cli') {
        error_log($logMessage);
    }
}

// Initialize required files
function initializeFiles() {
    try {
        // Create users.json if not exists
        if (!file_exists(USERS_FILE)) {
            file_put_contents(USERS_FILE, json_encode([]));
            chmod(USERS_FILE, 0664);
        }
        
        // Create error.log if not exists
        if (!file_exists(ERROR_LOG)) {
            file_put_contents(ERROR_LOG, '');
            chmod(ERROR_LOG, 0664);
        }
        
        // Set proper permissions
        if (file_exists(USERS_FILE)) {
            chmod(USERS_FILE, 0664);
        }
        if (file_exists(ERROR_LOG)) {
            chmod(ERROR_LOG, 0664);
        }
    } catch (Exception $e) {
        error_log("Initialization error: " . $e->getMessage());
    }
}

// Data management
function loadUsers() {
    try {
        if (!file_exists(USERS_FILE)) {
            file_put_contents(USERS_FILE, json_encode([]));
        }
        $content = file_get_contents(USERS_FILE);
        $users = json_decode($content, true);
        return is_array($users) ? $users : [];
    } catch (Exception $e) {
        logError("Load users failed: " . $e->getMessage());
        return [];
    }
}

function saveUsers($users) {
    try {
        $result = file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT));
        if ($result === false) {
            logError("Failed to write to users.json");
        }
        return $result !== false;
    } catch (Exception $e) {
        logError("Save users failed: " . $e->getMessage());
        return false;
    }
}

// Telegram API functions
function sendMessage($chat_id, $text, $keyboard = null) {
    try {
        $params = [
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];
        
        if ($keyboard) {
            $params['reply_markup'] = json_encode([
                'inline_keyboard' => $keyboard
            ]);
        }
        
        $url = API_URL . 'sendMessage';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        curl_close($ch);
        
        return $response;
    } catch (Exception $e) {
        logError("Send message failed: " . $e->getMessage());
        return false;
    }
}

function setWebhook($url) {
    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, API_URL . 'setWebhook');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, ['url' => $url]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        curl_close($ch);
        
        logError("Webhook set: $url - Response: $response");
        return $response;
    } catch (Exception $e) {
        logError("Set webhook failed: " . $e->getMessage());
        return false;
    }
}

function deleteWebhook() {
    try {
        $response = file_get_contents(API_URL . 'deleteWebhook');
        logError("Webhook deleted: $response");
        return $response;
    } catch (Exception $e) {
        logError("Delete webhook failed: " . $e->getMessage());
        return false;
    }
}

// Main keyboard
function getMainKeyboard() {
    return [
        [['text' => '💰 Earn', 'callback_data' => 'earn'], ['text' => '💳 Balance', 'callback_data' => 'balance']],
        [['text' => '🏆 Leaderboard', 'callback_data' => 'leaderboard'], ['text' => '👥 Referrals', 'callback_data' => 'referrals']],
        [['text' => '🏧 Withdraw', 'callback_data' => 'withdraw'], ['text' => '❓ Help', 'callback_data' => 'help']]
    ];
}

// Process commands and callbacks
function processUpdate($update) {
    $users = loadUsers();
    
    if (isset($update['message'])) {
        $chat_id = $update['message']['chat']['id'];
        $text = trim($update['message']['text'] ?? '');
        
        // Create new user if doesn't exist
        if (!isset($users[$chat_id])) {
            $users[$chat_id] = [
                'balance' => 0,
                'last_earn' => 0,
                'referrals' => 0,
                'ref_code' => substr(md5($chat_id . time()), 0, 8),
                'referred_by' => null
            ];
        }
        
        if (strpos($text, '/start') === 0) {
            $ref = explode(' ', $text)[1] ?? null;
            if ($ref && !$users[$chat_id]['referred_by']) {
                foreach ($users as $id => $user) {
                    if ($user['ref_code'] === $ref && $id != $chat_id) {
                        $users[$chat_id]['referred_by'] = $id;
                        $users[$id]['referrals']++;
                        $users[$id]['balance'] += 50; // Referral bonus
                        sendMessage($id, "🎉 New referral! +50 points bonus!");
                        break;
                    }
                }
            }
            
            $msg = "Welcome to Earning Bot!\nEarn points, invite friends, and withdraw your earnings!\nYour referral code: <b>{$users[$chat_id]['ref_code']}</b>";
            sendMessage($chat_id, $msg, getMainKeyboard());
        }
        
    } elseif (isset($update['callback_query'])) {
        $chat_id = $update['callback_query']['message']['chat']['id'];
        $data = $update['callback_query']['data'];
        
        if (!isset($users[$chat_id])) {
            $users[$chat_id] = [
                'balance' => 0,
                'last_earn' => 0,
                'referrals' => 0,
                'ref_code' => substr(md5($chat_id . time()), 0, 8),
                'referred_by' => null
            ];
        }
        
        switch ($data) {
            case 'earn':
                $time_diff = time() - $users[$chat_id]['last_earn'];
                if ($time_diff < 60) {
                    $remaining = 60 - $time_diff;
                    $msg = "⏳ Please wait $remaining seconds before earning again!";
                } else {
                    $earn = 10;
                    $users[$chat_id]['balance'] += $earn;
                    $users[$chat_id]['last_earn'] = time();
                    $msg = "✅ You earned $earn points!\nNew balance: {$users[$chat_id]['balance']}";
                }
                break;
                
            case 'balance':
                $msg = "💳 Your Balance\nPoints: {$users[$chat_id]['balance']}\nReferrals: {$users[$chat_id]['referrals']}";
                break;
                
            case 'leaderboard':
                $sorted = array_column($users, 'balance');
                arsort($sorted);
                $top = array_slice($sorted, 0, 5, true);
                $msg = "🏆 Top Earners\n";
                $i = 1;
                foreach ($top as $id => $bal) {
                    $msg .= "$i. User $id: $bal points\n";
                    $i++;
                }
                break;
                
            case 'referrals':
                $msg = "👥 Referral System\nYour code: <b>{$users[$chat_id]['ref_code']}</b>\nReferrals: {$users[$chat_id]['referrals']}\nInvite link: https://t.me/" . explode(':', BOT_TOKEN)[0] . "?start={$users[$chat_id]['ref_code']}\n50 points per referral!";
                break;
                
            case 'withdraw':
                $min = 100;
                if ($users[$chat_id]['balance'] < $min) {
                    $msg = "🏧 Withdrawal\nMinimum: $min points\nYour balance: {$users[$chat_id]['balance']}\nNeed " . ($min - $users[$chat_id]['balance']) . " more points!";
                } else {
                    $amount = $users[$chat_id]['balance'];
                    $users[$chat_id]['balance'] = 0;
                    $msg = "🏧 Withdrawal of $amount points requested!\nOur team will process it soon.";
                }
                break;
                
            case 'help':
                $msg = "❓ Help\n💰 Earn: Get 10 points/min\n👥 Refer: 50 points/ref\n🏧 Withdraw: Min 100 points\nUse buttons below to navigate!";
                break;
        }
        
        // Answer callback query (removes loading indicator)
        if (isset($update['callback_query']['id'])) {
            file_get_contents(API_URL . 'answerCallbackQuery?callback_query_id=' . $update['callback_query']['id']);
        }
        
        sendMessage($chat_id, $msg, getMainKeyboard());
    }
    
    saveUsers($users);
}

// ============================================
// Webhook Handler for Render.com
// ============================================

// Health check endpoint for Render
if (isset($_GET['health']) || $_SERVER['REQUEST_URI'] === '/health') {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'timestamp' => date('Y-m-d H:i:s'),
        'service' => 'Telegram Bot Webhook'
    ]);
    exit;
}

// Webhook setup endpoint
if (isset($_GET['setup']) && isset($_GET['token'])) {
    if ($_GET['token'] === BOT_TOKEN) {
        if (isset($_GET['url'])) {
            $result = setWebhook($_GET['url']);
            echo "Webhook set to: " . $_GET['url'] . "<br>Response: " . $result;
        } else {
            $result = deleteWebhook();
            echo "Webhook deleted. Response: " . $result;
        }
    } else {
        echo "Invalid token";
    }
    exit;
}

// Main webhook handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get the POST data from Telegram
    $input = file_get_contents('php://input');
    $update = json_decode($input, true);
    
    if ($update) {
        // Process the update asynchronously to respond quickly to Telegram
        // This prevents timeout issues
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        
        // FastCGI finish request if available
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        
        // Process update in background
        processUpdate($update);
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid update']);
    }
} else {
    // Display info page for GET requests
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Telegram Bot Webhook</title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <style>
            body { font-family: Arial, sans-serif; margin: 40px; line-height: 1.6; }
            .container { max-width: 800px; margin: 0 auto; }
            .status { padding: 15px; border-radius: 5px; margin: 20px 0; }
            .success { background: #d4edda; color: #155724; }
            .info { background: #d1ecf1; color: #0c5460; }
            code { background: #f8f9fa; padding: 2px 5px; border-radius: 3px; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>🤖 Telegram Bot Webhook</h1>
            <div class="status info">
                <strong>Status:</strong> Running on Render.com
            </div>
            
            <h2>📋 Setup Instructions:</h2>
            <ol>
                <li>Replace <code>Place_Your_Token_Here</code> in index.php with your bot token</li>
                <li>Set webhook URL: <code>https://your-app.onrender.com/?setup=1&token=YOUR_BOT_TOKEN&url=https://your-app.onrender.com/</code></li>
                <li>Remove webhook: <code>https://your-app.onrender.com/?setup=1&token=YOUR_BOT_TOKEN</code></li>
                <li>Health check: <code>https://your-app.onrender.com/health</code></li>
            </ol>
            
            <h2>🔧 Configuration:</h2>
            <ul>
                <li>Bot Token: <?php echo defined('BOT_TOKEN') && BOT_TOKEN !== 'Place_Your_Token_Here' ? '✅ Set' : '❌ Not set'; ?></li>
                <li>Users file: <?php echo file_exists(USERS_FILE) ? '✅ Exists' : '❌ Missing'; ?></li>
                <li>Error log: <?php echo file_exists(ERROR_LOG) ? '✅ Exists' : '❌ Missing'; ?></li>
                <li>Webhook URL: <?php echo !empty(WEBHOOK_URL) ? '✅ ' . WEBHOOK_URL : '❌ Not configured'; ?></li>
            </ul>
            
            <div class="status info">
                <strong>Note:</strong> Render.com free tier spins down after inactivity. 
                First request may take 30-60 seconds to wake up. Use a monitoring service 
                like UptimeRobot to keep it alive.
            </div>
        </div>
    </body>
    </html>
    <?php
}
?>