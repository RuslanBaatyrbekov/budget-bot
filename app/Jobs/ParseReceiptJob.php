<?php

namespace App\Jobs;

use App\Data\ParsedExpense;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\Ai\ExpenseParser;
use App\Telegram\TelegramFiles;
use DefStudio\Telegraph\Models\TelegraphChat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class ParseReceiptJob extends ParseExpenseJob
{
    private ?string $image = null;

    public function __construct(int $userId, int $chatId, public string $fileId, public string $mime = 'image/jpeg')
    {
        parent::__construct($userId, $chatId);
    }

    protected function parse(ExpenseParser $parser, Wallet $wallet, TelegraphChat $chat): ?ParsedExpense
    {
        $this->image = app(TelegramFiles::class)->download($chat->bot, $this->fileId);

        return $parser->parseReceipt($this->image, $this->mime, $wallet);
    }

    protected function source(): string
    {
        return 'receipt_photo';
    }

    protected function emptyMessage(): string
    {
        return '🤔 Не смог разобрать чек. Попробуй сфотографировать ровнее и при хорошем свете, или напиши сумму текстом.';
    }

    /** Keeps the photo so the receipt can be opened from the web panel. */
    protected function afterRecord(Collection $transactions): void
    {
        if ($this->image === null) {
            return;
        }

        $batchId = $transactions->first()->batch_id;
        $extension = str_contains($this->mime, 'png') ? 'png' : 'jpg';
        $path = "receipts/{$batchId}.{$extension}";

        Storage::disk('public')->put($path, $this->image);
        Transaction::where('batch_id', $batchId)->update(['receipt_image_path' => $path]);
    }
}
