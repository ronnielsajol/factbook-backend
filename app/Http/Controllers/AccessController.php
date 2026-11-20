<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AccessController
{
    public function __invoke(Request $request)
    {
        $key = config('factbook.api_key');
        if (!$key || !hash_equals($key, (string) $request->header('X-Factbook-Key'))) {
            return response()->json(['error' => 'Access denied.'], 403)->header('Cache-Control', 'no-store');
        }
        $client = $request->input('client', '');
        $pin = $request->input('pin', '');
        if (!is_string($client) || !preg_match('/^[a-f0-9]{64}$/D', $client) || !is_string($pin)) {
            return response()->json(['error' => 'Invalid request.'], 400)->header('Cache-Control', 'no-store');
        }
        $expected = config('factbook.pin');
        if (!is_string($expected) || !preg_match('/^[0-9]{6}$/D', $expected)) {
            return response()->json(['error' => 'Access is not configured.'], 503)->header('Cache-Control', 'no-store');
        }
        $status = DB::transaction(function () use ($client, $pin, $expected) {
            $now = time();
            DB::table('factbook_access_attempts')->insertOrIgnore(['client' => $client, 'attempts' => 0, 'reset_at' => $now + 900]);
            $attempt = DB::table('factbook_access_attempts')->where('client', $client)->lockForUpdate()->first();
            $count = $attempt->reset_at > $now ? $attempt->attempts : 0;
            $reset = $attempt->reset_at > $now ? $attempt->reset_at : $now + 900;
            if ($count >= 5) return 429;
            if (preg_match('/^[0-9]{6}$/D', $pin) && hash_equals(hash('sha256', $expected), hash('sha256', $pin))) {
                DB::table('factbook_access_attempts')->where('client', $client)->delete();
                return 200;
            }
            DB::table('factbook_access_attempts')->where('client', $client)->update(['attempts' => $count + 1, 'reset_at' => $reset]);
            return 401;
        });
        DB::table('factbook_access_attempts')->where('reset_at', '<', time() - 900)->delete();
        return response()->json(['ok' => $status === 200], $status)->header('Cache-Control', 'no-store');
    }
}

