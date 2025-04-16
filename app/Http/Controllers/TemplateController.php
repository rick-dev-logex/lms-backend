<?php

namespace App\Http\Controllers;

use App\Exports\TemplateExport;
use App\Models\User;
use App\Models\Project;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class TemplateController extends Controller
{
    public function downloadDiscountsTemplate(Request $request)
    {
        $projectNames = $this->getProjectNamesFromJwt($request);

        // Verificar si hay proyectos asignados
        if ($projectNames === ['Sin proyectos asignados']) {
            return response()->json([
                'message' => 'No tienes proyectos asignados aún. Por favor, pide que te asignen al menos un proyecto para poder continuar.'
            ], 403); // 403 Forbidden para indicar que no se permite la acción
        }

        if ($request->has('isIncome')) {
            $response = Excel::download(new TemplateExport('income', $projectNames), 'plantilla_ingresos.xlsx');
        } else {
            $response = Excel::download(new TemplateExport('discounts', $projectNames), 'plantilla_descuentos.xlsx');
        }

        $response->headers->set('Access-Control-Allow-Origin', '*');

        return $response;
    }

    public function downloadExpensesTemplate(Request $request)
    {
        // Log::info("Request recibido en downloadExpensesTemplate: " . json_encode($request->all()));

        $projectNames = $this->getProjectNamesFromJwt($request);

        // Verificar si hay proyectos asignados
        if ($projectNames === ['Sin proyectos asignados']) {
            return response()->json([
                'message' => 'No tienes proyectos asignados aún. Por favor, pide que te asignen al menos un proyecto para poder continuar.'
            ], 403);
        }

        return Excel::download(new TemplateExport('expenses', $projectNames), 'plantilla_gastos.xlsx');
    }

    private function getProjectNamesFromJwt(Request $request): array
    {
        $jwtToken = $request->cookie('jwt-token');
        if (!$jwtToken) {
            Log::warning("No 'jwt-token' cookie found. Cookies: " . json_encode($request->cookies->all()));
            return ['Sin proyectos asignados'];
        }

        try {
            $decoded = JWT::decode($jwtToken, new Key(env('JWT_SECRET'), 'HS256'));
            $jwt = (array) $decoded;
        } catch (\Exception $e) {
            Log::error("Error decoding JWT from 'jwt-token' cookie: " . $e->getMessage());
            return ['Sin proyectos asignados'];
        }

        if (!isset($jwt['user_id'])) {
            Log::warning("No user_id found in decoded JWT: " . json_encode($jwt));
            return ['Sin proyectos asignados'];
        }

        $user = User::with('assignedProjects')->find($jwt['user_id']);

        if ($user && $user->assignedProjects && !empty($user->assignedProjects->projects)) {
            $projectNames = Project::whereIn('id', $user->assignedProjects->projects)
                ->pluck('name')
                ->toArray();
            return !empty($projectNames) ? $projectNames : ['Sin proyectos asignados'];
        }

        return ['Sin proyectos asignados'];
    }
}
