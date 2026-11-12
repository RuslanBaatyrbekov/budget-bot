<?php

return [

    /*
    | Telegram user IDs that may use the bot. An empty list means nobody:
    | a public deployment must never be open to everyone by accident.
    */
    'allowed_users' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ALLOWED_TELEGRAM_USERS', ''))
    ))),

    'default_currency' => env('BUDGET_DEFAULT_CURRENCY', '€'),

    'currencies' => ['€', '$', '₽', 'сом', '₸', '£'],

    'invite_ttl_hours' => 48,

    'web_login_ttl_minutes' => 15,

    // Notify when a monthly limit reaches these shares of the budget.
    'limit_thresholds' => [0.8, 1.0],

    'max_voice_seconds' => 180,

    'max_file_bytes' => 20 * 1024 * 1024,

    'default_categories' => [
        ['name' => 'Продукты', 'icon' => '🛒', 'type' => 'expense'],
        ['name' => 'Кафе и доставка', 'icon' => '🍔', 'type' => 'expense'],
        ['name' => 'Транспорт', 'icon' => '🚆', 'type' => 'expense'],
        ['name' => 'Авто', 'icon' => '🚗', 'type' => 'expense'],
        ['name' => 'Жильё и коммуналка', 'icon' => '🏠', 'type' => 'expense'],
        ['name' => 'Связь и интернет', 'icon' => '📱', 'type' => 'expense'],
        ['name' => 'Здоровье', 'icon' => '💊', 'type' => 'expense'],
        ['name' => 'Одежда', 'icon' => '👕', 'type' => 'expense'],
        ['name' => 'Дом и быт', 'icon' => '🧴', 'type' => 'expense'],
        ['name' => 'Электроника и хобби', 'icon' => '🔌', 'type' => 'expense'],
        ['name' => 'Развлечения', 'icon' => '🎬', 'type' => 'expense'],
        ['name' => 'Зарплата', 'icon' => '💵', 'type' => 'income'],
        ['name' => 'Прочие доходы', 'icon' => '💰', 'type' => 'income'],
    ],
];
