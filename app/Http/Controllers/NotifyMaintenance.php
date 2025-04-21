<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\MaintenanceNotification;
use Illuminate\Http\Request;

class NotifyMaintenance extends Controller
{
    public function notifyMaintenance()
    {
        $users = User::all(); // o solo los que tengan email confirmado, etc.
        // $users = User::where('id', 29); // yo.
        $date = '21 de abril de 2025';
        $start = '12:00 p.m.';
        $end = '12:20 p.m.';

        foreach ($users as $user) {
            $user->notify(new MaintenanceNotification($date, $start, $end));
        }

        return response()->json(['message' => 'Correos enviados.']);
    }
}
