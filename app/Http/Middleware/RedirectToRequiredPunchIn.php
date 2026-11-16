<?php

namespace App\Http\Middleware;

use App\Models\DailyAttendance;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectToRequiredPunchIn
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->canPunchAttendance()
            || ! $user->checkPermissionTo('Require:PunchIn')
            || $request->routeIs('filament.admin.pages.punch-in')) {
            return $next($request);
        }

        $hasPunchedInToday = DailyAttendance::query()
            ->where('employee_id', $user->employee->id)
            ->whereDate('attendance_date', today())
            ->whereNotNull('first_punch_in')
            ->exists();

        if ($hasPunchedInToday) {
            return $next($request);
        }

        return redirect()->route('filament.admin.pages.punch-in');
    }
}
