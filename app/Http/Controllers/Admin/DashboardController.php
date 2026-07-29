<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WhatsappMessage;
use App\Support\AdminMetrics;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(AdminMetrics $metrics): View
    {
        return view('admin.dashboard', [
            'totals' => $metrics->totals(),
            'perDay' => $metrics->perDay(14),
            'perHour' => $metrics->perHour(30),
            'topAirports' => $metrics->topAirports(12),
            'airportsWeek' => $metrics->topAirports(5, 7),
            'topics' => $metrics->topics(),
            'unmatched' => $metrics->unmatched(30),
            'latency' => $metrics->latency(7),
            'topUsers' => $metrics->topUsers(8),
            'subscriptions' => $metrics->subscriptions(),
            'latest' => WhatsappMessage::query()->latest('id')->limit(8)->get(),
        ]);
    }
}
