# 🤖 AI Family Budget Tracker

A smart, automated family finance manager built with **Laravel 11**. 
Instead of manually entering expenses, users simply forward receipts (images) or voice/text messages to a **Telegram Bot**. The bot uses **Google Gemini AI** to extract transaction details and categorizes them into a beautiful **Filament Admin Dashboard**.

## ✨ Key Features

*   **AI Data Extraction:** Parses natural language ("Bought coffee for 5 euros") and receipt photos into structured JSON data using the Gemini API.
*   **Telegram Integration:** Fully functional conversational bot built with `defstudio/telegraph`.
*   **Shared Family Wallets:** Multiple users can join a single wallet via a unique code to track family expenses collaboratively.
*   **Smart Dashboard:** Visualizes spending habits with Filament-powered charts (Daily Dynamics, Store Distribution, Weekly Radar).
*   **Background Processing:** AI parsing and Telegram webhooks are decoupled into robust background Jobs/Workers to ensure fast response times.
*   **Reliable Testing:** Core logic is covered by Feature tests using advanced API and Queue mocking (`Http::fake`, `Queue::fake`).

## 🛠️ Tech Stack

*   **Backend:** PHP 8.2+, Laravel 11
*   **Admin Panel:** Filament v3
*   **AI / LLM:** Google Gemini Pro / Flash API
*   **Bot API:** Telegram Bot API
*   **Infrastructure:** Docker Compose, Redis (Queues), Ngrok (Webhooks)
*   **Testing:** PHPUnit, Laravel Testing Fakes

## 📸 Screenshots

| Telegram Bot (AI Parsing) | Admin Dashboard (Filament) |
| :---: | :---: |
| <img width="1523" height="713" alt="image" src="https://github.com/user-attachments/assets/b3fac9d1-f14b-4ae1-aec5-34764b9af984" /> | <img width="850" height="892" alt="image" src="https://github.com/user-attachments/assets/8af1a1a4-57e2-41fd-a799-439270094cd6" /> |

## 🚀 Local Setup & Installation

**1. Clone the repository and install dependencies**
```bash
git clone [https://github.com/RuslanBaatyrbekov/budget-bot.git](https://github.com/RuslanBaatyrbekov/budget-bot.git)
cd budget-bot
composer install
```

**2. Configure Environment**
Copy the `.env.example` file and configure your database, Telegram bot token, and Gemini API key:
```bash
cp .env.example .env
php artisan key:generate
```

**3. Run Infrastructure (Docker)**
Start the Laravel Sail environment along with the queue worker:
```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate --seed
```

**4. Set up Telegram Webhook**
Use Ngrok or any local tunnel to expose your app, then register the webhook:
```bash
ngrok http 80
./vendor/bin/sail artisan telegraph:set-webhook [https://your-ngrok-url.ngrok-free.app/telegraph/](https://your-ngrok-url.ngrok-free.app/telegraph/){token}/webhook
```

## 🧪 Testing

The project includes strict automated tests to ensure API reliability and proper queue dispatching without hitting real rate limits.

```bash
./vendor/bin/sail artisan test
```
