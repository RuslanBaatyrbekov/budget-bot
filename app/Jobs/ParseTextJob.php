<?php

namespace App\Jobs;

use App\Data\ParsedExpense;
use App\Models\Wallet;
use App\Services\Ai\ExpenseParser;
use DefStudio\Telegraph\Models\TelegraphChat;

class ParseTextJob extends ParseExpenseJob
{
    public function __construct(int $userId, int $chatId, public string $text)
    {
        parent::__construct($userId, $chatId);
    }

    protected function parse(ExpenseParser $parser, Wallet $wallet, TelegraphChat $chat): ?ParsedExpense
    {
        return $parser->parseText($this->text, $wallet);
    }

    protected function source(): string
    {
        return 'text';
    }
}
