<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use DefStudio\Telegraph\Models\TelegraphBot;

class SetupBotCommands extends Command
{
    protected $signature = 'bot:setup-commands';

    protected $description = 'Регистрирует команды меню для Telegram-бота';

    public function handle()
    {
        $bot = TelegraphBot::first();

        if (!$bot) {
            $this->error('Бот не найден в базе данных! Убедись, что выполнил миграции и создал бота.');
            return;
        }

        $this->info('Отправляем запрос в Telegram API...');

        $bot->registerCommands([
            'start'  => 'Настроить профиль',
            'report' => 'Отчет за месяц',
            'stores' => 'Отчет по магазинам',
            'invite' => 'Пригласить в семью',
            'undo'   => 'Отменить последнюю запись',
        ])->send();

        $this->info('✅ Меню бота успешно обновлено!');
    }
}
