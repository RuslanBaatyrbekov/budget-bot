<?php

namespace App\Console\Commands;

use DefStudio\Telegraph\Models\TelegraphBot;
use Illuminate\Console\Command;

class SetupBotCommands extends Command
{
    protected $signature = 'bot:setup-commands';

    protected $description = 'Registers the bot command menu in Telegram';

    public function handle(): int
    {
        $bot = TelegraphBot::first();

        if (! $bot) {
            $this->error('Бот не найден. Создай его: php artisan telegraph:new-bot');

            return self::FAILURE;
        }

        $bot->registerCommands([
            'report' => 'Отчёт за месяц',
            'stores' => 'Траты по магазинам',
            'limits' => 'Лимиты на месяц',
            'regular' => 'Регулярные платежи',
            'export' => 'Выгрузка в CSV',
            'undo' => 'Удалить последнюю запись',
            'invite' => 'Пригласить в общий бюджет',
            'currency' => 'Валюта кошелька',
            'web' => 'Вход в веб-панель',
            'help' => 'Подсказка',
        ])->send();

        $this->info('✅ Меню бота обновлено');

        return self::SUCCESS;
    }
}
