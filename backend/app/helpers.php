<?php

if (!function_exists('successResponse')) {
    function successResponse($message, $data = null, $statusCode = 200)
    {
        return response()->json([
            'success' => true,
            'status' => $statusCode,
            'message' => $message,
            'data' => $data,
        ]);
    }
}


if (!function_exists('queryErrorResponse')) {
    function queryErrorResponse($message = 'Failed to execute query', $error = null, $statusCode = 500)
    {
        return response()->json([
            'success' => false,
            'status' => $statusCode,
            'message' => $message,
            'error' => $error,
        ]);
    }
}

if (!function_exists('serverErrorResponse')) {
    function serverErrorResponse($message = 'An unexpected server error occurred', $error = null, $statusCode = 500)
    {
        return response()->json([
            'success' => false,
            'status' => $statusCode,
            'message' => $message,
            'error' => $error,
        ]);
    }
}



if (!function_exists('validationErrorResponse')) {
    function validationErrorResponse($errors, $message = 'Validation failed', $statusCode = 422)
    {
        return response()->json([
            'success' => false,
            'status' => $statusCode,
            'message' => $message,
            'errors' => $errors,
        ]);
    }
}



if (!function_exists('createdResponse')) {
    /**
     * Smart createdResponse that supports both patterns:
     * 1. Old style: createdResponse(string $message, int $code = 201)
     * 2. New style: createdResponse(mixed $data, string $message = null, int $statusCode = 201)
     */
    function createdResponse($first = null, $second = null, int $third = 201)
    {
        // If first is string and second is int/numeric -> it's (Message, Code)
        if (is_string($first) && (is_numeric($second) || is_null($second))) {
            $code = $second ?? 201;
            return response()->json([
                'success' => true,
                'status' => $code,
                'message' => $first,
                'data' => null
            ], $code);
        }

        // Otherwise assume it's (Data, Message, Code)
        $code = is_numeric($second) ? (int)$second : $third;
        return response()->json([
            'success' => true,
            'status' => $code,
            'message' => is_string($second) ? $second : null,
            'data' => $first
        ], $code);
    }
}



if (!function_exists('errorResponse')) {
    function errorResponse($message, $statusCode = 500, $error = null)
    {
        return response()->json([
            'success' => false,
            'status' => $statusCode,
            'message' => $message,
            'error' => $error,
        ]);
    }
}


if (!function_exists('updatedResponse')) {
    function updatedResponse($data, string $message = null, int $code = 200)
    {
        return response()->json([
            'success' => true,
            'status' => $code,
            'message' => $message,
            'data' => $data
        ], $code);
    }
}





if (!function_exists('deleteResponse')) {
    function deleteResponse(string $message = null, int $code = 200)  // Changed 204 to 200
    {
        return response()->json([
            'success' => true,
            'status' => $code,
            'message' => $message,
        ], $code);
    }
}


if (!function_exists('notFoundResponse')) {
    function notFoundResponse($message = 'Resource not found', $statusCode = 200)
    {
        return response()->json([
            'success' => true,
            'status' => $statusCode,
            'message' => $message,
            'data' => []
        ], $statusCode);
    }
}