<?php

namespace App\Jobs;

use App\Jobs\ParseTextJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ParseVoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected int $userId,
        protected string $chatId,
        protected string $filePath
    ) {}

    public function handle(): void
    {
        // Генерируем уникальный ID для этого конкретного запуска, чтобы легко искать в логах
        $jobId = uniqid('voice_');

        Log::info("[{$jobId}] 1. Старт ParseVoiceJob", [
            'user_id' => $this->userId,
            'chat_id' => $this->chatId,
            'file_path' => $this->filePath,
        ]);

        try {
            // Шаг 1: Проверка файла
            if (!file_exists($this->filePath)) {
                Log::error("[{$jobId}] ❌ Ошибка: Файл не найден по указанному пути", [
                    'path' => $this->filePath
                ]);
                return;
            }

            $fileSize = filesize($this->filePath);
            Log::info("[{$jobId}] 2. Файл найден", ['size_bytes' => $fileSize]);

            // Шаг 2: Отправка в Whisper
            $url = 'http://whisper:9000/asr?output=json&encode=true';
            Log::info("[{$jobId}] 3. Отправка POST запроса в Whisper", ['url' => $url]);

            $response = Http::timeout(30)
                ->attach(
                    'audio_file',
                    file_get_contents($this->filePath),
                    basename($this->filePath)
                )
                ->post($url);

            // Шаг 3: Проверка ответа
            Log::info("[{$jobId}] 4. Получен ответ от Whisper HTTP: {$response->status()}");

            if ($response->failed()) {
                Log::error("[{$jobId}] ❌ Ошибка Whisper API (HTTP {$response->status()})", [
                    'response_body' => $response->body()
                ]);
                return;
            }

            // Логируем сырой JSON для дебага структуры
            $responseArray = $response->json();
            Log::debug("[{$jobId}] 5. Сырой JSON от Whisper", ['data' => $responseArray]);

            $recognizedText = trim($responseArray['text'] ?? '');

            if (empty($recognizedText)) {
                Log::warning("[{$jobId}] ⚠️ Whisper вернул пустой текст", [
                    'raw_response' => $response->body()
                ]);
                return;
            }

            Log::info("[{$jobId}] 6. Успешно распознано: {$recognizedText}");

            // Шаг 4: Отправка следующей джобы
            ParseTextJob::dispatch($this->userId, $this->chatId, $recognizedText);
            Log::info("[{$jobId}] 7. ParseTextJob успешно отправлен в очередь");

        } catch (\Throwable $e) {
            // Теперь ловим не только сообщение, но и конкретный файл/строку ошибки
            Log::error("[{$jobId}] ❌ Критическая ошибка Exception", [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                // 'trace' => $e->getTraceAsString(), // Раскомментируй, если нужен полный стек-трейс
            ]);
        } finally {
            // Шаг 5: Очистка
            if (file_exists($this->filePath)) {
                if (@unlink($this->filePath)) {
                    Log::info("[{$jobId}] 8. Временный файл удален: {$this->filePath}");
                } else {
                    Log::error("[{$jobId}] ❌ Ошибка: Не удалось удалить файл: {$this->filePath}");
                }
            } else {
                Log::info("[{$jobId}] 8. Файл для удаления не найден (возможно, удален ранее): {$this->filePath}");
            }
        }
    }
}
