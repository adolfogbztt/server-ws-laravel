<?php

namespace App\Http\Controllers;

use App\Enums\NaiadeTaskService;
use App\Models\NaiadeTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NaiadeController extends Controller
{
    /**
     * @param Request $request
     * @param mixed $ticket
     * 
     * @return JsonResponse
     */
    public function sendTicket(Request $request): JsonResponse
    {
        $ticket = $request->route('ticket');

        $naiadeTask = NaiadeTask::firstWhere('ticket', $ticket);

        if ($naiadeTask) {
            return response()->json([
                'success' => false,
                'message' => 'Ticket already exists',
                'data' => null
            ], 400);
        }

        $naiadeTask = NaiadeTask::create([
            'ticket' => $ticket,
            'service' => NaiadeTaskService::GFPGAN
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Ticket processed successfully',
            'data' => null
        ], 200);
    }

    /**
     * @param Request $request
     * 
     * @return JsonResponse
     */
    public function ticketStatus(Request $request): JsonResponse
    {
        $ticket = $request->route('ticket');

        $naiadeTask = NaiadeTask::firstWhere('ticket', $ticket);

        if (!$naiadeTask) {
            return response()->json([
                'success' => false,
                'message' => 'Ticket not found',
                'data' => null
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Ticket status retrieved successfully',
            'data' => [
                'ticket' => $naiadeTask->ticket,
                'status' => $naiadeTask->status->value,
                'message' => $naiadeTask->message,
            ]
        ], 200);
    }
}
