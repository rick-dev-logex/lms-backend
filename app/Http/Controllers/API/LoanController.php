<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Models\Request;
use App\Models\Reposicion;
use App\Models\User;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Google\Cloud\Storage\StorageClient;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class LoanController extends Controller
{
    private $storage;
    private $bucketName;

    public function __construct()
    {
        $base64Key = env('GOOGLE_CLOUD_KEY_BASE64');
        if (!$base64Key) {
            throw new \Exception('La clave de Google Cloud no está definida en el archivo .env.');
        }
        $credentials = json_decode(base64_decode($base64Key), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Error al decodificar las credenciales de Google Cloud: ' . json_last_error_msg());
        }

        $this->storage = new StorageClient([
            'keyFile' => $credentials
        ]);
        $this->bucketName = env('GOOGLE_CLOUD_STORAGE_BUCKET', 'lms-archivos');
    }

    public function index(HttpRequest $request)
    {
        try {
            $jwtToken = $request->cookie('jwt-token');
            if (!$jwtToken) {
                throw new \Exception("No se encontró el token de autenticación.");
            }

            $decoded = JWT::decode($jwtToken, new Key(env('JWT_SECRET'), 'HS256'));
            $userId = $decoded->user_id ?? null;
            if (!$userId) {
                throw new \Exception("Usuario no identificado en el token JWT.");
            }

            $user = User::find($userId);
            if (!$user) {
                throw new \Exception("Usuario no encontrado.");
            }

            $assignedProjectIds = $this->getAssignedProjects($user);

            $query = Loan::with(['account:id,name'])
                ->whereIn('project', array_map('strval', $assignedProjectIds));

            if ($request->filled('type')) {
                $query->where('type', $request->type);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            $loans = $query->get();

            $projects = DB::connection('sistema_onix')
                ->table('onix_proyectos')
                ->whereIn('id', $assignedProjectIds)
                ->select('id', 'name')
                ->get()
                ->mapWithKeys(fn($project) => [$project->id => $project->name])
                ->all();

            $data = $loans->map(function ($loan) use ($projects) {
                $loanData = $loan->toArray();
                $loanData['project_name'] = $projects[$loan->project] ?? 'Unknown';
                return $loanData;
            })->all();

            return response()->json($data);
        } catch (\Exception $e) {
            Log::error('Error in LoanController@index:', ['message' => $e->getMessage()]);
            return response()->json(['message' => 'Error al listar préstamos', 'error' => $e->getMessage()], 500);
        }
    }

    public function store(HttpRequest $request)
    {
        try {
            DB::beginTransaction();

            $rules = [
                'type' => 'required|in:nomina,proveedor',
                'account_id' => 'required|exists:lms_backend.accounts,id',
                'amount' => 'required|numeric|min:0.01',
                'project' => 'required|string', // Recibe el nombre del proyecto
                'invoice_number' => 'required|string',
                'installments' => 'required|integer|min:1|max:36',
                'installment_dates' => 'required|array|size:' . $request->input('installments'),
                'installment_dates.*' => 'required|date_format:Y-m',
                'note' => 'required|string',
                'attachment' => 'required|file|mimes:pdf,jpg,png|max:10240',
            ];

            if ($request->input('type') === 'nomina') {
                $rules['responsible_id'] = 'required|exists:sistema_onix.onix_personal,id';
            } else {
                $rules['vehicle_id'] = 'required|exists:sistema_onix.onix_vehiculos,id';
            }

            $messages = [
                'attachment.mimes' => 'El archivo debe ser un documento PDF o una imagen JPG/PNG.',
            ];

            $validated = $request->validate($rules, $messages);

            $jwtToken = $request->cookie('jwt-token');
            $decoded = JWT::decode($jwtToken, new Key(env('JWT_SECRET'), 'HS256'));
            $user = User::findOrFail($decoded->user_id);
            $assignedProjectIds = $this->getAssignedProjects($user);

            // Convertir el nombre del proyecto a UUID
            $projectUuid = DB::connection('sistema_onix')
                ->table('onix_proyectos')
                ->whereIn('id', $assignedProjectIds)
                ->where('name', $validated['project'])
                ->value('id'); // Obtener el UUID (id)

            if (!$projectUuid) {
                throw ValidationException::withMessages([
                    'project' => 'El proyecto seleccionado no está asignado al usuario o no existe.',
                ]);
            }

            $file = $request->file('attachment');
            $fileName = time() . '_' . $file->getClientOriginalName();
            $bucket = $this->storage->bucket($this->bucketName);
            if (!$bucket->exists()) {
                throw new \Exception("El bucket '$this->bucketName' no existe o no es accesible");
            }
            $object = $bucket->upload(
                fopen($file->getRealPath(), 'r'),
                ['name' => $fileName]
            );
            $fileUrl = $object->signedUrl(new \DateTime('+10 years'));

            $loanData = [
                'loan_date' => now(),
                'type' => $validated['type'],
                'account_id' => $validated['account_id'],
                'amount' => $validated['amount'],
                'project' => $projectUuid, // Usar UUID
                'file_path' => $fileUrl,
                'note' => $validated['note'],
                'installments' => $validated['installments'],
                'responsible_id' => $validated['type'] === 'nomina' ? $validated['responsible_id'] : null,
                'vehicle_id' => $validated['type'] === 'proveedor' ? $validated['vehicle_id'] : null,
                'status' => 'pending',
            ];

            $loan = Loan::create($loanData);

            $amountPerInstallment = $validated['amount'] / $validated['installments'];
            $requestIds = [];

            foreach ($validated['installment_dates'] as $index => $date) {
                $prefix = 'P-';
                $lastRequest = Request::where('unique_id', 'like', 'P-%')
                    ->orderBy('id', 'desc')
                    ->first();
                $nextId = $lastRequest ? ((int)str_replace($prefix, '', $lastRequest->unique_id) + 1) : 1;
                $uniqueId = sprintf('%s%05d', $prefix, $nextId);

                $requestData = [
                    'unique_id' => $uniqueId,
                    'type' => 'discount',
                    'personnel_type' => $validated['type'],
                    'status' => 'in_reposition',
                    'request_date' => Carbon::createFromFormat('Y-m', $date)->startOfMonth(),
                    'invoice_number' => $validated['invoice_number'],
                    'account_id' => $validated['account_id'],
                    'amount' => round($amountPerInstallment, 2),
                    'project' => $projectUuid, // Usar UUID
                    'responsible_id' => $loan->responsible_id,
                    'transport_id' => $loan->vehicle_id,
                    'note' => $validated['note'] ?? "Cuota " . ($index + 1) . " de préstamo ID: {$loan->uniqueId}",
                ];

                $newRequest = Request::create($requestData);
                $requestIds[] = $newRequest->unique_id;
            }

            $reposicion = Reposicion::create([
                'fecha_reposicion' => now(),
                'total_reposicion' => $validated['amount'],
                'status' => 'pending',
                'project' => $projectUuid, // Usar UUID
                'detail' => $requestIds, // Pasar array directamente, Laravel lo convierte a JSON
                'attachment_url' => $fileUrl,
                'attachment_name' => $fileName,
                'note' => $validated['note'],
            ]);

            DB::commit();

            $loan->load('account');
            $reposicion->setRelation('requests', $reposicion->requestsWithRelations()->get());

            return response()->json([
                'message' => 'Préstamo creado exitosamente',
                'loan' => $loan,
                'reposicion' => $reposicion,
            ], 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Illuminate\Database\QueryException $e) {
            DB::rollBack();
            if (strpos($e->getMessage(), 'SQLSTATE[22001]') !== false && strpos($e->getMessage(), 'file_path') !== false) {
                Log::error('Error in LoanController@store: URL de archivo demasiado larga', ['message' => $e->getMessage()]);
                return response()->json([
                    'message' => 'Error al guardar el archivo',
                    'error' => 'La URL del archivo subido es demasiado larga. Por favor, contacta al soporte técnico.',
                ], 500);
            }
            Log::error('Error in LoanController@store:', ['message' => $e->getMessage()]);
            return response()->json([
                'message' => 'Error al crear el préstamo',
                'error' => $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in LoanController@store:', ['message' => $e->getMessage()]);
            return response()->json([
                'message' => 'Error al crear el préstamo',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function file($id)
    {
        try {
            $loan = Loan::findOrFail($id);
            if (!$loan->file_path) {
                return response()->json(['message' => 'No se encontró archivo adjunto para este préstamo'], 404);
            }

            $bucket = $this->storage->bucket($this->bucketName);
            $object = $bucket->object($loan->file_path);
            if (!$object->exists()) {
                return response()->json(['message' => 'El archivo no existe en Google Cloud Storage'], 404);
            }

            $fileUrl = $object->signedUrl(new \DateTime('+10 years'));
            return response()->json([
                'file_url' => $fileUrl,
                'file_name' => $loan->file_path,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get file URL', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Error al recuperar la URL del archivo', 'error' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        try {
            $loan = Loan::with(['account', 'responsible', 'vehicle', 'requests'])->findOrFail($id);
            return response()->json($loan);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Préstamo no encontrado', 'error' => $e->getMessage()], 404);
        }
    }

    public function update(HttpRequest $request, $id)
    {
        try {
            DB::beginTransaction();

            $loan = Loan::findOrFail($id);

            // Validación de campos actualizables
            $rules = [
                'status' => 'sometimes|in:pending,paid,rejected,review',
                'note' => 'sometimes|string',
                'installment_dates' => 'sometimes|array|size:' . $loan->installments,
                'installment_dates.*' => 'required_with:installment_dates|date_format:Y-m',
            ];

            $validated = $request->validate($rules);

            // Actualizar estado del préstamo y solicitudes asociadas
            if (isset($validated['status']) && $validated['status'] !== $loan->status) {
                $requestStatus = match ($validated['status']) {
                    'paid' => 'paid',
                    'rejected' => 'rejected',
                    'pending' => 'pending',
                    'review' => 'review',
                    default => 'pending'
                };

                // Actualizar solicitudes asociadas
                Request::whereIn('unique_id', $loan->requests->pluck('unique_id'))
                    ->update(['status' => $requestStatus]);

                // Verificar total si se marca como pagado
                if ($validated['status'] === 'paid') {
                    $calculatedTotal = $loan->requests()->sum('amount');
                    if (abs($calculatedTotal - $loan->amount) > 0.01) { // Tolerancia de 1 centavo
                        throw new \Exception('El total de las cuotas no coincide con el monto del préstamo.');
                    }
                }
            }

            // Actualizar fechas de cuotas si se proporcionan
            if (isset($validated['installment_dates'])) {
                $requests = $loan->requests()->get();
                if ($requests->count() !== count($validated['installment_dates'])) {
                    throw new \Exception('El número de fechas no coincide con el número de cuotas.');
                }

                foreach ($requests as $index => $req) {
                    $req->update([
                        'request_date' => Carbon::createFromFormat('Y-m', $validated['installment_dates'][$index])->startOfMonth(),
                    ]);
                }
            }

            // Actualizar el préstamo
            $loan->update(array_filter($validated)); // Solo actualiza campos presentes
            $loan = $loan->fresh();

            DB::commit();

            $loan->load('requests');
            return response()->json([
                'message' => 'Préstamo actualizado exitosamente',
                'loan' => $loan,
            ]);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in LoanController@update:', ['message' => $e->getMessage()]);
            return response()->json([
                'message' => 'Error al actualizar el préstamo',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function destroy($id)
    {
        try {
            DB::beginTransaction();

            $loan = Loan::findOrFail($id);

            // Eliminar archivo de Google Cloud Storage
            if ($loan->file_path) {
                $bucket = $this->storage->bucket($this->bucketName);
                $object = $bucket->object(basename($loan->file_path));
                if ($object->exists()) {
                    $object->delete();
                }
            }

            // Obtener la reposición asociada (si existe)
            $reposicion = Reposicion::whereJsonContains('detail', $loan->requests->pluck('unique_id')->first())->first();
            if ($reposicion) {
                if ($reposicion->attachment_name) {
                    $bucket = $this->storage->bucket($this->bucketName);
                    $object = $bucket->object($reposicion->attachment_name);
                    if ($object->exists()) {
                        $object->delete();
                    }
                }
                $reposicion->delete();
            }

            // Eliminar solicitudes asociadas
            Request::whereIn('unique_id', $loan->requests->pluck('unique_id'))->delete();

            // Eliminar el préstamo
            $loan->delete();

            DB::commit();

            return response()->json(['message' => 'Préstamo eliminado exitosamente']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in LoanController@destroy:', ['message' => $e->getMessage()]);
            return response()->json([
                'message' => 'Error al eliminar el préstamo',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    private function getAssignedProjects($user)
    {
        $assignedProjectIds = [];
        if ($user && isset($user->assignedProjects)) {
            if (is_object($user->assignedProjects) && isset($user->assignedProjects->projects)) {
                $projectsValue = $user->assignedProjects->projects;
                $assignedProjectIds = is_string($projectsValue) ? json_decode($projectsValue, true) : $projectsValue;
            } elseif (is_array($user->assignedProjects)) {
                $assignedProjectIds = $user->assignedProjects;
            }
        }
        return array_map('strval', $assignedProjectIds ?: []);
    }
}
