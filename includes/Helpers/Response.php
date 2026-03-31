<?php

namespace WBE\Helpers;

class Response
{
    public static function success(array $data = [], int $code = 200): void
    {
        wp_send_json([
            'success' => true,
            'data'    => $data
        ], $code);
    }

    public static function error(string $message, int $code = 400): void
    {
        wp_send_json([
            'success' => false,
            'message' => $message
        ], $code);
    }
}
