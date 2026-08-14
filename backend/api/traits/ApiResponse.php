<?php

namespace App\Traits;

trait ApiResponse
{
    /**
     * Standard success response format
     *
     * @param mixed $data Response data
     * @param string $message Optional success message
     * @param int $statusCode HTTP status code (default: 200)
     * @return array
     */
    protected function successResponse($data = null, string $message = null, int $statusCode = 200): array
    {
        $response = [
            'success' => true,
            'status' => $statusCode,
            'data' => $data,
        ];

        if ($message !== null) {
            $response['message'] = $message;
        }

        return $response;
    }

    /**
     * Standard error response format
     *
     * @param string $message Error message
     * @param int $statusCode HTTP status code (default: 400)
     * @param array $errors Optional validation errors or additional error details
     * @return array
     */
    protected function errorResponse(string $message, int $statusCode = 400, array $errors = []): array
    {
        $response = [
            'success' => false,
            'status' => $statusCode,
            'message' => $message,
        ];

        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        return $response;
    }

    /**
     * Standard pagination response format
     *
     * @param mixed $items Paginated items
     * @param array $pagination Pagination metadata
     * @param string $message Optional success message
     * @return array
     */
    protected function paginatedResponse($items, array $pagination, string $message = null): array
    {
        $response = [
            'success' => true,
            'status' => 200,
            'data' => $items,
            'pagination' => $pagination
        ];

        if ($message !== null) {
            $response['message'] = $message;
        }

        return $response;
    }

    /**
     * Send JSON response
     *
     * @param array $response Response array (from successResponse, errorResponse, etc.)
     * @return never
     */
    protected function sendResponse(array $response): void
    {
        $statusCode = $response['status'] ?? 200;
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
