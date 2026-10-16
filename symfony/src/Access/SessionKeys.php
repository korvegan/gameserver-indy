<?php
declare(strict_types=1);

namespace App\Access;

use App\Runtime\SliceRuntime;

/** Compatible HMAC contract; the private key survives framework/releases. */
final class SessionKeys
{
    public function __construct(private readonly SliceRuntime $runtime) {}
    public function token(Principal $principal, string $scope): string
    {
        $this->runtime->assertInitialized(); $path = $this->runtime->directory.'/session-key';
        if (!file_exists($path)) {
            $handle = @fopen($path, 'x');
            if ($handle !== false) {
                try {
                    chmod($path, 0600); $key = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                    if (fwrite($handle, $key) !== strlen($key) || !fflush($handle) || !fsync($handle)) { throw new \RuntimeException('Sitzungsschlüssel nicht gespeichert.'); }
                } finally { fclose($handle); }
            }
        }
        $key = file_get_contents($path);
        if (!is_string($key) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $key)) { throw new \RuntimeException('Privater Sitzungsschlüssel unlesbar; nicht automatisch ersetzen.'); }
        $token = hash_hmac('sha256', $scope, $key);
        return $principal->revision === 0 ? $token : hash_hmac('sha256', $principal->id.':'.$principal->revision, $token);
    }
}
