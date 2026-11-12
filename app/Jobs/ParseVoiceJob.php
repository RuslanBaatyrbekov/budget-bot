<?php

namespace App\Jobs;

use App\Data\ParsedExpense;
use App\Models\Wallet;
use App\Services\Ai\ExpenseParser;
use App\Telegram\TelegramFiles;
use DefStudio\Telegraph\Models\TelegraphChat;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ParseVoiceJob extends ParseExpenseJob
{
    private ?string $recognized = null;

    public function __construct(int $userId, int $chatId, public string $fileId)
    {
        parent::__construct($userId, $chatId);
    }

    protected function parse(ExpenseParser $parser, Wallet $wallet, TelegraphChat $chat): ?ParsedExpense
    {
        $audio = app(TelegramFiles::class)->download($chat->bot, $this->fileId);

        $this->recognized = $this->transcribe($audio);

        if ($this->recognized === '') {
            $chat->html('🎙 Не расслышал. Попробуй записать ещё раз или напиши текстом.')->send();

            return null;
        }

        return $parser->parseText($this->recognized, $wallet);
    }

    private function transcribe(string $audio): string
    {
        $response = Http::timeout(config('services.whisper.timeout'))
            ->attach('audio_file', $audio, 'voice.ogg')
            ->post(rtrim(config('services.whisper.url'), '/').'/asr?'.http_build_query([
                'output' => 'json',
                'encode' => 'true',
                'task' => 'transcribe',
            ]));

        if ($response->failed()) {
            throw new RuntimeException('Whisper error: HTTP '.$response->status());
        }

        return trim((string) $response->json('text', ''));
    }

    protected function source(): string
    {
        return 'voice';
    }

    protected function heard(): ?string
    {
        return $this->recognized;
    }

    protected function emptyMessage(): string
    {
        return '🎙 <i>«'.e((string) $this->recognized).'»</i>'."\n\n".parent::emptyMessage();
    }
}
