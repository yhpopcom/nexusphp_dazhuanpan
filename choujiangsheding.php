<?php
return [
    'prizes' => [
        'upload' => [
            ['name' => '1G 上传量', 'value' => 1],
            ['name' => '5G 上传量', 'value' => 5],
            ['name' => '10G 上传量', 'value' => 10],
            ['name' => '20G 上传量', 'value' => 20],
            ['name' => '100G 上传量', 'value' => 100],
        ],
        'magic' => [
            ['name' => '500 魔力值', 'value' => 500],
            ['name' => '1000 魔力值', 'value' => 1000],
            ['name' => '2000 魔力值', 'value' => 2000],
            ['name' => '5000 魔力值', 'value' => 5000],
            ['name' => '10000 魔力值', 'value' => 10000],
            ['name' => '100000 魔力值', 'value' => 100000],
        ],
        'special' => [
            ['name' => '临时邀请', 'value' => 3], // 3天有效期
            ['name' => '补签卡', 'value' => 1],
            ['name' => '7天VIP', 'value' => 7],
            ['name' => '谢谢惠顾', 'value' => 0],
        ],
    ],
    'probabilities' => [
        'upload' => 0.4,
        'magic' => 0.35,
        'special' => 0.25,
    ],
    'category_probabilities' => [
        'upload' => [0.6, 0.34, 0.04, 0.015, 0.005],
        'magic' => [0.4, 0.27, 0.3, 0.02, 0.008, 0.002],
        'special' => [0.007, 0.19, 0.003, 0.8],
    ],
];
?>
