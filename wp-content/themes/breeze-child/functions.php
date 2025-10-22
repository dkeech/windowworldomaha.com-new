<?php
// Enqueue child CSS after parent. 
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style(
        'child/style',
        get_stylesheet_directory_uri() . '/style.css',
        ['trimark/css'],
        filemtime(get_stylesheet_directory() . '/style.css')
    );
}, 20);