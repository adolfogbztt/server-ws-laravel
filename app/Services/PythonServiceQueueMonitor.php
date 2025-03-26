<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class PythonServiceQueueMonitor
{
    /**
     * @return array
     */
    public static function getQueueStatus(): array
    {
        $estimatedTimesPerService = [
            'GFPGAN' => 18,
            'REMBG' => 10
        ];

        $jobs = DB::table('jobs')->where('queue', 'python')->get();

        if ($jobs->isEmpty()) {
            return [
                'success' => true,
                'message' => 'No jobs in queue',
                'data' => [
                    'count_per_service' => [
                        'GFPGAN' => 0,
                        'REMBG' => 0
                    ],
                    'total_estimated_wait_time' => 0
                ],
            ];
        }

        $serviceCount = [
            'GFPGAN' => 0,
            'REMBG' => 0
        ];
        
        $totalTimeEstimate = 0;

        foreach ($jobs as $job) {
            $payload = json_decode($job->payload);
            $data = unserialize($payload->data->command);

            if (isset($data->service)) {
                $service = $data->service;

                if (!isset($serviceCount[$service])) {
                    $serviceCount[$service] = 0;
                }

                $serviceCount[$service]++;
                $totalTimeEstimate += $estimatedTimesPerService[$service] ?? 0;
            }
        }

        return [
            'success' => true,
            'message' => 'Jobs in queue',
            'data' => [
                'count_per_service' => $serviceCount,
                'total_estimated_wait_time' => $totalTimeEstimate
            ],
        ];
    }
}
