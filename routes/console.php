<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('budget:recurring')->dailyAt('08:00')->withoutOverlapping();
Schedule::command('budget:digest week')->sundays()->at('19:00');
Schedule::command('budget:digest month')->monthlyOn(1, '09:00');
