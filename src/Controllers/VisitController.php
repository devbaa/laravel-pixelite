<?php

namespace Boralp\Pixelite\Controllers;

use Boralp\Pixelite\Models\VisitRaw;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class VisitController extends BaseController
{
    /**
     * Update visit lifecycle data when user sends heartbeat or leaves
     */
    public function update(Request $request, string $pixeliteTraceId): JsonResponse
    {
        try {
            // Validate the request data
            $request->validate([
                'total_time' => 'nullable|integer|min:0',
                'screen_width' => 'nullable|integer|min:1',
                'screen_height' => 'nullable|integer|min:1',
                'viewport_width' => 'nullable|integer|min:1',
                'viewport_height' => 'nullable|integer|min:1',
                'color_depth' => 'nullable|integer|min:1',
                'pixel_ratio' => 'nullable|numeric|min:0',
                'timezone_offset' => 'nullable|integer',
            ]);

            $totalTime = $request->input('total_time');
            $sessionTimeoutHours = 6; // Configurable session timeout
            $timeoutThreshold = Carbon::now()->subHours($sessionTimeoutHours);

            // Build payload for JavaScript-generated data
            $payloadJs = [];

            // Add screen information if provided
            if ($request->has('screen_width')) {
                $payloadJs['screen'] = [
                    'screen_width' => $request->input('screen_width'),
                    'screen_height' => $request->input('screen_height'),
                    'viewport_width' => $request->input('viewport_width'),
                    'viewport_height' => $request->input('viewport_height'),
                    'color_depth' => $request->input('color_depth'),
                    'pixel_ratio' => $request->input('pixel_ratio'),
                ];
            }

            // Add timezone if provided
            if ($request->has('timezone_offset')) {
                $payloadJs['timezone_offset'] = $request->input('timezone_offset');
            }

            // Single UPDATE query with security and time checks - no SELECT needed
            $updated = VisitRaw::where('id', $pixeliteTraceId)
                ->where('created_at', '>=', $timeoutThreshold) // Must be within session timeout
                ->where('user_id', auth()->id())
                ->where('session_id', $request->session()->getId())
                ->update([
                    'total_time' => $totalTime,
                    'payload_js' => json_encode($payloadJs),
                ]);

            if ($updated === 0) {
                Log::warning('Visit update attempt failed - security, timeout check', [
                    'pixel_trace_id' => $pixeliteTraceId,
                    'session_id' => $request->session()->getId(),
                    'user_id' => auth()->id(),
                    'ip' => $request->ip(),
                    'timeout_threshold' => $timeoutThreshold->toISOString(),
                    'total_time' => $totalTime,
                ]);

                return $this->errorResponse('Visit not found, expired, or unauthorized', 404);
            }

            Log::debug('Visit updated successfully', [
                'visit_id' => $pixeliteTraceId,
                'total_time' => $totalTime,
                'payload_js_size' => strlen(json_encode($payloadJs)),
            ]);

            return response()->json([
                'success' => true,
                'timestamp' => now(),
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid data: '.implode(', ', Arr::flatten($e->errors())),
                'timestamp' => now(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Visit lifecycle update failed', [
                'visit_id' => $pixeliteTraceId ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Internal server error',
                'timestamp' => now(),
            ], 500);
        }
    }
}
