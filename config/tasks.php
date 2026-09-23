<?php
declare(strict_types=1);

// URLs are configuration, never browser input. No arbitrary URL or command runner.
return [
    'spirit-idle' => [
        'title' => 'Walk in the Spirit',
        'description' => 'Calls the existing game controller to remove its server only when idle. Players still start it automatically.',
        'url' => 'https://walkinthespirit.games.schaefchens.de/api/fetch-game-server.php',
        'query' => ['action' => 'destroy-if-idle'], 'key_env' => 'GAME_SERVER_ADMIN_KEY',
        'interval_env' => 'GAME_IDLE_CHECK_INTERVAL', 'interval' => 3600,
        'success_statuses' => ['not-destroyed', 'already-destroyed', 'destroying'],
    ],
];
