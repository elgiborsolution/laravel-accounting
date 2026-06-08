<?php

namespace ESolution\LaravelAccounting\Traits;

trait ApiResponse
{
    protected function successResponse($message = null, $data = null, $code = 200)
    {
        return response()->json([
            'status' => $code,
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    protected function errorResponse($data, $code = 422, $message = null)
    {
        return response()->json([
            'status' => $code,
            'message' => $message,
            'errors' => $data,
            'data' => null,
        ], $code);
    }
}
