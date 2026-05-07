<?php
session_start();

// Konfigurasi Database
$host = 'localhost';
$db   = 'db_olympus_sim'; 
$user = 'root';
$pass = ''; 

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("ALTER TABLE settings MODIFY setting_value FLOAT");
    
    // DEFAULT ENGINE ENTUL (Mesin Sedot - Volatility Extreme)
    $defaults = [
        'win_rate' => 20.0,        
        'combo_rate' => 25.0,      
        'scatter_rate' => 0.1,     
        'fs_scatter_rate' => 1.0,  
        'mult_rate' => 5.0,       
        'x500_rate' => 0.01,       
        'max_win_cap' => 5000,
        'w_crown' => 2, 'w_hourglass' => 5, 'w_ring' => 10, 'w_cup' => 15,      
        'w_red' => 25, 'w_purple' => 35, 'w_yellow' => 45, 'w_green' => 60, 'w_blue' => 80,
        'force_maxwin' => 0,
        'force_target_amount' => 0,
        'max_balance_limit' => 0,
        'auto_scatter_spin' => 0
    ];

    foreach($defaults as $key => $val) {
        $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('$key', $val)");
    }
    $pdo->exec("ALTER TABLE stats MODIFY result_symbols TEXT");
} catch (PDOException $e) {
    die("Koneksi gagal: " . $e->getMessage());
}

$columns_to_add = [
    "free_spins INT DEFAULT 0",
    "fs_total_win INT DEFAULT 0",
    "fs_global_multiplier INT DEFAULT 0",
    "spin_count INT DEFAULT 0"
];

foreach ($columns_to_add as $col) {
    try { $pdo->exec("ALTER TABLE users ADD COLUMN $col"); } catch(PDOException $e) {}
}

if (isset($_GET['login'])) {
    $_SESSION['user_role'] = $_GET['login'];
    header("Location: index.php");
    exit;
}
$role = $_SESSION['user_role'] ?? 'player';

function getSetting($pdo, $key) {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    return (float)$stmt->fetchColumn();
}

$win_rate = getSetting($pdo, 'win_rate'); 
$combo_rate = getSetting($pdo, 'combo_rate'); 
$scatter_rate = getSetting($pdo, 'scatter_rate'); 
$fs_scatter_rate = getSetting($pdo, 'fs_scatter_rate'); 
$mult_rate = getSetting($pdo, 'mult_rate'); 
$x500_rate = getSetting($pdo, 'x500_rate'); 
$max_win_multiplier = getSetting($pdo, 'max_win_cap');

$force_maxwin = getSetting($pdo, 'force_maxwin') == 1;
$force_target = (float)getSetting($pdo, 'force_target_amount');
$max_balance_limit = (int)getSetting($pdo, 'max_balance_limit');
$auto_scatter_spin = (int)getSetting($pdo, 'auto_scatter_spin');

$symbol_weights = [
    '👑' => getSetting($pdo, 'w_crown'), '⏳' => getSetting($pdo, 'w_hourglass'),
    '💍' => getSetting($pdo, 'w_ring'), '🏆' => getSetting($pdo, 'w_cup'),
    '🔴' => getSetting($pdo, 'w_red'), '🟣' => getSetting($pdo, 'w_purple'),
    '🟡' => getSetting($pdo, 'w_yellow'), '🟢' => getSetting($pdo, 'w_green'),
    '🔵' => getSetting($pdo, 'w_blue'),
];

$stmt = $pdo->prepare("SELECT * FROM users WHERE role = 'player' LIMIT 1");
$stmt->execute();
$player = $stmt->fetch();

$message = '';
$spin_result = array_fill(0, 30, '❓'); 
$is_auto_spinning = false;
$auto_spins_remaining = 0;
$current_bet = 400; 
$is_dc = false;     
$display_balance = round($player['balance']); 

$is_fs_active = ($player['free_spins'] > 0 || (int)$player['fs_total_win'] > 0);

// PAYTABLE OTENTIK DENGAN DESIMAL GANJIL
$payout_table = [
    '👑' => [8 => 10.113, 10 => 25.417, 12 => 50.831],
    '⏳' => [8 => 2.513,  10 => 10.117, 12 => 25.339],
    '💍' => [8 => 2.147,  10 => 5.618,  12 => 15.892],
    '🏆' => [8 => 1.571,  10 => 2.283,  12 => 12.113],
    '🔴' => [8 => 1.118,  10 => 1.631,  12 => 10.347],
    '🟣' => [8 => 0.843,  10 => 1.294,  12 => 8.438],
    '🟡' => [8 => 0.582,  10 => 1.176,  12 => 5.221],
    '🟢' => [8 => 0.419,  10 => 0.932,  12 => 4.195],
    '🔵' => [8 => 0.273,  10 => 0.768,  12 => 2.083],
];

$symbols = array_keys($payout_table);
$sequence_data = []; 
$final_fs_payout = 0; 
$current_fs_pot_start = round($player['fs_total_win']); 
$current_global_mult_start = (int)$player['fs_global_multiplier']; 

function getPayoutMultiplier($symbol, $count, $payout_table) {
    if (!isset($payout_table[$symbol])) return 0;
    if ($count >= 12) return $payout_table[$symbol][12];
    if ($count >= 10) return $payout_table[$symbol][10];
    if ($count >= 8)  return $payout_table[$symbol][8];
    return 0;
}

function getRandomWeightedSymbol($weights) {
    $totalWeight = array_sum($weights);
    $random = mt_rand(1, $totalWeight);
    foreach ($weights as $symbol => $weight) {
        if ($random <= $weight) return $symbol;
        $random -= $weight;
    }
    return array_key_last($weights);
}

function generateForcedGrid($symbols, $win_sym, $win_count, $orb) {
    $grid = array_fill(0, 30, '');
    $indices = range(0, 29);
    shuffle($indices);
    if ($orb) $grid[array_pop($indices)] = $orb;
    for ($i=0; $i<$win_count; $i++) $grid[array_pop($indices)] = $win_sym;
    foreach ($indices as $idx) {
        do { $sym = $symbols[array_rand($symbols)]; } while ($sym === $win_sym);
        $grid[$idx] = $sym;
    }
    return $grid;
}

function executeForcedCascade($old_grid, $broken_symbol, $symbols, $next_win_sym, $next_win_count, $orb) {
    $new_grid = $old_grid;
    for ($i=0; $i<30; $i++) if ($new_grid[$i] === $broken_symbol) $new_grid[$i] = '';
    
    for ($col = 0; $col < 6; $col++) {
        $col_items = [];
        for ($row = 4; $row >= 0; $row--) {
            $idx = $row * 6 + $col;
            if ($new_grid[$idx] !== '') $col_items[] = $new_grid[$idx];
        }
        $empty_count = 5 - count($col_items);
        for ($i=0; $i<$empty_count; $i++) $col_items[] = 'NEW'; 
        $row = 4;
        foreach ($col_items as $item) { $new_grid[$row * 6 + $col] = $item; $row--; }
    }

    $new_indices = [];
    for ($i=0; $i<30; $i++) if ($new_grid[$i] === 'NEW') $new_indices[] = $i;
    shuffle($new_indices);

    if ($next_win_sym !== '') {
        $existing = 0;
        for ($i=0; $i<30; $i++) if ($new_grid[$i] === $next_win_sym) $existing++;
        $needed = max(0, $next_win_count - $existing);
        
        if ($orb && count($new_indices) > 0) $new_grid[array_pop($new_indices)] = $orb;
        for ($i=0; $i<$needed; $i++) if (count($new_indices) > 0) $new_grid[array_pop($new_indices)] = $next_win_sym;
    }

    foreach ($new_indices as $idx) {
        do { $sym = $symbols[array_rand($symbols)]; } while ($sym === $next_win_sym);
        $new_grid[$idx] = $sym;
    }
    return $new_grid;
}

function generateAdvancedGrid($symbols, $win_sym, $win_count, $scatter_count, $mult_rate, $x500_rate, $weights) {
    $grid = array_fill(0, 30, '');
    $indices = range(0, 29);
    shuffle($indices);

    for ($i = 0; $i < $scatter_count; $i++) if(!empty($indices)) $grid[array_pop($indices)] = '🍭';
    
    $orb_count = (mt_rand(1, 10000) <= ($mult_rate * 100)) ? mt_rand(1, 2) : 0;
    for ($i = 0; $i < $orb_count; $i++) {
        if(!empty($indices)) {
            if (mt_rand(1, 10000) <= ($x500_rate * 100)) { $grid[array_pop($indices)] = 'M500'; } 
            else {
                $rand_tier = mt_rand(1, 100);
                if ($rand_tier <= 60) $m = [2, 3, 4, 5]; 
                elseif ($rand_tier <= 88) $m = [8, 10, 12, 15]; 
                elseif ($rand_tier <= 97) $m = [20, 25, 50]; 
                else $m = [100, 250]; 
                $grid[array_pop($indices)] = 'M' . $m[array_rand($m)];
            }
        }
    }

    for ($i = 0; $i < $win_count; $i++) if(!empty($indices)) $grid[array_pop($indices)] = $win_sym;

    foreach ($indices as $idx) {
        do {
            $sym = getRandomWeightedSymbol($weights);
            $grid[$idx] = $sym;
            $counts = array_count_values($grid);
        } while (($counts[$sym] ?? 0) >= 8 && $sym !== $win_sym); 
    }
    return $grid;
}

function executeCascade($old_grid, $broken_symbol, $symbols, $next_win_sym, $mult_rate, $x500_rate, $weights) {
    $new_grid = $old_grid;

    for ($i=0; $i<30; $i++) if ($new_grid[$i] === $broken_symbol) $new_grid[$i] = '';

    for ($col = 0; $col < 6; $col++) {
        $col_items = [];
        for ($row = 4; $row >= 0; $row--) {
            $idx = $row * 6 + $col;
            if ($new_grid[$idx] !== '') $col_items[] = $new_grid[$idx];
        }
        $empty_count = 5 - count($col_items);
        for ($i=0; $i<$empty_count; $i++) $col_items[] = 'NEW'; 

        $row = 4;
        foreach ($col_items as $item) {
            $idx = $row * 6 + $col;
            $new_grid[$idx] = $item;
            $row--;
        }
    }

    $new_indices = [];
    for ($i=0; $i<30; $i++) if ($new_grid[$i] === 'NEW') $new_indices[] = $i;

    $final_win_count = 0;
    if ($next_win_sym !== '') {
        $existing_count = 0;
        for ($i=0; $i<30; $i++) if ($new_grid[$i] === $next_win_sym) $existing_count++;
        
        $max_possible = $existing_count + count($new_indices);
        $target = ($max_possible >= 8) ? mt_rand(8, min(14, $max_possible)) : 0;
        
        $needed = $target - $existing_count;
        if ($needed > 0) {
            shuffle($new_indices);
            for($i=0; $i<$needed; $i++) {
                $idx = array_pop($new_indices);
                $new_grid[$idx] = $next_win_sym;
            }
        }
        $final_win_count = $target;
    }

    foreach ($new_indices as $idx) {
        if (mt_rand(1, 10000) <= ($mult_rate * 100)) {
            if (mt_rand(1, 10000) <= ($x500_rate * 100)) { $new_grid[$idx] = 'M500'; } 
            else {
                $rand_tier = mt_rand(1, 100);
                if ($rand_tier <= 60) $m = [2, 3, 4, 5]; 
                elseif ($rand_tier <= 88) $m = [8, 10, 12, 15]; 
                elseif ($rand_tier <= 97) $m = [20, 25, 50]; 
                else $m = [100, 250]; 
                $new_grid[$idx] = 'M' . $m[array_rand($m)];
            }
        } else {
            do { $sym = getRandomWeightedSymbol($weights); } while ($sym === $next_win_sym);
            $new_grid[$idx] = $sym;
        }
    }

    $counts = array_count_values($new_grid);
    foreach ($counts as $sym => $count) {
        if ($sym !== $next_win_sym && in_array($sym, $symbols) && $count >= 8) {
            for ($i=0; $i<30; $i++) {
                if ($new_grid[$i] === $sym && in_array($i, $new_indices)) { 
                    do { $safe_sym = getRandomWeightedSymbol($weights); } while ($safe_sym === $sym || $safe_sym === $next_win_sym);
                    $new_grid[$i] = $safe_sym;
                    $counts[$sym]--;
                    if ($counts[$sym] < 8) break;
                }
            }
        }
    }
    return ['grid' => $new_grid, 'win_count' => $final_win_count];
}

// ==========================================
// AKSI AJAX & ADMIN
// ==========================================
if ($role === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['trigger_maxwin'])) {
        $pdo->prepare("UPDATE settings SET setting_value = 1 WHERE setting_key = 'force_maxwin'")->execute();
        $pdo->prepare("UPDATE settings SET setting_value = 0 WHERE setting_key = 'force_target_amount'")->execute();
        $message = "🔥 Auto Maxwin (Pecah Beruntun) AKTIF untuk putaran pemain berikutnya!";
    } elseif (isset($_POST['trigger_target_win'])) {
        $amt = (float)$_POST['target_win_amount'];
        $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'force_target_amount'")->execute([$amt]);
        $pdo->prepare("UPDATE settings SET setting_value = 0 WHERE setting_key = 'force_maxwin'")->execute();
        $message = "🎯 Target Kemenangan " . number_format($amt) . " Koin AKTIF!";
    } elseif (isset($_POST['set_auto_scatter'])) {
        $spin_num = (int)$_POST['auto_scatter_spin']; 
        $target_spin = $player['spin_count'] + $spin_num;
        $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'auto_scatter_spin'")->execute([$target_spin]);
        $message = "🍭 Auto Scatter akan memicu dalam " . $spin_num . " putaran (Putaran Ke-" . $target_spin . ")";
    } elseif (isset($_POST['clear_override'])) {
        $pdo->prepare("UPDATE settings SET setting_value = 0 WHERE setting_key IN ('force_maxwin', 'force_target_amount', 'auto_scatter_spin')")->execute();
        $message = "🧹 Semua Override Bandar Dibatalkan.";
    } elseif (isset($_POST['reset_default'])) {
        $defaults = [
            'win_rate' => 20.0, 'combo_rate' => 25.0, 'scatter_rate' => 0.1,
            'fs_scatter_rate' => 1.0, 'mult_rate' => 5.0, 'x500_rate' => 0.01,
            'max_win_cap' => 5000, 'max_balance_limit' => 0,
            'w_crown' => 2, 'w_hourglass' => 5, 'w_ring' => 10, 'w_cup' => 15, 
            'w_red' => 25, 'w_purple' => 35, 'w_yellow' => 45, 'w_green' => 60, 'w_blue' => 80
        ];
        foreach ($defaults as $key => $val) {
            $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?")->execute([$val, $key]);
        }
        $message = "✅ Algoritma & Bobot dikembalikan ke Setelan Mesin Entul!";
    } elseif (isset($_POST['update_settings'])) {
        $updates = [
            'win_rate' => (float)$_POST['win_rate'], 'combo_rate' => (float)$_POST['combo_rate'],
            'scatter_rate' => (float)$_POST['scatter_rate'], 'fs_scatter_rate' => (float)$_POST['fs_scatter_rate'],
            'mult_rate' => (float)$_POST['mult_rate'], 'x500_rate' => (float)$_POST['x500_rate'],
            'max_win_cap' => (int)$_POST['max_win_cap'], 'max_balance_limit' => (int)$_POST['max_balance_limit'],
            'w_crown' => (int)$_POST['w_crown'], 'w_hourglass' => (int)$_POST['w_hourglass'], 
            'w_ring' => (int)$_POST['w_ring'], 'w_cup' => (int)$_POST['w_cup'], 'w_red' => (int)$_POST['w_red'],
            'w_purple' => (int)$_POST['w_purple'], 'w_yellow' => (int)$_POST['w_yellow'],
            'w_green' => (int)$_POST['w_green'], 'w_blue' => (int)$_POST['w_blue']
        ];
        foreach ($updates as $key => $val) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            if ($stmt->fetchColumn() == 0) $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)")->execute([$key, $val]);
            else $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?")->execute([$val, $key]);
        }
        $message = "✅ Pengaturan Mesin Slot Server berhasil diperbarui!";
    }
}

// ==========================================
// AKSI PEMAIN (MENDUKUNG AJAX/NO RELOAD)
// ==========================================
if ($role === 'player' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['topup_amount'])) {
        $amount = (int)$_POST['topup_amount'];
        $new_balance = $player['balance'] + $amount;
        $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?")->execute([$new_balance, $player['id']]);
        $player['balance'] = $new_balance;
        $display_balance = round($new_balance);
        $message = "✅ Top Up $amount Koin Berhasil!";
    } elseif (isset($_POST['withdraw_amount'])) {
        $amount = (int)$_POST['withdraw_amount'];
        if ($amount > 0 && $amount <= $player['balance']) {
            $new_balance = $player['balance'] - $amount;
            $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?")->execute([$new_balance, $player['id']]);
            $player['balance'] = $new_balance;
            $display_balance = round($new_balance);
            $message = "💸 Tarik $amount Koin Berhasil!";
        } else {
            $message = "❌ Saldo tidak cukup!";
        }
    } elseif (isset($_POST['ajax_spin'])) {
        
        $raw_bet = (int)($_POST['bet_amount'] ?? 400);
        $current_bet = max(400, floor($raw_bet / 400) * 400); 
        $is_dc = isset($_POST['is_dc']) && $_POST['is_dc'] == '1';
        $is_buy_spin = isset($_POST['is_buy_spin']) && $_POST['is_buy_spin'] == '1';
        
        $free_spins_owned = (int)$player['free_spins'];
        $is_using_free_spin = ($free_spins_owned > 0);
        
        $bet_cost = $current_bet;
        if ($is_buy_spin) { $bet_cost = $current_bet * 100; $is_dc = false; } 
        elseif ($is_dc) { $bet_cost = $current_bet * 1.25; }
        
        if ($is_using_free_spin) $bet_cost = 0; 
        
        $current_fs_pot = (float)$player['fs_total_win'];
        $fs_global_multiplier = (int)$player['fs_global_multiplier'];

        if ($player['balance'] >= $bet_cost || $is_using_free_spin) {
            
            $new_spin_count = (int)($player['spin_count'] ?? 0) + 1;
            $pdo->prepare("UPDATE users SET spin_count = ? WHERE id = ?")->execute([$new_spin_count, $player['id']]);
            $player['spin_count'] = $new_spin_count;

            if ($is_using_free_spin) {
                $free_spins_owned--; 
            } else {
                $new_balance = $player['balance'] - $bet_cost; 
                $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?")->execute([$new_balance, $player['id']]);
                $player['balance'] = $new_balance;
            }

            // CEK OVERRIDE BANDAR (GOD MODE)
            $is_forced_win = ($force_maxwin || $force_target > 0);
            
            if ($is_forced_win) {
                $pdo->exec("UPDATE settings SET setting_value = 0 WHERE setting_key IN ('force_maxwin', 'force_target_amount')");
                
                if ($force_maxwin) {
                    $total_base_win = 0; 
                    $sequence_data = [];
                    $previous_grid = [];
                    
                    $steps = [
                        ['sym' => '🔵', 'count' => 10, 'orb' => 'M50'],
                        ['sym' => '🟢', 'count' => 11, 'orb' => 'M100'],
                        ['sym' => '🟡', 'count' => 12, 'orb' => 'M250'],
                        ['sym' => '🔴', 'count' => 10, 'orb' => 'M500'],
                        ['sym' => '👑', 'count' => 12, 'orb' => 'M500'],
                    ];

                    foreach ($steps as $idx => $step) {
                        if ($idx === 0) $grid = generateForcedGrid($symbols, $step['sym'], $step['count'], $step['orb']);
                        else $grid = executeForcedCascade($previous_grid, $steps[$idx-1]['sym'], $symbols, $step['sym'], $step['count'], $step['orb']);
                        
                        $emote_multiplier = $payout_table[$step['sym']][$step['count'] >= 12 ? 12 : ($step['count'] >= 10 ? 10 : 8)];
                        $step_win = round($current_bet * $emote_multiplier); 
                        $total_base_win += $step_win;
                        
                        $sequence_data[] = ['grid' => $grid, 'win_symbol' => $step['sym'], 'win_count' => $step['count'], 'step_win' => $step_win, 'total_base_win' => $total_base_win, 'scatters' => 0, 'fs_awarded' => 0, 'scatter_cash' => 0];
                        $previous_grid = $grid;
                    }

                    $grid_lose = executeForcedCascade($previous_grid, '👑', $symbols, '', 0, null);
                    $sequence_data[] = ['grid' => $grid_lose, 'win_symbol' => '', 'win_count' => 0, 'step_win' => 0, 'total_base_win' => $total_base_win, 'scatters' => 0, 'fs_awarded' => 0, 'scatter_cash' => 0];
                    
                    $scatter_count_initial = 0; $free_spins_awarded = 0; $scatter_payout_cash = 0;

                } else {
                    $target_amount = min($force_target, $current_bet * $max_win_multiplier); 
                    
                    $orb_pool = [3, 4, 5, 8, 10, 12, 15, 20];
                    $orb1 = $orb_pool[array_rand($orb_pool)];
                    $orb2 = $orb_pool[array_rand($orb_pool)];
                    $total_mult = $orb1 + $orb2;
                    
                    $total_base_needed = $target_amount / $total_mult;
                    
                    $win1 = round($total_base_needed * 0.4);
                    $win2 = round($total_base_needed * 0.3);
                    $win3 = $total_base_needed - ($win1 + $win2); 
                    
                    $total_base_win = 0; 
                    $sequence_data = [];

                    $grid1 = generateForcedGrid($symbols, '🔵', 8, 'M'.$orb1);
                    $total_base_win += $win1;
                    $sequence_data[] = ['grid' => $grid1, 'win_symbol' => '🔵', 'win_count' => 8, 'step_win' => $win1, 'total_base_win' => $total_base_win, 'scatters' => 0, 'fs_awarded' => 0, 'scatter_cash' => 0];

                    $grid2 = executeForcedCascade($grid1, '🔵', $symbols, '🟢', 10, null);
                    $total_base_win += $win2;
                    $sequence_data[] = ['grid' => $grid2, 'win_symbol' => '🟢', 'win_count' => 10, 'step_win' => $win2, 'total_base_win' => $total_base_win, 'scatters' => 0, 'fs_awarded' => 0, 'scatter_cash' => 0];

                    $grid3 = executeForcedCascade($grid2, '🟢', $symbols, '🟡', 12, 'M'.$orb2);
                    $total_base_win += $win3;
                    $sequence_data[] = ['grid' => $grid3, 'win_symbol' => '🟡', 'win_count' => 12, 'step_win' => $win3, 'total_base_win' => $total_base_win, 'scatters' => 0, 'fs_awarded' => 0, 'scatter_cash' => 0];

                    $grid4 = executeForcedCascade($grid3, '🟡', $symbols, '', 0, null);
                    $sequence_data[] = ['grid' => $grid4, 'win_symbol' => '', 'win_count' => 0, 'step_win' => 0, 'total_base_win' => $total_base_win, 'scatters' => 0, 'fs_awarded' => 0, 'scatter_cash' => 0];

                    $scatter_count_initial = 0; $free_spins_awarded = 0; $scatter_payout_cash = 0;
                }

            } else {
                // NORMAL RNG SPIN
                $active_mult_rate = $is_dc ? min(100, $mult_rate * 1.5) : $mult_rate; 
                $active_scatter_rate = $is_using_free_spin ? $fs_scatter_rate : ($is_dc ? $scatter_rate * 2 : $scatter_rate);
                
                $force_auto_scatter = false;
                if ($auto_scatter_spin > 0 && $player['spin_count'] == $auto_scatter_spin) {
                    $force_auto_scatter = true;
                    $pdo->exec("UPDATE settings SET setting_value = 0 WHERE setting_key = 'auto_scatter_spin'");
                }

                $is_scatter_hit = $force_auto_scatter ? true : (mt_rand(1, 10000) <= ($active_scatter_rate * 100));
                
                if ($is_buy_spin || $force_auto_scatter) {
                    $scatter_count_initial = (mt_rand(1, 100) <= 5) ? 5 : 4; 
                } else {
                    if ($is_scatter_hit) {
                        if ($is_using_free_spin) { $scatter_count_initial = 3; } 
                        else {
                            $rand = mt_rand(1, 100);
                            $scatter_count_initial = ($rand <= 5) ? 5 : ($rand <= 1 ? 6 : 4);
                        }
                    } else {
                        $scatter_count_initial = mt_rand(0, 2); 
                    }
                }
                
                $free_spins_awarded = ($scatter_count_initial >= ($is_using_free_spin ? 3 : 4)) ? ($is_using_free_spin ? 5 : 15) : 0;
                
                $scatter_payout_cash = 0;
                if (!$is_using_free_spin && $scatter_count_initial >= 4) {
                    if ($scatter_count_initial == 4) $scatter_payout_cash = 3 * $current_bet;
                    elseif ($scatter_count_initial == 5) $scatter_payout_cash = 5 * $current_bet;
                    elseif ($scatter_count_initial >= 6) $scatter_payout_cash = 100 * $current_bet;
                }

                $total_base_win = 0; 
                $sequence_data = [];
                $max_safeguard = 15; 
                
                $loop_count = 0;
                $previous_grid = [];
                $previous_win_sym = '';
                
                while(true) {
                    $loop_count++;
                    $step_is_win = false;
                    if ($loop_count === 1) $step_is_win = (mt_rand(1, 10000) <= ($win_rate * 100));
                    else $step_is_win = (mt_rand(1, 10000) <= ($combo_rate * 100) && $loop_count < $max_safeguard);

                    if ($step_is_win) {
                        $win_sym = getRandomWeightedSymbol($symbol_weights);
                        
                        if ($loop_count === 1) {
                            $rand_tier = mt_rand(1, 100);
                            if ($rand_tier <= 70) $win_count = mt_rand(8, 9);
                            elseif ($rand_tier <= 95) $win_count = mt_rand(10, 11);
                            else $win_count = mt_rand(12, 16);
                            $grid = generateAdvancedGrid($symbols, $win_sym, $win_count, $scatter_count_initial, $active_mult_rate, $x500_rate, $symbol_weights);
                        } else {
                            $cascade_res = executeCascade($previous_grid, $previous_win_sym, $symbols, $win_sym, $active_mult_rate, $x500_rate, $symbol_weights);
                            $grid = $cascade_res['grid'];
                            $win_count = $cascade_res['win_count'];
                        }

                        $emote_multiplier = getPayoutMultiplier($win_sym, $win_count, $payout_table);
                        $step_base_win = round($current_bet * $emote_multiplier); 
                        $total_base_win += $step_base_win;
                        
                        $sequence_data[] = [
                            'grid' => $grid, 'win_symbol' => $win_sym, 'win_count' => $win_count,
                            'step_win' => $step_base_win, 'total_base_win' => $total_base_win, 
                            'scatters' => ($loop_count === 1) ? $scatter_count_initial : 0,
                            'fs_awarded' => ($loop_count === 1) ? $free_spins_awarded : 0,
                            'scatter_cash' => ($loop_count === 1) ? $scatter_payout_cash : 0
                        ];
                        $previous_grid = $grid; $previous_win_sym = $win_sym;
                    } else {
                        if ($loop_count === 1) $grid_lose = generateAdvancedGrid($symbols, '', 0, $scatter_count_initial, $active_mult_rate, $x500_rate, $symbol_weights);
                        else $grid_lose = executeCascade($previous_grid, $previous_win_sym, $symbols, '', $active_mult_rate, $x500_rate, $symbol_weights)['grid'];
                        
                        $sequence_data[] = [
                            'grid' => $grid_lose, 'win_symbol' => '', 'win_count' => 0, 'step_win' => 0,
                            'total_base_win' => $total_base_win, 'scatters' => ($loop_count === 1) ? $scatter_count_initial : 0, 
                            'fs_awarded' => ($loop_count === 1) ? $free_spins_awarded : 0, 'scatter_cash' => ($loop_count === 1) ? $scatter_payout_cash : 0
                        ];
                        break; 
                    }
                }
            }
            
            $final_grid = end($sequence_data)['grid'];
            $spin_orb_multiplier_sum = 0;
            foreach ($final_grid as $sym) {
                if (strpos($sym, 'M') === 0) $spin_orb_multiplier_sum += (int)substr($sym, 1);
            }

            $applied_multiplier = 1;
            $global_mult_used = 0;

            if ($total_base_win > 0) {
                if ($is_using_free_spin) {
                    if ($spin_orb_multiplier_sum > 0) {
                        $fs_global_multiplier += $spin_orb_multiplier_sum; 
                        $global_mult_used = $fs_global_multiplier;
                        $applied_multiplier = $fs_global_multiplier;
                    } else {
                        $applied_multiplier = 1; 
                        $global_mult_used = 0;
                    }
                } else {
                    if ($spin_orb_multiplier_sum > 0) {
                        $applied_multiplier = $spin_orb_multiplier_sum;
                    }
                }
            }

            $final_spin_payout = round(($total_base_win * $applied_multiplier) + $scatter_payout_cash);

            $is_max_win = false;
            $max_win_amount = $max_win_multiplier * $current_bet;
            
            if ($is_using_free_spin) {
                if (($current_fs_pot + $final_spin_payout) >= $max_win_amount) {
                    $final_spin_payout = $max_win_amount - $current_fs_pot;
                    $is_max_win = true;
                    $free_spins_awarded = 0;
                    $free_spins_owned = 0; 
                }
            } else {
                if ($final_spin_payout >= $max_win_amount) {
                    $final_spin_payout = $max_win_amount;
                    $is_max_win = true;
                    $free_spins_awarded = 0; 
                }
            }

            // ANTI RUNGKAD (OVERRIDE SALDO MAKSIMAL)
            if ($max_balance_limit > 0) {
                $projected_balance = $player['balance']; 
                if ($is_using_free_spin) {
                    $projected_balance = $player['balance'] + $current_fs_pot + $final_spin_payout;
                } else {
                    $projected_balance += $final_spin_payout;
                }

                if ($projected_balance > $max_balance_limit) {
                    $total_base_win = 0;
                    $spin_orb_multiplier_sum = 0;
                    $applied_multiplier = 1;
                    $global_mult_used = $is_using_free_spin ? $fs_global_multiplier : 0;
                    $final_spin_payout = 0;
                    $is_max_win = false;
                    $scatter_count_initial = 0;
                    $scatter_payout_cash = 0;
                    $free_spins_awarded = 0;
                    
                    $grid_lose = generateAdvancedGrid($symbols, '', 0, 0, 0, 0, $symbol_weights);
                    $sequence_data = [[
                        'grid' => $grid_lose, 'win_symbol' => '', 'win_count' => 0, 'step_win' => 0,
                        'total_base_win' => 0, 'scatters' => 0, 'fs_awarded' => 0, 'scatter_cash' => 0
                    ]];
                }
            }

            $last_idx = count($sequence_data) - 1;
            $sequence_data[$last_idx]['final_payout'] = $final_spin_payout;
            $sequence_data[$last_idx]['spin_mult_total'] = $spin_orb_multiplier_sum;
            $sequence_data[$last_idx]['global_mult_used'] = $global_mult_used;
            $sequence_data[$last_idx]['is_max_win'] = $is_max_win;

            $new_balance = $player['balance']; 
            if ($is_using_free_spin) {
                $current_fs_pot += $final_spin_payout;
                if ($free_spins_owned == 0) {
                    $new_balance += $current_fs_pot;    
                }
            } else {
                $new_balance += $final_spin_payout;
            }
            
            if ($free_spins_awarded > 0) $free_spins_owned += $free_spins_awarded;

            // Update Database (Saldo Final setelah menang dicatat di sini)
            $pdo->prepare("UPDATE users SET balance = ?, free_spins = ?, fs_total_win = ?, fs_global_multiplier = ? WHERE id = ?")
                ->execute([$new_balance, $free_spins_owned, ($free_spins_owned == 0 ? 0 : $current_fs_pot), ($free_spins_owned == 0 ? 0 : $fs_global_multiplier), $player['id']]);
            
            // JIKA AJAX, KEMBALIKAN JSON, LALU EXIT!
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'sequence_data' => $sequence_data,
                'new_balance' => $player['balance'], // Ini adalah saldo SETELAH terpotong bet (tapi SEBELUM menang untuk animasi)
                'true_final_balance' => $new_balance, // Ini saldo akhir mutlak
                'free_spins' => $free_spins_owned,
                'fs_total_win' => $current_fs_pot,
                'fs_global_multiplier' => $fs_global_multiplier
            ]);
            exit;

        } else {
            header('Content-Type: application/json');
            echo json_encode(['error' => '❌ Saldo kurang! Top Up dulu.']);
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gates of Olympus - Simulasi Realistis 1:1</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .olympus-glow { text-shadow: 0 0 10px #eab308, 0 0 20px #eab308; }
        .scatter-glow { text-shadow: 0 0 15px #ec4899, 0 0 30px #ec4899; animation: pulse 1s infinite; }
        .mult-glow-red { text-shadow: 0 0 15px #ef4444, 0 0 30px #ef4444; }
        .text-glow-yellow { text-shadow: 0 0 15px #fbbf24, 0 0 30px #fbbf24; }
        .grid-shadow { box-shadow: 0 15px 50px rgba(0,0,0,0.8), inset 0 0 20px rgba(168, 85, 247, 0.4); }
        
        /* Animasi Jatuh Mulus (Smooth Gravity Drop) */
        .symbol-drop { animation: dropIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275) both; }
        @keyframes dropIn { 0% { transform: translateY(-300px); opacity: 0; } 100% { transform: translateY(0); opacity: 1; } }
        .cascade-drop { animation: cascadeIn 0.5s cubic-bezier(0.25, 0.46, 0.45, 0.94) both; }
        @keyframes cascadeIn { 0% { transform: translateY(-120px); opacity: 0; } 100% { transform: translateY(0); opacity: 1; } }
        
        .symbol-burst { animation: burstOut 0.6s forwards; display: inline-block; z-index: 10; position: relative;}
        @keyframes burstOut { 0% { transform: scale(1); opacity: 1; filter: brightness(1); } 40% { transform: scale(1.6) rotate(15deg); opacity: 0.9; filter: brightness(2); } 100% { transform: scale(0) rotate(-15deg); opacity: 0; filter: brightness(3); } }
        
        .fs-overlay { position: absolute; inset: 0; background: rgba(0,0,0,0.9); z-index: 50; display: flex; align-items: center; justify-content: center; flex-direction: column; opacity: 0; pointer-events: none; transition: opacity 0.5s; border-radius: 0.75rem;}
        .fs-overlay.active { opacity: 1; pointer-events: auto; }
        .scale-in { animation: scaleIn 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards; }
        @keyframes scaleIn { 0% { transform: scale(0.5); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
        @keyframes epicBounce { 0%, 100% { transform: translateY(0) scale(1); } 50% { transform: translateY(-20px) scale(1.05); } }
        .animate-epic { animation: epicBounce 1.5s infinite; }
        
        /* EFEK MENEGANGKAN SAAT FREE SPIN */
        body.fs-mode {
            background: radial-gradient(ellipse at top, #4c0519, #0f0206, #000);
            animation: pulse-fs 3s infinite alternate;
        }
        body.fs-mode #gridContainer {
            border-color: #f43f5e;
            box-shadow: 0 15px 50px rgba(0,0,0,0.9), inset 0 0 30px rgba(244, 63, 94, 0.6);
        }
        @keyframes pulse-fs {
            0% { box-shadow: inset 0 0 50px rgba(225, 29, 72, 0.1); }
            100% { box-shadow: inset 0 0 150px rgba(225, 29, 72, 0.4); }
        }

        input[type=number]::-webkit-inner-spin-button, input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
        input[type=number] { -moz-appearance: textfield; }
        
        /* Utility untuk hide elemen tanpa merusak DOM */
        .hidden-hud { display: none !important; }
    </style>
</head>
<body class="<?= $is_fs_active && $role === 'player' ? 'fs-mode' : ($role === 'player' ? 'bg-indigo-950 bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-purple-900 via-indigo-950 to-black' : 'bg-slate-100') ?> text-gray-800 font-sans min-h-screen overflow-x-hidden transition-colors duration-1000">

    <nav class="bg-black/50 backdrop-blur-md border-b border-white/10 sticky top-0 z-50">
        <div class="max-w-[1400px] mx-auto px-4 py-3 flex justify-between items-center">
            <div class="text-yellow-500 font-black tracking-tighter text-xl italic drop-shadow-md">
                ⚡ OLYMPUS ENGINE 1:1
            </div>
            <div class="flex space-x-4 items-center">
                <?php if ($role === 'player'): ?>
                <button onclick="toggleAudio()" id="audioBtn" class="text-gray-300 hover:text-white transition cursor-pointer" title="Nyalakan/Matikan Suara">
                    <i id="audioIcon" class="fa-solid fa-volume-high text-xl"></i>
                </button>
                <?php endif; ?>
                
                <a href="?login=player" class="px-5 py-1.5 rounded-full text-xs font-bold transition-all <?= $role == 'player' ? 'bg-yellow-500 text-black shadow-[0_0_10px_yellow]' : 'text-gray-400 hover:bg-white/10' ?>">PLAYER</a>
                <a href="?login=admin" class="px-5 py-1.5 rounded-full text-xs font-bold transition-all <?= $role == 'admin' ? 'bg-red-600 text-white shadow-[0_0_10px_red]' : 'text-gray-400 hover:bg-white/10' ?>">ADMIN</a>
            </div>
        </div>
    </nav>

    <div class="max-w-[1400px] mx-auto py-8 px-4 flex flex-col items-center">

    <?php if ($role === 'admin'): ?>
    <div class="w-full max-w-5xl">
        
        <?php if($force_maxwin || $force_target > 0 || $auto_scatter_spin > 0): ?>
        <div class="bg-red-600 text-white p-4 rounded-2xl mb-6 shadow-[0_0_20px_red] flex items-center justify-between animate-pulse">
            <div>
                <h2 class="font-black text-xl"><i class="fa-solid fa-triangle-exclamation mr-2"></i> PERINGATAN OVERRIDE AKTIF!</h2>
                <p class="text-sm opacity-90 mt-1">
                    <?php 
                        if ($force_maxwin) echo "Pemain akan mendapatkan Max Win (5000x) pada spin berikutnya!";
                        elseif ($force_target > 0) echo "Pemain akan mendapatkan target kemenangan ".number_format(round($force_target))." Koin!";
                        elseif ($auto_scatter_spin > 0) echo "Pemain akan dipaksa mendapat Scatter pada Putaran Ke-".$auto_scatter_spin."!";
                    ?>
                </p>
            </div>
            <form method="POST"><button type="submit" name="clear_override" class="bg-white text-red-600 font-bold px-4 py-2 rounded-xl hover:bg-red-100 transition">Batalkan Semua</button></form>
        </div>
        <?php endif; ?>

        <div class="bg-white rounded-[2rem] shadow-2xl overflow-hidden border border-slate-200 mb-8">
            <div class="bg-indigo-950 p-6 flex items-center justify-between border-b-4 border-yellow-500 relative overflow-hidden">
                <div class="absolute inset-0 opacity-20 bg-[radial-gradient(circle_at_center,_var(--tw-gradient-stops))] from-yellow-400 to-transparent"></div>
                <div class="relative z-10">
                    <h2 class="text-white text-xl font-black"><i class="fa-solid fa-wand-magic-sparkles text-yellow-400 mr-2"></i> KONTROL BANDAR (GOD MODE)</h2>
                    <p class="text-indigo-200 text-xs mt-1">Status Putaran Pemain Saat Ini: <b class="text-yellow-400"><?= $player['spin_count'] ?></b> Putaran.</p>
                </div>
            </div>
            <div class="p-6 bg-indigo-50 grid grid-cols-1 md:grid-cols-3 gap-6">
                <form method="POST" class="bg-white p-6 rounded-2xl shadow-sm border border-indigo-100 text-center flex flex-col justify-center">
                    <h3 class="font-black text-slate-800 mb-2">Instan Max Win</h3>
                    <p class="text-[10px] text-slate-500 mb-4">Paksa sistem melakukan tumble beruntun dengan bola petir super besar hingga menyentuh 5000x Bet secara alami.</p>
                    <button type="submit" name="trigger_maxwin" class="w-full mt-auto bg-gradient-to-r from-red-600 to-orange-500 text-white font-black py-3 rounded-xl shadow-lg hover:scale-[1.02] active:scale-95 transition-all">
                        <i class="fa-solid fa-bolt text-yellow-300 mr-2"></i> AUTO MAXWIN
                    </button>
                </form>
                
                <form method="POST" class="bg-white p-6 rounded-2xl shadow-sm border border-indigo-100 flex flex-col justify-center">
                    <h3 class="font-black text-slate-800 mb-2">Target Kemenangan</h3>
                    <p class="text-[10px] text-slate-500 mb-4">Tulis jumlah koin kemenangan, sistem akan pecah 3x secara alami dan menjatuhkan petir agar nominalnya pas.</p>
                    <label class="block text-[10px] font-bold text-indigo-400 uppercase mb-1">Target Menang (Koin)</label>
                    <div class="flex gap-2 mt-auto">
                        <input type="number" name="target_win_amount" placeholder="Cth: 150000" min="1" required class="flex-1 bg-indigo-50 border border-indigo-200 p-2 rounded-xl font-black text-indigo-900 outline-none focus:ring-2 focus:ring-indigo-400">
                        <button type="submit" name="trigger_target_win" class="bg-indigo-600 text-white font-bold px-4 rounded-xl hover:bg-indigo-700 transition active:scale-95">SET!</button>
                    </div>
                </form>

                <form method="POST" class="bg-white p-6 rounded-2xl shadow-sm border border-indigo-100 flex flex-col justify-center">
                    <h3 class="font-black text-slate-800 mb-2">Auto Scatter Trigger</h3>
                    <p class="text-[10px] text-slate-500 mb-4">Masukkan <b>Berapa putaran lagi</b> pemain akan dipaksa mendapat Scatter. Contoh isi 1 jika ingin spin selanjutnya.</p>
                    <div class="flex gap-2 mt-auto">
                        <input type="number" name="auto_scatter_spin" placeholder="Dlm Berapa Spin?" min="1" required class="flex-1 bg-indigo-50 border border-indigo-200 p-2 rounded-xl font-black text-indigo-900 outline-none focus:ring-2 focus:ring-indigo-400">
                        <button type="submit" name="set_auto_scatter" class="bg-pink-600 text-white font-bold px-4 rounded-xl hover:bg-pink-700 transition active:scale-95">SET!</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="bg-white rounded-[2rem] shadow-2xl overflow-hidden border border-slate-200">
            <div class="bg-slate-900 p-8 text-center relative overflow-hidden">
                <div class="absolute inset-0 opacity-20 bg-[radial-gradient(circle_at_center,_var(--tw-gradient-stops))] from-blue-400 to-transparent"></div>
                <h1 class="text-white text-3xl font-black relative z-10 drop-shadow-md">Pragmatic CMS (Operator View)</h1>
                <p class="text-slate-400 text-sm mt-2 relative z-10">Sistem Algoritma Weighted Probability & Volatility Engine.</p>
                <?php if($message) echo "<div class='mt-4 inline-block bg-green-500 text-white px-4 py-1 rounded-full font-bold text-sm shadow-lg relative z-10'>$message</div>"; ?>
            </div>
            
            <form method="POST" class="p-8 bg-slate-50 grid grid-cols-1 lg:grid-cols-2 gap-8">
                
                <div class="space-y-6">
                    <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
                        <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest mb-4 flex items-center"><span class="w-6 h-[2px] bg-slate-300 mr-3"></span> CORE ENGINE</h3>
                        <div class="grid grid-cols-2 gap-4">
                            <div><label class="block font-bold text-slate-700 text-xs mb-1">Hit Frequency (%)</label><input type="number" step="0.1" name="win_rate" value="<?= $win_rate ?>" min="0" max="100" class="w-full bg-slate-100 border border-slate-300 p-2.5 rounded-lg text-sm outline-none"></div>
                            <div><label class="block font-bold text-slate-700 text-xs mb-1">Tumble Rate (%)</label><input type="number" step="0.1" name="combo_rate" value="<?= $combo_rate ?>" min="0" max="99" class="w-full bg-slate-100 border border-slate-300 p-2.5 rounded-lg text-sm outline-none"></div>
                            <div class="col-span-2"><label class="block font-bold text-red-600 text-xs mb-1">Max Win Limit (x Bet)</label><input type="number" name="max_win_cap" value="<?= $max_win_multiplier ?>" class="w-full bg-red-50 border border-red-200 p-2.5 rounded-lg text-sm outline-none text-red-700 font-bold"></div>
                        </div>
                    </div>

                    <div class="bg-pink-50 p-6 rounded-2xl border border-pink-100 shadow-sm">
                        <h3 class="text-xs font-black text-pink-400 uppercase tracking-widest mb-4 flex items-center"><span class="w-6 h-[2px] bg-pink-300 mr-3"></span> SCATTER (🍭)</h3>
                        <div class="grid grid-cols-2 gap-4">
                            <div><label class="block font-bold text-pink-700 text-xs mb-1">Base Trigger (%)</label><input type="number" step="0.01" name="scatter_rate" value="<?= $scatter_rate ?>" min="0" max="100" class="w-full bg-white border border-pink-200 p-2.5 rounded-lg text-sm outline-none"></div>
                            <div><label class="block font-bold text-pink-700 text-xs mb-1">Retrigger in FS (%)</label><input type="number" step="0.01" name="fs_scatter_rate" value="<?= $fs_scatter_rate ?>" min="0" max="100" class="w-full bg-white border border-pink-200 p-2.5 rounded-lg text-sm outline-none"></div>
                        </div>
                    </div>

                    <div class="bg-blue-50 p-6 rounded-2xl border border-blue-100 shadow-sm">
                        <h3 class="text-xs font-black text-blue-400 uppercase tracking-widest mb-4 flex items-center"><span class="w-6 h-[2px] bg-blue-300 mr-3"></span> MULTIPLIER (M)</h3>
                        <div class="grid grid-cols-2 gap-4">
                            <div><label class="block font-bold text-slate-700 text-xs mb-1">Drop Chance (%)</label><input type="number" step="0.1" name="mult_rate" value="<?= $mult_rate ?>" min="0" max="100" class="w-full bg-white border border-blue-200 p-2.5 rounded-lg text-sm outline-none"></div>
                            <div><label class="block font-black text-blue-700 text-xs mb-1">Peluang x500 (%)</label><input type="number" step="0.01" name="x500_rate" value="<?= $x500_rate ?>" min="0" max="100" class="w-full bg-blue-600 text-white font-bold border-none p-2.5 rounded-lg text-sm outline-none"></div>
                        </div>
                    </div>
                    
                    <div class="bg-yellow-50 p-6 rounded-2xl border border-yellow-200 shadow-sm">
                        <h3 class="text-xs font-black text-yellow-600 uppercase tracking-widest mb-4 flex items-center"><span class="w-6 h-[2px] bg-yellow-400 mr-3"></span> ANTI RUNGKAD BANDAR</h3>
                        <div>
                            <label class="block font-bold text-slate-700 text-xs mb-1">Maksimal Saldo Pemain (Koin)</label>
                            <input type="number" name="max_balance_limit" value="<?= $max_balance_limit ?>" min="0" class="w-full bg-white border border-yellow-300 p-2.5 rounded-lg text-sm outline-none focus:ring-2 focus:ring-yellow-400">
                            <p class="text-[10px] text-slate-500 mt-1 leading-tight">Jika spin membuat saldo melewati batas ini, spin otomatis akan di <b>ZONK</b>-kan. Isi <b>0</b> jika ingin tanpa batas (Unlimited).</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex flex-col">
                    <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest mb-2 flex items-center"><span class="w-6 h-[2px] bg-slate-300 mr-3"></span> SYMBOL VOLATILITY (WEIGHTS)</h3>
                    <p class="text-[10px] text-gray-500 mb-4 leading-tight">Makin kecil angka bobot, makin mustahil simbol tsb turun di layar (Rarity). Ini mengontrol RTP game.</p>
                    
                    <div class="space-y-3 flex-1 overflow-y-auto pr-2">
                        <div class="flex items-center justify-between"><span class="text-2xl w-10">👑</span> <div class="flex-1 ml-4"><label class="text-[10px] font-bold text-gray-400 uppercase">Weight Crown</label><input type="number" name="w_crown" value="<?= $symbol_weights['👑'] ?>" class="w-full border p-2 rounded text-sm text-right font-mono"></div></div>
                        <div class="flex items-center justify-between"><span class="text-2xl w-10">⏳</span> <div class="flex-1 ml-4"><label class="text-[10px] font-bold text-gray-400 uppercase">Weight Hourglass</label><input type="number" name="w_hourglass" value="<?= $symbol_weights['⏳'] ?>" class="w-full border p-2 rounded text-sm text-right font-mono"></div></div>
                        <div class="flex items-center justify-between"><span class="text-2xl w-10">💍</span> <div class="flex-1 ml-4"><label class="text-[10px] font-bold text-gray-400 uppercase">Weight Ring</label><input type="number" name="w_ring" value="<?= $symbol_weights['💍'] ?>" class="w-full border p-2 rounded text-sm text-right font-mono"></div></div>
                        <div class="flex items-center justify-between"><span class="text-2xl w-10">🏆</span> <div class="flex-1 ml-4"><label class="text-[10px] font-bold text-gray-400 uppercase">Weight Cup</label><input type="number" name="w_cup" value="<?= $symbol_weights['🏆'] ?>" class="w-full border p-2 rounded text-sm text-right font-mono"></div></div>
                        <div class="flex items-center justify-between"><span class="text-2xl w-10">🔴</span> <div class="flex-1 ml-4"><label class="text-[10px] font-bold text-gray-400 uppercase">Weight Red Gem</label><input type="number" name="w_red" value="<?= $symbol_weights['🔴'] ?>" class="w-full border p-2 rounded text-sm text-right font-mono"></div></div>
                        <div class="flex items-center justify-between"><span class="text-2xl w-10">🟣</span> <div class="flex-1 ml-4"><label class="text-[10px] font-bold text-gray-400 uppercase">Weight Purple Gem</label><input type="number" name="w_purple" value="<?= $symbol_weights['🟣'] ?>" class="w-full border p-2 rounded text-sm text-right font-mono"></div></div>
                        <div class="flex items-center justify-between"><span class="text-2xl w-10">🟡</span> <div class="flex-1 ml-4"><label class="text-[10px] font-bold text-gray-400 uppercase">Weight Yellow Gem</label><input type="number" name="w_yellow" value="<?= $symbol_weights['🟡'] ?>" class="w-full border p-2 rounded text-sm text-right font-mono"></div></div>
                        <div class="flex items-center justify-between"><span class="text-2xl w-10">🟢</span> <div class="flex-1 ml-4"><label class="text-[10px] font-bold text-gray-400 uppercase">Weight Green Gem</label><input type="number" name="w_green" value="<?= $symbol_weights['🟢'] ?>" class="w-full border p-2 rounded text-sm text-right font-mono"></div></div>
                        <div class="flex items-center justify-between"><span class="text-2xl w-10">🔵</span> <div class="flex-1 ml-4"><label class="text-[10px] font-bold text-gray-400 uppercase">Weight Blue Gem (Most Commmon)</label><input type="number" name="w_blue" value="<?= $symbol_weights['🔵'] ?>" class="w-full border p-2 rounded text-sm text-right font-mono"></div></div>
                    </div>
                </div>

                <div class="col-span-1 lg:col-span-2 flex gap-4 mt-4 border-t border-slate-200 pt-6">
                    <button type="submit" name="reset_default" onclick="return confirm('Apakah Anda yakin ingin mereset semua persentase dan bobot ke pengaturan Pragmatic Play?')" class="flex-none bg-red-100 text-red-600 border border-red-200 font-bold px-6 py-4 rounded-2xl hover:bg-red-200 transition-all active:scale-95 flex justify-center items-center">
                        <i class="fa-solid fa-rotate-left"></i>
                    </button>
                    <button type="submit" name="update_settings" class="flex-1 bg-slate-900 text-white font-black text-lg py-4 rounded-2xl shadow-xl hover:bg-black transition-all active:scale-95 flex justify-center items-center">
                        <i class="fa-solid fa-cloud-arrow-up mr-2"></i> UPDATE CMS
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php else: ?>
    
    <div class="w-full flex flex-col lg:flex-row gap-6 justify-center items-start">
        
        <div class="w-full lg:w-56 flex flex-row lg:flex-col gap-4 order-2 lg:order-1 shrink-0">
            <button type="button" onclick="triggerBuySpin()" id="buySpinBtn" class="flex-1 lg:flex-none bg-green-700/80 border border-green-500 rounded-[1.5rem] p-4 flex flex-col items-center justify-center shadow-[0_0_15px_rgba(34,197,94,0.3)] hover:bg-green-600 transition-all disabled:opacity-50 disabled:grayscale relative overflow-hidden group">
                <div class="absolute inset-0 bg-white/10 opacity-0 group-hover:opacity-100 transition-opacity"></div>
                <span class="text-[10px] font-black text-green-300 tracking-widest mb-1">BELI FITUR</span>
                <span class="text-sm font-black text-white text-center leading-tight">FREE SPINS</span>
                <span class="text-xl font-black text-yellow-400 mt-2 olympus-glow" id="buySpinCostText"><?= number_format($current_bet * 100) ?></span>
            </button>
            
            <div id="dcButton" onclick="toggleDC()" class="flex-1 lg:flex-none bg-blue-900/60 border border-blue-500 rounded-[1.5rem] p-4 flex flex-col items-center justify-center shadow-[0_0_15px_rgba(59,130,246,0.3)] hover:bg-blue-800/80 transition-all cursor-pointer relative overflow-hidden group">
                <span class="text-[9px] font-black text-blue-300 tracking-widest text-center leading-tight mb-1">PELUANG GANDA<br>MENANG FITUR</span>
                <span class="text-xs font-bold text-white mt-1">TARUHAN</span>
                <span class="text-lg font-black text-yellow-400 olympus-glow" id="dcCostText"><?= number_format($current_bet * 1.25) ?></span>
                
                <div class="mt-3 w-12 h-6 bg-black/60 rounded-full p-1 transition-colors border border-white/10" id="dcToggleBg">
                    <div class="w-4 h-4 bg-gray-400 rounded-full transition-transform" id="dcToggleKnob"></div>
                </div>
            </div>
        </div>

        <div class="w-full lg:w-[680px] relative flex flex-col items-center order-1 lg:order-2 shrink-0">
            
            <div class="w-full flex justify-between items-center mb-4 bg-black/40 backdrop-blur-md p-4 rounded-3xl border border-white/10 shadow-2xl relative overflow-hidden">
                <div class="z-10 relative flex flex-col gap-1">
                    <p class="text-[10px] font-black text-gray-400 tracking-[0.2em] uppercase">VIRTUAL BALANCE</p>
                    <p class="text-2xl font-black text-green-400" id="balanceDisplay" data-raw="<?= $display_balance ?>"></p>
                    
                    <p id="fsTextWrapper" class="text-sm font-bold text-pink-400 animate-pulse mt-1 hidden-hud">🍭 SISA SPIN: <span id="fsDisplay" class="text-white text-lg">0</span></p>
                    <div id="fsMultWrapper" class="bg-red-900 border border-red-400 px-3 py-1.5 rounded-lg inline-block w-max shadow-[0_0_15px_red] mt-2 hidden-hud">
                        <p class="text-[9px] text-yellow-300 font-black tracking-widest uppercase">Celengan Petir</p>
                        <p class="text-2xl font-black text-white drop-shadow-md" id="globalMultDisplay">x<?= $current_global_mult_start ?></p>
                    </div>
                </div>
                
                <div id="fsWinWrapper" class="z-10 relative bg-black/80 border-2 border-yellow-400 p-3 rounded-xl text-center min-w-[140px] shadow-[0_0_20px_rgba(234,179,8,0.3)] hidden-hud">
                    <p class="text-[10px] text-yellow-400 uppercase font-black tracking-widest mb-1">TOTAL WIN FS</p>
                    <p class="text-2xl font-black text-white" id="fsTotalWinDisplay"><?= number_format($current_fs_pot_start) ?></p>
                </div>

                <div id="normalBetWrapper" class="z-10 relative bg-black/40 p-3 rounded-xl text-xs text-gray-300 border border-white/10 text-right">
                    Taruhan Aktif: <br><b class="text-lg text-yellow-400" id="activeBetDisplay"><?= number_format($is_dc ? ($current_bet * 1.25) : $current_bet) ?></b> Koin
                </div>
            </div>

            <div class="flex justify-between items-end mb-2 px-2 w-full max-w-[680px]">
                <div id="liveMessage" class="font-bold text-sm bg-black/60 backdrop-blur px-6 py-1.5 rounded-full border border-white/10 text-gray-300">
                    <?= $message ?: 'Semoga Kakek Zeus Memberkati Anda' ?>
                </div>
                <div class="bg-black/80 border border-yellow-500/50 px-4 py-1.5 rounded-full flex gap-2 items-center shadow-[0_0_10px_rgba(234,179,8,0.2)]">
                    <span class="text-[10px] text-gray-400 font-bold uppercase tracking-widest">Kemenangan Spin:</span>
                    <span class="text-lg font-black text-yellow-400 olympus-glow transition-all duration-300" id="tumbleWinDisplay">0</span>
                </div>
            </div>
            
            <div id="gridContainer" class="grid grid-cols-6 gap-2 bg-indigo-950/80 backdrop-blur-sm p-3 sm:p-4 rounded-[2rem] border-4 border-yellow-500 w-full grid-shadow mb-6 relative">
                <div id="fsOverlay" class="fs-overlay"><span class="text-6xl mb-4 scatter-glow">🍭🍭🍭</span><h2 id="fsOverlayText" class="text-5xl font-black text-pink-400 olympus-glow scale-in">15 FREE SPINS</h2><p class="text-white font-bold tracking-widest mt-2 scale-in">Dimenangkan!</p></div>
                <div id="summaryOverlay" class="fs-overlay" style="z-index: 60;"><h2 id="summaryTitle" class="text-4xl sm:text-6xl font-black olympus-glow scale-in text-center leading-tight">TOTAL WIN</h2><span id="summaryAmount" class="text-5xl sm:text-7xl font-black text-yellow-400 mt-2 text-glow-yellow scale-in drop-shadow-2xl">0</span></div>
                <div id="multOverlay" class="fs-overlay !bg-black/80" style="z-index: 55;"><h2 class="text-3xl sm:text-4xl font-black text-white olympus-glow scale-in mb-6">TUMBLE WIN</h2><div class="flex items-center space-x-4 scale-in bg-white/5 p-4 rounded-2xl border border-white/10"><span id="multBase" class="text-4xl sm:text-5xl font-bold text-green-400">0</span><span class="text-3xl font-bold text-white/50">X</span><span id="multOrb" class="text-5xl sm:text-6xl font-black text-red-500 mult-glow-red">0</span></div><div class="w-3/4 h-[2px] bg-gradient-to-r from-transparent via-yellow-500 to-transparent my-6"></div><span id="multTotal" class="text-6xl sm:text-7xl font-black text-yellow-400 scale-in text-glow-yellow drop-shadow-2xl">0</span></div>
                
                <div id="maxWinOverlay" class="fs-overlay" style="z-index: 70; background: rgba(0,0,0,0.95);">
                    <div class="absolute inset-0 bg-[radial-gradient(circle_at_center,_var(--tw-gradient-stops))] from-yellow-600/40 to-transparent animate-pulse"></div>
                    <h2 class="text-6xl sm:text-8xl font-black text-transparent bg-clip-text bg-gradient-to-b from-yellow-300 via-yellow-500 to-red-600 olympus-glow scale-in text-center leading-tight drop-shadow-[0_0_30px_rgba(234,179,8,1)] uppercase animate-epic">MAX WIN</h2>
                    <p class="text-white font-black tracking-[0.3em] mt-4 scale-in bg-red-600 px-6 py-2 rounded-full shadow-[0_0_20px_red] animate-pulse relative z-10">BATAS KEMENANGAN MAKSIMAL TERCAPAI!</p>
                    <span id="maxWinAmount" class="text-6xl sm:text-8xl font-black text-yellow-400 mt-6 text-glow-yellow scale-in drop-shadow-[0_0_50px_rgba(234,179,8,0.8)] relative z-10">0</span>
                </div>

                <?php for($i=0; $i<30; $i++): ?>
                    <div class="grid-cell aspect-square rounded-xl flex items-center justify-center text-3xl sm:text-4xl border shadow-inner transition-colors duration-300"><span class="symbol-text inline-block"></span></div>
                <?php endfor; ?>
            </div>

            <div class="w-full bg-black/50 backdrop-blur-md p-5 rounded-[2rem] border border-white/10 shadow-2xl">
                <form id="spinForm" onsubmit="event.preventDefault(); triggerSpin();" class="flex flex-col sm:flex-row gap-4 items-center justify-between">
                    
                    <input type="hidden" name="ajax_spin" value="1">
                    <input type="hidden" name="is_dc" id="isDcInput" value="<?= $is_dc ? '1' : '0' ?>">
                    <input type="hidden" name="is_buy_spin" id="isBuySpinInput" value="0">
                    
                    <div class="flex space-x-4 w-full sm:w-auto justify-center">
                        <div class="group">
                            <label class="block text-[10px] font-black text-gray-400 mb-1 uppercase tracking-widest pl-2">Taruhan (Min 400)</label>
                            <div class="flex items-center bg-gray-900/80 border border-yellow-600/50 rounded-2xl h-[46px] overflow-hidden">
                                <button type="button" onclick="changeBet(-400)" class="h-full px-4 text-yellow-600 hover:bg-white/10 hover:text-yellow-400 font-black transition text-lg disabled:opacity-50" id="btnMinusBet">-</button>
                                <input type="number" id="betAmountInput" name="bet_amount" value="<?= $current_bet ?>" min="400" step="400" class="w-16 bg-transparent text-yellow-400 text-center font-black focus:outline-none pointer-events-none" readonly>
                                <button type="button" onclick="changeBet(400)" class="h-full px-4 text-yellow-600 hover:bg-white/10 hover:text-yellow-400 font-black transition text-lg disabled:opacity-50" id="btnPlusBet">+</button>
                            </div>
                        </div>
                        <div class="group">
                            <label class="block text-[10px] font-black text-gray-400 mb-1 uppercase tracking-widest pl-2">Auto Spin</label>
                            <div class="flex items-center bg-gray-900/80 border border-purple-500/50 rounded-2xl h-[46px] overflow-hidden">
                                <button type="button" onclick="changeAuto(-10)" class="h-full px-4 text-purple-600 hover:bg-white/10 hover:text-purple-400 font-black transition text-lg disabled:opacity-50" id="btnMinusAuto">-</button>
                                <input type="number" id="autoSpinAmountInput" name="auto_spins" value="1" min="1" class="w-12 bg-transparent text-white text-center font-black focus:outline-none pointer-events-none" readonly>
                                <button type="button" onclick="changeAuto(10)" class="h-full px-4 text-purple-600 hover:bg-white/10 hover:text-purple-400 font-black transition text-lg disabled:opacity-50" id="btnPlusAuto">+</button>
                            </div>
                        </div>
                    </div>

                    <button type="submit" id="spinBtn" class="w-24 h-24 mx-auto mt-2 sm:mt-0 sm:ml-4 rounded-full bg-gradient-to-b from-yellow-400 to-yellow-600 border-yellow-300/50 shadow-[0_0_30px_rgba(234,179,8,0.4)] text-black font-black text-xl hover:scale-105 active:scale-95 transition-all border-4 flex items-center justify-center pointer-events-auto leading-tight text-center shrink-0">
                        SPIN
                    </button>
                </form>
            </div>

            <div id="bankForm" class="w-full mt-6 flex gap-4 opacity-70 hover:opacity-100 transition-opacity duration-300">
                <form method="POST" class="flex-1 flex bg-black/30 border border-white/10 rounded-xl overflow-hidden"><input type="number" name="topup_amount" placeholder="Nominal TopUp" min="1" required class="w-full p-2 text-xs bg-transparent text-green-400 font-bold outline-none px-4"><button type="submit" class="bg-green-600 hover:bg-green-500 text-white px-4 py-2 font-black transition"><i class="fa-solid fa-plus"></i></button></form>
                <form method="POST" class="flex-1 flex bg-black/30 border border-white/10 rounded-xl overflow-hidden"><input type="number" name="withdraw_amount" placeholder="Nominal Tarik" min="1" required class="w-full p-2 text-xs bg-transparent text-red-400 font-bold outline-none px-4"><button type="submit" class="bg-red-600 hover:bg-red-500 text-white px-4 py-2 font-black transition"><i class="fa-solid fa-minus"></i></button></form>
            </div>

        </div>

        <div class="w-full lg:w-[320px] bg-black/40 backdrop-blur-xl p-5 rounded-[2rem] border border-white/10 shadow-2xl h-fit sticky top-24 order-3 shrink-0">
            <h3 class="text-yellow-400 font-black text-center mb-4 tracking-[0.2em] border-b border-white/10 pb-3 olympus-glow text-sm">
                <i class="fa-solid fa-book-open text-white/50 mr-2"></i> PAYTABLE
            </h3>
            <div class="space-y-2">
                <?php foreach($payout_table as $sym => $tiers): ?>
                <div class="flex items-center justify-between bg-white/5 p-2 px-3 rounded-xl border border-white/5 hover:bg-white/10 transition">
                    <div class="text-3xl drop-shadow-md w-10 text-center"><?= $sym ?></div>
                    <div class="text-[10px] text-right text-gray-400 font-bold space-y-0.5">
                        <div class="flex justify-between w-24"><span>8-9</span> <span class="text-green-400">x<?= number_format($tiers[8], 2) ?></span></div>
                        <div class="flex justify-between w-24"><span>10-11</span> <span class="text-yellow-400">x<?= number_format($tiers[10], 2) ?></span></div>
                        <div class="flex justify-between w-24"><span>12+</span> <span class="text-red-400">x<?= number_format($tiers[12], 2) ?></span></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <div class="flex items-center justify-between bg-pink-900/40 p-2 px-3 rounded-xl border border-pink-500/30 mt-4 relative overflow-hidden">
                    <div class="absolute inset-0 bg-pink-500/10 animate-pulse"></div>
                    <div class="text-3xl scatter-glow w-10 text-center relative z-10">🍭</div>
                    <div class="text-[10px] text-right text-pink-300 font-bold relative z-10 space-y-0.5">
                        <div class="flex justify-between w-28"><span>4 Scatter</span> <span class="text-yellow-400">x3</span></div>
                        <div class="flex justify-between w-28"><span>5 Scatter</span> <span class="text-yellow-400">x5</span></div>
                        <div class="flex justify-between w-28"><span>6 Scatter</span> <span class="text-yellow-400">x100</span></div>
                        <div class="flex justify-between w-28 text-white mt-1 pt-1 border-t border-pink-500/30"><span>4+ SC</span> <span>15 FS</span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    </div>

    <script>
        // ==========================================
        // JS FORMATTER
        // ==========================================
        function formatNumber(num) { 
            return Math.round(Number(num)).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ","); 
        }

        let isMuted = false;
        const sfx = {
            spin: new Audio('sounds/spin.mp3'),
            pecah: new Audio('sounds/pecah.mp3'),
            scatter: new Audio('sounds/scatter.mp3'),
            multiplier: new Audio('sounds/multiplier.mp3'),
            bigwin: new Audio('sounds/bigwin.mp3'),
            sensational: new Audio('sounds/sensational.mp3')
        };

        function playAudio(name) {
            if (isMuted) return;
            try {
                let soundClone = sfx[name].cloneNode();
                soundClone.volume = 0.5;
                soundClone.play().catch(e => {});
            } catch (err) {}
        }

        function toggleAudio() {
            isMuted = !isMuted;
            const icon = document.getElementById('audioIcon');
            if (isMuted) {
                icon.className = "fa-solid fa-volume-xmark text-xl text-red-400";
            } else {
                icon.className = "fa-solid fa-volume-high text-xl";
            }
        }

        // STATE ENGINE
        let sequenceData = <?= json_encode($sequence_data) ?>;
        const initialSpinResult = <?= json_encode($spin_result) ?>;
        
        let fsLeft = <?= (int)$player['free_spins'] ?>;
        let runningFsPot = <?= (int)$player['fs_total_win'] ?>;
        
        let isAutoSpinning = false;
        let inFreeSpinMode = (fsLeft > 0 || runningFsPot > 0);
        
        let currentStep = 0;
        let isCascadePlaying = false;
        let isDoubleChance = <?= $is_dc ? 'true' : 'false' ?>;

        function updateFeatureUI() {
            let baseBet = parseInt(document.getElementById('betAmountInput').value) || 400;
            if(document.getElementById('buySpinCostText')) document.getElementById('buySpinCostText').innerText = formatNumber(baseBet * 100);
            if(document.getElementById('dcCostText')) document.getElementById('dcCostText').innerText = formatNumber(baseBet * 1.25);
            
            const bg = document.getElementById('dcToggleBg');
            const knob = document.getElementById('dcToggleKnob');
            const hiddenInput = document.getElementById('isDcInput');
            const activeBetDisplay = document.getElementById('activeBetDisplay');
            
            if (bg) {
                if (isDoubleChance) {
                    bg.classList.replace('bg-black/60', 'bg-green-500');
                    knob.classList.replace('bg-gray-400', 'bg-white');
                    knob.style.transform = 'translateX(100%)';
                    hiddenInput.value = '1';
                    if(activeBetDisplay) activeBetDisplay.innerText = formatNumber(baseBet * 1.25);
                } else {
                    bg.classList.replace('bg-green-500', 'bg-black/60');
                    knob.classList.replace('bg-white', 'bg-gray-400');
                    knob.style.transform = 'translateX(0)';
                    hiddenInput.value = '0';
                    if(activeBetDisplay) activeBetDisplay.innerText = formatNumber(baseBet);
                }
            }
        }

        function toggleDC() {
            if (inFreeSpinMode || isAutoSpinning || isCascadePlaying) return;
            isDoubleChance = !isDoubleChance;
            updateFeatureUI();
        }

        function changeBet(amount) {
            let input = document.getElementById('betAmountInput');
            let current = parseInt(input.value) || 400;
            let newValue = current + amount;
            if (newValue < 400) newValue = 400;
            input.value = newValue;
            updateFeatureUI();
        }

        function changeAuto(amount) {
            let input = document.getElementById('autoSpinAmountInput');
            let current = parseInt(input.value) || 1;
            let newValue = current;
            if(current === 1 && amount > 0) newValue = 10;
            else if(current <= 10 && amount < 0) newValue = 1;
            else newValue = current + amount;
            if (newValue < 1) newValue = 1;
            input.value = newValue;
        }

        // INIT LOAD
        const initBalElem = document.getElementById('balanceDisplay');
        if(initBalElem) initBalElem.innerText = formatNumber(initBalElem.getAttribute('data-raw'));

        function updateMessage(text, isWin = false) {
            const msgBox = document.getElementById('liveMessage');
            if(inFreeSpinMode && !isWin) {
                msgBox.innerHTML = `<span class="text-pink-400 animate-pulse"><i class="fa-solid fa-bolt"></i> FITUR FREE SPIN AKTIF <i class="fa-solid fa-bolt"></i></span>`;
            } else {
                msgBox.innerHTML = text;
            }
            if(isWin) msgBox.className = "font-bold text-sm bg-black/60 backdrop-blur px-6 py-1.5 rounded-full border border-yellow-500/50 text-yellow-400 shadow-[0_0_15px_rgba(234,179,8,0.3)] transition-all";
            else msgBox.className = "font-bold text-sm bg-black/60 backdrop-blur px-6 py-1.5 rounded-full border border-white/10 text-gray-300 transition-all";
        }

        function updateFSHud() {
            const btn = document.getElementById('spinBtn');
            
            if(inFreeSpinMode) {
                document.body.classList.add('fs-mode');
                document.getElementById('fsTextWrapper').classList.remove('hidden-hud');
                document.getElementById('fsMultWrapper').classList.remove('hidden-hud');
                document.getElementById('fsWinWrapper').classList.remove('hidden-hud');
                document.getElementById('normalBetWrapper').classList.add('hidden-hud');
                
                document.getElementById('fsDisplay').innerText = fsLeft;
                document.getElementById('fsTotalWinDisplay').innerText = formatNumber(runningFsPot);
                
                btn.innerHTML = "FREE<br>SPIN";
                btn.className = "w-24 h-24 mx-auto mt-2 sm:mt-0 sm:ml-4 rounded-full bg-gradient-to-b from-pink-500 to-pink-700 border-pink-400/50 shadow-[0_0_30px_rgba(236,72,153,0.5)] text-black font-black text-xl hover:scale-105 active:scale-95 transition-all border-4 flex items-center justify-center pointer-events-auto leading-tight text-center shrink-0";
                
                document.getElementById('bankForm').classList.add('hidden-hud');
                if(document.getElementById('buySpinBtn')) {
                    document.getElementById('buySpinBtn').classList.add('opacity-50', 'grayscale', 'pointer-events-none');
                    document.getElementById('dcButton').classList.add('opacity-50', 'pointer-events-none');
                    document.getElementById('btnMinusBet').classList.add('opacity-50', 'pointer-events-none');
                    document.getElementById('btnPlusBet').classList.add('opacity-50', 'pointer-events-none');
                    document.getElementById('btnMinusAuto').classList.add('opacity-50', 'pointer-events-none');
                    document.getElementById('btnPlusAuto').classList.add('opacity-50', 'pointer-events-none');
                }
            } else {
                document.body.classList.remove('fs-mode');
                document.getElementById('fsTextWrapper').classList.add('hidden-hud');
                document.getElementById('fsMultWrapper').classList.add('hidden-hud');
                document.getElementById('fsWinWrapper').classList.add('hidden-hud');
                document.getElementById('normalBetWrapper').classList.remove('hidden-hud');
                
                if (isAutoSpinning) {
                    btn.innerHTML = `STOP<br>(${document.getElementById('autoSpinAmountInput').value})`;
                    btn.className = "w-24 h-24 mx-auto mt-2 sm:mt-0 sm:ml-4 rounded-full bg-red-600 text-white font-black text-sm shadow-[0_0_30px_rgba(220,38,38,0.6)] hover:bg-red-500 transition animate-pulse border-4 border-red-400/30 flex items-center justify-center text-center uppercase leading-tight pointer-events-auto shrink-0";
                    document.getElementById('bankForm').classList.add('hidden-hud');
                    if(document.getElementById('buySpinBtn')) {
                        document.getElementById('buySpinBtn').classList.add('opacity-50', 'grayscale', 'pointer-events-none');
                        document.getElementById('dcButton').classList.add('opacity-50', 'pointer-events-none');
                        document.getElementById('btnMinusBet').classList.add('opacity-50', 'pointer-events-none');
                        document.getElementById('btnPlusBet').classList.add('opacity-50', 'pointer-events-none');
                        document.getElementById('btnMinusAuto').classList.add('opacity-50', 'pointer-events-none');
                        document.getElementById('btnPlusAuto').classList.add('opacity-50', 'pointer-events-none');
                    }
                } else {
                    btn.innerHTML = "SPIN";
                    btn.className = "w-24 h-24 mx-auto mt-2 sm:mt-0 sm:ml-4 rounded-full bg-gradient-to-b from-yellow-400 to-yellow-600 border-yellow-300/50 shadow-[0_0_30px_rgba(234,179,8,0.4)] text-black font-black text-xl hover:scale-105 active:scale-95 transition-all border-4 flex items-center justify-center pointer-events-auto leading-tight text-center shrink-0";
                    document.getElementById('bankForm').classList.remove('hidden-hud');
                    if(document.getElementById('buySpinBtn')) {
                        document.getElementById('buySpinBtn').classList.remove('opacity-50', 'grayscale', 'pointer-events-none');
                        document.getElementById('dcButton').classList.remove('opacity-50', 'pointer-events-none');
                        document.getElementById('btnMinusBet').classList.remove('opacity-50', 'pointer-events-none');
                        document.getElementById('btnPlusBet').classList.remove('opacity-50', 'pointer-events-none');
                        document.getElementById('btnMinusAuto').classList.remove('opacity-50', 'pointer-events-none');
                        document.getElementById('btnPlusAuto').classList.remove('opacity-50', 'pointer-events-none');
                    }
                }
            }
        }

        if(inFreeSpinMode) updateFSHud();

        // ==========================================
        // AJAX SPIN TRIGGER (ANTI RELOAD)
        // ==========================================
        async function triggerSpin(isLoop = false) {
            
            // JIKA SEDANG ANIMASI TAPI MAU MANUAL STOP AUTO SPIN
            if(isCascadePlaying) {
                if (!isLoop && isAutoSpinning && !inFreeSpinMode) {
                    isAutoSpinning = false;
                    document.getElementById('autoSpinAmountInput').value = 1;
                    updateFSHud();
                }
                return; 
            }

            let autoVal = parseInt(document.getElementById('autoSpinAmountInput').value) || 1;

            if (!isLoop) {
                // JIKA USER MANUAL CLICK
                if (isAutoSpinning && !inFreeSpinMode) {
                    // Berhentikan Auto Spin
                    isAutoSpinning = false;
                    document.getElementById('autoSpinAmountInput').value = 1;
                    updateFSHud();
                    return;
                }
                if (autoVal > 1 && !inFreeSpinMode) {
                    isAutoSpinning = true;
                    updateFSHud();
                }
            }
            
            const twDisp = document.getElementById('tumbleWinDisplay');
            if(twDisp) twDisp.innerText = '0';
            
            playAudio('spin');
            const btn = document.getElementById('spinBtn');
            
            // Kunci tombol hanya jika bukan auto spin di mode biasa
            if (!isAutoSpinning || inFreeSpinMode) {
                btn.classList.add('opacity-50', 'pointer-events-none', 'scale-95');
            }
            
            const formData = new FormData(document.getElementById('spinForm'));

            try {
                const response = await fetch('', { method: 'POST', body: formData });
                const data = await response.json();

                if (data.error) {
                    updateMessage(data.error, false);
                    btn.classList.remove('opacity-50', 'pointer-events-none', 'scale-95');
                    isAutoSpinning = false;
                    document.getElementById('autoSpinAmountInput').value = 1;
                    updateFSHud();
                    return;
                }

                // Matikan input buy spin
                document.getElementById('isBuySpinInput').value = '0';

                // SET DATA BARU
                sequenceData = data.sequence_data;
                currentStep = 0;
                finalFsPayout = data.fs_total_win; // Total mutlak dari server
                
                // PENGURANGAN AUTO SPIN LOKAL
                if (!inFreeSpinMode) {
                    // Update Saldo (Kena Potongan Bet)
                    const balDisp = document.getElementById('balanceDisplay');
                    balDisp.setAttribute('data-raw', data.new_balance); 
                    balDisp.innerText = formatNumber(data.new_balance);
                    
                    if (isAutoSpinning) {
                        let cAuto = parseInt(document.getElementById('autoSpinAmountInput').value);
                        if (cAuto > 1) {
                            document.getElementById('autoSpinAmountInput').value = cAuto - 1;
                        } else {
                            isAutoSpinning = false;
                            document.getElementById('autoSpinAmountInput').value = 1;
                        }
                    }
                } else {
                    if (fsLeft > 0) fsLeft--; 
                }
                
                updateFSHud();
                playSequence();

            } catch (err) {
                console.error(err);
                btn.classList.remove('opacity-50', 'pointer-events-none', 'scale-95');
                isAutoSpinning = false;
                updateFSHud();
            }
        }
        
        function triggerBuySpin() {
            if(isCascadePlaying || inFreeSpinMode || isAutoSpinning) return;
            let baseBet = parseInt(document.getElementById('betAmountInput').value) || 400;
            let cost = baseBet * 100;
            
            if (confirm(`Beli 15 Free Spins seharga ${formatNumber(cost)} Koin?`)) {
                document.getElementById('isBuySpinInput').value = '1';
                triggerSpin();
            }
        }

        function updateGridVisuals(gridArray, isCascade = false) {
            const cells = document.querySelectorAll('.grid-cell');
            
            gridArray.forEach((sym, index) => {
                const cell = cells[index];
                const span = cell.querySelector('.symbol-text');
                
                cell.classList.remove('symbol-drop', 'cascade-drop');
                span.classList.remove('symbol-burst', 'scatter-glow');
                void cell.offsetWidth; 
                
                cell.className = 'grid-cell aspect-square flex items-center justify-center border shadow-inner transition-all duration-300';
                span.className = 'symbol-text font-black inline-block';

                if (sym.startsWith('M')) {
                    let val = sym.substring(1);
                    cell.classList.add('rounded-full', 'border-[3px]');
                    
                    if(val >= 500) cell.classList.add('bg-red-600', 'border-red-400', 'shadow-[0_0_30px_red]', 'animate-pulse');
                    else if(val >= 50) cell.classList.add('bg-pink-600', 'border-pink-400', 'shadow-[0_0_15px_pink]');
                    else if(val >= 10) cell.classList.add('bg-green-600', 'border-green-400', 'shadow-[0_0_15px_green]');
                    else cell.classList.add('bg-blue-600', 'border-blue-400', 'shadow-[0_0_15px_blue]');
                    
                    span.classList.add('text-lg', 'sm:text-xl', 'text-white', 'drop-shadow-md');
                    span.innerText = `${val}x`;
                } else {
                    cell.classList.add('rounded-2xl', 'text-3xl', 'sm:text-4xl');
                    if (sym === '🍭') {
                        cell.classList.add('bg-pink-900/60', 'border-pink-400/50', 'shadow-[0_0_20px_rgba(236,72,153,0.4)]');
                        span.classList.add('scatter-glow');
                    } else {
                        cell.classList.add('bg-white/5', 'border-white/5');
                    }
                    span.innerText = sym;
                }

                if (isCascade) {
                    cell.classList.add('cascade-drop');
                    let col = index % 6; let row = Math.floor(index / 6);
                    cell.style.animationDelay = `${(col * 0.05) + ((4 - row) * 0.05)}s`; 
                } else {
                    cell.classList.add('symbol-drop');
                    let col = index % 6; let row = Math.floor(index / 6);
                    cell.style.animationDelay = `${(col * 0.05) + (row * 0.05)}s`;
                }
            });
        }

        function showMaxWinOverlay(amount, callback) {
            const mwOverlay = document.getElementById('maxWinOverlay');
            document.getElementById('maxWinAmount').innerText = formatNumber(amount);
            mwOverlay.classList.add('active');
            
            playAudio('sensational');
            setTimeout(() => playAudio('sensational'), 2000);
            
            setTimeout(() => {
                mwOverlay.classList.remove('active');
                setTimeout(callback, 500); 
            }, 6000);
        }

        function showWinOverlay(amount, multiplier, fallbackTitle, callback) {
            const o = document.getElementById('summaryOverlay');
            const title = document.getElementById('summaryTitle');
            const amt = document.getElementById('summaryAmount');

            if (multiplier >= 100) {
                title.innerHTML = "SENSATIONAL!!!";
                title.className = "text-5xl sm:text-7xl font-black text-glow-red text-red-500 scale-in text-center leading-tight mb-2 drop-shadow-2xl";
                playAudio('sensational');
            } else if (multiplier >= 50) {
                title.innerHTML = "MEGA WIN!";
                title.className = "text-5xl sm:text-6xl font-black text-glow-purple text-purple-400 scale-in text-center leading-tight mb-2 drop-shadow-2xl";
                playAudio('bigwin');
            } else if (multiplier >= 20) {
                title.innerHTML = "BIG WIN!";
                title.className = "text-5xl sm:text-6xl font-black text-glow-yellow text-yellow-400 scale-in text-center leading-tight mb-2 drop-shadow-2xl";
                playAudio('bigwin');
            } else {
                title.innerHTML = fallbackTitle;
                title.className = "text-3xl sm:text-5xl font-black olympus-glow text-white scale-in text-center leading-tight mb-2";
            }

            amt.innerText = formatNumber(amount);
            o.classList.add('active');
            
            setTimeout(() => {
                o.classList.remove('active');
                setTimeout(callback, 500); 
            }, 4000);
        }

        function checkEnd() {
            const last = sequenceData[sequenceData.length - 1];
            let singleSpinWin = (last && last.final_payout > 0) ? last.final_payout : 0;
            
            let cBet = parseInt(document.getElementById('betAmountInput').value) || 400;
            if (isDoubleChance) cBet = cBet * 1.25;

            let mult = singleSpinWin / cBet;

            // CEK APAKAH INI AKHIR DARI FREE SPIN
            let isEndOfFs = (fsLeft === 0 && inFreeSpinMode && currentStep >= (sequenceData.length - 1)); 

            if (last && last.is_max_win) {
                showMaxWinOverlay(singleSpinWin, () => { handlePostSpinWin(singleSpinWin, isEndOfFs); });
                return;
            }

            if (mult >= 20 && !isEndOfFs) {
                showWinOverlay(singleSpinWin, mult, "SPIN WIN", () => {
                    handlePostSpinWin(singleSpinWin, isEndOfFs);
                });
            } else {
                handlePostSpinWin(singleSpinWin, isEndOfFs);
            }
        }

        function handlePostSpinWin(singleSpinWin, isEndOfFs) {
            let cBet = parseInt(document.getElementById('betAmountInput').value) || 400;
            if (isDoubleChance) cBet = cBet * 1.25;

            if (isEndOfFs) {
                let totalMult = runningFsPot / cBet;
                showWinOverlay(runningFsPot, totalMult, "TOTAL FREE SPIN WIN", () => {
                    // SELESAI FS - TAMBAHKAN SALDO TOTAL
                    const balDisp = document.getElementById('balanceDisplay');
                    let curBal = parseFloat(balDisp.getAttribute('data-raw'));
                    let newBal = curBal + runningFsPot;
                    balDisp.setAttribute('data-raw', newBal);
                    balDisp.innerText = formatNumber(newBal);
                    
                    inFreeSpinMode = false;
                    runningFsPot = 0; 
                    document.getElementById('globalMultDisplay').innerText = 'x0';
                    updateMessage("Free Spin Selesai!", true);
                    updateFSHud();
                    
                    if (isAutoSpinning) setTimeout(() => triggerSpin(true), 1500);
                    else document.getElementById('spinBtn').classList.remove('opacity-50', 'pointer-events-none', 'scale-95');
                });
            } else {
                if (!inFreeSpinMode && singleSpinWin > 0) {
                    // KEMENANGAN SPIN NORMAL LANSUNG MASUK SALDO
                    const balDisp = document.getElementById('balanceDisplay');
                    let curBal = parseFloat(balDisp.getAttribute('data-raw'));
                    let newBal = curBal + singleSpinWin;
                    balDisp.setAttribute('data-raw', newBal);
                    balDisp.innerText = formatNumber(newBal);
                    balDisp.classList.add('text-yellow-400', 'scale-125', 'transition-all');
                    setTimeout(() => balDisp.classList.remove('text-yellow-400', 'scale-125'), 1000);
                }
                
                if (fsLeft > 0 || isAutoSpinning) {
                    setTimeout(() => triggerSpin(true), 1500);
                } else {
                    document.getElementById('spinBtn').classList.remove('opacity-50', 'pointer-events-none', 'scale-95');
                    updateFSHud();
                }
            }
        }

        function playSequence() {
            try {
                if (!sequenceData || sequenceData.length === 0) {
                    checkEnd();
                    return;
                }
                isCascadePlaying = true;
                
                const step = sequenceData[currentStep];
                let delayOffset = 0;
                
                if (currentStep > 0) updateGridVisuals(step.grid, true);
                else updateGridVisuals(step.grid, false); 

                // Tampilkan Scatter Info di Awal Putaran (Anti Spoiler)
                if (currentStep === 0 && (step.fs_awarded > 0 || step.scatter_cash > 0)) {
                    if (step.scatter_cash > 0) {
                        const twDisp = document.getElementById('tumbleWinDisplay');
                        if (twDisp) twDisp.innerText = formatNumber(step.scatter_cash);
                    }
                    
                    if (step.fs_awarded > 0) {
                        const overlay = document.getElementById('fsOverlay');
                        let textFs = inFreeSpinMode ? `+${step.fs_awarded} FREE SPINS` : `15 FREE SPINS`;
                        document.getElementById('fsOverlayText').innerHTML = textFs;
                        overlay.classList.add('active');
                        updateMessage(`🍭 SCATTER HIT! Mendapatkan ${step.fs_awarded} FS!`, true);
                        playAudio('scatter');
                        
                        setTimeout(() => {
                            overlay.classList.remove('active');
                            fsLeft += step.fs_awarded;
                            inFreeSpinMode = true; 
                            updateFSHud();
                        }, 2500);
                        delayOffset = 3000;
                    } else {
                        updateMessage(`🍭 SCATTER PAYS! Menang ${formatNumber(step.scatter_cash)} Koin`, true);
                    }
                }

                setTimeout(() => {
                    if (step.win_symbol !== '') {
                        updateMessage(`⚡ PECAH EMOTE! Menghitung kombo...`, true);
                        
                        setTimeout(() => {
                            playAudio('pecah');
                            const cells = document.querySelectorAll('.grid-cell');
                            cells.forEach(cell => {
                                const span = cell.querySelector('.symbol-text');
                                if (span && span.innerText === step.win_symbol) {
                                    cell.classList.remove('bg-white/5', 'border-white/5');
                                    cell.classList.add('bg-yellow-500/40', 'border-yellow-400', 'shadow-[0_0_15px_rgba(234,179,8,0.5)]');
                                    span.classList.add('symbol-burst');
                                }
                            });
                            
                            if (step.total_base_win > 0) {
                                const twDisp = document.getElementById('tumbleWinDisplay');
                                if(twDisp) {
                                    twDisp.innerText = formatNumber(step.total_base_win);
                                    twDisp.classList.add('scale-125', 'text-white');
                                    setTimeout(() => twDisp.classList.remove('scale-125', 'text-white'), 300);
                                }
                            }

                            setTimeout(() => {
                                currentStep++;
                                playSequence(); 
                            }, 800); 

                        }, 1200); 
                        
                    } else {
                        const lastStep = sequenceData[sequenceData.length - 1]; 
                        
                        if (lastStep.total_base_win > 0 && lastStep.spin_mult_total > 0) {
                            
                            setTimeout(() => {
                                const multOvl = document.getElementById('multOverlay');
                                if (multOvl) {
                                    document.getElementById('multBase').innerText = formatNumber(lastStep.total_base_win);
                                    
                                    let usedMult = lastStep.global_mult_used > 0 ? lastStep.global_mult_used : lastStep.spin_mult_total;
                                    document.getElementById('multOrb').innerText = `x${usedMult}`;
                                    document.getElementById('multTotal').innerText = formatNumber(lastStep.final_payout);
                                    
                                    multOvl.classList.add('active');
                                    updateMessage(`💥 TUMBLE MULTIPLIER x${usedMult}!`, true);
                                    playAudio('multiplier');
                                    
                                    const globDisplay = document.getElementById('globalMultDisplay');
                                    if(globDisplay && lastStep.global_mult_used > 0) {
                                        globDisplay.innerText = `x${lastStep.global_mult_used}`;
                                        globDisplay.parentElement.classList.add('scale-125', 'transition-all');
                                    }

                                    setTimeout(() => {
                                        multOvl.classList.remove('active');
                                        
                                        const twDisp = document.getElementById('tumbleWinDisplay');
                                        if(twDisp) twDisp.innerText = formatNumber(lastStep.final_payout);
                                        
                                        if(globDisplay) globDisplay.parentElement.classList.remove('scale-125');
                                        
                                        if (inFreeSpinMode) {
                                            runningFsPot += lastStep.final_payout;
                                            const potDisplay = document.getElementById('fsTotalWinDisplay');
                                            if (potDisplay) {
                                                potDisplay.innerText = formatNumber(runningFsPot);
                                                potDisplay.parentElement.classList.add('scale-110', 'border-green-400');
                                                setTimeout(() => potDisplay.parentElement.classList.remove('scale-110', 'border-green-400'), 500);
                                            }
                                        }

                                        setTimeout(() => {
                                            isCascadePlaying = false;
                                            checkEnd();
                                        }, 500);
                                    }, 3000); 
                                } else {
                                    isCascadePlaying = false; checkEnd();
                                }
                            }, 500); 

                        } else {
                            if (lastStep.total_base_win > 0 || lastStep.scatter_cash > 0) {
                                let totalThisRound = lastStep.final_payout || lastStep.scatter_cash;
                                updateMessage(`🎉 Putaran Selesai! Kemenangan: ${formatNumber(totalThisRound)} Koin`, true);
                                
                                if (inFreeSpinMode) {
                                    runningFsPot += totalThisRound;
                                    const potDisplay = document.getElementById('fsTotalWinDisplay');
                                    if (potDisplay) {
                                        potDisplay.innerText = formatNumber(runningFsPot);
                                        potDisplay.parentElement.classList.add('scale-105', 'border-green-400');
                                        setTimeout(() => potDisplay.parentElement.classList.remove('scale-105', 'border-green-400'), 500);
                                    }
                                }
                            } else {
                                if(currentStep === 0) updateMessage(`Belum Beruntung, coba lagi.`, false);
                            }
                            
                            setTimeout(() => {
                                isCascadePlaying = false;
                                checkEnd();
                            }, 800);
                        }
                    }
                }, delayOffset);
            } catch (error) {
                console.error("Animasi Error: ", error);
                isCascadePlaying = false; checkEnd();
            }
        }

        window.onload = function() {
            updateFeatureUI();
            
            if(initialSpinResult && initialSpinResult.length > 0 && (!sequenceData || sequenceData.length === 0)) {
                updateGridVisuals(initialSpinResult, false);
            }
        }
    </script>

</body>
</html>