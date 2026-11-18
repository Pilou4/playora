<?php

/**
 * Returns the importmap for this application.
 *
 * Un point d'entrée par type de page : chaque page ne charge que ce dont elle a besoin.
 */
return [
    'president' => [
        'path' => './assets/president.js',
        'entrypoint' => true,
    ],
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
    'games' => [
        'path' => './assets/games.js',
        'entrypoint' => true,
    ],
    'belote' => [
        'path' => './assets/belote.js',
        'entrypoint' => true,
    ],
    'tarot' => [
        'path' => './assets/tarot.js',
        'entrypoint' => true,
    ],
    'solitaire' => [
        'path' => './assets/solitaire.js',
        'entrypoint' => true,
    ],
    'patience' => [
        'path' => './assets/patience.js',
        'entrypoint' => true,
    ],
    'spider' => [
        'path' => './assets/spider.js',
        'entrypoint' => true,
    ],
    'rami' => [
        'path' => './assets/rami.js',
        'entrypoint' => true,
    ],
    'huit' => [
        'path' => './assets/huit.js',
        'entrypoint' => true,
    ],
    'golf' => [
        'path' => './assets/golf.js',
        'entrypoint' => true,
    ],
    'avatar' => [
        'path' => './assets/avatar.js',
        'entrypoint' => true,
    ],
    'admin' => [
        'path' => './assets/admin.js',
        'entrypoint' => true,
    ],
];
