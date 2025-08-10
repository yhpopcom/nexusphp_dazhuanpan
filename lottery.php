<?php
require_once("../include/bittorrent.php");
require_once("choujiangsheding.php");
dbconn();
loggedinorreturn();

$user_id = $CURUSER['id'];
$user = $CURUSER;

// VIP7天时间累计开关（true=开启累计时间，false=关闭累计转换为魔力）
$vip_stackable = true;  // 可在此处切换开关状态

// 从user_metas表获取数据的函数
function get_user_meta_deadline($uid, $meta_key) {
    $res = sql_query("SELECT deadline FROM user_metas 
                     WHERE uid = " . sqlesc($uid) . " 
                     AND meta_key = " . sqlesc($meta_key) . " 
                     AND status = 0 
                     AND deadline > NOW()");
    $row = mysqli_fetch_assoc($res);
    return $row ? $row['deadline'] : null;
}

function update_user_meta($uid, $meta_key, $new_deadline, $is_rainbow = false) {
    $res = sql_query("SELECT id FROM user_metas 
                     WHERE uid = " . sqlesc($uid) . " 
                     AND meta_key = " . sqlesc($meta_key));
    $row = mysqli_fetch_assoc($res);
    
    $status = $is_rainbow ? 0 : 1;
    
    if ($row) {
        sql_query("UPDATE user_metas 
                 SET deadline = " . sqlesc($new_deadline) . ", 
                     status = $status, 
                     updated_at = NOW() 
                 WHERE id = " . sqlesc($row['id']));
    } else {
        sql_query("INSERT INTO user_metas (uid, meta_key, status, deadline, created_at, updated_at) 
                  VALUES (" . sqlesc($uid) . ", 
                          " . sqlesc($meta_key) . ", 
                          $status, 
                          " . sqlesc($new_deadline) . ", 
                          NOW(), 
                          NOW())");
    }
}

$initial_magic = $user['seedbonus'];
$initial_uploaded = $user['uploaded'];
$initial_vip_until = $user['vip_until'];
$initial_attendance_card = $user['attendance_card'];
$initial_rainbow_id_until = get_user_meta_deadline($user_id, 'PERSONALIZED_USERNAME');

$error = '';
$results = [];
$upload_changed = false;
$magic_changed = false;
$vip_changed = false;
$attendance_card_changed = false;
$rainbow_id_changed = false;

$draw = isset($_POST['lottery_type']);
if ($draw) {
    $lottery_type = (int)$_POST['lottery_type'];
    $cost = 2000;

    if ($initial_magic < 0) {
        $error = "魔力值异常，无法抽奖";
    } elseif ($initial_magic < ($cost * $lottery_type)) {
        $error = "魔力值不足，无法抽奖";
    } elseif ($lottery_type < 1) {
        sql_query("UPDATE users SET seedbonus = seedbonus - 4000000 WHERE id = " . sqlesc($user_id));
    } elseif (!in_array($lottery_type, [1, 10, 50, 100])) {
        sql_query("UPDATE users SET seedbonus = seedbonus - 2000000 WHERE id = " . sqlesc($user_id));
    } else {
        try {
            // 设置抽奖次数
            $draw_count = 1;
            if ($lottery_type == 10) {
                $draw_count = 11;  // 10+1
            } elseif ($lottery_type == 50) {
                $draw_count = 58;  // 50+8
            } elseif ($lottery_type == 100) {
                $draw_count = 115; // 100+15
            }
            
            $total_cost = $cost * $lottery_type;
            sql_query("UPDATE users SET seedbonus = seedbonus - $total_cost WHERE id = " . sqlesc($user_id));

            // 关键修复：维护实时更新的VIP到期时间
            $current_vip_until = $user['vip_until'];
            $current_rainbow_id_until = $initial_rainbow_id_until;

            for ($i = 0; $i < $draw_count; $i++) {
                // 传入当前最新的时间而非初始值
                list($result, $current_vip_until, $current_rainbow_id_until) = process_lottery(
                    $user_id, 
                    $user['class'], 
                    $current_vip_until, 
                    $current_rainbow_id_until,
                    $vip_stackable
                );
                $results[] = $result;
            }

            $user_query = sql_query("SELECT * FROM users WHERE id = " . sqlesc($user_id));
            $user = mysqli_fetch_assoc($user_query);
            $new_rainbow_id_until = get_user_meta_deadline($user_id, 'PERSONALIZED_USERNAME');

            $upload_changed = $user['uploaded'] != $initial_uploaded;
            $magic_changed = $user['seedbonus'] != $initial_magic;
            $vip_changed = $user['vip_until'] != $initial_vip_until;
            $attendance_card_changed = $user['attendance_card'] != $initial_attendance_card;
            $rainbow_id_changed = $new_rainbow_id_until != $initial_rainbow_id_until;
        } catch (Exception $e) {
            $error = "抽奖过程中发生错误：" . $e->getMessage();
        }
    }
}


function process_lottery($user_id, $user_class, $current_vip_until, $current_rainbow_id_until, $vip_stackable) {
    $config = include('choujiangsheding.php');
    $category = select_category($config['probabilities']);
    $prize = select_prize($config['prizes'][$category], $config['category_probabilities'][$category]);
    // 处理奖励并返回更新后的时间
    list($result, $new_vip_until, $new_rainbow_until) = process_prize(
        $user_id, 
        $user_class, 
        $current_vip_until, 
        $current_rainbow_id_until,
        $category, 
        $prize, 
        $vip_stackable
    );
    return [$result, $new_vip_until, $new_rainbow_until];
}

function select_category($probabilities) {
    $random = mt_rand() / mt_getrandmax();
    $cumulative_probability = 0;

    foreach ($probabilities as $category => $probability) {
        $cumulative_probability += $probability;
        if ($random <= $cumulative_probability) {
            return $category;
        }
    }
    return array_key_last($probabilities);
}

function select_prize($prizes, $probabilities) {
    $random = mt_rand() / mt_getrandmax();
    $cumulative_probability = 0;

    foreach ($prizes as $index => $prize) {
        $cumulative_probability += $probabilities[$index];
        if ($random <= $cumulative_probability) {
            return $prize;
        }
    }
    return end($prizes);
}

function process_prize($user_id, $user_class, $current_vip_until, $current_rainbow_id_until, $category, $prize, $vip_stackable) {
    $new_vip_until = $current_vip_until;
    $new_rainbow_until = $current_rainbow_id_until;

    switch ($category) {
        case 'upload':
            $upload_increase = $prize['value'] * 1024 * 1024 * 1024;
            sql_query("UPDATE users SET uploaded = uploaded + $upload_increase WHERE id = " . sqlesc($user_id));
            return [$prize['name'], $new_vip_until, $new_rainbow_until];

        case 'magic':
            $bonus_increase = $prize['value'];
            sql_query("UPDATE users SET seedbonus = seedbonus + $bonus_increase WHERE id = " . sqlesc($user_id));
            return [$prize['name'], $new_vip_until, $new_rainbow_until];

        case 'special':
            switch ($prize['name']) {
                case '临时邀请':
                    $hash = make_invite_code();
                    sql_query("INSERT INTO invites (inviter, hash, time_invited, valid, expired_at) VALUES (" . sqlesc($user_id) . ", " . sqlesc($hash) . ", NOW(), 1, DATE_ADD(NOW(), INTERVAL " . $prize['value'] . " DAY))");
                    return [$prize['name'], $new_vip_until, $new_rainbow_until];

                case '补签卡':
                    sql_query("UPDATE users SET attendance_card = attendance_card + 1 WHERE id = " . sqlesc($user_id));
                    return [$prize['name'], $new_vip_until, $new_rainbow_until];

                case '7天VIP':
                    $is_current_vip = $user_class >= 10 || (strtotime($current_vip_until) > time());
                    
                    if ($is_current_vip) {
                        if ($vip_stackable) {
                            // 基于当前最新时间叠加
                            $new_vip_until = date("Y-m-d H:i:s", strtotime($current_vip_until . " +7 days"));
                            sql_query("UPDATE users SET vip_until = '$new_vip_until' WHERE id = " . sqlesc($user_id));
                            return ["7天VIP（时间累计）", $new_vip_until, $new_rainbow_until];
                        } else {
                            sql_query("UPDATE users SET seedbonus = seedbonus + 100000 WHERE id = " . sqlesc($user_id));
                            return ["7天VIP（已转换为10W魔力值）", $new_vip_until, $new_rainbow_until];
                        }
                    } else {
                        $new_vip_until = date("Y-m-d H:i:s", strtotime("+7 days"));
                        sql_query("UPDATE users SET class = '10', vip_until = '$new_vip_until',`vip_added` = 'yes' WHERE id = " . sqlesc($user_id));
                        return [$prize['name'], $new_vip_until, $new_rainbow_until];
                    }
                    
                case '彩虹ID(7天)':
                    $current_time = time();
                    $existing_rainbow_end = $current_rainbow_id_until ? strtotime($current_rainbow_id_until) : 0;
                    
                    if ($existing_rainbow_end > $current_time) {
                        $new_rainbow_until = date("Y-m-d H:i:s", strtotime("+7 days", $existing_rainbow_end));
                    } else {
                        $new_rainbow_until = date("Y-m-d H:i:s", strtotime("+7 days"));
                    }
                    
                    update_user_meta($user_id, 'PERSONALIZED_USERNAME', $new_rainbow_until, true);
                    return [
                        $prize['name'] . " (累计至: " . date("Y/m/d", strtotime($new_rainbow_until)) . ")",
                        $new_vip_until,
                        $new_rainbow_until
                    ];

                case '谢谢惠顾':
                    return [$prize['name'], $new_vip_until, $new_rainbow_until];
            }
    }
    return ["未中奖", $new_vip_until, $new_rainbow_until];
}

function format_bytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function make_invite_code() {
    return md5(uniqid(rand(), true));
}

if (!$draw) {
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>幸运大转盘</title>
    <style>
        :root {
            --primary: #e63946;
            --secondary: #ffb703;
            --accent: #1d3557;
            --light: #f1faee;
            --dark: #0d1b2a;
            --success: #4cc9f0;
            --rainbow: linear-gradient(90deg, #ff0000, #ff9a00, #d0de21, #4fdc4a, #3fdad8, #2fc9e2, #1c7fee, #5f15f2, #ba0cf8, #fb07d9, #ff0000);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f8f9fa;
            background-image: 
                radial-gradient(circle at 10% 20%, rgba(230, 57, 70, 0.05) 0%, transparent 20%),
                radial-gradient(circle at 90% 80%, rgba(255, 183, 3, 0.05) 0%, transparent 20%);
            color: var(--dark);
            line-height: 1.6;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        header {
            text-align: center;
            margin-bottom: 30px;
            padding: 20px 0;
            background: linear-gradient(135deg, var(--accent), var(--dark));
            border-radius: 12px;
            color: white;
        }

        header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .rainbow-text {
            background: var(--rainbow);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            background-size: 400% 100%;
            animation: rainbow 5s linear infinite;
            display: inline-block;
        }

        @keyframes rainbow {
            0% { background-position: 0% 50%; }
            100% { background-position: 400% 50%; }
        }

        header p {
            color: rgba(255,255,255,0.8);
            max-width: 800px;
            margin: 0 auto;
        }

        .intro {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .gif-container {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin: 20px 0;
            flex-wrap: wrap;
        }

        .gif-container img {
            border-radius: 8px;
            max-height: 150px;
            box-shadow: 0 3px 8px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }

        .gif-container img:hover {
            transform: scale(1.05);
        }

        .lottery-form {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 30px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .lottery-form label {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 15px;
            display: block;
            color: var(--accent);
        }

        .btn-group {
            display: flex;
            justify-content: center;
            gap: 20px;
            flex-wrap: wrap;
            margin: 20px 0;
        }

        .lottery-btn {
            background: linear-gradient(135deg, var(--primary), #d62828);
            color: white;
            border: none;
            padding: 12px 30px;
            font-size: 1.1rem;
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 10px rgba(230, 57, 70, 0.3);
        }

        .lottery-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(230, 57, 70, 0.4);
        }

        .lottery-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .prizes {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .prizes h2 {
            color: var(--accent);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--secondary);
            display: inline-block;
        }

        .prize-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
        }

        .prize-card {
            background: var(--light);
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border-left: 4px solid var(--secondary);
        }

        .prize-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .prize-card.upload {
            border-left-color: var(--success);
        }

        .prize-card.magic {
            border-left-color: var(--primary);
        }

        .prize-card.special {
            border-left-color: var(--accent);
        }

        .prize-card.rainbow {
            border-left: 4px solid transparent;
            background-clip: padding-box;
            position: relative;
            overflow: hidden;
        }
        
        .prize-card.rainbow::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: var(--rainbow);
            background-size: 400% 100%;
            z-index: -1;
            animation: rainbow 5s linear infinite;
            opacity: 0.1;
        }

        .user-info {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .user-info p {
            margin: 8px 0;
            font-size: 1.05rem;
        }

        .results {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .results h2 {
            color: var(--primary);
            margin-bottom: 15px;
        }

        .result-list {
            list-style: none;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
        }

        .result-list li {
            background: var(--light);
            padding: 8px 15px;
            border-radius: 20px;
            transition: all 0.2s ease;
        }

        .result-list li.rainbow-prize {
            background: linear-gradient(90deg, rgba(255,0,0,0.1), rgba(255,153,0,0.1), rgba(208,222,33,0.1), rgba(79,220,74,0.1), rgba(63,218,216,0.1), rgba(47,201,226,0.1), rgba(28,127,238,0.1), rgba(95,21,242,0.1));
            border: 1px solid rgba(255,255,255,0.5);
        }

        .result-list li:hover {
            background: #e9f5ff;
        }

        .error {
            color: var(--primary);
            font-weight: bold;
            padding: 15px;
            background: #ffebee;
            border-radius: 8px;
            margin: 15px 0;
            text-align: center;
        }

        .change-highlight {
            background: linear-gradient(120deg, rgba(255, 183, 3, 0.2) 0%, rgba(255, 183, 3, 0) 100%);
            padding: 2px 4px;
            border-radius: 4px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(1440deg); }
        }

        .spinning {
            animation: spin 6s cubic-bezier(0.17, 0.67, 0.12, 0.99) forwards;
        }

        .wheel-container {
            width: 300px;
            height: 300px;
            margin: 30px auto;
            position: relative;
            perspective: 1000px;
        }

        .wheel {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            position: relative;
            overflow: hidden;
            border: 8px solid var(--accent);
            box-shadow: 0 0 20px rgba(0,0,0,0.2);
        }

        .wheel-section {
            position: absolute;
            width: 50%;
            height: 50%;
            transform-origin: bottom right;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .wheel-section-content {
            position: absolute;
            width: 200%;
            text-align: center;
            transform-origin: bottom left;
            font-weight: bold;
            padding: 10px;
            color: white;
            text-shadow: 0 1px 2px rgba(0,0,0,0.5);
        }

        .wheel-pointer {
            position: absolute;
            top: -20px;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 0;
            border-left: 20px solid transparent;
            border-right: 20px solid transparent;
            border-top: 30px solid var(--secondary);
            z-index: 10;
        }

        @media (max-width: 768px) {
            header h1 {
                font-size: 2rem;
            }
            
            .btn-group {
                flex-direction: column;
                align-items: center;
            }
            
            .lottery-btn {
                width: 80%;
            }
            
            .wheel-container {
                width: 250px;
                height: 250px;
            }
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form');
            const buttons = document.querySelectorAll('button[name="lottery_type"]');
            const lockTime = 10000;
            const wheel = document.querySelector('.wheel');
            
            initWheel();

            let lastClick = localStorage.getItem('lastLotteryClick');
            if (lastClick) {
                const diff = Date.now() - lastClick;
                if (diff < lockTime) {
                    disableButtons(lockTime - diff);
                }
            }

            form.addEventListener('submit', async function (e) {
                e.preventDefault();
                const clickedButton = e.submitter;
                const lotteryType = clickedButton.value;

                if (lastClick && Date.now() - lastClick < lockTime) {
                    alert('请等待冷却时间结束');
                    return;
                }

                document.getElementById('change').textContent = '';
                wheel.classList.add('spinning');

                document.getElementById("lottery_type").value = lotteryType;

                localStorage.setItem('lastLotteryClick', Date.now());
                disableButtons(lockTime);

                const formData = new FormData(this);
                const fetchData = fetch("/lottery.php", {
                    method: 'POST',
                    body: formData,
                }).then(response => response.text());

                Promise.all([
                    fetchData,
                    new Promise(resolve => setTimeout(resolve, 6000))
                ]).then(resolved => {
                    wheel.classList.remove('spinning');
                    document.getElementById('data').innerHTML = resolved[0];
                    document.getElementById('change').scrollIntoView({ behavior: 'smooth' });
                    
                    document.querySelectorAll('.result-list li').forEach(li => {
                        if (li.textContent.includes('彩虹ID')) {
                            li.classList.add('rainbow-prize');
                        }
                    });
                }).catch(error => {
                    wheel.classList.remove('spinning');
                    alert(error.message);
                });
            });

            function disableButtons(remaining) {
                buttons.forEach(btn => {
                    btn.disabled = true;
                    btn.innerHTML = `请等待 (${Math.ceil(remaining / 1000)}秒)`;
                });
                
                const timer = setInterval(() => {
                    remaining -= 1000;
                    if (remaining <= 0) {
                        clearInterval(timer);
                        enableButtons();
                        return;
                    }
                    buttons.forEach(btn => {
                        btn.innerHTML = `请等待 (${Math.ceil(remaining / 1000)}秒)`;
                    });
                }, 1000);
            }

            function enableButtons() {
                buttons.forEach(btn => {
                    btn.disabled = false;
                    btn.innerHTML = getButtonText(btn.value);
                });
                localStorage.removeItem('lastLotteryClick');
            }

            function getButtonText(value) {
                switch(value) {
                    case '1': return '单抽 (2000魔力)';
                    case '10': return '10连抽 (送1抽)';
                    case '50': return '50连抽 (送8抽)';  // 新增50次按钮文本
                    case '100': return '100连抽 (送15抽)';
                    default: return '抽奖';
                }
            }

            function initWheel() {
                const sections = [
                    { color: '#e63946', text: '1G上传' },
                    { color: '#f1faee', text: '500魔力', textColor: '#000' },
                    { color: '#457b9d', text: '补签卡' },
                    { color: '#1d3557', text: '谢谢惠顾' },
                    { color: '#ffb703', text: '5G上传' },
                    { color: '#a8dadc', text: '1000魔力', textColor: '#000' },
                    { color: '#4cc9f0', text: '7天VIP' },
                    { color: '#f1faee', text: '临时邀请', textColor: '#000' },
                    { color: '#1d3557', text: '10G上传' },
                    { color: '#e63946', text: '2000魔力' },
                    { color: '#457b9d', text: '彩虹ID(7天)' },
                    { color: '#ffb703', text: '20G上传' },
                    { color: '#a8dadc', text: '5000魔力', textColor: '#000' },
                    { color: '#1d3557', text: '100G上传' },
                    { color: '#e63946', text: '10000魔力' }
                ];

                const wheel = document.querySelector('.wheel');
                const sliceAngle = 360 / sections.length;

                sections.forEach((section, index) => {
                    const angle = index * sliceAngle;
                    const sectionEl = document.createElement('div');
                    sectionEl.className = 'wheel-section';
                    sectionEl.style.transform = `rotate(${angle}deg)`;
                    sectionEl.style.background = section.color;

                    const contentEl = document.createElement('div');
                    contentEl.className = 'wheel-section-content';
                    contentEl.style.transform = `rotate(45deg)`;
                    contentEl.style.top = '100%';
                    contentEl.style.left = '0';
                    contentEl.style.color = section.textColor || 'white';
                    contentEl.textContent = section.text;

                    if (section.text === '彩虹ID(7天)') {
                        contentEl.style.background = 'var(--rainbow)';
                        contentEl.style.backgroundSize = '400% 100%';
                        contentEl.style.animation = 'rainbow 5s linear infinite';
                        contentEl.style.webkitBackgroundClip = 'text';
                        contentEl.style.backgroundClip = 'text';
                        contentEl.style.color = 'transparent';
                    }

                    sectionEl.appendChild(contentEl);
                    wheel.appendChild(sectionEl);
                });
            }
        });
    </script>
</head>
<body>
    <div class="container">
        <header>
            <h1>幸运大转盘</h1>
            <p>转动转盘赢取丰厚奖励！新增<span class="rainbow-text">彩虹ID(7天)</span>特殊道具，支持累计叠加</p>
        </header>

        <div class="intro">
            <p>说明：每次转动大转盘需要消耗2000魔力值。您可以选择单次、10次、50次或100次抽奖。<br>
            10次抽奖额外获得1次抽奖机会，50次额外获得8次，100次抽奖额外获得15次抽奖机会。<br>
            所有时效类道具（包括彩虹ID）均可累计叠加时间！</p>
            
            <div class="gif-container">
                <img src="pic/dazhuanpan_1.gif" alt="转盘动画1">
                <img src="pic/dazhuanpan_2.gif" alt="转盘动画2">
                <img src="pic/dazhuanpan_3.gif" alt="转盘动画3">
            </div>
        </div>

        <div class="lottery-form">
            <div class="wheel-container">
                <div class="wheel"></div>
                <div class="wheel-pointer"></div>
            </div>
            
            <form method="POST">
                <label>选择抽奖类型：</label>
                <input type="hidden" name="lottery_type" id="lottery_type">
                <div class="btn-group">
                    <button type="submit" name="lottery_type" value="1" class="lottery-btn">单抽 (2000魔力)</button>
                    <button type="submit" name="lottery_type" value="10" class="lottery-btn">10连抽 (送1抽)</button>
                    <button type="submit" name="lottery_type" value="50" class="lottery-btn">50连抽 (送8抽)</button>
                    <button type="submit" name="lottery_type" value="100" class="lottery-btn">100连抽 (送15抽)</button>
                </div>
            </form>
        </div>

        <div class="prizes">
            <h2>奖品展示</h2>
            <div class="prize-grid">
                <div class="prize-card upload">
                    <h3>上传量奖励</h3>
                    <p>1G、5G、10G、20G、100G</p>
                </div>
                <div class="prize-card magic">
                    <h3>魔力值奖励</h3>
                    <p>500、1000、2000、5000、10000、100000</p>
                </div>
                <div class="prize-card special">
                    <h3>特殊奖励</h3>
                    <p>临时邀请(3天)、补签卡、7天VIP</p>
                </div>
                <div class="prize-card rainbow">
                    <h3><span class="rainbow-text">彩虹ID(7天)</span></h3>
                    <p>特殊展示效果，可累计叠加时间</p>
                </div>
                <div class="prize-card special">
                    <h3>参与奖励</h3>
                    <p>谢谢惠顾</p>
                </div>
            </div>
        </div>

        <div class="user-info">
            <h2>我的状态</h2>
            <p>当前魔力值(now bonus)：<?php echo htmlspecialchars($user['seedbonus']); ?></p>
            <p>当前上传量(now uploaded)：<?php echo format_bytes($user['uploaded']); ?></p>
            <p>当前VIP到期时间(now vip out time)：<?php echo ($user['vip_until'] ? date("Y/m/d", strtotime($user['vip_until'])) : "无"); ?></p>
            <p>当前补签卡数量(now attendance card)：<?php echo htmlspecialchars($user['attendance_card']); ?></p>
            <p>彩虹ID到期时间：<?php 
                $rainbow_end = $initial_rainbow_id_until ? strtotime($initial_rainbow_id_until) : 0;
                echo ($rainbow_end > time() ? date("Y/m/d", $rainbow_end) : "无"); 
            ?></p>
        </div>

        <div id="data">
            <?php } ?>

            <?php if ($error): ?>
                <div class="results">
                    <p class="error"><?php echo htmlspecialchars($error); ?></p>
                </div>
            <?php endif; ?>

            <div id="change">
                <?php if (!empty($results)): ?>
                    <div class="results">
                        <h2>变动情况(change)：</h2>
                        <?php if ($upload_changed): ?>
                            <p>上传量(uploaded)：<span class="change-highlight"><?php echo format_bytes($initial_uploaded); ?></span> => <span class="change-highlight"><?php echo format_bytes($user['uploaded']); ?></span></p>
                        <?php endif; ?>
                        <?php
                        if ($magic_changed): ?>
                            <p>魔力值(bonus)：<span class="change-highlight"><?php echo number_format($initial_magic, 1); ?></span> => <span class="change-highlight"><?php echo number_format($user['seedbonus'], 1); ?></span></p>
                        <?php endif; ?>
                        <?php if ($vip_changed): ?>
                            <?php if ($initial_vip_until): ?>
                                <p>VIP有效期(vip time)：<span class="change-highlight"><?php echo date("Y/m/d", strtotime($initial_vip_until)); ?></span> => <span class="change-highlight"><?php echo ($user['vip_until'] ? date("Y/m/d", strtotime($user['vip_until'])) : "无"); ?></span></p>
                            <?php else: ?>
                                <p>VIP有效期：<span class="change-highlight">无(not vip)</span> => <span class="change-highlight"><?php echo ($user['vip_until'] ? date("Y/m/d", strtotime($user['vip_until'])) : "无"); ?></span></p>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($attendance_card_changed): ?>
                            <p>补签卡(attendance card)：<span class="change-highlight"><?php echo $initial_attendance_card; ?></span> => <span class="change-highlight"><?php echo $user['attendance_card']; ?></span></p>
                        <?php endif; ?>
                        <?php
                        if ($rainbow_id_changed): ?>
                            <?php 
                                $initial_rainbow_end = $initial_rainbow_id_until ? strtotime($initial_rainbow_id_until) : 0;
                                $new_rainbow_end = get_user_meta_deadline($user_id, 'PERSONALIZED_USERNAME') ? strtotime(get_user_meta_deadline($user_id, 'PERSONALIZED_USERNAME')) : 0;
                            ?>
                            <p>彩虹ID有效期：<span class="change-highlight"><?php echo ($initial_rainbow_end > time() ? date("Y/m/d", $initial_rainbow_end) : "无"); ?></span> => <span class="change-highlight"><?php echo ($new_rainbow_end > time() ? date("Y/m/d", $new_rainbow_end) : "无"); ?></span></p>
                        <?php endif; ?>

                        <h2>抽奖结果：</h2>
                        <ul class="result-list">
                            <?php foreach ($results as $result): ?>
                                <li<?php echo strpos($result, '彩虹ID') !== false ? ' class="rainbow-prize"' : ''; ?>>
                                    <?php echo htmlspecialchars($result); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>

            <?php
            $errors = error_get_last();
            if ($errors !== NULL) {
                echo "<div class='results'><p class='error'>PHP错误：" . htmlspecialchars(print_r($errors, true)) . "</p></div>";
            }
            if (!$draw) {
            ?>
        </div>
    </div>
</body>
</html>
<?php } ?>
