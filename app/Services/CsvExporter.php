<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class CsvExporter
{
    /** Writes the month to a CSV file (Excel-friendly: BOM + ";") and returns its absolute path. */
    public function month(Wallet $wallet, Carbon $month): string
    {
        $path = 'exports/budget-'.$month->format('Y-m').'-'.$wallet->id.'-'.uniqid().'.csv';
        Storage::disk('local')->makeDirectory('exports');
        $absolute = Storage::disk('local')->path($path);

        $handle = fopen($absolute, 'w');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['Дата', 'Тип', 'Категория', 'Описание', 'Магазин', 'Сумма', 'Валюта', 'Оплата', 'Кто'], ';');

        Transaction::where('wallet_id', $wallet->id)
            ->forMonth($month)
            ->with(['category', 'user'])
            ->orderBy('purchased_at')
            ->each(function (Transaction $t) use ($handle, $wallet) {
                fputcsv($handle, [
                    $t->purchased_at?->format('d.m.Y H:i'),
                    $t->type->label(),
                    $t->category?->name,
                    $t->description,
                    $t->store,
                    number_format($t->amount / 100, 2, ',', ''),
                    $wallet->currencySign(),
                    $t->payment_method->value,
                    $t->user?->name,
                ], ';');
            });

        fclose($handle);

        return $absolute;
    }
}
