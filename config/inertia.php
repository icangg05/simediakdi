<?php

return [
    'testing' => [
        // Bawaan Inertia mencari `js/Pages` berhuruf besar, sedangkan repo ini
        // memakai `js/pages`. Akibatnya `assertInertia()->component()` selalu
        // gagal dengan pesan "page does not exist" walau berkasnya ada, dan itu
        // tidak pernah ketahuan karena belum ada tes yang memakainya.
        'page_paths' => [resource_path('js/pages')],

        'page_extensions' => ['js', 'jsx', 'svelte', 'ts', 'tsx', 'vue'],
    ],
];
