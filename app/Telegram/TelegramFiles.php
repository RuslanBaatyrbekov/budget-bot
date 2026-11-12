<?php

namespace App\Telegram;

use DefStudio\Telegraph\Models\TelegraphBot;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramFiles
{
    /** Downloads a file sent to the bot and returns its raw bytes. */
    public function download(TelegraphBot $bot, string $fileId): string
    {
        $base = rtrim(config('telegraph.telegram_api_url', 'https://api.telegram.org/'), '/');

        $info = Http::timeout(15)->get("{$base}/bot{$bot->token}/getFile", ['file_id' => $fileId]);

        $path = $info->json('result.file_path');
        $size = (int) $info->json('result.file_size', 0);

        if ($info->failed() || ! $path) {
            throw new RuntimeException('Telegram getFile failed: HTTP '.$info->status());
        }

        if ($size > config('budget.max_file_bytes')) {
            throw new RuntimeException('File is too large: '.$size.' bytes');
        }

        $file = Http::timeout(30)->get("{$base}/file/bot{$bot->token}/{$path}");

        if ($file->failed()) {
            throw new RuntimeException('Telegram file download failed: HTTP '.$file->status());
        }

        return $file->body();
    }
}
