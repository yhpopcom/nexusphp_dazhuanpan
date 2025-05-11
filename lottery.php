<?php
require_once("../include/bittorrent.php");
require_once("choujiangsheding.php");
dbconn();
loggedinorreturn();

$user_id = $CURUSER['id'];
$user = $CURUSER;

$initial_magic = $user['seedbonus'];
$initial_uploaded = $user['uploaded'];
$initial_vip_until = $user['vip_until'];
$initial_attendance_card = $user['attendance_card'];

$error = '';
$results = [];
$upload_changed = false;
$magic_changed = false;
$vip_changed = false;
$attendance_card_changed = false;

// 处理抽奖请求
$draw = isset($_POST['lottery_type']);
if ($draw) {
    $lottery_type = (int)$_POST['lottery_type']; // 转换为整数确保类型一致
    $cost = 2000; // 每次抽奖的魔力值成本

    // 如果魔力值低于0，禁止抽奖
    if ($initial_magic < 0) {
        $error = "魔力值异常，无法抽奖";
    }

    // 检查用户的魔力值是否足够
    elseif ($initial_magic < ($cost * $lottery_type)) {
        $error = "魔力值不足，无法抽奖";
    } elseif ($lottery_type < 1) {
        sql_query("UPDATE users SET seedbonus = seedbonus - 4000000 WHERE id = " . sqlesc($user_id));
    } elseif (!in_array($lottery_type, [1, 10, 100])) {
        sql_query("UPDATE users SET seedbonus = seedbonus - 2000000 WHERE id = " . sqlesc($user_id));
    } else {
        try {
            // 计算需要抽奖的次数
            $draw_count = 1;
            if ($lottery_type == 10) {
                $draw_count = 11; // 10抽送1抽，共11次
            } elseif ($lottery_type == 100) {
                $draw_count = 115; // 100抽送15抽，共115次
            }
            
            // 扣除魔力值
            $total_cost = $cost * $lottery_type;
            sql_query("UPDATE users SET seedbonus = seedbonus - $total_cost WHERE id = " . sqlesc($user_id));

            // 处理抽奖逻辑
            for ($i = 0; $i < $draw_count; $i++) {
                $result = process_lottery($user_id, $user['class'], $user['vip_until']);
                $results[] = $result;
            }

            // 更新用户信息
            $user_query = sql_query("SELECT * FROM users WHERE id = " . sqlesc($user_id));
            $user = mysqli_fetch_assoc($user_query);

            // 检查是否有变动
            $upload_changed = $user['uploaded'] != $initial_uploaded;
            $magic_changed = $user['seedbonus'] != $initial_magic;
            $vip_changed = $user['vip_until'] != $initial_vip_until;
            $attendance_card_changed = $user['attendance_card'] != $initial_attendance_card;
        } catch (Exception $e) {
            $error = "抽奖过程中发生错误：" . $e->getMessage();
        }
    }
}


// 抽奖结果处理函数
function process_lottery($user_id, $user_class, $user_vip_until) {
    $config = include('choujiangsheding.php');

    // 选择奖品类别
    $category = select_category($config['probabilities']);

    // 选择具体奖品
    $prize = select_prize($config['prizes'][$category], $config['category_probabilities'][$category]);

    // 处理奖品
    $result = process_prize($user_id, $user_class, $user_vip_until, $category, $prize);

    return $result;
}

// 选择奖品类别
function select_category($probabilities) {
    $random = mt_rand() / mt_getrandmax();
    $cumulative_probability = 0;

    foreach ($probabilities as $category => $probability) {
        $cumulative_probability += $probability;
        if ($random <= $cumulative_probability) {
            return $category;
        }
    }

    // 理论上不会到达这里，但为了安全起见
    return array_key_last($probabilities);
}

// 选择具体奖品
function select_prize($prizes, $probabilities) {
    $random = mt_rand() / mt_getrandmax();
    $cumulative_probability = 0;

    foreach ($prizes as $index => $prize) {
        $cumulative_probability += $probabilities[$index];
        if ($random <= $cumulative_probability) {
            return $prize;
        }
    }

    // 理论上不会到达这里，但为了安全起见
    return end($prizes);
}

// 处理奖品
function process_prize($user_id, $user_class, $user_vip_until, $category, $prize) {
    switch ($category) {
        case 'upload':
            $upload_increase = $prize['value'] * 1024 * 1024 * 1024;
            sql_query("UPDATE users SET uploaded = uploaded + $upload_increase WHERE id = " . sqlesc($user_id));
            return $prize['name'];

        case 'magic':
            $bonus_increase = $prize['value'];
            sql_query("UPDATE users SET seedbonus = seedbonus + $bonus_increase WHERE id = " . sqlesc($user_id));
            return $prize['name'];

        case 'special':
            switch ($prize['name']) {
                case '临时邀请':
                    $hash = make_invite_code();
                    sql_query("INSERT INTO invites (inviter, hash, time_invited, valid, expired_at) VALUES (" . sqlesc($user_id) . ", " . sqlesc($hash) . ", NOW(), 1, DATE_ADD(NOW(), INTERVAL " . $prize['value'] . " DAY))");
                    return $prize['name'];

                case '补签卡':
                    sql_query("UPDATE users SET attendance_card = attendance_card + 1 WHERE id = " . sqlesc($user_id));
                    return $prize['name'];

                case '7天VIP':
                    if ($user_class >= 10) {
                        sql_query("UPDATE users SET seedbonus = seedbonus + 100000 WHERE id = " . sqlesc($user_id));
                        return "7天VIP 因为用户已经是VIP或等级高于VIP，已兑换为10W魔力值";
                    } else {
                        $new_vip_until = $user_vip_until && strtotime($user_vip_until) > time()
                            ? date("Y-m-d H:i:s", strtotime($user_vip_until . " +" . $prize['value'] . " days"))
                            : date("Y-m-d H:i:s", strtotime("+" . $prize['value'] . " days"));

                        sql_query("UPDATE users SET class = '10', vip_until = '$new_vip_until',`vip_added` = 'yes' WHERE id = " . sqlesc($user_id));
                        return $prize['name'];
                    }

                case '谢谢惠顾':
                    return $prize['name'];
            }
    }

    return "未中奖";
}

// 格式化字节数
function format_bytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);

    $bytes /= pow(1024, $pow);

    return round($bytes, $precision) . ' ' . $units[$pow];
}

// 生成邀请码
function make_invite_code() {
    return md5(uniqid(rand(), true));
}

// 开始输出HTML
if (!$draw) {
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>大转盘（大嘘）</title>
    <style>
        html {
            perspective: 1000px;
        }
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            transform-style: preserve-3d;
            transform-origin: center;
        }
        h1 {
            color: #333;
        }
        p {
            color: #666;
        }
        ul {
            list-style-type: none;
            padding: 0;
        }
        li {
            margin-bottom: 5px;
        }
        form {
            margin-top: 20px;
        }
        input[type="submit"] {
            background-color: #4CAF50;
            color: white;
            padding: 10px 15px;
            border: none;
            cursor: pointer;
        }
        input[type="submit"]:hover {
            background-color: #45a049;
        }
        select {
            padding: 5px;
        }
        .error {
            color: red;
            font-weight: bold;
        }

        .spin3d {
            animation: spin3d 1s infinite linear;
        }

        @keyframes spin3d {
            0% {
                transform: translateZ(0px) rotateY(0deg);
            }
            50% {
                transform: translateZ(-100vh) rotateY(180deg);
            }
            100% {
                transform: translateZ(0px) rotateY(360deg);
            }
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var form = document.querySelector('form');
            var buttons = document.querySelectorAll('button[name="lottery_type"]');
            var lockTime = 10000; // 10秒锁定时间

            // 初始化检查锁定状态
            var lastClick = localStorage.getItem('lastLotteryClick');
            if (lastClick) {
                var diff = Date.now() - lastClick;
                if (diff < lockTime) {
                    disableButtons(lockTime - diff);
                }
            }

            // 表单提交事件
            form.addEventListener('submit', async function (e) {
                e.preventDefault(); // 阻止默认提交

                // 获取点击的按钮的值
                var clickedButton = e.submitter;
                var lotteryType = clickedButton.value;

                // 检查是否在冷却期内
                if (lastClick && Date.now() - lastClick < lockTime) {
                    alert('请等待冷却时间结束');
                    return;
                }

                document.getElementById('change').textContent = '';
                document.body.classList.add('spin3d');

                // 添加隐藏字段以确保参数传递
                var hiddenInput = document.getElementById("lottery_type");
                hiddenInput.value = lotteryType;

                // 记录点击时间并禁用按钮
                localStorage.setItem('lastLotteryClick', Date.now());
                disableButtons(lockTime);

                const formData = new FormData(this);
                const fetchData = fetch("/lottery.php", {
                    method: 'POST',
                    body: formData,
                }).then((response) => {
                    return response.text();
                });
                Promise.all([
                    fetchData,
                    new Promise(resolve => setTimeout(resolve, 6000))
                ]).then((resolved) => {
                    document.body.classList.remove('spin3d');
                    document.getElementById('data').innerHTML = resolved[0];
                    document.getElementById('change').scrollIntoView();
                }).catch((error) => {
                    document.body.classList.remove('spin3d');
                    alert(error.message);
                });
            });

            // 禁用按钮及倒计时逻辑（保持不变）
            function disableButtons(remaining) {
                buttons.forEach(btn => {
                    btn.disabled = true;
                    btn.innerHTML = `请等待 (${Math.ceil(remaining / 1000)}秒)`;
                });
                var timer = setInterval(() => {
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
                    case '1': return '单抽';
                    case '10': return '10连抽';
                    case '100': return '100连抽';
                    default: return '抽奖';
                }
            }
        });
    </script>
</head>
<body>
    <h1>大转盘（大嘘）</h1>
    <p>说明：每次转动大转盘需要消耗2000魔力值。您可以选择单次、10次或100次抽奖。10次抽奖额外获得1次抽奖机会，100次抽奖额外获得15次抽奖机会。<br>
NOTE: Each draw costs 2000 bonus. You can choose between a single, 10, or 100 draw. 10 draws get 1 extra entry and 100 draws get 15 extra entries.<br>另外由于站点缺乏美工，所以请自行脑补大转盘转转转的画面，或者参考下面几张gif脑补</p>
<img src="pic/dazhuanpan_1.gif">
<img src="pic/dazhuanpan_2.gif">
<img src="pic/dazhuanpan_3.gif">

    <form method="POST">
        <label>选择抽奖类型：</label>
        <input type="hidden" name="lottery_type" id="lottery_type">
        <button type="submit" name="lottery_type" value="1">单抽</button>
        <button type="submit" name="lottery_type" value="10">10连抽</button>
        <button type="submit" name="lottery_type" value="100">100连抽</button>
    </form>


    <h2>奖品展示</h2>
    <ul>
        <li>上传量：1G、5G、10G、20G、100G</li>
        <li>魔力值：500、1000、2000、5000、10000、100000</li>
        <li>临时邀请3天*1</li>
        <li>补签卡</li>
        <li>7天VIP</li>
        <li>谢谢惠顾</li>
    </ul>

    <div id="data">

        <?php } ?>

    <p>当前魔力值(now bonus)：<?php echo htmlspecialchars($user['seedbonus']); ?><br>
当前上传量(now uploaded)：<?php echo format_bytes($user['uploaded']); ?><br>
当前VIP到期时间(now vip out time)：<?php echo ($user['vip_until'] ? date("Y/m/d", strtotime($user['vip_until'])) : "无"); ?><br>
当前补签卡数量(now attendance card)：<?php echo htmlspecialchars($user['attendance_card']); ?></p>

    <?php if ($error): ?>
        <p class="error"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>

        <div id="change">
    <?php if (!empty($results)): ?>
        <h2>变动情况(change)：</h2>
        <?php if ($upload_changed): ?>
            <p>上传量(uploaded)：<?php echo format_bytes($initial_uploaded); ?> => <?php echo format_bytes($user['uploaded']); ?><br>
        <?php endif; ?>
        <?php if ($magic_changed): ?>
魔力值(bonus)：<?php echo number_format($initial_magic, 1); ?> => <?php echo number_format($user['seedbonus'], 1); ?><br>
        <?php endif; ?>
        <?php if ($vip_changed): ?>
            <?php if ($initial_vip_until): ?>
VIP有效期(vip time)：<?php echo date("Y/m/d", strtotime($initial_vip_until)); ?> => <?php echo ($user['vip_until'] ? date("Y/m/d", strtotime($user['vip_until'])) : "无"); ?><br>
            <?php else: ?>
VIP有效期：无(not vip) => <?php echo ($user['vip_until'] ? date("Y/m/d", strtotime($user['vip_until'])) : "无"); ?><br>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($initial_attendance_card): ?>
补签卡(attendance card)：<?php echo $initial_attendance_card; ?> => <?php echo $user['attendance_card']; ?></p>
        <?php endif; ?>
        <h2>抽奖结果：</h2>
        <ul>
            <?php foreach ($results as $result): ?>
                <li><?php echo htmlspecialchars($result); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
        </div>


    <?php
    // 显示任何PHP错误
    $errors = error_get_last();
    if ($errors !== NULL) {
        echo "<p class='error'>PHP错误：" . htmlspecialchars(print_r($errors, true)) . "</p>";
    }
    if (!$draw) {
    ?>
    </div>
</body>
</html>
<?php } ?>
