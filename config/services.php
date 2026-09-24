<?php
declare(strict_types=1);

// A service owns these exact Primary IPs. Names alone never authorize an action.
// Add more snapshot-backed servers here. Scheduled HTTP tasks live in tasks.php.
return [
    'nextcloud' => [
        'title' => 'Nextcloud', 'subtitle' => 'Files, Office & Talk', 'domain' => 'wolke2.schaefchens.de',
        'description' => 'Your shared workspace, with local Collabora and a choice of internal or external Talk.',
        'simple_title' => 'Nextcloud · Files & documents',
        'simple_description' => 'Our shared place for files, documents and working together.',
        'simple_open_label' => 'Open our files',
        'initial_image' => 434940917,
        'credential_fields' => ['admin_user'=>'Administrator username','admin_password'=>'Initial administrator password'],
        'name' => 'wolke2', 'aliases' => ['wolke2.schaefchens.de'], 'location' => 'fsn1', 'type' => 'cx23',
        'ipv4' => 150948650, 'ipv6' => 150948651, 'firewall' => 11663891, 'ssh_keys' => [100740697],
        'labels' => ['service' => 'nextcloud-aio', 'instance' => 'wolke2', 'lifecycle' => 'managed'],
        'prepare' => 'aio', 'ready_url' => 'https://wolke2.schaefchens.de/status.php', 'ready_kind' => 'nextcloud',
        'activity_kind' => 'nextcloud', 'idle_timeout' => 1800,
    ],
    'hpb' => [
        'title' => 'Talk backend', 'subtitle' => 'Shared signaling & calls', 'domain' => 'hpb.schaefchens.de',
        'description' => 'Shared by wolke and wolke2. Start and stop it independently of Nextcloud.',
        'simple_title' => 'Nextcloud Talk · High Performance Backend',
        'simple_description' => 'Helps our group calls work. Files & documents also needs to be on to use Talk here.',
        'simple_url' => 'https://wolke2.schaefchens.de/apps/spreed/',
        'simple_open_label' => 'Open Talk',
        'initial_image' => 430318286,
        'credential_fields' => ['wolke_secret'=>'Signaling secret · wolke','wolke2_secret'=>'Signaling secret · wolke2','turn_secret'=>'TURN secret'],
        'name' => 'hpb', 'aliases' => ['hpb.schaefchens.de'], 'location' => 'fsn1', 'type' => 'cpx12',
        'ipv4' => 97117715, 'ipv6' => 97117717, 'firewall' => 11602103, 'ssh_keys' => [100740697],
        'labels' => ['service' => 'nextcloud-talk-hpb', 'lifecycle' => 'managed'],
        'prepare' => null, 'ready_url' => 'https://hpb.schaefchens.de/api/v1/welcome', 'ready_kind' => 'hpb',
        'activity_kind' => 'hpb', 'idle_timeout' => 1800,
    ],
];
